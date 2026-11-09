<?php

function rocket_clean_domain(): void
{
    $GLOBALS['altimiser_purged'][] = 'rocket';
}

function rocket_clean_files(array $urls): array
{
    $GLOBALS['altimiser_purged'][] = ['rocket', $urls];

    return array_fill_keys($urls, true);
}

function w3tc_flush_all(): void
{
    $GLOBALS['altimiser_purged'][] = 'w3tc';
}

function w3tc_flush_url(string $url): void
{
    $GLOBALS['altimiser_purged'][] = ['w3tc', $url];
}

function wp_cache_clear_cache(): void
{
    $GLOBALS['altimiser_purged'][] = 'super-cache';
}

function wpsc_delete_url_cache(string $url): bool
{
    $GLOBALS['altimiser_purged'][] = ['super-cache', $url];

    return $url !== home_url('/');
}
