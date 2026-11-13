<?php

define('ABSPATH', dirname(__DIR__).'/wordpress/');

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'altimiser_wordpress_tests');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Altimiser tests');
define('WP_PHP_BINARY', escapeshellarg(PHP_BINARY));
