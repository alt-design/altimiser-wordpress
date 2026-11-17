<?php

/**
 * Removes what the plugin keeps for itself. Changes it applied are the site's content
 * by now, and stay. A sitemap it declared in the robots.txt WordPress prints goes with
 * it, because only the plugin's filter printed it.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$forgetThisSite = static function (): void {
    delete_option('altimiser_settings');
    delete_option('altimiser_robots_sitemap');
    delete_transient('altimiser_summary');
    delete_transient('altimiser_summary_lock');
    delete_transient('altimiser_refusal_logged');
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
