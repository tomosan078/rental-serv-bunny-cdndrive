<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function prompt(string $label, ?string $default = null): string
{
    $suffix = $default !== null ? " [{$default}]" : '';
    fwrite(STDOUT, $label . $suffix . ': ');
    $value = trim((string)fgets(STDIN));
    return $value !== '' ? $value : (string)$default;
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

$root = __DIR__;
$dataDir = $root . '/data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
    fail('Could not create data directory.');
}

$role = strtolower(prompt('Role (primary/replica)', 'primary'));
if (!in_array($role, ['primary', 'replica'], true)) {
    fail('Role must be primary or replica.');
}

$nodeName = prompt('Node name', $role === 'primary' ? 'origin-a' : 'origin-b');
$peerUrl = rtrim(prompt('Peer base URL (https://...)'), '/');
if (!filter_var($peerUrl, FILTER_VALIDATE_URL) || !str_starts_with($peerUrl, 'https://')) {
    fail('Peer URL must be a valid HTTPS URL.');
}

$secret = prompt('Shared secret (leave blank to generate)');
if ($secret === '') {
    $secret = bin2hex(random_bytes(32));
    fwrite(STDOUT, "Generated shared secret:\n{$secret}\n\nCopy this exact secret to the other node.\n");
}
if (strlen($secret) < 32) {
    fail('Shared secret must be at least 32 characters.');
}

$config = [
    'node_name' => $nodeName,
    'role' => $role,
    'peer_url' => $peerUrl,
    'shared_secret' => $secret,
    'chunk_size_bytes' => 4194304,
    'connect_timeout_seconds' => 10,
    'request_timeout_seconds' => 120,
    'sync_on_request' => $role === 'primary',
    'sync_recent_limit' => 100,
    'stale_transfer_max_age_seconds' => 86400,
];

$file = $dataDir . '/peer.json';
if (is_file($file)) {
    $backup = $file . '.bak-' . gmdate('Ymd-His');
    if (!copy($file, $backup)) {
        fail('Could not back up existing peer.json.');
    }
    fwrite(STDOUT, "Existing config backed up to {$backup}\n");
}

$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (file_put_contents($file, $json, LOCK_EX) === false) {
    fail('Could not write data/peer.json.');
}
@chmod($file, 0600);

fwrite(STDOUT, "\nWrote data/peer.json for {$role} node '{$nodeName}'.\n");
fwrite(STDOUT, "Next: run php peer-check.php\n");
