<?php
declare(strict_types=1);

namespace CdnDrive;

use RuntimeException;

final class PeerNode
{
    private string $root;
    private string $originDir;
    private string $configFile;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->originDir = $this->root . '/origin';
        $this->configFile = $this->root . '/data/peer.json';
    }

    public function handleHttp(): void
    {
        try {
            $config = $this->config();
            $this->requirePeerAuth($config);
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $action = (string)($_GET['action'] ?? 'health');

            if ($method === 'GET' && $action === 'health') {
                $this->json(['ok' => true, 'node' => $config['node_name'] ?? 'peer', 'time' => gmdate('c')]);
                return;
            }
            if ($method === 'POST' && $action === 'put') {
                $this->handlePut();
                return;
            }
            if ($method === 'POST' && $action === 'delete') {
                $this->handleDelete();
                return;
            }
            if ($method === 'POST' && $action === 'restore') {
                $this->handleRestore();
                return;
            }
            if ($method === 'POST' && $action === 'verify') {
                $this->handleVerify();
                return;
            }

            $this->json(['ok' => false, 'error' => 'Peer action not found.'], 404);
        } catch (\Throwable $e) {
            error_log((string)$e);
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function pushFile(string $relativePath): array
    {
        $config = $this->config();
        $source = $this->originDir . '/' . ltrim($relativePath, '/');
        if (!is_file($source)) {
            throw new RuntimeException('Local source file does not exist.');
        }
        return $this->request($config, 'put', [
            'relative_path' => ltrim($relativePath, '/'),
            'checksum' => hash_file('sha256', $source) ?: '',
            'contents_b64' => base64_encode((string)file_get_contents($source)),
        ]);
    }

    public function deleteRemote(string $relativePath): array
    {
        return $this->request($this->config(), 'delete', ['relative_path' => ltrim($relativePath, '/')]);
    }

    public function restoreRemote(string $relativePath): array
    {
        return $this->request($this->config(), 'restore', ['relative_path' => ltrim($relativePath, '/')]);
    }

    public function verifyRemote(string $relativePath, string $checksum): array
    {
        return $this->request($this->config(), 'verify', [
            'relative_path' => ltrim($relativePath, '/'),
            'checksum' => $checksum,
        ]);
    }

    private function handlePut(): void
    {
        $data = $this->input();
        $relative = $this->safeRelative((string)($data['relative_path'] ?? ''));
        $contents = base64_decode((string)($data['contents_b64'] ?? ''), true);
        if ($contents === false) {
            throw new RuntimeException('Invalid file payload.');
        }
        $target = $this->originDir . '/' . $relative;
        $this->ensureParent($target);
        if (file_put_contents($target, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Could not write replicated file.');
        }
        @chmod($target, 0644);
        $actual = hash_file('sha256', $target) ?: '';
        $expected = (string)($data['checksum'] ?? '');
        if ($expected !== '' && !hash_equals($expected, $actual)) {
            @unlink($target);
            throw new RuntimeException('Checksum mismatch after replication.');
        }
        $this->json(['ok' => true, 'relative_path' => $relative, 'checksum' => $actual]);
    }

    private function handleDelete(): void
    {
        $data = $this->input();
        $relative = $this->safeRelative((string)($data['relative_path'] ?? ''));
        $source = $this->originDir . '/' . $relative;
        $trash = $this->root . '/data/trash-peer/' . $relative;
        if (is_file($source)) {
            $this->ensureParent($trash);
            if (!@rename($source, $trash)) {
                if (!copy($source, $trash) || !unlink($source)) {
                    throw new RuntimeException('Could not move replicated file to trash.');
                }
            }
        }
        $this->json(['ok' => true]);
    }

    private function handleRestore(): void
    {
        $data = $this->input();
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

    private function handleVerify(): void
    {
        $data = $this->input();
        $relative = $this->safeRelative((string)($data['relative_path'] ?? ''));
        $target = $this->originDir . '/' . $relative;
        $exists = is_file($target);
        $checksum = $exists ? (hash_file('sha256', $target) ?: '') : '';
        $expected = (string)($data['checksum'] ?? '');
        $this->json([
            'ok' => true,
            'exists' => $exists,
            'checksum' => $checksum,
            'matches' => $exists && ($expected === '' || hash_equals($expected, $checksum)),
        ]);
    }

    private function request(array $config, string $action, array $payload): array
    {
        $url = rtrim((string)($config['peer_url'] ?? ''), '/') . '/peer.php?action=' . rawurlencode($action);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Peer URL is not configured.');
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Could not encode peer payload.');
        }
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . '\n' . $body, (string)$config['shared_secret']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Peer-Timestamp: ' . $timestamp,
                'X-Peer-Signature: ' . $signature,
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            throw new RuntimeException('Peer request failed: ' . curl_error($ch));
        }
        curl_close($ch);
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['ok'])) {
            throw new RuntimeException('Peer returned an error: ' . $raw);
        }
        return $decoded;
    }

    private function requirePeerAuth(array $config): void
    {
        $secret = (string)($config['shared_secret'] ?? '');
        if ($secret === '') {
            throw new RuntimeException('Peer shared secret is not configured.');
        }
        $timestamp = (string)($_SERVER['HTTP_X_PEER_TIMESTAMP'] ?? '');
        $signature = (string)($_SERVER['HTTP_X_PEER_SIGNATURE'] ?? '');
        if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) {
            throw new RuntimeException('Invalid peer timestamp.');
        }
        $body = file_get_contents('php://input') ?: '';
        $expected = hash_hmac('sha256', $timestamp . '\n' . $body, $secret);
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
        if (!is_array($config) || empty($config['shared_secret'])) {
            throw new RuntimeException('Invalid peer configuration.');
        }
        return $config;
    }

    private function input(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON payload.');
        }
        return $data;
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
