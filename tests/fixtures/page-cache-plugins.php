<?php

function rocket_clean_domain(): void
{
    $GLOBALS['altimiser_purged'][] = 'rocket';
}

function w3tc_flush_all(): void
{
    $GLOBALS['altimiser_purged'][] = 'w3tc';
}

function wp_cache_clear_cache(): void
{
    $GLOBALS['altimiser_purged'][] = 'super-cache';
}
