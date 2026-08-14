<?php
declare(strict_types=1);

require __DIR__ . '/app/PeerNode.php';

use CdnDrive\PeerNode;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = __DIR__;
$checks = [];
$failed = false;

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failed): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failed = true;
    }
};

$record('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
$record('cURL extension', function_exists('curl_init'));
$record('PDO SQLite', extension_loaded('pdo_sqlite'));
$record('origin writable', is_dir($root . '/origin') && is_writable($root . '/origin'), $root . '/origin');
$record('data writable', is_dir($root . '/data') && is_writable($root . '/data'), $root . '/data');

$configFile = $root . '/data/peer.json';
$config = null;
if (is_file($configFile)) {
    $config = json_decode((string)file_get_contents($configFile), true);
}
$record('peer config', is_array($config), $configFile);
if (is_array($config)) {
    $secret = (string)($config['shared_secret'] ?? '');
    $record('shared secret >= 32 chars', strlen($secret) >= 32);
    $peerUrl = (string)($config['peer_url'] ?? '');
    $record('peer URL is HTTPS', str_starts_with(strtolower($peerUrl), 'https://'), $peerUrl);
    $role = (string)($config['role'] ?? '');
    $record('role is primary/replica', in_array($role, ['primary', 'replica'], true), $role);
}

if (!$failed) {
    try {
        $health = (new PeerNode($root))->health();
        $record('peer reachable/authenticated', true, ($health['node'] ?? 'unknown') . ' (' . ($health['role'] ?? 'unknown') . ')');
    } catch (Throwable $e) {
        $record('peer reachable/authenticated', false, $e->getMessage());
    }
}

foreach ($checks as $check) {
    fwrite($check['ok'] ? STDOUT : STDERR, sprintf(
        "[%s] %s%s\n",
        $check['ok'] ? 'OK' : 'NG',
        $check['name'],
        $check['detail'] !== '' ? ': ' . $check['detail'] : ''
    ));
}

exit($failed ? 1 : 0);
