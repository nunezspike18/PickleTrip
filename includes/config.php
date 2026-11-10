<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('DB_TYPE')) {
    define('DB_TYPE', getenv('PICKLE_DB_TYPE') ?: 'mysql');
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('PICKLE_DB_HOST') ?: '127.0.0.1');
}

if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('PICKLE_DB_NAME') ?: 'pickle_trip_app');
}

if (!defined('DB_USER')) {
    define('DB_USER', getenv('PICKLE_DB_USER') ?: 'root');
}

if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('PICKLE_DB_PASS') ?: '');
}

if (!defined('DB_PATH')) {
    define('DB_PATH', APP_ROOT . '/storage/pickletrip.sqlite');
}

if (!defined('APP_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http';
    define('APP_URL', $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}
