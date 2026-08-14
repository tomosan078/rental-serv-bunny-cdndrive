<?php
declare(strict_types=1);

// Replica nodes are origin-only peers. They must not expose the CDN Drive
// installer, login screen, admin UI, or application API. Static files under
// /origin and the dedicated /peer.php endpoint are served directly by the web
// server and therefore never reach this front controller.
$configFile = __DIR__ . '/data/peer.json';
if (is_file($configFile)) {
    $peerConfig = json_decode((string)file_get_contents($configFile), true);
    if (is_array($peerConfig) && ($peerConfig['role'] ?? '') === 'replica') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "Not Found\n";
        exit;
    }
}

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

// Best-effort near-real-time replication after mutating requests. The normal
// response is flushed first on FastCGI where possible, and failures here never
// change the user's successful application response. Scheduled peer-sync.php
// remains the authoritative repair/retry mechanism.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $dbFile = __DIR__ . '/data/app.sqlite';
    if (is_file($configFile) && is_file($dbFile)) {
        $peerConfig = json_decode((string)file_get_contents($configFile), true);
        if (is_array($peerConfig)
            && ($peerConfig['role'] ?? '') === 'primary'
            && !empty($peerConfig['sync_on_request'])) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                require_once __DIR__ . '/app/PeerNode.php';
                require_once __DIR__ . '/app/PeerReconciler.php';
                $limit = max(1, min(1000, (int)($peerConfig['sync_recent_limit'] ?? 100)));
                $reconciler = new CdnDrive\PeerReconciler(__DIR__);
                $stats = $reconciler->reconcileRecent($limit);
                if (($stats['failed'] ?? 0) > 0) {
                    error_log('CDN Drive peer replication completed with failures: ' . json_encode($stats['errors'] ?? []));
                }
            } catch (Throwable $e) {
                error_log('CDN Drive peer replication failed: ' . $e->getMessage());
            }
        }
    }
}
