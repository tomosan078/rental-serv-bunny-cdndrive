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

// External API deletes historically remove the files row immediately. Create a
// durable SQLite delete queue before the request reaches App so the DELETE
// trigger records the wp/... path even if the Replica is temporarily offline.
// A later re-upload of the same path is detected by PeerReconciler and cancels
// the stale queued delete rather than deleting the new Replica object.
if ($requestMethod === 'POST'
    && $requestPath === '/api/external/delete'
    && is_file($configFile)
    && is_file(__DIR__ . '/data/app.sqlite')) {
    $queueConfig = json_decode((string)file_get_contents($configFile), true);
    if (is_array($queueConfig) && ($queueConfig['role'] ?? '') === 'primary') {
        try {
            $queuePdo = new PDO('sqlite:' . __DIR__ . '/data/app.sqlite', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $queuePdo->exec("CREATE TABLE IF NOT EXISTS peer_delete_queue (
                relative_path TEXT PRIMARY KEY,
                queued_at TEXT NOT NULL
            )");
            $queuePdo->exec("CREATE TRIGGER IF NOT EXISTS peer_queue_wp_file_delete
                AFTER DELETE ON files
                WHEN OLD.relative_path LIKE 'wp/%'
                BEGIN
                    INSERT OR REPLACE INTO peer_delete_queue(relative_path, queued_at)
                    VALUES(OLD.relative_path, strftime('%Y-%m-%dT%H:%M:%fZ','now'));
                END");
        } catch (Throwable $e) {
            error_log('CDN Drive could not prepare peer delete queue: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Could not prepare peer deletion tracking.']);
            exit;
        }
    }
}

if ($requestMethod === 'POST') {
    $syncOnShutdown = in_array($requestPath, [
        '/api/upload',
        '/api/copy',
        '/api/delete',
        '/api/restore',
        '/api/maintenance/repair-paths',
        '/api/external/upload',
        '/api/external/delete',
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

            // App starts a file-backed PHP session for every request. Without
            // releasing it here, slow peer I/O in this shutdown callback keeps
            // the session file locked and later browser requests using the same
            // cookie can block until replication finishes. Close the session
            // before doing any peer work so the UI remains responsive.
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
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
