# Altimiser for WordPress

Connects a WordPress site to Altimiser. It implements the [receiver protocol](https://github.com/alt-design/altimiser/blob/main/docs/receiver-protocol.md), and it is held to that document rather than to the [Statamic receiver](https://github.com/alt-design/altimiser-statamic), with which it shares no code.

It applies the changes Altimiser sends to titles, meta descriptions and Open Graph text, by writing to Yoast SEO's own fields. Without Yoast SEO active a site can still connect, but `GET /health` advertises no checks, Altimiser reports the gap as `receiver.no_seo_plugin`, and nothing on the site is changed.

## Requirements

WordPress 6.8 or newer, PHP 8.1 or newer, and Yoast SEO for anything to be applied. The plugin has no runtime Composer dependencies, because vendor classes in a plugin can collide with another plugin's copies.

## Installing

Put this repository in `wp-content/plugins/altimiser` and activate the plugin.

Then add a shared secret to `wp-config.php`, above the line that says to stop editing:

```php
define('ALTIMISER_SECRET', 'a long random string');
```

With WP-CLI, this generates one and writes it there:

```bash
wp config set ALTIMISER_SECRET "$(openssl rand -hex 32)"
```

Every request is refused until the secret is set, so an install without one is inert rather than open.

## Connecting

From Altimiser, using the same secret:

```bash
php artisan altimiser:connect https://example.com https://example.com/wp-json/altimiser/v1 <secret>
```

Use the address the site actually answers at, including `https` and any `www`. Altimiser follows redirects, which works for this `GET`, but a `POST` that gets redirected arrives as a `GET` and fails later on.

A successful connection shows the receiver as `wordpress` with the plugin's version and the WordPress version. Without Yoast SEO active a warning appears: "advertises no checks".

Connect again after activating Yoast SEO, so Altimiser reads what the site can now apply.

## What gets changed

| Checks | Yoast SEO field |
| --- | --- |
| `title.missing`, `too_short`, `too_long`, `irrelevant`, `duplicate` | SEO title |
| `meta_description.missing`, `too_short`, `too_long`, `irrelevant`, `duplicate` | Meta description |
| `open_graph.missing` for `og:title` and `og:description`, while Yoast SEO's social tags are switched on | Social title and description |

These are written to the post, page, custom post type or taxonomy term the URL belongs to. A shared template is never edited and a meta title is never written to `post_title`.

`canonical.missing` is left to a developer. Yoast SEO prints a canonical itself except on noindex pages, so a missing one points to something else on the site.

A change is refused rather than guessed at, and the finding goes to a person:

| Reason | What it means |
| --- | --- |
| `value_changed` | The field no longer holds what the scan saw, usually because an editor has been in since |
| `ambiguous` | The page has no value of its own and its template does not produce what the scan saw. A renamed page does this, and so does a `wpseo_title` or `wpseo_metadesc` filter, so a site using those filters gets inherited values refused |
| `not_found` | Nothing editable answers to the URL. This covers a blog home listing latest posts, post type and author archives, term pages on plain permalinks, and a page's address with a query on the end, such as a search or a campaign link |
| `no_suggestion` | There is no replacement value, as with `og:image` |
| `no_field` | Yoast SEO has no text field for that tag |

If Yoast SEO is not active when a change arrives, nothing is attempted and the change comes back as `failed` with the reason `seo_plugin_inactive`. That keeps its approval, so it goes out again on the next apply.

A page that inherits from a template goes back to inheriting when the change is reverted, rather than keeping a copy of the template's text.

Each request attempts up to 10 changes within 45 seconds and hands the rest back as `deferred`, for Altimiser to send again. The `altimiser_batch_size` and `altimiser_time_budget` filters change those numbers. The time budget is also held to half of `max_execution_time`.

## Page caches

A request that applies something, or finds it already in place, purges the page cache once, during the request, after Yoast has rebuilt what the page prints. A dry run never does.

| Plugin | How it was checked |
| --- | --- |
| Breeze 2.6.4 and 2.1.11 | Live: a cached page rendered afresh after an apply |
| W3 Total Cache 2.11.0 | Live: an apply carried out its page cache flush. A page coming back afresh was not observed, because it did not serve cached pages on the local install |
| WP Super Cache 3.1.5 | The purge function exists with the plugin active, and the call is tested. Purging was not observed, because it did not serve cached pages on the local install |
| WP Rocket, LiteSpeed Cache | From reading their source, and not from testing on a running site |

Any other page cache is not purged, so a change appears there once the cache expires.

`GET /health` is marked as uncacheable, because a cached copy would answer later requests without checking their signature. Breeze 2.1.11 ignores the usual signals on REST responses, so the plugin also makes the check that one version does make come out as not cacheable. Without that, a signed request left a cache file behind on a local install.

## The review queue in wp-admin

This is optional. Add Altimiser's address to `wp-config.php`, and an Altimiser item appears in the admin menu for editors and administrators:

```php
define('ALTIMISER_URL', 'https://altimiser.example.com');
```

The page shows the queue in a frame that fills the space beside the menu. Altimiser only allows framing by the origin of the site's URL, so the queue will not load for an admin served from another origin, or in a browser that refuses the frame. The frame's link is signed on the server, carries the email address of whoever is signed in, which is who an approval gets recorded against, and expires. Reloading the page gets a new one.

The menu item and a dashboard widget show how many changes are waiting for review. The number is kept for five minutes, so it can lag behind the queue. If Altimiser cannot be reached the number is left out, and it is not asked again for five minutes, so one admin page waits at most five seconds rather than all of them.

Two more constants are available:

| Constant | What it is for |
| --- | --- |
| `ALTIMISER_SITE_URL` | The address the site was added to Altimiser under, where that differs from WordPress's home URL |
| `ALTIMISER_LINK_MINUTES` | How long a review link lasts. The default is 30 |

## Updates

WordPress's own updater offers new versions, from the latest release on GitHub. The **View details** link in the update notice says the plugin was not found, because it is not in the WordPress directory. The update itself works.

A site that keeps its plugins in git should commit the update instead, or the next deploy puts the old version back.

To publish a release, change `Version` in `altimiser.php`, then push a tag naming that version, such as `v0.2.0`. The release workflow builds `altimiser.zip` and publishes it, and fails if the tag and the version disagree.

## When the connection fails

`altimiser:connect` prints the status and the start of the response.

| What Altimiser reports | What it means | What to check |
| --- | --- | --- |
| `401` Altimiser is not configured on this site | `ALTIMISER_SECRET` is missing, empty or not a string | That `wp-config.php` on this server defines the constant, with a non empty string as its value |
| `401` Signature does not match | The two ends hold different secrets | Connect again passing the value from `wp-config.php` |
| `401` Signature timestamp is outside the accepted window | The timestamp was more than five minutes out, or never arrived | The clocks on both servers. Something in front of the site may also be removing the `X-Altimiser-*` headers |
| `404` `rest_no_route` | WordPress answered and has no such route | That the plugin is active, and that the receiver URL ends in `/altimiser/v1` |
| `200` followed by HTML | The address is not reaching the REST API. On plain permalinks `/wp-json` is not routed, so WordPress answers with an ordinary page | Choose any other permalink structure, or use `https://example.com/?rest_route=/altimiser/v1` as the receiver URL |
| `401` or `403` with another code, such as `rest_not_logged_in` | Something is refusing anonymous REST requests before the signature is looked at. Altimiser's requests are anonymous, because the signature is what identifies them | Security plugins and any `rest_authentication_errors` filter. Allow `/wp-json/altimiser/v1/` |
| `403` from something other than WordPress, or a timeout | A firewall or CDN in front of the site | Let Altimiser's server through |

Each row was reproduced on a local install except the firewall row and the missing headers, which are inferred.

Two common security plugins need nothing doing. From reading their source, and not from testing on a running site: the REST API restriction in Kadence Security 9.0.3 (formerly iThemes Security) covers only `wp` routes, and the REST hooks in Wordfence 8.2.2 cover only user routes.

## Testing

The tests run against a real WordPress, which Composer installs into `wordpress/`, and a real Yoast SEO from wpackagist, in `vendor/wp-plugins/`. They need MySQL at `127.0.0.1` as `root` with no password.

```bash
composer install
mysql -h127.0.0.1 -uroot -e "CREATE DATABASE IF NOT EXISTS altimiser_wordpress_tests"
vendor/bin/phpunit
```

GitHub Actions runs them on PHP 8.1 to 8.4, against WordPress 6.8 and the latest release.

`SignatureTest` computes the signature from the protocol document rather than taking it from the plugin or from Altimiser, and checks that calculation against the document's worked examples. Requests go through WordPress's own REST server, so `permission_callback` is what is under test.

`NoSecretTest` runs each test in a process of its own, because a constant cannot be undefined or redefined. `PageCacheTest` does the same, and defines stand-ins for the cache plugins' purge functions in `tests/fixtures/page-cache-plugins.php`.
