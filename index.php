<?php
declare(strict_types=1);

require __DIR__ . '/app/App.php';

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if (is_file($file)) {
        return false;
    }
}

$app = new CdnDrive\App(__DIR__);
$app->run();
