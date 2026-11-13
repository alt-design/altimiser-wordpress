# Altimiser for WordPress

Connects a WordPress site to Altimiser. It implements Altimiser's receiver protocol, which is kept with Altimiser itself, and it is held to that document rather than to the [Statamic receiver](https://github.com/alt-design/altimiser-statamic), with which it shares no code.

It applies the changes Altimiser sends to titles, meta descriptions and Open Graph text, by writing to Yoast SEO's own fields. Without Yoast SEO active a site can still connect, but `GET /health` advertises none of those checks, Altimiser reports the gap as `receiver.no_seo_plugin`, and none of those fields is changed.

## Requirements

WordPress 6.8 or newer, PHP 8.1 or newer, and Yoast SEO 24.0 or newer for titles, descriptions and Open Graph text. The tests run against Yoast SEO 24.0 as well as the latest release. The plugin has no runtime Composer dependencies, because vendor classes in a plugin can collide with another plugin's copies.

## Installing

Install from `altimiser.zip` on the latest GitHub release, through **Plugins > Add New > Upload Plugin**, and activate it. A clone in a folder not named `altimiser` gets a second copy on its first update.

Then give the site a shared secret of at least 32 characters. Every request is refused until there is one, and while it is shorter than that, so an install without one is inert rather than open. There are three places to put it, and the first one found wins:

1. A constant in `wp-config.php`, above the line that says to stop editing:

   ```php
   define('ALTIMISER_SECRET', 'a long random string');
   ```

   With WP-CLI, this generates one and writes it there:

   ```bash
   wp config set ALTIMISER_SECRET "$(openssl rand -hex 32)"
   ```

2. An `ALTIMISER_SECRET` environment variable, for hosts that manage configuration that way.
3. **Settings > Altimiser**, for administrators. The field never shows a saved secret, and leaving it blank keeps the one saved.

The settings page also shows the receiver address to give Altimiser, and says whether the site will accept its requests.

### Multisite

Each site on a network has its own **Settings > Altimiser** page, and so its own secret and its own connection in Altimiser. A constant in `wp-config.php` or an environment variable applies to every site on the network at once, so use the settings page there. Multisite has not yet been tried on a running network.

## Connecting

From Altimiser, using the same secret:

```bash
php artisan altimiser:connect https://example.com https://example.com/wp-json/altimiser/v1 <secret>
```

Use the address the site actually answers at, including `https` and any `www`. Altimiser follows redirects, which works for this `GET`, but a `POST` that gets redirected arrives as a `GET` and fails later on.

A successful connection shows the receiver as `wordpress` with the plugin's version and the WordPress version. Without Yoast SEO active a warning appears: "advertises no checks".

Connect again after activating Yoast SEO, so Altimiser reads what the site can now apply.

`GET /health` also reports two settings under `setup`, which Altimiser turns into findings because they stop fixes from counting: **Discourage search engines** under **Settings > Reading**, and plain permalinks.

## What gets changed

| Checks | Yoast SEO field |
| --- | --- |
| `title.missing`, `too_short`, `too_long`, `irrelevant`, `duplicate` | SEO title |
| `meta_description.missing`, `too_short`, `too_long`, `irrelevant`, `duplicate` | Meta description |
| `open_graph.missing` for `og:title` and `og:description`, while Yoast SEO's social tags are switched on | Social title and description |

These are written to the post, page, custom post type or taxonomy term the URL belongs to, as long as its type has public pages. A shared template is never edited and a meta title is never written to `post_title`.

`canonical.missing` is left to a developer. Yoast SEO prints a canonical itself except on noindex pages, so a missing one points to something else on the site.

A change is refused rather than guessed at, and the finding goes to a person:

| Reason | What it means |
| --- | --- |
| `value_changed` | The field no longer holds what the scan saw, usually because an editor has been in since |
| `ambiguous` | The page has no value of its own and its template does not produce what the scan saw. A renamed page does this, and so does a `wpseo_title` or `wpseo_metadesc` filter, so a site using those filters gets inherited values refused |
| `not_found` | Nothing editable answers to the URL. This covers a blog home listing latest posts, post type and author archives, term pages on plain permalinks, a type with no pages of its own such as a menu item, and a page's address with a query on the end, such as a search or a campaign link |
| `no_suggestion` | There is no replacement value, as with `og:image` |
| `no_field` | Yoast SEO has no text field for that tag |

If Yoast SEO is not active when one of its changes arrives, nothing is attempted and the change comes back as `failed` with the reason `seo_plugin_inactive`. That keeps its approval, so it goes out again on the next apply.

A page that inherited from a template goes back to inheriting when the change is reverted, as long as the template still produces what the scan saw. A field that was empty with nothing to inherit is not reverted, because Altimiser has no earlier value to send back, so it keeps the new value until someone clears it in the editor.

Each request attempts up to 10 changes within 45 seconds and hands the rest back as `deferred`, for Altimiser to send again. The `altimiser_batch_size` and `altimiser_time_budget` filters change those numbers. The time budget is also held to half of `max_execution_time`.

## Page caches

A request that applies something, or finds it already in place, purges the pages it changed, once, during the request, after Yoast has rebuilt what the page prints. A dry run never does.

| Plugin | What is purged | How it was checked |
| --- | --- | --- |
| WP Rocket | Each changed page, with `rocket_clean_files()` | From reading its source |
| W3 Total Cache | Each changed page, with `w3tc_flush_url()` | From reading its source. 2.11.0 carried out a whole cache flush live on a local install |
| WP Super Cache | Each changed page, with `wpsc_delete_url_cache()`, and the whole cache for the home page or an address with a query, which it will not delete by address | From reading its source |
| LiteSpeed Cache | Each changed page, through its `litespeed_purge_url` action | From reading its source |
| Breeze | The whole cache, because it offers no purge by address | Live on 2.6.4 and 2.1.11: a cached page rendered afresh after an apply |

Any other cache, including one run by the host or a CDN, is not purged, so a change appears there once it expires. To purge one, listen for the `altimiser_purge_urls` action, which receives the addresses and whether the whole site changed:

```php
add_action('altimiser_purge_urls', function (array $urls, bool $everything): void {
    // Purge $urls, or everything when $everything is true.
}, 10, 2);
```

`GET /health` is marked as uncacheable, because a cached copy would answer later requests without checking their signature. Breeze 2.1.11 ignores the usual signals on REST responses, so the plugin also makes the check that one version does make come out as not cacheable. Without that, a signed request left a cache file behind on a local install.

## The review queue in wp-admin

This is optional. Add Altimiser's address on **Settings > Altimiser**, or in `wp-config.php`, and an Altimiser item appears in the admin menu for editors and administrators:

```php
define('ALTIMISER_URL', 'https://altimiser.example.com');
```

Each editor connects their own Altimiser account once, from that page. **Connect** opens Altimiser, which asks them to sign in and to approve the connection, then sends them back. Altimiser decides what they can do in the queue from their account, so an editor without an account, or whose account has not been given the site, gets no further than the **Connect** button. A connection lasts twelve hours for an agency account and thirty days for a client account, after which the page offers to connect again. **Disconnect** forgets it straight away. Connecting needs wp-admin to be on the same address as the site's URL.

Once connected, the page shows the queue in a frame that fills the space beside the menu. Altimiser only allows framing by the origin of the site's URL, so the queue will not load for an admin served from another origin, or in a browser that refuses the frame. The frame's link is signed on the server with the site secret and with the editor's connection, and expires. Reloading the page gets a new one.

The menu item shows how many changes are waiting for review, to an editor who has connected. The dashboard widget shows the same, along with how many approved changes are waiting to be applied and how many of the site's open findings Altimiser can fix here. The counts are kept for five minutes, so they can lag behind the queue. Altimiser is given two seconds to answer, and only one admin page asks at a time. If it cannot be reached the counts are left out, and it is not asked again for five minutes.

Two more constants are available:

| Constant | What it is for |
| --- | --- |
| `ALTIMISER_SITE_URL` | The address the site was added to Altimiser under, where that differs from WordPress's home URL |
| `ALTIMISER_LINK_MINUTES` | How long a review link lasts. The default is 30 |

## What is sent where

The plugin sends nothing on its own. Altimiser scans the site's public pages from outside, and what it does with them, including sending text and images to AI model providers to write suggestions, is covered by the agreement with the client rather than by this plugin.

Altimiser sends the site the changes that were approved: page addresses, the values the scan saw, and the values to write. The site answers with what it did.

With the review queue switched on, the site asks Altimiser for its counts, naming only the site. Connecting and the frame's link carry the signed in editor's email address to Altimiser, which shows it when they connect and keeps it with the connection. Approvals are recorded against the Altimiser account. The token Altimiser hands back is kept against the editor in the database, encrypted with a key drawn from the salts in `wp-config.php`, and never reaches the browser. Tell the client this before switching it on.

Refused requests that carried a signature, and changes that could not be written, are logged to PHP's error log, which is `wp-content/debug.log` when `WP_DEBUG_LOG` is on. The log names the route or the page address, and holds no secrets or personal data.

## Updates

WordPress's own updater offers new versions, from the latest release on GitHub. The **View details** link in the update notice says the plugin was not found, because it is not in the WordPress directory. The update itself works.

A site that keeps its plugins in git should commit the update instead, or the next deploy puts the old version back.

To publish a release, change `Version` in `altimiser.php` on `main`, then push a tag naming that version, such as `v0.2.0`. The release workflow runs the tests, checks that the tag is on `main` and matches the version, and only then builds `altimiser.zip` and publishes it. A ruleset lets only repository admins create a `v` tag and stops anyone moving or deleting one, and published releases are immutable, so the zip of a release cannot be swapped once it is out. The updater trusts the latest release, so keep admin access to the repository to people who need it.

## Uninstalling

Deleting the plugin removes its settings, the counts it keeps and every editor's connection, on every site of a network. Changes it applied are the site's content by then, and stay.

## When the connection fails

`altimiser:connect` prints the status and the start of the response.

| What Altimiser reports | What it means | What to check |
| --- | --- | --- |
| `401` Altimiser is not configured on this site | No secret is set, or it is empty or not a string | **Settings > Altimiser**, which says where the secret is expected and whether one is found |
| `401` The Altimiser secret on this site is shorter than 32 characters | A secret is set and too short to accept | Replace it with a longer one, in the same place, and connect again with the new value |
| `401` Signature does not match | The two ends hold different secrets | Connect again passing the value the site holds |
| `401` Signature timestamp is outside the accepted window | The timestamp was more than five minutes out, or never arrived | The clocks on both servers. Something in front of the site may also be removing the `X-Altimiser-*` headers |
| `404` `rest_no_route` | WordPress answered and has no such route | That the plugin is active, and that the receiver URL ends in `/altimiser/v1` |
| `200` followed by HTML | The address is not reaching the REST API. On plain permalinks `/wp-json` is not routed, so WordPress answers with an ordinary page | Choose any other permalink structure, or use `https://example.com/?rest_route=/altimiser/v1` as the receiver URL |
| `401` or `403` with another code, such as `rest_not_logged_in` | Something is refusing anonymous REST requests before the signature is looked at. Altimiser's requests are anonymous, because the signature is what identifies them | Security plugins and any `rest_authentication_errors` filter. Allow `/wp-json/altimiser/v1/` |
| `403` from something other than WordPress, or a timeout | A firewall or CDN in front of the site | Let Altimiser's server through |

Each row was reproduced on a local install except the firewall row and the missing headers, which are inferred.

Two common security plugins need nothing doing. From reading their source, and not from testing on a running site: the REST API restriction in Kadence Security 9.0.3 (formerly iThemes Security) covers only `wp` routes, and the REST hooks in Wordfence 8.2.2 cover only user routes.

## Testing

The tests run against a real WordPress, which Composer installs into `wordpress/`, and a real Yoast SEO from wpackagist, in `vendor/wp-plugins/`. They need MySQL as `root` with no password, at `127.0.0.1` unless `WP_TESTS_DB_HOST` says otherwise, in a database named `altimiser_wordpress_tests` unless `WP_TESTS_DB_NAME` says otherwise.

```bash
composer install
mysql -h127.0.0.1 -uroot -e "CREATE DATABASE IF NOT EXISTS altimiser_wordpress_tests"
vendor/bin/phpunit
```

GitHub Actions runs them on PHP 8.1 to 8.4, against WordPress 6.8 and the latest release, with the latest Yoast SEO, and once more on PHP 8.1 and WordPress 6.8 with Yoast SEO 24.0. Anything the plugin logs during the tests goes to `altimiser-tests.log` in the system's temporary directory.

`SignatureTest` computes the signature from the protocol document rather than taking it from the plugin or from Altimiser, and checks that calculation against the document's worked examples. Requests go through WordPress's own REST server, so `permission_callback` is what is under test.

`NoSecretTest`, `SecretSourcesTest` and `SettingsPageTest` run each test in a process of its own, because a constant cannot be undefined or redefined. `PageCacheTest` does the same where it defines stand-ins for the cache plugins' purge functions, in `tests/fixtures/page-cache-plugins.php`.
