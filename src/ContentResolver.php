<?php

namespace AltDesign\Altimiser;

use WP_Post;
use WP_Term;

class ContentResolver
{
    public function find(string $url): WP_Post|WP_Term|null
    {
        $postId = url_to_postid($url);

        if ($postId === 0) {
            return $this->findTerm($url);
        }

        return $this->isPublishedAt($url, $postId) ? get_post($postId) : null;
    }

    /**
     * url_to_postid() answers for unpublished records and revisions, for the addresses
     * beneath a page, such as its feed or its second page, and ignores the query, so
     * /?s=anything answers as a static home page. A suggestion made for one of those
     * does not belong on the page itself.
     */
    private function isPublishedAt(string $url, int $postId): bool
    {
        if (get_post_status($postId) !== 'publish') {
            return false;
        }

        return $this->address(get_permalink($postId)) === $this->address($url);
    }

    private function findTerm(string $url): ?WP_Term
    {
        foreach (get_taxonomies(['public' => true]) as $taxonomy) {
            $term = get_term_by('slug', wp_basename($this->path($url)), $taxonomy);

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
