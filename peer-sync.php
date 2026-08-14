<?php
declare(strict_types=1);

require __DIR__ . '/app/PeerNode.php';

use CdnDrive\PeerNode;
use PDO;
use RuntimeException;

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

$dbFile = $root . '/data/app.sqlite';
if (!is_file($dbFile)) {
    fwrite(STDERR, "data/app.sqlite not found.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$peer = new PeerNode($root);

$stmt = $pdo->query('SELECT uuid, relative_path, checksum, deleted_at FROM files ORDER BY id ASC');
$ok = 0;
$changed = 0;
$failed = 0;
foreach ($stmt as $row) {
    $relative = (string)$row['relative_path'];
    try {
        if (!empty($row['deleted_at'])) {
            $peer->deleteRemote($relative);
            ++$ok;
            continue;
        }
        $verify = $peer->verifyRemote($relative, (string)$row['checksum']);
        if (!empty($verify['matches'])) {
            ++$ok;
            continue;
        }
        $peer->pushFile($relative);
        ++$changed;
        fwrite(STDOUT, "SYNC {$relative}\n");
    } catch (Throwable $e) {
        ++$failed;
        fwrite(STDERR, "FAIL {$relative}: {$e->getMessage()}\n");
    }
}

fwrite(STDOUT, "done unchanged={$ok} synced={$changed} failed={$failed}\n");
exit($failed > 0 ? 2 : 0);
