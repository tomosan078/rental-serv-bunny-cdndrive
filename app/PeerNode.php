<?php
declare(strict_types=1);

namespace CdnDrive;

use RuntimeException;

final class PeerNode
{
    private string $root;
    private string $originDir;
    private string $configFile;
    private string $transferDir;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->originDir = $this->root . '/origin';
        $this->configFile = $this->root . '/data/peer.json';
        $this->transferDir = $this->root . '/data/peer-transfers';
    }

    public function handleHttp(): void
    {
        try {
            $config = $this->config();
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $action = (string)($_GET['action'] ?? 'health');
            $raw = file_get_contents('php://input') ?: '';
            $this->requirePeerAuth($config, $action, $raw);

            if ($method === 'GET' && $action === 'health') {
                $this->json([
                    'ok' => true,
                    'node' => $config['node_name'] ?? 'peer',
                    'role' => $config['role'] ?? 'unknown',
                    'time' => gmdate('c'),
                ]);
                return;
            }
            if ($method === 'POST' && $action === 'upload-init') {
                $this->handleUploadInit($raw);
                return;
            }
            if ($method === 'POST' && $action === 'upload-chunk') {
                $this->handleUploadChunk($raw);
                return;
            }
            if ($method === 'POST' && $action === 'upload-commit') {
                $this->handleUploadCommit($raw);
                return;
            }
            if ($method === 'POST' && $action === 'delete') {
                $this->handleDelete($raw);
                return;
            }
            if ($method === 'POST' && $action === 'restore') {
                $this->handleRestore($raw);
                return;
            }
            if ($method === 'POST' && $action === 'verify') {
                $this->handleVerify($raw);
                return;
            }

            $this->json(['ok' => false, 'error' => 'Peer action not found.'], 404);
        } catch (\Throwable $e) {
            error_log((string)$e);
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function health(): array
    {
        return $this->request($this->config(), 'health', '', false);
    }

    public function pushFile(string $relativePath): array
    {
        $config = $this->config();
        $relative = ltrim($relativePath, '/');
        $source = $this->originDir . '/' . $relative;
        if (!is_file($source)) {
            throw new RuntimeException('Local source file does not exist.');
        }

        $size = filesize($source);
        if ($size === false) {
            throw new RuntimeException('Could not determine local file size.');
        }
        $checksum = hash_file('sha256', $source) ?: '';
        $init = $this->requestJson($config, 'upload-init', [
            'relative_path' => $relative,
            'size' => $size,
            'checksum' => $checksum,
        ]);
        $transferId = (string)($init['transfer_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $transferId)) {
            throw new RuntimeException('Peer returned an invalid transfer id.');
        }

        $chunkSize = max(1024 * 1024, min(32 * 1024 * 1024, (int)($config['chunk_size_bytes'] ?? 8 * 1024 * 1024)));
        $fp = fopen($source, 'rb');
        if ($fp === false) {
            throw new RuntimeException('Could not open local source file.');
        }

        try {
            $offset = 0;
            while (!feof($fp)) {
                $chunk = fread($fp, $chunkSize);
                if ($chunk === false) {
                    throw new RuntimeException('Could not read local source file.');
                }
                if ($chunk === '') {
                    break;
                }
                $this->request($config, 'upload-chunk', $chunk, true, [
                    'X-Peer-Transfer-Id: ' . $transferId,
                    'X-Peer-Offset: ' . $offset,
                ]);
                $offset += strlen($chunk);
            }
        } finally {
            fclose($fp);
        }

        return $this->requestJson($config, 'upload-commit', ['transfer_id' => $transferId]);
    }

    public function deleteRemote(string $relativePath): array
    {
        return $this->requestJson($this->config(), 'delete', ['relative_path' => ltrim($relativePath, '/')]);
    }

    public function restoreRemote(string $relativePath): array
    {
        return $this->requestJson($this->config(), 'restore', ['relative_path' => ltrim($relativePath, '/')]);
    }

    public function verifyRemote(string $relativePath, string $checksum): array
    {
        return $this->requestJson($this->config(), 'verify', [
            'relative_path' => ltrim($relativePath, '/'),
            'checksum' => $checksum,
        ]);
    }

    public function cleanupStaleTransfers(int $maxAgeSeconds = 86400): int
    {
        if (!is_dir($this->transferDir)) {
            return 0;
        }
        $removed = 0;
        $cutoff = time() - max(3600, $maxAgeSeconds);
        foreach (glob($this->transferDir . '/*.{json,part}', GLOB_BRACE) ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
                ++$removed;
            }
        }
        return $removed;
    }

    private function handleUploadInit(string $raw): void
    {
        $data = $this->decodeJson($raw);
        $relative = $this->safeRelative((string)($data['relative_path'] ?? ''));
        $size = filter_var($data['size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $checksum = strtolower((string)($data['checksum'] ?? ''));
        if ($size === false || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new RuntimeException('Invalid transfer metadata.');
        }
        if (!is_dir($this->transferDir) && !mkdir($this->transferDir, 0755, true) && !is_dir($this->transferDir)) {
            throw new RuntimeException('Could not create peer transfer directory.');
        }

        $id = bin2hex(random_bytes(16));
        $meta = [
            'relative_path' => $relative,
            'size' => (int)$size,
            'checksum' => $checksum,
            'created_at' => time(),
        ];
        if (file_put_contents($this->transferDir . '/' . $id . '.json', json_encode($meta, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('Could not create transfer metadata.');
        }
        if (file_put_contents($this->transferDir . '/' . $id . '.part', '') === false) {
            @unlink($this->transferDir . '/' . $id . '.json');
            throw new RuntimeException('Could not create transfer file.');
        }
        $this->json(['ok' => true, 'transfer_id' => $id]);
    }

    private function handleUploadChunk(string $raw): void
    {
        $id = (string)($_SERVER['HTTP_X_PEER_TRANSFER_ID'] ?? '');
        $offsetRaw = (string)($_SERVER['HTTP_X_PEER_OFFSET'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !ctype_digit($offsetRaw)) {
            throw new RuntimeException('Invalid chunk headers.');
        }
        $meta = $this->transferMeta($id);
        $part = $this->transferDir . '/' . $id . '.part';
        $current = is_file($part) ? filesize($part) : false;
        if ($current === false || (int)$current !== (int)$offsetRaw) {
            throw new RuntimeException('Chunk offset mismatch.');
        }
        if ($current + strlen($raw) > (int)$meta['size']) {
            throw new RuntimeException('Chunk exceeds declared file size.');
        }
        $fp = fopen($part, 'ab');
        if ($fp === false) {
            throw new RuntimeException('Could not open transfer file.');
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('Could not lock transfer file.');
            }
            $written = fwrite($fp, $raw);
            fflush($fp);
            flock($fp, LOCK_UN);
            if ($written === false || $written !== strlen($raw)) {
                throw new RuntimeException('Could not write complete chunk.');
            }
        } finally {
            fclose($fp);
        }
        $this->json(['ok' => true, 'received' => strlen($raw), 'next_offset' => $current + strlen($raw)]);
    }

    private function handleUploadCommit(string $raw): void
    {
        $data = $this->decodeJson($raw);
        $id = (string)($data['transfer_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new RuntimeException('Invalid transfer id.');
        }
        $meta = $this->transferMeta($id);
        $part = $this->transferDir . '/' . $id . '.part';
        $size = is_file($part) ? filesize($part) : false;
        if ($size === false || (int)$size !== (int)$meta['size']) {
            throw new RuntimeException('Transferred size does not match declared size.');
        }
        $actual = hash_file('sha256', $part) ?: '';
        if (!hash_equals((string)$meta['checksum'], $actual)) {
            throw new RuntimeException('Checksum mismatch after replication.');
        }

        $target = $this->originDir . '/' . $this->safeRelative((string)$meta['relative_path']);
        $this->ensureParent($target);
        if (is_file($target) && !@unlink($target)) {
            throw new RuntimeException('Could not replace existing replicated file.');
        }
        if (!@rename($part, $target)) {
            if (!copy($part, $target) || !unlink($part)) {
                throw new RuntimeException('Could not commit replicated file.');
            }
        }
        @chmod($target, 0644);
        @unlink($this->transferDir . '/' . $id . '.json');
        $this->json(['ok' => true, 'relative_path' => $meta['relative_path'], 'checksum' => $actual, 'size' => $size]);
    }

    private function handleDelete(string $raw): void
    {
        $data = $this->decodeJson($raw);
        $relative = $this->safeRelative((string)($data['relative_path'] ?? ''));
        $source = $this->originDir . '/' . $relative;
        $trash = $this->root . '/data/trash-peer/' . $relative;
        if (is_file($source)) {
            $this->ensureParent($trash);
            if (is_file($trash)) {
                @unlink($trash);
            }
            if (!@rename($source, $trash)) {
                if (!copy($source, $trash) || !unlink($source)) {
                    throw new RuntimeException('Could not move replicated file to trash.');
                }
            }
        }
        $this->json(['ok' => true]);
    }

    private function handleRestore(string $raw): void
    {
        $data = $this->decodeJson($raw);
        $relative = $this->safeRelative((string)($data['relative_path'] ?? ''));
        $trash = $this->root . '/data/trash-peer/' . $relative;
        $target = $this->originDir . '/' . $relative;
        if (!is_file($trash)) {
            throw new RuntimeException('Replicated trash file does not exist.');
        }
        $this->ensureParent($target);
        if (!@rename($trash, $target)) {
            if (!copy($trash, $target) || !unlink($trash)) {
                throw new RuntimeException('Could not restore replicated file.');
            }
        }
        $this->json(['ok' => true]);
    }

    private function handleVerify(string $raw): void
    {
        $data = $this->decodeJson($raw);
        $relative = $this->safeRelative((string)($data['relative_path'] ?? ''));
        $target = $this->originDir . '/' . $relative;
        $exists = is_file($target);
        $checksum = $exists ? (hash_file('sha256', $target) ?: '') : '';
        $expected = strtolower((string)($data['checksum'] ?? ''));
        $this->json([
            'ok' => true,
            'exists' => $exists,
            'checksum' => $checksum,
            'matches' => $exists && ($expected === '' || hash_equals($expected, $checksum)),
        ]);
    }

    private function requestJson(array $config, string $action, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Could not encode peer payload.');
        }
        return $this->request($config, $action, $body, true);
    }

    private function request(array $config, string $action, string $body, bool $post, array $extraHeaders = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for peer replication.');
        }
        $base = rtrim((string)($config['peer_url'] ?? ''), '/');
        $url = $base . '/peer.php?action=' . rawurlencode($action);
        if ($base === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Peer URL is not configured.');
        }
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . "\n" . $action . "\n" . $body, (string)$config['shared_secret']);
        $headers = array_merge([
            'X-Peer-Timestamp: ' . $timestamp,
            'X-Peer-Signature: ' . $signature,
        ], $extraHeaders);
        if ($post) {
            $headers[] = $action === 'upload-chunk' ? 'Content-Type: application/octet-stream' : 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, (int)($config['connect_timeout_seconds'] ?? 10)),
            CURLOPT_TIMEOUT => max(10, (int)($config['request_timeout_seconds'] ?? 120)),
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($post) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Peer request failed: ' . $error);
        }
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['ok'])) {
            throw new RuntimeException('Peer returned an error: HTTP ' . $status . ' ' . $raw);
        }
        return $decoded;
    }

    private function requirePeerAuth(array $config, string $action, string $body): void
    {
        $secret = (string)($config['shared_secret'] ?? '');
        if (strlen($secret) < 32) {
            throw new RuntimeException('Peer shared secret must be at least 32 characters.');
        }
        $timestamp = (string)($_SERVER['HTTP_X_PEER_TIMESTAMP'] ?? '');
        $signature = (string)($_SERVER['HTTP_X_PEER_SIGNATURE'] ?? '');
        if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) {
            throw new RuntimeException('Invalid peer timestamp.');
        }
        $expected = hash_hmac('sha256', $timestamp . "\n" . $action . "\n" . $body, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid peer signature.');
        }
    }

    private function config(): array
    {
        if (!is_file($this->configFile)) {
            throw new RuntimeException('data/peer.json does not exist.');
        }
        $config = json_decode((string)file_get_contents($this->configFile), true);
        if (!is_array($config) || strlen((string)($config['shared_secret'] ?? '')) < 32) {
            throw new RuntimeException('Invalid peer configuration.');
        }
        return $config;
    }

    private function decodeJson(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON payload.');
        }
        return $data;
    }

    private function transferMeta(string $id): array
    {
        $file = $this->transferDir . '/' . $id . '.json';
        if (!is_file($file)) {
            throw new RuntimeException('Transfer does not exist or has expired.');
        }
        $meta = json_decode((string)file_get_contents($file), true);
        if (!is_array($meta)) {
            throw new RuntimeException('Transfer metadata is invalid.');
        }
        return $meta;
    }

    private function safeRelative(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '../') || str_contains($path, '/..') || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid relative path.');
        }
        if (!preg_match('#^objects/[A-Za-z0-9._/-]+$#', $path)) {
            throw new RuntimeException('Path is outside the replicated object namespace.');
        }
        return $path;
    }

    private function ensureParent(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create directory.');
        }
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
