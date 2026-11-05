<?php

namespace AltDesign\Altimiser;

use Throwable;

class PageCache
{
    /**
     * Altimiser scans again a few minutes after applying, and a cached page would
     * hand a fix that landed back as one that did not. A failure here is logged and
     * not returned, because the changes it follows have already been written.
     */
    public function purge(): void
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
        } catch (Throwable $exception) {
            error_log("Altimiser could not purge the page cache: {$exception->getMessage()}");
        }
    }
}
