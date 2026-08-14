<?php
declare(strict_types=1);

require __DIR__ . '/app/PeerNode.php';
require __DIR__ . '/app/PeerReconciler.php';

use CdnDrive\PeerNode;
use CdnDrive\PeerReconciler;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = __DIR__;
$configFile = $root . '/data/peer.json';
if (!is_file($configFile)) {
    fwrite(STDERR, "data/peer.json not found. Copy data/peer.example.json and edit it.\n");
    exit(1);
}
$config = json_decode((string)file_get_contents($configFile), true);
if (!is_array($config) || ($config['role'] ?? '') !== 'primary') {
    fwrite(STDERR, "peer-sync.php may only run on a node configured with role=primary.\n");
    exit(1);
}
if (!is_file($root . '/data/app.sqlite')) {
    fwrite(STDERR, "data/app.sqlite not found.\n");
    exit(1);
}

$recent = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--recent=')) {
        $recent = max(1, min(1000, (int)substr($arg, 9)));
    }
}

try {
    $peer = new PeerNode($root);
    $health = $peer->health();
    fwrite(STDOUT, 'peer=' . ($health['node'] ?? 'unknown') . ' role=' . ($health['role'] ?? 'unknown') . " online\n");

    $reconciler = new PeerReconciler($root);
    $report = static function (string $event, string $relative, ?string $error): void {
        $line = $event . ' ' . $relative;
        if ($error !== null) {
            $line .= ': ' . $error;
        }
        fwrite($event === 'FAIL' ? STDERR : STDOUT, $line . "\n");
    };

    $stats = $recent === null
        ? $reconciler->reconcileAll($report)
        : $reconciler->reconcileRecent($recent, $report);

    $removed = $peer->cleanupStaleTransfers((int)($config['stale_transfer_max_age_seconds'] ?? 86400));
    fwrite(STDOUT, sprintf(
        "done checked=%d unchanged=%d synced=%d deleted=%d failed=%d stale_transfers_removed=%d\n",
        $stats['checked'],
        $stats['unchanged'],
        $stats['synced'],
        $stats['deleted'],
        $stats['failed'],
        $removed
    ));
    exit($stats['failed'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'fatal: ' . $e->getMessage() . "\n");
    exit(1);
}
