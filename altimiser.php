<?php

/**
 * Plugin Name: Altimiser
 * Plugin URI: https://github.com/alt-design/altimiser-wordpress
 * Description: Applies Altimiser's approved changes to this site.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author: Alt Design
 * Author URI: https://alt-design.net
 * Update URI: https://github.com/alt-design/altimiser-wordpress
 */

use AltDesign\Altimiser\ControlPanel;
use AltDesign\Altimiser\Receiver;
use AltDesign\Altimiser\ReviewQueuePage;
use AltDesign\Altimiser\Updater;
use AltDesign\Altimiser\Yoast;

defined('ABSPATH') || exit;

require_once __DIR__.'/src/ChangeDispatcher.php';
require_once __DIR__.'/src/ChangeRequest.php';
require_once __DIR__.'/src/ChangeResult.php';
require_once __DIR__.'/src/ContentResolver.php';
require_once __DIR__.'/src/ControlPanel.php';
require_once __DIR__.'/src/PageCache.php';
require_once __DIR__.'/src/Receiver.php';
require_once __DIR__.'/src/ReviewQueuePage.php';
require_once __DIR__.'/src/Updater.php';
require_once __DIR__.'/src/Yoast.php';
require_once __DIR__.'/src/YoastApplier.php';

add_action('rest_api_init', static function (): void {
    (new Receiver(__FILE__, defined('WPSEO_VERSION') ? new Yoast() : null))->registerRoutes();
});

add_action('plugins_loaded', static function (): void {
    $controlPanel = ControlPanel::configured();

    if ($controlPanel !== null) {
        (new ReviewQueuePage($controlPanel))->register();
    }
});

(new Updater(__FILE__))->register();
