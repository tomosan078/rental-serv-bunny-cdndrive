<?php
declare(strict_types=1);

namespace CdnDrive;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class App
{
    private string $root;
    private string $dataDir;
    private string $originDir;
    private string $trashDir;
    private string $sessionDir;
    private string $dbFile;
    private ?PDO $pdo = null;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->dataDir = $this->root . '/data';
        $this->originDir = $this->root . '/origin';
        $this->trashDir = $this->dataDir . '/trash';
        $this->sessionDir = $this->dataDir . '/sessions';
        $this->dbFile = $this->dataDir . '/app.sqlite';
    }

    public function run(): void
    {
        $this->sendSecurityHeaders();
        $this->prepareRuntime();
        $this->startSession();

        $path = $this->path();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            if ($path === '/install') {
                $method === 'POST' ? $this->handleInstallPost() : $this->renderInstall();
                return;
            }

            if (!$this->isInstalled()) {
                $this->redirect($this->url('/install'));
                return;
            }

            $this->openDb();
            $this->touchSession();

            if ($path === '/login') {
                $method === 'POST' ? $this->handleLoginPost() : $this->renderLogin();
                return;
            }
            if ($path === '/logout') {
                $this->logout();
                return;
            }
            if (str_starts_with($path, '/share/')) {
                $this->renderShare(substr($path, 7));
                return;
            }
            if (str_starts_with($path, '/api/external/')) {
                $this->rateLimit('external:' . $this->ip(), 600, 60);
                $this->routeExternalApi($path, $method);
                return;
            }
            if (str_starts_with($path, '/api/')) {
                $this->requireAuth();
                $this->rateLimit('api:' . $this->ip(), 240, 60);
                if ($method !== 'GET') {
                    $this->checkCsrf();
                }
                $this->routeApi($path, $method);
                return;
            }

            $this->requireAuth(false);
            $this->renderApp();
        } catch (Throwable $e) {
            error_log((string)$e);
            if (str_starts_with($path, '/api/')) {
                $this->json(['ok' => false, 'error' => $this->publicError($e)], 500);
                return;
            }
            http_response_code(500);
            $message = $e instanceof RuntimeException ? $this->publicError($e) : '処理中にエラーが発生しました。設定と権限を確認してください。';
            echo $this->page('エラー', '<div class="max-w-xl mx-auto mt-16 rounded-xl border border-red-200 bg-red-50 p-6 text-red-900">' . $this->e($message) . '</div>');
        }
    }

    private function routeApi(string $path, string $method): void
    {
        if ($path === '/api/me' && $method === 'GET') {
            $this->apiMe();
        } elseif ($path === '/api/items' && $method === 'GET') {
            $this->apiItems();
        } elseif ($path === '/api/folders' && $method === 'POST') {
            $this->apiCreateFolder();
        } elseif ($path === '/api/upload' && $method === 'POST') {
            $this->apiUpload();
        } elseif ($path === '/api/rename' && $method === 'POST') {
            $this->apiRename();
        } elseif ($path === '/api/move' && $method === 'POST') {
            $this->apiMove();
        } elseif ($path === '/api/copy' && $method === 'POST') {
            $this->apiCopy();
        } elseif ($path === '/api/delete' && $method === 'POST') {
            $this->apiDelete();
        } elseif ($path === '/api/restore' && $method === 'POST') {
            $this->apiRestore();
        } elseif ($path === '/api/share' && $method === 'POST') {
            $this->apiShare();
        } elseif ($path === '/api/settings' && $method === 'POST') {
            $this->requireAdmin();
            $this->apiSaveSettings();
        } elseif ($path === '/api/settings/wordpress-token' && $method === 'POST') {
            $this->requireAdmin();
            $this->apiGenerateWordPressToken();
        } elseif ($path === '/api/bunny/test' && $method === 'POST') {
            $this->requireAdmin();
            $this->apiBunnyTest();
        } elseif ($path === '/api/bunny/purge' && $method === 'POST') {
            $this->requireAdmin();
            $this->apiBunnyPurge();
        } elseif ($path === '/api/maintenance/repair-paths' && $method === 'POST') {
            $this->requireAdmin();
            $this->apiRepairPaths();
        } elseif ($path === '/api/users' && $method === 'GET') {
            $this->requireAdmin();
            $this->apiUsers();
        } elseif ($path === '/api/users' && $method === 'POST') {
            $this->requireAdmin();
            $this->apiSaveUser();
        } elseif ($path === '/api/logs' && $method === 'GET') {
            $this->requireAdmin();
            $this->apiLogs();
        } else {
            $this->json(['ok' => false, 'error' => '指定された API は存在しません。'], 404);
        }
    }

    private function routeExternalApi(string $path, string $method): void
    {
        $this->requireExternalToken();
        if ($path === '/api/external/ping' && $method === 'GET') {
            $this->apiExternalPing();
        } elseif ($path === '/api/external/upload' && $method === 'POST') {
            $this->apiExternalUpload();
        } elseif ($path === '/api/external/upload-chunk' && $method === 'POST') {
            $this->apiExternalUploadChunk();
        } elseif ($path === '/api/external/delete' && $method === 'POST') {
            $this->apiExternalDelete();
        } elseif ($path === '/api/external/purge' && $method === 'POST') {
            $this->apiExternalPurge();
        } else {
            $this->json(['ok' => false, 'error' => 'External API not found.'], 404);
        }
    }

    private function prepareRuntime(): void
    {
        foreach ([$this->dataDir, $this->originDir, $this->trashDir, $this->sessionDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
        if (is_dir($this->dataDir) && !is_file($this->dataDir . '/.htaccess')) {
            @file_put_contents($this->dataDir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (is_dir($this->originDir) && !is_file($this->originDir . '/.htaccess')) {
            @file_put_contents($this->originDir . '/.htaccess', "Options -Indexes\n");
        }
    }

    private function startSession(): void
    {
        if (is_dir($this->sessionDir) && is_writable($this->sessionDir)) {
            session_save_path($this->sessionDir);
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->basePath() ?: '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('cdn_drive_session');
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    private function openDb(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0755, true) && !is_dir($this->dataDir)) {
            throw new RuntimeException('SQLite database directory is missing.');
        }
        $pdo = new PDO('sqlite:' . $this->dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo = $pdo;
        return $pdo;
    }

    private function createSchema(): void
    {
        $pdo = $this->openDb();
        $pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('admin','user')),
    active INTEGER NOT NULL DEFAULT 1,
    storage_limit_bytes INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_login_at TEXT
);
CREATE TABLE IF NOT EXISTS folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    owner_id INTEGER NOT NULL,
    parent_id INTEGER,
    name TEXT NOT NULL,
    deleted_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(parent_id) REFERENCES folders(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    owner_id INTEGER NOT NULL,
    folder_id INTEGER,
    name TEXT NOT NULL,
    relative_path TEXT NOT NULL UNIQUE,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL,
    checksum TEXT NOT NULL,
    deleted_at TEXT,
    original_folder_id INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL UNIQUE,
    owner_id INTEGER NOT NULL,
    file_id INTEGER NOT NULL,
    permission TEXT NOT NULL CHECK(permission IN ('view','download')),
    password_hash TEXT,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(file_id) REFERENCES files(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT,
    ip TEXT NOT NULL,
    user_agent TEXT NOT NULL,
    details TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER,
    ip TEXT NOT NULL,
    user_agent TEXT NOT NULL,
    csrf_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS rate_limits (
    key TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL,
    reset_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_files_owner_folder ON files(owner_id, folder_id, deleted_at);
CREATE INDEX IF NOT EXISTS idx_folders_owner_parent ON folders(owner_id, parent_id, deleted_at);
CREATE INDEX IF NOT EXISTS idx_logs_created ON logs(created_at);
");
    }

    private function isInstalled(): bool
    {
        if (!is_file($this->dbFile)) {
            return false;
        }
        try {
            $this->openDb();
            $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE key = 'installed' LIMIT 1");
            $stmt->execute();
            return $stmt->fetchColumn() === '1';
        } catch (Throwable) {
            return false;
        }
    }

    private function renderInstall(): void
    {
        if ($this->isInstalled()) {
            $this->redirect($this->url('/login'));
            return;
        }
        $origin = $this->baseUrl() . '/origin';
        $checks = $this->installChecks();
        $rows = '';
        foreach ($checks as $label => $ok) {
            $rows .= '<div class="flex items-center justify-between border-b border-white/10 py-2"><span>' . $this->e($label) . '</span><span class="' . ($ok ? 'text-emerald-400' : 'text-red-400') . '">' . ($ok ? 'OK' : 'NG') . '</span></div>';
        }
        $body = '
<main class="min-h-screen bg-slate-950 text-slate-100">
  <section class="mx-auto flex min-h-screen max-w-6xl items-center px-5 py-10">
    <div class="grid w-full gap-8 lg:grid-cols-[1fr_440px]">
      <div class="flex flex-col justify-center">
        <p class="text-sm uppercase tracking-[0.25em] text-cyan-300">CORESERVER V2 + BunnyCDN</p>
        <h1 class="mt-5 text-4xl font-semibold tracking-normal text-white sm:text-5xl">CDN Drive</h1>
        <p class="mt-5 max-w-2xl text-lg leading-8 text-slate-300">CORESERVER V2 をオリジンとして使い、BunnyCDN Pull Zone で配信するファイル管理サービスを初期化します。</p>
        <div class="mt-8 rounded-2xl border border-white/10 bg-white/5 p-5 shadow-2xl shadow-cyan-950/30 backdrop-blur">' . $rows . '</div>
      </div>
      <form method="post" class="rounded-2xl border border-white/10 bg-white/10 p-6 shadow-2xl shadow-cyan-950/30 backdrop-blur">
        ' . $this->csrfField() . '
        <h2 class="text-xl font-semibold text-white">初期設定</h2>
        <label class="mt-5 block text-sm text-slate-300">管理者名<input required name="name" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-white outline-none focus:border-cyan-300" autocomplete="name"></label>
        <label class="mt-4 block text-sm text-slate-300">メールアドレス<input required type="email" name="email" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-white outline-none focus:border-cyan-300" autocomplete="email"></label>
        <label class="mt-4 block text-sm text-slate-300">パスワード<input required type="password" minlength="12" name="password" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-white outline-none focus:border-cyan-300" autocomplete="new-password"></label>
        <label class="mt-4 block text-sm text-slate-300">CDN Hostname<input required name="cdn_hostname" placeholder="cdn.example.com" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-white outline-none focus:border-cyan-300"><span class="mt-1 block text-xs text-slate-500">https:// やパスは入れず、BunnyCDN の配信用ホスト名だけを入力します。</span></label>
        <label class="mt-4 block text-sm text-slate-300">Origin URL<input required name="origin_url" value="' . $this->e($origin) . '" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-white outline-none focus:border-cyan-300"><span class="mt-1 block text-xs text-slate-500">CORESERVER 上の origin ディレクトリ URL を入力します。</span></label>
        <label class="mt-4 block text-sm text-slate-300">Bunny API Key<input name="bunny_api_key" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-white outline-none focus:border-cyan-300" autocomplete="off"></label>
        <label class="mt-4 block text-sm text-slate-300">Pull Zone ID<input name="pull_zone_id" inputmode="numeric" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-white outline-none focus:border-cyan-300"></label>
        <button class="mt-6 w-full rounded-lg bg-cyan-400 px-4 py-3 font-semibold text-slate-950 hover:bg-cyan-300">インストール</button>
      </form>
    </div>
  </section>
</main>';
        echo $this->page('Install', $body);
    }

    private function handleInstallPost(): void
    {
        if ($this->isInstalled()) {
            $this->redirect($this->url('/login'));
            return;
        }
        $this->checkCsrf();
        $this->ensureWritable();
        $checks = $this->installChecks();
        foreach (['PHP 8.2 以上', 'PDO SQLite', 'JSON', 'data 書き込み', 'origin 書き込み'] as $required) {
            if (empty($checks[$required])) {
                throw new RuntimeException('必要な PHP 拡張または書き込み権限が不足しています。/install のチェック結果を確認してください。');
            }
        }
        $this->createSchema();

        $name = $this->cleanText($_POST['name'] ?? '', 80);
        $email = strtolower($this->cleanText($_POST['email'] ?? '', 190));
        $password = (string)($_POST['password'] ?? '');
        $cdn = $this->normalizeHost((string)($_POST['cdn_hostname'] ?? ''));
        $origin = $this->normalizeUrl((string)($_POST['origin_url'] ?? ''));
        $apiKey = trim((string)($_POST['bunny_api_key'] ?? ''));
        $pullZoneId = trim((string)($_POST['pull_zone_id'] ?? ''));

        $errors = [];
        if ($name === '') {
            $errors[] = '管理者名を入力してください。';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'メールアドレスの形式が正しくありません。';
        }
        if (strlen($password) < 12) {
            $errors[] = 'パスワードは12文字以上にしてください。';
        }
        if ($cdn === '') {
            $errors[] = 'CDN Hostname は cdn.example.com のようにホスト名だけを入力してください。';
        }
        if ($origin === '') {
            $errors[] = 'Origin URL は https://example.com/origin のような http または https の URL を入力してください。';
        }
        if ($errors) {
            throw new RuntimeException(implode(' ', $errors));
        }
        if ($pullZoneId !== '' && !ctype_digit($pullZoneId)) {
            throw new RuntimeException('Pull Zone ID は数字で入力してください。');
        }

        $this->pdo->beginTransaction();
        try {
            $now = $this->now();
            $stmt = $this->pdo->prepare('INSERT INTO users(email, name, password_hash, role, active, storage_limit_bytes, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT), 'admin', 1, null, $now, $now]);
            $adminId = (int)$this->pdo->lastInsertId();

            $this->setSetting('cdn_hostname', $cdn);
            $this->setSetting('origin_url', $origin);
            $this->setSetting('bunny_api_key', $apiKey);
        $this->setSetting('pull_zone_id', $pullZoneId);
        $this->setSetting('app_name', 'CDN Drive');
        $this->setSetting('max_upload_bytes', (string)(1024 * 1024 * 1024));
        $this->setSetting('preview_source', 'origin');

            if ($apiKey !== '' && $pullZoneId !== '') {
                $this->bunny()->updateOrigin($origin);
            }

            $this->setSetting('installed', '1');
            $this->log($adminId, 'install', 'system', null, ['origin_url' => $origin, 'cdn_hostname' => $cdn]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->redirect($this->url('/login'));
    }

    private function renderLogin(?string $error = null): void
    {
        if ($this->user()) {
            $this->redirect($this->url('/'));
            return;
        }
        $err = $error ? '<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">' . $this->e($error) . '</div>' : '';
        $body = '
<main class="min-h-screen bg-slate-950 text-slate-100">
  <section class="mx-auto flex min-h-screen max-w-md items-center px-5">
    <form method="post" class="w-full rounded-2xl border border-white/10 bg-white/10 p-6 shadow-2xl shadow-cyan-950/30 backdrop-blur">
      ' . $this->csrfField() . '
      <p class="text-sm uppercase tracking-[0.25em] text-cyan-300">CDN Drive</p>
      <h1 class="mt-3 text-3xl font-semibold text-white">ログイン</h1>
      ' . $err . '
      <label class="mt-6 block text-sm text-slate-300">メールアドレス<input required type="email" name="email" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-white outline-none focus:border-cyan-300" autocomplete="email"></label>
      <label class="mt-4 block text-sm text-slate-300">パスワード<input required type="password" name="password" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/70 px-3 py-2 text-white outline-none focus:border-cyan-300" autocomplete="current-password"></label>
      <button class="mt-6 w-full rounded-lg bg-cyan-400 px-4 py-3 font-semibold text-slate-950 hover:bg-cyan-300">ログイン</button>
    </form>
  </section>
</main>';
        echo $this->page('Login', $body);
    }

    private function handleLoginPost(): void
    {
        $this->checkCsrf();
        $this->rateLimit('login:' . $this->ip(), 10, 900);
        $email = strtolower($this->cleanText($_POST['email'] ?? '', 190));
        $password = (string)($_POST['password'] ?? '');
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->log(null, 'login_failed', 'user', null, ['email' => $email]);
            $this->renderLogin('メールアドレスまたはパスワードが正しくありません。');
            return;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $this->pdo->prepare('UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?')->execute([$this->now(), $this->now(), (int)$user['id']]);
        $this->touchSession();
        $this->log((int)$user['id'], 'login', 'user', (string)$user['id'], []);
        $this->redirect($this->url('/'));
    }

    private function logout(): void
    {
        $userId = $this->userId();
        if ($this->pdo) {
            $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([session_id()]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        if ($userId) {
            $this->log($userId, 'logout', 'user', (string)$userId, []);
        }
        $this->redirect($this->url('/login'));
    }

    private function renderApp(): void
    {
        $user = $this->user();
        $settings = $this->publicSettings();
        $state = [
            'csrf' => $_SESSION['csrf'],
            'baseUrl' => $this->url('/'),
            'user' => $this->safeUser($user),
            'settings' => $settings,
        ];
        $body = '
<div id="app" class="min-h-screen"></div>
<script>window.CDN_DRIVE_STATE = ' . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>
<script src="' . $this->asset('/assets/app.js') . '"></script>';
        echo $this->page('CDN Drive', $body, true);
    }

    private function apiMe(): void
    {
        $this->json(['ok' => true, 'user' => $this->safeUser($this->user()), 'settings' => $this->publicSettings(), 'usage' => $this->usageForUser($this->userId())]);
    }

    private function apiItems(): void
    {
        $user = $this->user();
        $folderId = $this->nullableId($_GET['folder'] ?? null);
        $search = $this->cleanText($_GET['q'] ?? '', 120);
        $trash = (string)($_GET['trash'] ?? '') === '1';
        $ownerSql = $user['role'] === 'admin' && isset($_GET['all']) ? '1=1' : 'owner_id = :owner';
        $params = [];
        if ($ownerSql !== '1=1') {
            $params[':owner'] = (int)$user['id'];
        }

        $folders = [];
        if (!$trash) {
            $sql = "SELECT * FROM folders WHERE $ownerSql AND deleted_at IS NULL";
            if ($search !== '') {
                $sql .= ' AND name LIKE :q';
                $params[':q'] = '%' . $search . '%';
            } else {
                $sql .= $folderId ? ' AND parent_id = :folder' : ' AND parent_id IS NULL';
                if ($folderId) {
                    $params[':folder'] = $folderId;
                }
            }
            $sql .= ' ORDER BY lower(name) ASC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $folders = array_map(fn($row) => $this->folderOut($row), $stmt->fetchAll());
        }

        $params = [];
        if ($ownerSql !== '1=1') {
            $params[':owner'] = (int)$user['id'];
        }
        $sql = "SELECT * FROM files WHERE $ownerSql AND " . ($trash ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL');
        if ($search !== '') {
            $sql .= ' AND name LIKE :q';
            $params[':q'] = '%' . $search . '%';
        } elseif (!$trash) {
            $sql .= $folderId ? ' AND folder_id = :folder' : ' AND folder_id IS NULL';
            if ($folderId) {
                $params[':folder'] = $folderId;
            }
        }
        $sql .= ' ORDER BY updated_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $files = array_map(fn($row) => $this->fileOut($row), $stmt->fetchAll());

        $this->json(['ok' => true, 'folders' => $folders, 'files' => $files, 'folder' => $folderId ? $this->folderOut($this->folderById($folderId)) : null, 'usage' => $this->usageForUser((int)$user['id'])]);
    }

    private function apiCreateFolder(): void
    {
        $data = $this->jsonInput();
        $name = $this->validName($data['name'] ?? '');
        $parentId = $this->nullableId($data['parent_id'] ?? null);
        if ($parentId) {
            $this->assertFolderAccess($parentId);
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM folders WHERE owner_id = ? AND parent_id IS ? AND name = ? AND deleted_at IS NULL');
        $stmt->execute([$this->userId(), $parentId, $name]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('同じ名前のフォルダが存在します。');
        }
        $now = $this->now();
        $uuid = $this->uuid();
        $stmt = $this->pdo->prepare('INSERT INTO folders(uuid, owner_id, parent_id, name, created_at, updated_at) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$uuid, $this->userId(), $parentId, $name, $now, $now]);
        $id = (int)$this->pdo->lastInsertId();
        $this->log($this->userId(), 'folder_create', 'folder', (string)$id, ['name' => $name]);
        $this->json(['ok' => true, 'folder' => $this->folderOut($this->folderById($id))]);
    }

    private function apiUpload(): void
    {
        $folderId = $this->nullableId($_POST['folder_id'] ?? null);
        if ($folderId) {
            $this->assertFolderAccess($folderId);
        }
        if (empty($_FILES['files'])) {
            throw new RuntimeException('アップロードするファイルがありません。');
        }
        $files = $this->normalizeFiles($_FILES['files']);
        $maxUpload = (int)$this->setting('max_upload_bytes', (string)(1024 * 1024 * 1024));
        $created = [];
        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('アップロードに失敗しました。');
            }
            if ($file['size'] <= 0 || $file['size'] > $maxUpload) {
                throw new RuntimeException('許可されたサイズを超えています。');
            }
            $this->assertQuota($this->userId(), (int)$file['size']);
            $name = $this->validName($file['name']);
            $uuid = $this->uuid();
            $relative = $this->objectPath($this->userId(), $uuid, $name);
            $target = $this->originDir . '/' . $relative;
            $this->ensureParent($target);
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                throw new RuntimeException('ファイルを保存できません。');
            }
            @chmod($target, 0644);
            $mime = $this->detectMime($target, $name);
            $checksum = hash_file('sha256', $target) ?: '';
            $now = $this->now();
            $stmt = $this->pdo->prepare('INSERT INTO files(uuid, owner_id, folder_id, name, relative_path, mime, size, checksum, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$uuid, $this->userId(), $folderId, $name, $relative, $mime, (int)$file['size'], $checksum, $now, $now]);
            $id = (int)$this->pdo->lastInsertId();
            $row = $this->fileById($id);
            $created[] = $this->fileOut($row);
            $this->log($this->userId(), 'file_upload', 'file', (string)$id, ['name' => $name, 'size' => (int)$file['size']]);
        }
        $this->json(['ok' => true, 'files' => $created, 'usage' => $this->usageForUser($this->userId())]);
    }

    private function apiRename(): void
    {
        $data = $this->jsonInput();
        $type = $data['type'] ?? '';
        $id = $this->requiredId($data['id'] ?? null);
        $name = $this->validName($data['name'] ?? '');
        if ($type === 'file') {
            $row = $this->assertFileAccess($id);
            $this->pdo->prepare('UPDATE files SET name = ?, updated_at = ? WHERE id = ?')->execute([$name, $this->now(), $id]);
            $this->log($this->userId(), 'file_rename', 'file', (string)$id, ['from' => $row['name'], 'to' => $name]);
            $this->json(['ok' => true, 'file' => $this->fileOut($this->fileById($id))]);
            return;
        }
        if ($type === 'folder') {
            $row = $this->assertFolderAccess($id);
            $this->pdo->prepare('UPDATE folders SET name = ?, updated_at = ? WHERE id = ?')->execute([$name, $this->now(), $id]);
            $this->log($this->userId(), 'folder_rename', 'folder', (string)$id, ['from' => $row['name'], 'to' => $name]);
            $this->json(['ok' => true, 'folder' => $this->folderOut($this->folderById($id))]);
            return;
        }
        throw new RuntimeException('対象種別が正しくありません。');
    }

    private function apiMove(): void
    {
        $data = $this->jsonInput();
        $id = $this->requiredId($data['id'] ?? null);
        $type = $data['type'] ?? '';
        $folderId = $this->nullableId($data['folder_id'] ?? null);
        if ($folderId) {
            $this->assertFolderAccess($folderId);
        }
        if ($type === 'file') {
            $this->assertFileAccess($id);
            $this->pdo->prepare('UPDATE files SET folder_id = ?, updated_at = ? WHERE id = ?')->execute([$folderId, $this->now(), $id]);
            $this->log($this->userId(), 'file_move', 'file', (string)$id, ['folder_id' => $folderId]);
            $this->json(['ok' => true]);
            return;
        }
        if ($type === 'folder') {
            $folder = $this->assertFolderAccess($id);
            if ($folderId === $id || $this->isDescendantFolder($folderId, $id)) {
                throw new RuntimeException('フォルダを自身または配下へ移動できません。');
            }
            $this->pdo->prepare('UPDATE folders SET parent_id = ?, updated_at = ? WHERE id = ?')->execute([$folderId, $this->now(), $id]);
            $this->log($this->userId(), 'folder_move', 'folder', (string)$id, ['parent_id' => $folderId]);
            $this->json(['ok' => true]);
            return;
        }
        throw new RuntimeException('対象種別が正しくありません。');
    }

    private function apiCopy(): void
    {
        $data = $this->jsonInput();
        $id = $this->requiredId($data['id'] ?? null);
        $folderId = $this->nullableId($data['folder_id'] ?? null);
        if ($folderId) {
            $this->assertFolderAccess($folderId);
        }
        $row = $this->assertFileAccess($id);
        $source = $this->originDir . '/' . $row['relative_path'];
        if (!is_file($source)) {
            throw new RuntimeException('元ファイルが存在しません。');
        }
        $this->assertQuota($this->userId(), (int)$row['size']);
        $uuid = $this->uuid();
        $name = $this->copyName($row['name']);
        $relative = $this->objectPath($this->userId(), $uuid, $name);
        $target = $this->originDir . '/' . $relative;
        $this->ensureParent($target);
        if (!copy($source, $target)) {
            throw new RuntimeException('コピーに失敗しました。');
        }
        @chmod($target, 0644);
        $now = $this->now();
        $stmt = $this->pdo->prepare('INSERT INTO files(uuid, owner_id, folder_id, name, relative_path, mime, size, checksum, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$uuid, $this->userId(), $folderId, $name, $relative, $row['mime'], (int)$row['size'], $row['checksum'], $now, $now]);
        $newId = (int)$this->pdo->lastInsertId();
        $this->log($this->userId(), 'file_copy', 'file', (string)$newId, ['source_id' => $id]);
        $this->json(['ok' => true, 'file' => $this->fileOut($this->fileById($newId)), 'usage' => $this->usageForUser($this->userId())]);
    }

    private function apiDelete(): void
    {
        $data = $this->jsonInput();
        $id = $this->requiredId($data['id'] ?? null);
        $type = $data['type'] ?? '';
        $now = $this->now();
        if ($type === 'file') {
            $row = $this->assertFileAccess($id, true);
            if ($row['deleted_at']) {
                $this->purgeFile($row);
                $this->pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
                $action = 'file_purge';
            } else {
                $this->moveFileToTrash($row);
                $this->pdo->prepare('UPDATE files SET deleted_at = ?, original_folder_id = folder_id, folder_id = NULL, updated_at = ? WHERE id = ?')->execute([$now, $now, $id]);
                $action = 'file_delete';
            }
            $this->log($this->userId(), $action, 'file', (string)$id, []);
            $this->tryPurgeCdnCache($action);
            $this->json(['ok' => true, 'usage' => $this->usageForUser($this->userId())]);
            return;
        }
        if ($type === 'folder') {
            $this->assertFolderAccess($id);
            $this->deleteFolderTree($id, $now);
            $this->log($this->userId(), 'folder_delete', 'folder', (string)$id, []);
            $this->tryPurgeCdnCache('folder_delete');
            $this->json(['ok' => true, 'usage' => $this->usageForUser($this->userId())]);
            return;
        }
        throw new RuntimeException('対象種別が正しくありません。');
    }

    private function apiRestore(): void
    {
        $data = $this->jsonInput();
        $id = $this->requiredId($data['id'] ?? null);
        $row = $this->assertFileAccess($id, true);
        $this->restoreFileFromTrash($row);
        $this->pdo->prepare('UPDATE files SET deleted_at = NULL, folder_id = original_folder_id, original_folder_id = NULL, updated_at = ? WHERE id = ?')->execute([$this->now(), $id]);
        $this->log($this->userId(), 'file_restore', 'file', (string)$id, []);
        $this->tryPurgeCdnCache('file_restore');
        $this->json(['ok' => true, 'file' => $this->fileOut($this->fileById($id))]);
    }

    private function apiShare(): void
    {
        $data = $this->jsonInput();
        $fileId = $this->requiredId($data['file_id'] ?? null);
        $requestedPermission = (string)($data['permission'] ?? 'view');
        $permission = in_array($requestedPermission, ['view', 'download'], true) ? $requestedPermission : 'view';
        $days = max(1, min(365, (int)($data['days'] ?? 7)));
        $password = trim((string)($data['password'] ?? ''));
        $file = $this->assertFileAccess($fileId);
        $token = bin2hex(random_bytes(24));
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format(DATE_ATOM);
        $stmt = $this->pdo->prepare('INSERT INTO shares(token, owner_id, file_id, permission, password_hash, expires_at, created_at) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([$token, $this->userId(), $fileId, $permission, $password === '' ? null : password_hash($password, PASSWORD_DEFAULT), $expires, $this->now()]);
        $url = $this->baseUrl() . $this->url('/share/' . $token, false);
        $this->log($this->userId(), 'share_create', 'file', (string)$fileId, ['expires_at' => $expires, 'permission' => $permission]);
        $this->json(['ok' => true, 'share' => ['url' => $url, 'expires_at' => $expires, 'permission' => $permission, 'file' => $this->fileOut($file)]]);
    }

    private function apiSaveSettings(): void
    {
        $data = $this->jsonInput();
        $cdn = $this->normalizeHost((string)($data['cdn_hostname'] ?? ''));
        $origin = $this->normalizeUrl((string)($data['origin_url'] ?? ''));
        $pullZoneId = trim((string)($data['pull_zone_id'] ?? ''));
        $apiKey = trim((string)($data['bunny_api_key'] ?? ''));
        $wordpressToken = trim((string)($data['wordpress_api_token'] ?? ''));
        $maxUpload = max(1, (int)($data['max_upload_mb'] ?? 1024)) * 1024 * 1024;
        $previewSource = (string)($data['preview_source'] ?? 'cdn');
        $previewSource = in_array($previewSource, ['cdn', 'origin'], true) ? $previewSource : 'cdn';
        $syncBunny = !empty($data['sync_bunny']);
        if ($cdn === '' || $origin === '') {
            throw new RuntimeException('CDN Hostname と Origin URL は必須です。');
        }
        if ($pullZoneId !== '' && !ctype_digit($pullZoneId)) {
            throw new RuntimeException('Pull Zone ID は数字で入力してください。');
        }
        $this->setSetting('cdn_hostname', $cdn);
        $this->setSetting('origin_url', $origin);
        $this->setSetting('pull_zone_id', $pullZoneId);
        $this->setSetting('max_upload_bytes', (string)$maxUpload);
        $this->setSetting('preview_source', $previewSource);
        if ($apiKey !== '') {
            $this->setSetting('bunny_api_key', $apiKey);
        }
        if ($wordpressToken !== '') {
            if (strlen($wordpressToken) < 32) {
                throw new RuntimeException('WordPress API Token must be at least 32 characters.');
            }
            $this->setSetting('wordpress_api_token_hash', password_hash($wordpressToken, PASSWORD_DEFAULT));
        }
        if ($syncBunny && $this->setting('bunny_api_key', '') !== '' && $pullZoneId !== '') {
            $result = $this->bunny()->updateOrigin($origin);
            $this->log($this->userId(), 'settings_update_bunny', 'settings', 'bunny', ['origin_url' => $origin, 'status' => $result['status']]);
        }
        $this->log($this->userId(), 'settings_update', 'settings', 'app', ['cdn_hostname' => $cdn, 'origin_url' => $origin, 'sync_bunny' => $syncBunny]);
        $this->json(['ok' => true, 'settings' => $this->publicSettings(), 'bunny_synced' => $syncBunny]);
    }

    private function apiGenerateWordPressToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->setSetting('wordpress_api_token_hash', password_hash($token, PASSWORD_DEFAULT));
        $this->log($this->userId(), 'wordpress_token_generate', 'settings', 'wordpress', []);
        $this->json(['ok' => true, 'token' => $token, 'settings' => $this->publicSettings()]);
    }

    private function apiBunnyTest(): void
    {
        $result = $this->bunny()->getPullZone();
        $body = $result['body'];
        $this->log($this->userId(), 'bunny_test', 'settings', 'bunny', ['status' => $result['status']]);
        $this->json(['ok' => true, 'status' => $result['status'], 'pull_zone' => [
            'id' => $body['Id'] ?? null,
            'name' => $body['Name'] ?? null,
            'origin_url' => $body['OriginUrl'] ?? null,
            'enabled' => $body['Enabled'] ?? null,
            'suspended' => $body['Suspended'] ?? null,
        ]]);
    }

    private function apiBunnyPurge(): void
    {
        $result = $this->bunny()->purgeCache();
        $this->log($this->userId(), 'bunny_purge', 'settings', 'bunny', ['status' => $result['status']]);
        $this->json(['ok' => true, 'status' => $result['status']]);
    }

    private function apiRepairPaths(): void
    {
        $stmt = $this->pdo->query('SELECT * FROM files WHERE deleted_at IS NULL ORDER BY id ASC');
        $checked = 0;
        $repaired = 0;
        $skipped = 0;
        foreach ($stmt->fetchAll() as $row) {
            $checked++;
            $desired = $this->objectPath((int)$row['owner_id'], $row['uuid'], $row['name']);
            if ($desired === $row['relative_path']) {
                continue;
            }
            $source = $this->originDir . '/' . $row['relative_path'];
            $target = $this->originDir . '/' . $desired;
            if (!is_file($source) || is_file($target)) {
                $skipped++;
                continue;
            }
            $this->ensureParent($target);
            if (!rename($source, $target)) {
                $skipped++;
                continue;
            }
            @chmod($target, 0644);
            $this->pdo->prepare('UPDATE files SET relative_path = ?, updated_at = ? WHERE id = ?')->execute([$desired, $this->now(), (int)$row['id']]);
            $repaired++;
        }
        if ($repaired > 0) {
            $this->tryPurgeCdnCache('repair_paths');
        }
        $this->log($this->userId(), 'maintenance_repair_paths', 'system', null, ['checked' => $checked, 'repaired' => $repaired, 'skipped' => $skipped]);
        $this->json(['ok' => true, 'checked' => $checked, 'repaired' => $repaired, 'skipped' => $skipped]);
    }

    private function apiExternalPing(): void
    {
        $this->json(['ok' => true, 'service' => 'CDN Drive External API', 'cdn_hostname' => $this->setting('cdn_hostname', ''), 'origin_url' => $this->setting('origin_url', '')]);
    }

    private function apiExternalUpload(): void
    {
        if (empty($_FILES['file'])) {
            throw new RuntimeException('file is required.');
        }
        $path = $this->externalRelativePath($_POST['path'] ?? '');
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }
        $maxUpload = (int)$this->setting('max_upload_bytes', (string)(1024 * 1024 * 1024));
        if ($size > $maxUpload) {
            throw new RuntimeException('Uploaded file exceeds the configured size limit.');
        }

        $ownerId = $this->externalOwnerId();
        $target = $this->originDir . '/' . $path;
        $this->ensureParent($target);
        if (is_file($target) && !unlink($target)) {
            throw new RuntimeException('Existing file could not be replaced.');
        }
        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            throw new RuntimeException('File could not be saved.');
        }
        @chmod($target, 0644);

        $row = $this->recordExternalFile($path, $target, $ownerId);
        $this->json(['ok' => true, 'file' => $this->fileOut($row), 'relative_path' => $path]);
    }

    private function apiExternalUploadChunk(): void
    {
        if (empty($_FILES['file'])) {
            throw new RuntimeException('file is required.');
        }
        $path = $this->externalRelativePath($_POST['path'] ?? '');
        $uploadId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['upload_id'] ?? ''));
        $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
        $totalChunks = (int)($_POST['total_chunks'] ?? 0);
        if ($uploadId === '' || strlen($uploadId) > 80) {
            throw new RuntimeException('upload_id is invalid.');
        }
        if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
            throw new RuntimeException('chunk index is invalid.');
        }

        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        $chunkSize = (int)($file['size'] ?? 0);
        if ($chunkSize <= 0) {
            throw new RuntimeException('Uploaded chunk is empty.');
        }

        $chunkDir = $this->dataDir . '/chunks/' . $uploadId;
        if (!is_dir($chunkDir) && !mkdir($chunkDir, 0755, true) && !is_dir($chunkDir)) {
            throw new RuntimeException('Chunk directory could not be created.');
        }
        $chunkPath = $chunkDir . '/' . str_pad((string)$chunkIndex, 8, '0', STR_PAD_LEFT) . '.part';
        if (is_file($chunkPath) && !unlink($chunkPath)) {
            throw new RuntimeException('Existing chunk could not be replaced.');
        }
        if (!move_uploaded_file((string)$file['tmp_name'], $chunkPath)) {
            throw new RuntimeException('Chunk could not be saved.');
        }

        if ($chunkIndex + 1 < $totalChunks) {
            $this->json(['ok' => true, 'complete' => false, 'chunk_index' => $chunkIndex]);
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $expected = $chunkDir . '/' . str_pad((string)$i, 8, '0', STR_PAD_LEFT) . '.part';
            if (!is_file($expected)) {
                throw new RuntimeException('Waiting for chunk ' . $i . '.');
            }
        }

        $ownerId = $this->externalOwnerId();
        $target = $this->originDir . '/' . $path;
        $this->ensureParent($target);
        $partTarget = $target . '.uploading';
        $out = fopen($partTarget, 'wb');
        if (!is_resource($out)) {
            throw new RuntimeException('Target file could not be opened.');
        }
        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $expected = $chunkDir . '/' . str_pad((string)$i, 8, '0', STR_PAD_LEFT) . '.part';
                $in = fopen($expected, 'rb');
                if (!is_resource($in)) {
                    throw new RuntimeException('Chunk could not be read.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
        if (is_file($target) && !unlink($target)) {
            throw new RuntimeException('Existing file could not be replaced.');
        }
        if (!rename($partTarget, $target)) {
            throw new RuntimeException('Uploaded chunks could not be finalized.');
        }
        @chmod($target, 0644);
        $this->removeDirectory($chunkDir);
        $row = $this->recordExternalFile($path, $target, $ownerId);
        $this->json(['ok' => true, 'complete' => true, 'file' => $this->fileOut($row), 'relative_path' => $path]);
    }

    private function recordExternalFile(string $path, string $target, int $ownerId): array
    {
        $size = filesize($target) ?: 0;
        $name = basename($path);
        $mime = $this->detectMime($target, $name);
        $checksum = hash_file('sha256', $target) ?: '';
        $now = $this->now();
        $stmt = $this->pdo->prepare('SELECT id, uuid FROM files WHERE relative_path = ? LIMIT 1');
        $stmt->execute([$path]);
        $existing = $stmt->fetch();
        if ($existing) {
            $id = (int)$existing['id'];
            $this->pdo->prepare('UPDATE files SET owner_id=?, folder_id=NULL, name=?, mime=?, size=?, checksum=?, deleted_at=NULL, original_folder_id=NULL, updated_at=? WHERE id=?')
                ->execute([$ownerId, $name, $mime, filesize($target) ?: $size, $checksum, $now, $id]);
            $action = 'external_file_update';
        } else {
            $uuid = $this->uuid();
            $this->pdo->prepare('INSERT INTO files(uuid, owner_id, folder_id, name, relative_path, mime, size, checksum, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute([$uuid, $ownerId, null, $name, $path, $mime, filesize($target) ?: $size, $checksum, $now, $now]);
            $id = (int)$this->pdo->lastInsertId();
            $action = 'external_file_upload';
        }

        $row = $this->fileById($id);
        $this->log($ownerId, $action, 'file', (string)$id, ['relative_path' => $path, 'source' => 'wordpress']);
        return $row;
    }

    private function apiExternalDelete(): void
    {
        $data = $this->requestData();
        $paths = $data['paths'] ?? ($data['path'] ?? null);
        if (is_string($paths)) {
            $paths = [$paths];
        }
        if (!is_array($paths) || !$paths) {
            throw new RuntimeException('path or paths is required.');
        }
        $deleted = 0;
        $missing = 0;
        foreach ($paths as $rawPath) {
            $path = $this->externalRelativePath($rawPath);
            $stmt = $this->pdo->prepare('SELECT * FROM files WHERE relative_path = ? LIMIT 1');
            $stmt->execute([$path]);
            $row = $stmt->fetch();
            $filePath = $this->originDir . '/' . $path;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            if ($row) {
                $this->purgeFile($row);
                $this->pdo->prepare('DELETE FROM files WHERE id = ?')->execute([(int)$row['id']]);
                $deleted++;
            } else {
                $missing++;
            }
        }
        if ($deleted > 0) {
            $this->tryPurgeCdnCache('external_delete');
        }
        $this->log($this->externalOwnerId(), 'external_file_delete', 'file', null, ['deleted' => $deleted, 'missing' => $missing, 'source' => 'wordpress']);
        $this->json(['ok' => true, 'deleted' => $deleted, 'missing' => $missing]);
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'Upload failed: file exceeds upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE => 'Upload failed: file exceeds MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL => 'Upload failed: file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'Upload failed: no file was received. The request may exceed post_max_size.',
            UPLOAD_ERR_NO_TMP_DIR => 'Upload failed: temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Upload failed: could not write to disk.',
            UPLOAD_ERR_EXTENSION => 'Upload failed: blocked by a PHP extension.',
            default => 'Upload failed: PHP upload error ' . $code . '.',
        };
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function apiExternalPurge(): void
    {
        $this->tryPurgeCdnCache('external_purge');
        $this->json(['ok' => true]);
    }

    private function apiUsers(): void
    {
        $stmt = $this->pdo->query('SELECT id,email,name,role,active,storage_limit_bytes,created_at,last_login_at FROM users ORDER BY id ASC');
        $this->json(['ok' => true, 'users' => $stmt->fetchAll()]);
    }

    private function apiSaveUser(): void
    {
        $data = $this->jsonInput();
        $id = $this->nullableId($data['id'] ?? null);
        $email = strtolower($this->cleanText($data['email'] ?? '', 190));
        $name = $this->cleanText($data['name'] ?? '', 80);
        $role = ($data['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $active = !empty($data['active']) ? 1 : 0;
        $limitGb = trim((string)($data['storage_limit_gb'] ?? ''));
        $limit = $limitGb === '' ? null : max(0, (int)round((float)$limitGb * 1024 * 1024 * 1024));
        $password = (string)($data['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            throw new RuntimeException('ユーザー情報が正しくありません。');
        }
        $now = $this->now();
        if ($id) {
            $params = [$email, $name, $role, $active, $limit, $now, $id];
            $this->pdo->prepare('UPDATE users SET email=?, name=?, role=?, active=?, storage_limit_bytes=?, updated_at=? WHERE id=?')->execute($params);
            if ($password !== '') {
                if (strlen($password) < 12) {
                    throw new RuntimeException('パスワードは12文字以上にしてください。');
                }
                $this->pdo->prepare('UPDATE users SET password_hash=?, updated_at=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $now, $id]);
            }
            $this->log($this->userId(), 'user_update', 'user', (string)$id, ['email' => $email]);
        } else {
            if (strlen($password) < 12) {
                throw new RuntimeException('新規ユーザーのパスワードは12文字以上です。');
            }
            $this->pdo->prepare('INSERT INTO users(email,name,password_hash,role,active,storage_limit_bytes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT), $role, $active, $limit, $now, $now]);
            $id = (int)$this->pdo->lastInsertId();
            $this->log($this->userId(), 'user_create', 'user', (string)$id, ['email' => $email]);
        }
        $this->apiUsers();
    }

    private function apiLogs(): void
    {
        $stmt = $this->pdo->query('SELECT logs.*, users.email FROM logs LEFT JOIN users ON users.id = logs.user_id ORDER BY logs.id DESC LIMIT 300');
        $this->json(['ok' => true, 'logs' => $stmt->fetchAll()]);
    }

    private function renderShare(string $token): void
    {
        $this->openDb();
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
        $stmt = $this->pdo->prepare('SELECT shares.*, files.name, files.mime, files.relative_path, files.size, files.deleted_at AS file_deleted_at FROM shares JOIN files ON files.id = shares.file_id WHERE shares.token = ? LIMIT 1');
        $stmt->execute([$token]);
        $share = $stmt->fetch();
        if (!$share || $share['revoked_at'] || $share['file_deleted_at'] || strtotime($share['expires_at']) < time()) {
            http_response_code(404);
            echo $this->page('共有リンク', '<main class="min-h-screen bg-slate-950 p-6 text-slate-100"><div class="mx-auto mt-20 max-w-lg rounded-xl border border-white/10 bg-white/10 p-6">共有リンクは利用できません。</div></main>');
            return;
        }
        if ($share['password_hash']) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !password_verify((string)($_POST['password'] ?? ''), $share['password_hash'])) {
                $form = '<main class="min-h-screen bg-slate-950 p-6 text-slate-100"><form method="post" class="mx-auto mt-20 max-w-md rounded-xl border border-white/10 bg-white/10 p-6"><h1 class="text-xl font-semibold">パスワード確認</h1><input type="password" name="password" class="mt-5 w-full rounded-lg bg-slate-900 px-3 py-2" autofocus><button class="mt-4 rounded-lg bg-cyan-400 px-4 py-2 font-semibold text-slate-950">表示</button></form></main>';
                echo $this->page('共有リンク', $form);
                return;
            }
        }
        $cdn = $this->cdnUrl($share['relative_path']);
        $preview = $this->previewHtml($share['mime'], $cdn, $share['name']);
        $download = $share['permission'] === 'download' ? '<a download class="rounded-lg bg-cyan-400 px-4 py-2 font-semibold text-slate-950" href="' . $this->e($cdn) . '">ダウンロード</a>' : '';
        $body = '<main class="min-h-screen bg-slate-950 p-5 text-slate-100"><section class="mx-auto max-w-5xl"><div class="mb-5 flex items-center justify-between gap-4"><div><p class="text-sm text-cyan-300">Shared file</p><h1 class="text-2xl font-semibold">' . $this->e($share['name']) . '</h1></div>' . $download . '</div><div class="rounded-xl border border-white/10 bg-white/10 p-4">' . $preview . '</div><p class="mt-4 break-all text-sm text-slate-400">' . $this->e($cdn) . '</p></section></main>';
        $this->log((int)$share['owner_id'], 'share_view', 'share', (string)$share['id'], ['file' => $share['name']]);
        echo $this->page('共有リンク', $body);
    }

    private function previewHtml(string $mime, string $url, string $name): string
    {
        if (str_starts_with($mime, 'image/')) {
            return '<img class="max-h-[72vh] w-full rounded-lg object-contain" alt="' . $this->e($name) . '" src="' . $this->e($url) . '" onerror="this.classList.add(\'hidden\');this.nextElementSibling.classList.remove(\'hidden\')"><div class="hidden rounded-lg bg-red-950/50 p-5 text-sm text-red-100">CDN URL から画像を読み込めません。リンクの有効性、Bunny Pull Zone、Origin URL を確認してください。</div>';
        }
        if (str_starts_with($mime, 'video/')) {
            return '<video class="max-h-[72vh] w-full rounded-lg" controls src="' . $this->e($url) . '"></video>';
        }
        return '<div class="rounded-lg bg-slate-900 p-10 text-center text-slate-300">このファイルはブラウザプレビューに対応していません。</div>';
    }

    private function bunny(): BunnyClient
    {
        return new BunnyClient($this->setting('bunny_api_key', ''), $this->setting('pull_zone_id', ''));
    }

    private function tryPurgeCdnCache(string $sourceAction): void
    {
        if ($this->setting('bunny_api_key', '') === '' || $this->setting('pull_zone_id', '') === '') {
            return;
        }
        try {
            $result = $this->bunny()->purgeCache();
            $this->log($this->userId(), 'bunny_purge_after_' . $sourceAction, 'settings', 'bunny', ['status' => $result['status']]);
        } catch (Throwable $e) {
            error_log((string)$e);
            $this->log($this->userId(), 'bunny_purge_failed_after_' . $sourceAction, 'settings', 'bunny', ['error' => $this->publicError($e)]);
        }
    }

    private function fileOut(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'mime' => $row['mime'],
            'size' => (int)$row['size'],
            'url' => $this->cdnUrl($row['relative_path']),
            'preview_url' => $this->previewUrl($row['relative_path']),
            'origin_url' => $this->originUrl($row['relative_path']),
            'folder_id' => $row['folder_id'] !== null ? (int)$row['folder_id'] : null,
            'deleted_at' => $row['deleted_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function folderOut(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function folderById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM folders WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('フォルダが見つかりません。');
        }
        return $row;
    }

    private function fileById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM files WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('ファイルが見つかりません。');
        }
        return $row;
    }

    private function assertFileAccess(int $id, bool $allowDeleted = false): array
    {
        $row = $this->fileById($id);
        $user = $this->user();
        if ($user['role'] !== 'admin' && (int)$row['owner_id'] !== (int)$user['id']) {
            throw new RuntimeException('アクセス権限がありません。');
        }
        if (!$allowDeleted && $row['deleted_at']) {
            throw new RuntimeException('削除済みファイルです。');
        }
        return $row;
    }

    private function assertFolderAccess(int $id): array
    {
        $row = $this->folderById($id);
        $user = $this->user();
        if ($user['role'] !== 'admin' && (int)$row['owner_id'] !== (int)$user['id']) {
            throw new RuntimeException('アクセス権限がありません。');
        }
        if ($row['deleted_at']) {
            throw new RuntimeException('削除済みフォルダです。');
        }
        return $row;
    }

    private function deleteFolderTree(int $folderId, string $now): void
    {
        $children = $this->pdo->prepare('SELECT id FROM folders WHERE parent_id = ? AND deleted_at IS NULL');
        $children->execute([$folderId]);
        foreach ($children->fetchAll() as $child) {
            $this->deleteFolderTree((int)$child['id'], $now);
        }
        $files = $this->pdo->prepare('SELECT * FROM files WHERE folder_id = ? AND deleted_at IS NULL');
        $files->execute([$folderId]);
        foreach ($files->fetchAll() as $file) {
            $this->moveFileToTrash($file);
        }
        $this->pdo->prepare('UPDATE files SET deleted_at = ?, original_folder_id = folder_id, folder_id = NULL, updated_at = ? WHERE folder_id = ? AND deleted_at IS NULL')->execute([$now, $now, $folderId]);
        $this->pdo->prepare('UPDATE folders SET deleted_at = ?, updated_at = ? WHERE id = ?')->execute([$now, $now, $folderId]);
    }

    private function purgeFile(array $row): void
    {
        foreach ([$this->originDir . '/' . $row['relative_path'], $this->trashFilePath($row)] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function moveFileToTrash(array $row): void
    {
        $source = $this->originDir . '/' . $row['relative_path'];
        if (!is_file($source)) {
            return;
        }
        $target = $this->trashFilePath($row);
        $this->ensureParent($target);
        if (!rename($source, $target)) {
            throw new RuntimeException('ファイルをゴミ箱へ移動できません。');
        }
    }

    private function restoreFileFromTrash(array $row): void
    {
        $source = $this->trashFilePath($row);
        $target = $this->originDir . '/' . $row['relative_path'];
        if (!is_file($source)) {
            throw new RuntimeException('ゴミ箱内のファイルが見つかりません。');
        }
        $this->ensureParent($target);
        if (!rename($source, $target)) {
            throw new RuntimeException('ファイルを復元できません。');
        }
        @chmod($target, 0644);
    }

    private function trashFilePath(array $row): string
    {
        return $this->trashDir . '/u' . (int)$row['owner_id'] . '/' . $row['uuid'];
    }

    private function isDescendantFolder(?int $candidate, int $folderId): bool
    {
        while ($candidate) {
            $row = $this->folderById($candidate);
            if ((int)$row['parent_id'] === $folderId) {
                return true;
            }
            $candidate = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
        }
        return false;
    }

    private function objectPath(int $ownerId, string $uuid, string $name): string
    {
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,16}$/', $extension) ? $extension : '';
        $base = (string)pathinfo($name, PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: 'file';
        $safeBase = trim($safeBase, '.-');
        if ($safeBase === '') {
            $safeBase = 'file';
        }
        $safe = $extension === '' ? $safeBase : $safeBase . '.' . $extension;
        return 'objects/u' . $ownerId . '/' . substr($uuid, 0, 2) . '/' . $uuid . '/' . $safe;
    }

    private function cdnUrl(string $relativePath): string
    {
        $host = $this->setting('cdn_hostname', '');
        $host = preg_replace('#^https?://#i', '', $host);
        return 'https://' . rtrim($host, '/') . '/' . ltrim($relativePath, '/');
    }

    private function originUrl(string $relativePath): string
    {
        return rtrim($this->setting('origin_url', $this->baseUrl() . '/origin'), '/') . '/' . ltrim($relativePath, '/');
    }

    private function previewUrl(string $relativePath): string
    {
        return $this->setting('preview_source', 'origin') === 'origin'
            ? $this->originUrl($relativePath)
            : $this->cdnUrl($relativePath);
    }

    private function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files['name'] as $i => $name) {
            $out[] = [
                'name' => $name,
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }
        return $out;
    }

    private function assertQuota(int $userId, int $addBytes): void
    {
        $stmt = $this->pdo->prepare('SELECT storage_limit_bytes FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $limit = $stmt->fetchColumn();
        if ($limit === null || $limit === false || (int)$limit <= 0) {
            return;
        }
        $usage = $this->usageForUser($userId)['bytes'];
        if ($usage + $addBytes > (int)$limit) {
            throw new RuntimeException('ストレージ容量の上限を超えます。');
        }
    }

    private function usageForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(size),0) FROM files WHERE owner_id = ? AND deleted_at IS NULL');
        $stmt->execute([$userId]);
        $bytes = (int)$stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT storage_limit_bytes FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $limit = $stmt->fetchColumn();
        return ['bytes' => $bytes, 'limit' => $limit === null || $limit === false ? null : (int)$limit];
    }

    private function safeUser(?array $user): ?array
    {
        if (!$user) {
            return null;
        }
        return ['id' => (int)$user['id'], 'email' => $user['email'], 'name' => $user['name'], 'role' => $user['role']];
    }

    private function user(): ?array
    {
        $id = $this->userId();
        if (!$id || !$this->pdo) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? AND active = 1 LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    private function requireAuth(bool $json = true): void
    {
        if ($this->user()) {
            return;
        }
        if ($json) {
            $this->json(['ok' => false, 'error' => 'ログインが必要です。'], 401);
        }
        $this->redirect($this->url('/login'));
    }

    private function requireAdmin(): void
    {
        $user = $this->user();
        if (!$user || $user['role'] !== 'admin') {
            $this->json(['ok' => false, 'error' => '管理者権限が必要です。'], 403);
        }
    }

    private function requireExternalToken(): void
    {
        $hash = $this->setting('wordpress_api_token_hash', '');
        if ($hash === '') {
            $this->json(['ok' => false, 'error' => 'External API token is not configured.'], 401);
        }
        $token = $this->externalTokenFromRequest();
        if ($token === '' || !password_verify($token, $hash)) {
            $this->json(['ok' => false, 'error' => 'External API token is invalid.'], 401);
        }
    }

    private function externalTokenFromRequest(): string
    {
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) {
            return trim($m[1]);
        }
        $header = trim((string)($_SERVER['HTTP_X_CDN_DRIVE_TOKEN'] ?? ''));
        if ($header !== '') {
            return $header;
        }
        return trim((string)($_POST['token'] ?? ($_GET['token'] ?? '')));
    }

    private function externalOwnerId(): int
    {
        $stmt = $this->pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id ASC LIMIT 1");
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $stmt = $this->pdo->query('SELECT id FROM users WHERE active = 1 ORDER BY id ASC LIMIT 1');
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('No active owner user exists.');
        }
        return (int)$id;
    }

    private function touchSession(): void
    {
        if (!$this->pdo) {
            return;
        }
        $now = $this->now();
        $expires = (new DateTimeImmutable('+12 hours', new DateTimeZone('UTC')))->format(DATE_ATOM);
        $stmt = $this->pdo->prepare('INSERT INTO sessions(id,user_id,ip,user_agent,csrf_hash,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)
            ON CONFLICT(id) DO UPDATE SET user_id=excluded.user_id, ip=excluded.ip, user_agent=excluded.user_agent, csrf_hash=excluded.csrf_hash, expires_at=excluded.expires_at, updated_at=excluded.updated_at');
        $stmt->execute([session_id(), $this->userId(), $this->ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), hash('sha256', $_SESSION['csrf'] ?? ''), $expires, $now, $now]);
        $this->pdo->prepare('DELETE FROM sessions WHERE expires_at < ?')->execute([$now]);
    }

    private function checkCsrf(): void
    {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($token) || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
            throw new RuntimeException('CSRF トークンが無効です。');
        }
    }

    private function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e((string)($_SESSION['csrf'] ?? '')) . '">';
    }

    private function jsonInput(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('JSON を解析できません。');
        }
        return $data;
    }

    private function requestData(): array
    {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            return $this->jsonInput();
        }
        return $_POST;
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function rateLimit(string $key, int $limit, int $window): void
    {
        $now = time();
        $stmt = $this->pdo->prepare('SELECT attempts, reset_at FROM rate_limits WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['reset_at'] <= $now) {
            $this->pdo->prepare('REPLACE INTO rate_limits(key, attempts, reset_at) VALUES(?,?,?)')->execute([$key, 1, $now + $window]);
            return;
        }
        if ((int)$row['attempts'] >= $limit) {
            throw new RuntimeException('アクセスが集中しています。しばらく待ってから再試行してください。');
        }
        $this->pdo->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE key = ?')->execute([$key]);
    }

    private function setting(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at');
        $stmt->execute([$key, $value, $this->now()]);
    }

    private function publicSettings(): array
    {
        return [
            'cdn_hostname' => $this->setting('cdn_hostname', ''),
            'origin_url' => $this->setting('origin_url', ''),
            'pull_zone_id' => $this->setting('pull_zone_id', ''),
            'preview_source' => $this->setting('preview_source', 'origin'),
            'bunny_api_key_set' => $this->setting('bunny_api_key', '') !== '',
            'wordpress_api_token_set' => $this->setting('wordpress_api_token_hash', '') !== '',
            'wordpress_api_base' => $this->baseUrl() . $this->url('/api/external', false),
            'max_upload_mb' => (int)ceil(((int)$this->setting('max_upload_bytes', (string)(1024 * 1024 * 1024))) / 1024 / 1024),
        ];
    }

    private function log(?int $userId, string $action, string $type, ?string $entityId, array $details): void
    {
        if (!$this->pdo) {
            return;
        }
        $stmt = $this->pdo->prepare('INSERT INTO logs(user_id,action,entity_type,entity_id,ip,user_agent,details,created_at) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute([$userId, $action, $type, $entityId, $this->ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->now()]);
    }

    private function validName(mixed $value): string
    {
        $name = $this->cleanText($value, 180);
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('名前が正しくありません。');
        }
        return $name;
    }

    private function externalRelativePath(mixed $value): string
    {
        $path = trim((string)$value);
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = ltrim($path, '/');
        if ($path === '') {
            throw new RuntimeException('path is required.');
        }
        if (strlen($path) > 900 || preg_match('/[\x00-\x1F\x7F?#]/', $path)) {
            throw new RuntimeException('path contains invalid characters.');
        }
        if (str_contains($path, '..')) {
            throw new RuntimeException('path traversal is not allowed.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with(strtolower($segment), '.ht')) {
                throw new RuntimeException('path contains invalid segments.');
            }
        }
        if (!str_starts_with($path, 'wp/')) {
            $path = 'wp/' . $path;
        }
        return $path;
    }

    private function cleanText(mixed $value, int $max): string
    {
        $text = trim((string)$value);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) > $max) {
                $text = mb_substr($text, 0, $max);
            }
        } elseif (strlen($text) > $max) {
            $text = substr($text, 0, $max);
        }
        return $text;
    }

    private function normalizeHost(string $host): string
    {
        $host = trim($host);
        $host = preg_replace('#^https?://#i', '', $host) ?? '';
        $host = trim($host, "/ \t\n\r\0\x0B");
        if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+(?::[0-9]+)?$/', $host)) {
            return '';
        }
        return strtolower($host);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $parts = parse_url($url);
        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return '';
        }
        return rtrim($url, '/');
    }

    private function requiredId(mixed $value): int
    {
        $id = $this->nullableId($value);
        if (!$id) {
            throw new RuntimeException('ID が正しくありません。');
        }
        return $id;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        if (is_numeric($value) && (int)$value > 0) {
            return (int)$value;
        }
        throw new RuntimeException('ID が正しくありません。');
    }

    private function copyName(string $name): string
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return $name . ' copy';
        }
        return substr($name, 0, $dot) . ' copy' . substr($name, $dot);
    }

    private function detectMime(string $path, string $name): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path) ?: '';
                finfo_close($finfo);
            }
        }
        if ($mime !== '') {
            return $mime;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function ensureParent(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('保存先ディレクトリを作成できません。');
        }
    }

    private function ensureWritable(): void
    {
        foreach ([$this->dataDir, $this->originDir, $this->trashDir, $this->sessionDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException($dir . ' を作成できません。');
            }
            if (!is_writable($dir)) {
                throw new RuntimeException($dir . ' に書き込みできません。');
            }
        }
    }

    private function installChecks(): array
    {
        return [
            'PHP 8.2 以上' => PHP_VERSION_ID >= 80200,
            'PDO SQLite' => extension_loaded('pdo_sqlite'),
            'JSON' => extension_loaded('json'),
            'fileinfo' => extension_loaded('fileinfo'),
            'data 書き込み' => is_dir($this->dataDir) && is_writable($this->dataDir),
            'origin 書き込み' => is_dir($this->originDir) && is_writable($this->originDir),
        ];
    }

    private function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https: http:; media-src 'self' https: http: blob:; connect-src 'self'; font-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
    }

    private function page(string $title, string $body, bool $app = false): string
    {
        $htmlTitle = $this->e($title);
        $theme = $app ? ' class="h-full bg-slate-950"' : '';
        $footer = '<footer class="border-t border-white/10 px-4 py-3 text-center text-xs text-slate-500">Created by Pronelt · MIT License</footer>';
        return '<!doctype html><html lang="ja"' . $theme . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $htmlTitle . '</title><script src="https://cdn.tailwindcss.com"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script><link rel="stylesheet" href="' . $this->asset('/assets/styles.css') . '"></head><body class="bg-slate-950 antialiased">' . $body . $footer . '</body></html>';
    }

    private function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = $this->basePath();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }
        return '/' . trim($uri, '/');
    }

    private function basePath(): string
    {
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        return $script === '/' ? '' : rtrim($script, '/');
    }

    private function url(string $path, bool $withBase = true): string
    {
        $p = '/' . ltrim($path, '/');
        return ($withBase ? $this->basePath() : '') . $p;
    }

    private function asset(string $path): string
    {
        $file = $this->root . '/' . ltrim($path, '/');
        $v = is_file($file) ? (string)filemtime($file) : '1';
        return $this->url($path) . '?v=' . rawurlencode($v);
    }

    private function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . $this->basePath();
    }

    private function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function ip(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 64);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function publicError(Throwable $e): string
    {
        return $e instanceof RuntimeException ? $e->getMessage() : 'サーバーエラーが発生しました。';
    }

}

final class BunnyClient
{
    public function __construct(private string $apiKey, private string $pullZoneId)
    {
    }

    public function getPullZone(): array
    {
        $this->assertConfigured();
        return $this->request('GET', '/pullzone/' . rawurlencode($this->pullZoneId));
    }

    public function updateOrigin(string $originUrl): array
    {
        $this->assertConfigured();
        return $this->request('POST', '/pullzone/' . rawurlencode($this->pullZoneId), [
            'OriginUrl' => $originUrl,
            'BlockPostRequests' => true,
        ]);
    }

    public function purgeCache(): array
    {
        $this->assertConfigured();
        return $this->request('POST', '/pullzone/' . rawurlencode($this->pullZoneId) . '/purgeCache');
    }

    private function assertConfigured(): void
    {
        if ($this->apiKey === '' || $this->pullZoneId === '') {
            throw new RuntimeException('Bunny API Key と Pull Zone ID を設定してください。');
        }
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = 'https://api.bunny.net' . $path;
        $headers = [
            'AccessKey: ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($response === false) {
                throw new RuntimeException('Bunny API 通信に失敗しました: ' . $error);
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $body ?? '',
                    'timeout' => 20,
                    'ignore_errors' => true,
                ],
            ]);
            $response = file_get_contents($url, false, $context);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $status = (int)$m[1];
                }
            }
            if ($response === false) {
                throw new RuntimeException('Bunny API 通信に失敗しました。');
            }
        }
        $decoded = json_decode((string)$response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$response;
            if ($status === 405) {
                throw new RuntimeException('Bunny API エラー HTTP 405 Method Not Allowed: Bunny API のエンドポイントではない URL、Pull Zone ID の誤り、または CDN Hostname を Origin URL として設定している可能性があります。');
            }
            throw new RuntimeException('Bunny API エラー HTTP ' . $status . ': ' . $message);
        }
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }
}
