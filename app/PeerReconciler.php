<?php
declare(strict_types=1);

namespace CdnDrive;

use PDO;
use RuntimeException;
use Throwable;

final class PeerReconciler
{
    private string $root;
    private PDO $pdo;
    private PeerNode $peer;

    public function __construct(string $root, ?PDO $pdo = null)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->pdo = $pdo ?? new PDO('sqlite:' . $this->root . '/data/app.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->peer = new PeerNode($this->root);
    }

    public function reconcileAll(?callable $report = null): array
    {
        $stmt = $this->pdo->query('SELECT id, uuid, relative_path, checksum, deleted_at FROM files ORDER BY id ASC');
        return $this->reconcileRows($stmt->fetchAll(), $report);
    }

    public function reconcileRecent(int $limit = 100, ?callable $report = null): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->query('SELECT id, uuid, relative_path, checksum, deleted_at FROM files ORDER BY updated_at DESC, id DESC LIMIT ' . $limit);
        return $this->reconcileRows($stmt->fetchAll(), $report);
    }

    private function reconcileRows(array $rows, ?callable $report): array
    {
        $stats = [
            'checked' => 0,
            'unchanged' => 0,
            'synced' => 0,
            'deleted' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($rows as $row) {
            ++$stats['checked'];
            $relative = (string)$row['relative_path'];
            try {
                if (!empty($row['deleted_at'])) {
                    $this->peer->deleteRemote($relative);
                    ++$stats['deleted'];
                    $this->emit($report, 'DELETE', $relative, null);
                    continue;
                }

                $local = $this->root . '/origin/' . ltrim($relative, '/');
                if (!is_file($local)) {
                    throw new RuntimeException('Local origin file is missing.');
                }
                $expected = (string)$row['checksum'];
                if ($expected === '') {
                    $expected = hash_file('sha256', $local) ?: '';
                }

                $verify = $this->peer->verifyRemote($relative, $expected);
                if (!empty($verify['matches'])) {
                    ++$stats['unchanged'];
                    continue;
                }

                $this->peer->pushFile($relative);
                ++$stats['synced'];
                $this->emit($report, 'SYNC', $relative, null);
            } catch (Throwable $e) {
                ++$stats['failed'];
                $stats['errors'][] = ['relative_path' => $relative, 'error' => $e->getMessage()];
                $this->emit($report, 'FAIL', $relative, $e->getMessage());
            }
        }

        return $stats;
    }

    private function emit(?callable $report, string $event, string $relative, ?string $error): void
    {
        if ($report !== null) {
            $report($event, $relative, $error);
        }
    }
}
