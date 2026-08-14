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

// API responses terminate through App::json(), which calls exit. Register the
// replication callback before App::run() so successful mutating requests still
// reconcile after the response has been produced. Scheduled peer-sync.php
// remains the authoritative repair/retry mechanism.
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$syncOnShutdown = false;

if ($requestMethod === 'POST') {
    $syncOnShutdown = in_array($requestPath, [
        '/api/upload',
        '/api/copy',
        '/api/delete',
        '/api/restore',
        '/api/maintenance/repair-paths',
        '/api/external/upload',
    ], true);

    // Chunked external uploads only change the origin/DB on the final chunk.
    if ($requestPath === '/api/external/upload-chunk') {
        $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
        $totalChunks = (int)($_POST['total_chunks'] ?? 0);
        $syncOnShutdown = $totalChunks > 0 && ($chunkIndex + 1) === $totalChunks;
    }
}

if ($syncOnShutdown && is_file($configFile)) {
    $peerConfig = json_decode((string)file_get_contents($configFile), true);
    if (is_array($peerConfig)
        && ($peerConfig['role'] ?? '') === 'primary'
        && !empty($peerConfig['sync_on_request'])) {
        register_shutdown_function(static function () use ($configFile): void {
            $status = http_response_code();
            if (is_int($status) && $status >= 400) {
                return;
            }

            // Send the application response before doing best-effort peer I/O.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            try {
                $dbFile = __DIR__ . '/data/app.sqlite';
                if (!is_file($configFile) || !is_file($dbFile)) {
                    return;
                }

                $config = json_decode((string)file_get_contents($configFile), true);
                if (!is_array($config)
                    || ($config['role'] ?? '') !== 'primary'
                    || empty($config['sync_on_request'])) {
                    return;
                }

                require_once __DIR__ . '/app/PeerNode.php';
                require_once __DIR__ . '/app/PeerReconciler.php';

                $limit = max(1, min(1000, (int)($config['sync_recent_limit'] ?? 100)));
                $reconciler = new CdnDrive\PeerReconciler(__DIR__);
                $stats = $reconciler->reconcileRecent($limit);
                if (($stats['failed'] ?? 0) > 0) {
                    error_log('CDN Drive peer replication completed with failures: ' . json_encode($stats['errors'] ?? []));
                }
            } catch (Throwable $e) {
                error_log('CDN Drive peer replication failed: ' . $e->getMessage());
            }
        });
    }
}

$app = new CdnDrive\App(__DIR__);
$app->run();
