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
 * Text Domain: altimiser
 */

use AltDesign\Altimiser\ControlPanel;
use AltDesign\Altimiser\Receiver;
use AltDesign\Altimiser\ReviewQueuePage;
use AltDesign\Altimiser\RobotsApplier;
use AltDesign\Altimiser\Settings;
use AltDesign\Altimiser\SettingsPage;
use AltDesign\Altimiser\Updater;
use AltDesign\Altimiser\Yoast;

defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $namespace = 'AltDesign\\Altimiser\\';
    $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';

    if (str_starts_with($class, $namespace) && is_file($file)) {
        require $file;
    }
});

add_action('rest_api_init', static function (): void {
    (new Receiver(__FILE__, defined('WPSEO_VERSION') ? new Yoast() : null))->registerRoutes();
});

add_action('add_option_'.Settings::$option, [Settings::class, 'keepOutOfAutoload']);
add_action('update_option_'.Settings::$option, [Settings::class, 'keepOutOfAutoload']);
add_filter('robots_txt', [RobotsApplier::class, 'declareSitemap']);

add_action('plugins_loaded', static function (): void {
    if (is_admin()) {
        (new SettingsPage())->register();
    }

    $controlPanel = ControlPanel::configured();

    if ($controlPanel !== null) {
        (new ReviewQueuePage($controlPanel))->register();
    }
});

(new Updater(__FILE__))->register();
(new AltDesign\Altimiser\StructuredDataPrinter())->register();
