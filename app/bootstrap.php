<?php
/**
 * Amorçage de l'application : constantes, autoload, .env, configuration.
 * Inclus par public/index.php (web) et par les scripts bin/ (CLI).
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', __DIR__);

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

App\Env::load(BASE_PATH . '/.env');

// Réglages PHP raisonnables pour un hébergement mutualisé
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Paris');
error_reporting(E_ALL);
ini_set('display_errors', App\Config::isLocal() ? '1' : '0');
ini_set('log_errors', '1');
$logDir = App\Config::path('storage/logs');
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
ini_set('error_log', $logDir . '/php-error.log');
