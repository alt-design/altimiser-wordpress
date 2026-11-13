<?php

/**
 * Removes what the plugin keeps for itself. Changes it applied are the site's content
 * by now, and stay.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$forgetThisSite = static function (): void {
    delete_option('altimiser_settings');
    delete_transient('altimiser_summary');
    delete_transient('altimiser_summary_lock');
    delete_metadata('user', 0, $GLOBALS['wpdb']->get_blog_prefix().'altimiser_connection', '', true);
};

if (! is_multisite()) {
    $forgetThisSite();

    return;
}

foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
    switch_to_blog($siteId);
    $forgetThisSite();
    restore_current_blog();
}
