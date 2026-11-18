<?php

namespace AltDesign\Altimiser;

use WP_Post;
use WP_Post_Type;
use WP_Term;

class ContentResolver
{
    public function __construct(private Languages $languages = new Languages()) {}

    /**
     * On a multilingual site an address in another language only answers while WPML is
     * set to that language, so each of the site's languages is tried in turn once the
     * current one has found nothing. The address check below stops a record being
     * taken for an address it is not at, whichever language is being tried.
     */
    public function find(string $url): WP_Post|WP_Term|null
    {
        $lookup = $this->languages->withoutLanguage($url);

        return $this->inEachLanguage(fn (): WP_Post|WP_Term|null => $this->findInCurrentLanguage($url, $lookup));
    }

    private function findInCurrentLanguage(string $url, string $lookup): WP_Post|WP_Term|null
    {
        $postId = url_to_postid($lookup);

        if ($postId === 0) {
            $postId = $this->landingPageAt($url);
        }

        if ($postId === 0) {
            return $this->findTerm($url, $lookup);
        }

        return $this->isPublishedAt($url, $postId) ? get_post($postId) : null;
    }

    public function findArchive(string $url): ?WP_Post_Type
    {
        return $this->inEachLanguage(fn (): ?WP_Post_Type => $this->findArchiveInCurrentLanguage($url));
    }

    /**
     * @template T of WP_Post|WP_Term|WP_Post_Type
     *
     * @param  callable(): ?T  $resolve
     * @return ?T
     */
    private function inEachLanguage(callable $resolve): WP_Post|WP_Term|WP_Post_Type|null
    {
        $found = $resolve();

        if ($found !== null || ! $this->languages->multilingual()) {
            return $found;
        }

        $original = $this->languages->current();

        try {
            foreach (array_diff($this->languages->codes(), [$original]) as $code) {
                $this->languages->switchTo($code);

                $found = $resolve();

                if ($found !== null) {
                    return $found;
                }
            }
        } finally {
            $this->languages->switchTo($original);
        }

        return null;
    }

    private function findArchiveInCurrentLanguage(string $url): ?WP_Post_Type
    {
        foreach (get_post_types(['public' => true, 'has_archive' => true], 'objects') as $type) {
            $link = get_post_type_archive_link($type->name);

            if (is_string($link) && $this->address($link) === $this->address($url)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * url_to_postid() has no answer for the page chosen to list the blog, because its
     * address is the posts index and not the page's own rewrite rule, and none for a
     * static front page once the address carries a language.
     */
    private function landingPageAt(string $url): int
    {
        if (get_option('show_on_front') !== 'page') {
            return 0;
        }

        foreach (['page_for_posts', 'page_on_front'] as $option) {
            $pageId = (int) get_option($option);
            $link = $pageId === 0 ? false : get_permalink($pageId);

            if (is_string($link) && $this->address($link) === $this->address($url)) {
                return $pageId;
            }
        }

        return 0;
    }

    /**
     * url_to_postid() answers for unpublished records and revisions, for the addresses
     * beneath a page, such as its feed or its second page, and ignores the query, so
     * /?s=anything answers as a static home page. A suggestion made for one of those
     * does not belong on the page itself. Under plain permalinks it also answers
     * ?p=N for a menu item or any other type with no page of its own.
     */
    private function isPublishedAt(string $url, int $postId): bool
    {
        if (get_post_status($postId) !== 'publish') {
            return false;
        }

        if (! is_post_type_viewable((string) get_post_type($postId))) {
            return false;
        }

        return $this->address(get_permalink($postId)) === $this->address($url);
    }

    private function findTerm(string $url, string $lookup): ?WP_Term
    {
        foreach (get_taxonomies(['public' => true]) as $taxonomy) {
            $term = get_term_by('slug', wp_basename($this->path($lookup)), $taxonomy);

            if (! $term instanceof WP_Term) {
                continue;
            }

            if ($this->address(get_term_link($term)) === $this->address($url)) {
                return $term;
            }
        }

        return null;
    }

    private function address(string $url): string
    {
        return $this->path($url).'?'.parse_url($url, PHP_URL_QUERY);
    }

    /**
     * Decoded, because WordPress keeps a slug in another alphabet percent encoded in
     * lower case, and a scan may have seen it in upper case or as the letters themselves.
     */
    private function path(string $url): string
    {
        return trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/');
    }
}
