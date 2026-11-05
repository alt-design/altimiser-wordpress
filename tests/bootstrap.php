<?php

$pluginDirectory = dirname(__DIR__);

require_once "{$pluginDirectory}/vendor/autoload.php";

putenv('WP_PHPUNIT__TESTS_CONFIG='.__DIR__.'/wp-tests-config.php');

$wordPressTests = getenv('WP_PHPUNIT__DIR');

require_once "{$wordPressTests}/includes/functions.php";

tests_add_filter('muplugins_loaded', static function () use ($pluginDirectory): void {
    require "{$pluginDirectory}/vendor/wp-plugins/wordpress-seo/wp-seo.php";
    require "{$pluginDirectory}/altimiser.php";
});

require_once "{$wordPressTests}/includes/bootstrap.php";

require_once __DIR__.'/ReceiverTestCase.php';
