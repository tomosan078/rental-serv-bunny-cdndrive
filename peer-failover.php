<?php
declare(strict_types=1);

use CdnDrive\PeerNode;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = __DIR__;
$configFile = $root . '/data/failover.json';
$peerConfigFile = $root . '/data/peer.json';
$stateFile = $root . '/data/failover-state.json';
$lockFile = $root . '/data/failover.lock';

if (!is_file($configFile)) {
    fwrite(STDERR, "Missing data/failover.json. Copy data/failover.example.json and configure it.\n");
    exit(1);
}
if (!is_file($peerConfigFile)) {
    fwrite(STDERR, "Missing data/peer.json.\n");
    exit(1);
}

$config = json_decode((string)file_get_contents($configFile), true);
$peerConfig = json_decode((string)file_get_contents($peerConfigFile), true);
if (!is_array($config) || !is_array($peerConfig)) {
    fwrite(STDERR, "Invalid failover or peer configuration JSON.\n");
    exit(1);
}
if (empty($config['enabled'])) {
    echo "[HOLD] Failover monitoring is disabled.\n";
    exit(0);
}

$lock = fopen($lockFile, 'c');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[HOLD] Another failover check is already running.\n";
    exit(0);
}

require_once $root . '/app/PeerNode.php';

$state = loadState($stateFile);
$role = (string)($peerConfig['role'] ?? '');
$failureThreshold = clampInt($config['failure_threshold'] ?? 3, 1, 20);
$recoveryThreshold = clampInt($config['recovery_threshold'] ?? 5, 1, 50);
$cooldown = clampInt($config['cooldown_seconds'] ?? 300, 30, 86400);
$now = time();

try {
    $peer = new PeerNode($root);
    $health = $peer->health();
    $peerOk = !empty($health['ok']);
} catch (Throwable $e) {
    $peerOk = false;
    $state['last_error'] = $e->getMessage();
}

if ($peerOk) {
    $state['consecutive_successes'] = ((int)($state['consecutive_successes'] ?? 0)) + 1;
    $state['consecutive_failures'] = 0;
    $state['last_peer_ok_at'] = gmdate(DATE_ATOM);
    $state['last_error'] = null;
    echo "[OK] Peer health check succeeded.\n";
} else {
    $state['consecutive_failures'] = ((int)($state['consecutive_failures'] ?? 0)) + 1;
    $state['consecutive_successes'] = 0;
    $state['last_peer_failure_at'] = gmdate(DATE_ATOM);
    echo "[WARN] Peer health check failed ({$state['consecutive_failures']}/{$failureThreshold}).\n";
}

// Primary only monitors the replica and records warnings. The replica is the
// only node allowed to change the Bunny Pull Zone origin.
if ($role !== 'replica') {
    saveState($stateFile, $state);
    exit(0);
}

$primaryOrigin = normalizeOrigin((string)($config['primary_origin'] ?? ''));
$secondaryOrigin = normalizeOrigin((string)($config['secondary_origin'] ?? ''));
$pullZoneId = trim((string)($config['pull_zone_id'] ?? ''));
$apiKey = trim((string)($config['bunny_api_key'] ?? ''));
$healthPath = '/' . ltrim((string)($config['secondary_health_path'] ?? '/origin-health.txt'), '/');

if ($primaryOrigin === '' || $secondaryOrigin === '' || !ctype_digit($pullZoneId) || $apiKey === '') {
    saveState($stateFile, $state);
    fwrite(STDERR, "Replica failover controller is missing Bunny/origin configuration.\n");
    exit(1);
}

$lastSwitch = (int)($state['last_switch_unix'] ?? 0);
$cooldownReady = ($now - $lastSwitch) >= $cooldown;

try {
    if (!$peerOk && (int)$state['consecutive_failures'] >= $failureThreshold) {
        if (!$cooldownReady) {
            echo "[HOLD] Failover threshold reached, but switch cooldown is active.\n";
        } else {
            $selfHealthUrl = $secondaryOrigin . $healthPath;
            if (!httpHealthy($selfHealthUrl)) {
                throw new RuntimeException('Secondary public origin health check failed; refusing failover.');
            }

            $current = normalizeOrigin((string)(bunnyRequest($apiKey, $pullZoneId, 'GET')['OriginUrl'] ?? ''));
            $state['last_bunny_origin'] = $current;

            if ($current === $secondaryOrigin) {
                echo "[HOLD] Bunny is already using the Secondary origin.\n";
            } elseif ($current !== $primaryOrigin) {
                throw new RuntimeException('Bunny OriginUrl is neither configured Primary nor Secondary; refusing to overwrite it.');
            } else {
                bunnyRequest($apiKey, $pullZoneId, 'POST', ['OriginUrl' => $secondaryOrigin]);
                markSwitch($state, $secondaryOrigin, $now, 'failover');
                echo "[SWITCH] Bunny origin changed to Secondary: {$secondaryOrigin}\n";
            }
        }
    } elseif ($peerOk && (int)$state['consecutive_successes'] >= $recoveryThreshold) {
        if (!$cooldownReady) {
            echo "[HOLD] Recovery threshold reached, but switch cooldown is active.\n";
        } else {
            if (!httpHealthy($primaryOrigin . $healthPath)) {
                throw new RuntimeException('Primary peer health recovered, but public origin health check still fails; refusing failback.');
            }

            $current = normalizeOrigin((string)(bunnyRequest($apiKey, $pullZoneId, 'GET')['OriginUrl'] ?? ''));
            $state['last_bunny_origin'] = $current;

            if ($current === $primaryOrigin) {
                echo "[HOLD] Bunny is already using the Primary origin.\n";
            } elseif ($current !== $secondaryOrigin) {
                throw new RuntimeException('Bunny OriginUrl is neither configured Primary nor Secondary; refusing to overwrite it.');
            } else {
                bunnyRequest($apiKey, $pullZoneId, 'POST', ['OriginUrl' => $primaryOrigin]);
                markSwitch($state, $primaryOrigin, $now, 'failback');
                echo "[SWITCH] Bunny origin changed back to Primary: {$primaryOrigin}\n";
            }
        }
    }
} catch (Throwable $e) {
    $state['last_error'] = $e->getMessage();
    echo '[WARN] ' . $e->getMessage() . "\n";
}

saveState($stateFile, $state);

function clampInt(mixed $value, int $min, int $max): int
{
    return max($min, min($max, (int)$value));
}

function normalizeOrigin(string $url): string
{
    $url = rtrim(trim($url), '/');
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
        return '';
    }
    return $url;
}

function loadState(string $file): array
{
    if (!is_file($file)) {
        return [
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'last_switch_unix' => 0,
        ];
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveState(string $file, array $state): void
{
    $state['updated_at'] = gmdate(DATE_ATOM);
    $tmp = $file . '.tmp';
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save failover state.');
    }
    @chmod($file, 0600);
}

function markSwitch(array &$state, string $origin, int $now, string $reason): void
{
    $state['last_switch_unix'] = $now;
    $state['last_switch_at'] = gmdate(DATE_ATOM, $now);
    $state['last_switch_reason'] = $reason;
    $state['last_bunny_origin'] = $origin;
    $state['last_error'] = null;
}

function httpHealthy(string $url): bool
{
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'CDN-Drive-Failover/1.0',
    ]);
    $result = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $result !== false && $status >= 200 && $status < 400;
}

function bunnyRequest(string $apiKey, string $pullZoneId, string $method, ?array $body = null): array
{
    $url = 'https://api.bunny.net/pullzone/' . rawurlencode($pullZoneId);
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize Bunny API request.');
    }

    $headers = [
        'AccessKey: ' . $apiKey,
        'Accept: application/json',
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($method === 'POST') {
        $payload = json_encode($body ?? [], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            curl_close($ch);
            throw new RuntimeException('Could not encode Bunny API request.');
        }
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $payload;
    }

    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Bunny API request failed (HTTP ' . $status . '): ' . ($error !== '' ? $error : (string)$raw));
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Bunny API returned invalid JSON.');
    }
    return $decoded;
}
