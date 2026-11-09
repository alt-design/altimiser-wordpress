<?php

namespace AltDesign\Altimiser;

use Throwable;

/**
 * Altimiser scans again a few minutes after applying, and a cached page would hand a
 * fix that landed back as one that did not. A failure here is logged and not returned,
 * because the changes it follows have already been written.
 *
 * Each plugin's function was checked in its own source: WP Rocket's rocket_clean_files(),
 * W3 Total Cache's w3tc_flush_url(), WP Super Cache's wpsc_delete_url_cache() and
 * LiteSpeed's litespeed_purge_url action. Breeze offers no purge by address, so it is
 * cleared whole. A host or edge cache can listen for altimiser_purge_urls.
 */
class PageCache
{
    /**
     * @param  array<int, string>  $urls
     */
    public function purge(array $urls): void
    {
        $urls = array_values(array_unique($urls));

        if ($urls === []) {
            return;
        }

        try {
            $this->purgeAddresses($urls);
        } catch (Throwable $exception) {
            error_log("Altimiser could not purge the page cache: {$exception->getMessage()}");
        }
    }

    /**
     * For a change that shows on every page of a kind, such as a default for a post type.
     */
    public function purgeEverything(): void
    {
        try {
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
            }

            if (function_exists('w3tc_flush_all')) {
                w3tc_flush_all();
            }

            if (function_exists('wp_cache_clear_cache')) {
                wp_cache_clear_cache();
            }

            do_action('litespeed_purge_all');
            do_action('breeze_clear_all_cache');
            do_action('altimiser_purge_urls', [home_url('/')], true);
        } catch (Throwable $exception) {
            error_log("Altimiser could not purge the page cache: {$exception->getMessage()}");
        }
    }

    /**
     * WP Super Cache will not delete its home page or an address with a query by
     * address, and says so by returning false, so it is cleared whole for those.
     *
     * @param  array<int, string>  $urls
     */
    private function purgeAddresses(array $urls): void
    {
        if (function_exists('rocket_clean_files')) {
            rocket_clean_files($urls);
        }

        if (function_exists('w3tc_flush_url')) {
            array_map('w3tc_flush_url', $urls);
        }

        if (function_exists('wpsc_delete_url_cache') && in_array(false, array_map('wpsc_delete_url_cache', $urls), true)) {
            wp_cache_clear_cache();
        }

        foreach ($urls as $url) {
            do_action('litespeed_purge_url', $url);
        }

        do_action('breeze_clear_all_cache');
        do_action('altimiser_purge_urls', $urls, false);
    }
}
