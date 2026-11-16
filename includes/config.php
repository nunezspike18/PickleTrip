<?php

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', rtrim((string) getenv('PICKLE_SUPABASE_URL'), '/'));
}

if (!defined('SUPABASE_SERVICE_ROLE_KEY')) {
    define('SUPABASE_SERVICE_ROLE_KEY', (string) getenv('PICKLE_SUPABASE_SERVICE_ROLE_KEY'));
}

if (!defined('GCASH_NUMBER')) {
    define('GCASH_NUMBER', '09563563926');
}

if (!defined('OPEN_PLAY_PRICE')) {
    define('OPEN_PLAY_PRICE', max(0, (int) (getenv('PICKLE_OPEN_PLAY_PRICE') ?: 100)));
}

if (!defined('COURT_RENTAL_HOURLY_PRICE')) {
    define('COURT_RENTAL_HOURLY_PRICE', max(0, (int) (getenv('PICKLE_COURT_RENTAL_HOURLY_PRICE') ?: 250)));
}

if (!defined('APP_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443 ? 'https' : 'http';
    define('APP_URL', $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}
