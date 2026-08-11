<?php
/**
 * Plugin Name: CDN Drive Sync
 * Description: Syncs WordPress media files and generated image sizes to CDN Drive, then rewrites media URLs to the BunnyCDN hostname.
 * Version: 1.1.5
 * Author: Pronelt
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class CDN_Drive_Sync
{
    private const OPTION = 'cdn_drive_sync_options';
    private const NOTICE_OPTION = 'cdn_drive_sync_notice';
    private const LAST_RESULT_OPTION = 'cdn_drive_sync_last_result';
    private const GITHUB_OWNER = 'tomosan078';
    private const GITHUB_REPO = 'rental-serv-bunny-cdndrive';
    private const GITHUB_ASSET = 'cdn-drive-sync.zip';

    public static function boot(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'injectUpdateInfo']);
        add_filter('plugins_api', [self::class, 'pluginInformation'], 20, 3);
        add_action('admin_menu', [self::class, 'adminMenu']);
        add_action('admin_notices', [self::class, 'adminNotice']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_cdn_drive_test_connection', [self::class, 'handleTestConnection']);
        add_action('admin_post_cdn_drive_test_upload', [self::class, 'handleTestUpload']);
        add_action('admin_post_cdn_drive_sync_attachment', [self::class, 'handleManualSync']);
        add_action('admin_post_cdn_drive_sync_batch', [self::class, 'handleBatchSync']);
        add_action('wp_ajax_cdn_drive_sync_prepare', [self::class, 'ajaxProgressPrepare']);
        add_action('wp_ajax_cdn_drive_sync_step', [self::class, 'ajaxProgressStep']);
        add_action('add_attachment', [self::class, 'syncAttachmentAdded']);
        add_filter('wp_update_attachment_metadata', [self::class, 'syncAttachmentMetadata'], 20, 2);
        add_action('delete_attachment', [self::class, 'deleteAttachment']);
        add_filter('wp_get_attachment_url', [self::class, 'filterAttachmentUrl'], 20, 2);
        add_filter('wp_calculate_image_srcset', [self::class, 'filterSrcset'], 20);
        add_filter('manage_media_columns', [self::class, 'mediaColumns']);
        add_action('manage_media_custom_column', [self::class, 'mediaColumnContent'], 10, 2);
        add_filter('media_row_actions', [self::class, 'mediaRowActions'], 10, 2);
    }

    public static function adminMenu(): void
    {
        add_options_page('CDN Drive Sync', 'CDN Drive Sync', 'manage_options', 'cdn-drive-sync', [self::class, 'settingsPage']);
    }

    public static function adminNotice(): void
    {
        if (!current_user_can('manage_options') || (string)($_GET['page'] ?? '') !== 'cdn-drive-sync') {
            return;
        }
        $notice = self::pullNotice();
        if ($notice === '') {
            return;
        }
        $class = self::noticeClass($notice);
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p style="white-space:pre-wrap">' . esc_html($notice) . '</p></div>';
    }

    public static function registerSettings(): void
    {
        register_setting('cdn_drive_sync', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeOptions'],
            'default' => self::defaults(),
        ]);
    }

    public static function injectUpdateInfo($transient)
    {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = self::latestGitHubRelease();
        if ($release === null) {
            return $transient;
        }

        $latestVersion = ltrim((string)($release['tag_name'] ?? ''), "vV");
        if ($latestVersion === '' || version_compare($latestVersion, self::pluginVersion(), '<=')) {
            return $transient;
        }

        $package = self::releaseAssetUrl($release) ?: (string)($release['zipball_url'] ?? '');
        if ($package === '') {
            return $transient;
        }

        $plugin = plugin_basename(__FILE__);
        $transient->response[$plugin] = (object) [
            'slug' => 'cdn-drive-sync',
            'plugin' => $plugin,
            'new_version' => $latestVersion,
            'tested' => get_bloginfo('version'),
            'requires' => '6.0',
            'package' => $package,
            'url' => self::projectUrl(),
        ];

        return $transient;
    }
    public static function pluginInformation($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'cdn-drive-sync') {
            return $result;
        }

        $release = self::latestGitHubRelease();
        if ($release === null) {
            return $result;
        }

        $version = ltrim((string)($release['tag_name'] ?? self::pluginVersion()), "vV");
        $info = new stdClass();
        $info->name = 'CDN Drive Sync';
        $info->slug = 'cdn-drive-sync';
        $info->version = $version;
        $info->author = '<a href="' . esc_url('https://github.com/' . self::GITHUB_OWNER) . '">Pronelt</a>';
        $info->homepage = self::projectUrl();
        $info->download_link = self::releaseAssetUrl($release) ?: (string)($release['zipball_url'] ?? '');
        $info->requires = '6.0';
        $info->tested = get_bloginfo('version');
        $info->sections = [
            'description' => 'CORESERVER V2 / BunnyCDN 連携の CDN 配信プラグインです。GitHub Release の最新版を WordPress の更新画面に表示します。',
            'changelog' => (string)($release['body'] ?? 'GitHub Release を確認してください。'),
        ];

        return $info;
    }

    private static function renderLastResult(): void
    {
        $result = get_option(self::LAST_RESULT_OPTION);
        if (!is_array($result) || trim((string)($result['message'] ?? '')) === '') {
            return;
        }
        $message = (string)$result['message'];
        $class = self::noticeClass($message);
        $time = trim((string)($result['time'] ?? ''));
        echo '<div class="notice ' . esc_attr($class) . '" style="margin-top:12px"><p><strong>Last Result</strong>';
        if ($time !== '') {
            echo ' <small>' . esc_html($time) . '</small>';
        }
        echo '</p><p style="white-space:pre-wrap">' . esc_html($message) . '</p></div>';
    }

    private static function renderProgressSyncPanel(): void
    {
        $nonce = wp_create_nonce('cdn_drive_sync_progress');
        ?>
        <div id="cdn-drive-progress-panel" style="border:1px solid #ccd0d4;background:#fff;padding:16px;margin:12px 0;max-width:760px">
            <h3 style="margin-top:0">Progress Transfer</h3>
            <p>既存メディアを画面上で進捗表示しながら CDN Drive へ転送します。動画やオリジナル画像も同期対象です。</p>
            <p>
                <label for="cdn-drive-progress-limit">Step size</label>
                <input id="cdn-drive-progress-limit" type="number" min="1" max="10" value="1">
                <button type="button" class="button button-primary" id="cdn-drive-progress-start">Start Transfer</button>
                <button type="button" class="button" id="cdn-drive-progress-stop" disabled>Stop</button>
            </p>
            <div style="height:22px;background:#f0f0f1;border-radius:4px;overflow:hidden">
                <div id="cdn-drive-progress-bar" style="height:22px;width:0;background:#2271b1;transition:width .2s"></div>
            </div>
            <p id="cdn-drive-progress-status" style="font-weight:600">待機中</p>
            <pre id="cdn-drive-progress-log" style="display:none;max-height:220px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;white-space:pre-wrap"></pre>
        </div>
        <script>
        (function() {
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            const start = document.getElementById('cdn-drive-progress-start');
            const stop = document.getElementById('cdn-drive-progress-stop');
            const limitInput = document.getElementById('cdn-drive-progress-limit');
            const bar = document.getElementById('cdn-drive-progress-bar');
            const status = document.getElementById('cdn-drive-progress-status');
            const log = document.getElementById('cdn-drive-progress-log');
            let running = false;
            let offset = 0;
            let total = 0;
            let success = 0;
            let failed = 0;

            function setStatus(text) {
                status.textContent = text;
            }
            function setProgress() {
                const pct = total > 0 ? Math.min(100, Math.round((offset / total) * 100)) : 0;
                bar.style.width = pct + '%';
            }
            function appendLog(text) {
                if (!text) return;
                log.style.display = 'block';
                log.textContent += text + "\n";
                log.scrollTop = log.scrollHeight;
            }
            async function post(action, data) {
                const body = new URLSearchParams({ action: action, _ajax_nonce: nonce });
                Object.keys(data || {}).forEach(key => body.set(key, data[key]));
                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                });
                const json = await response.json();
                if (!response.ok || !json.success) {
                    throw new Error((json.data && json.data.message) ? json.data.message : 'Request failed.');
                }
                return json.data;
            }
            async function run() {
                running = true;
                start.disabled = true;
                stop.disabled = false;
                offset = 0;
                success = 0;
                failed = 0;
                log.textContent = '';
                log.style.display = 'none';
                setProgress();
                setStatus('準備中...');
                try {
                    const prepared = await post('cdn_drive_sync_prepare', {});
                    total = prepared.total || 0;
                    if (total <= 0) {
                        setStatus('同期対象がありません。');
                        running = false;
                    }
                    const limit = Math.max(1, Math.min(10, parseInt(limitInput.value || '1', 10)));
                    while (running && offset < total) {
                        setStatus('転送中... ' + offset + ' / ' + total + ' 件');
                        const result = await post('cdn_drive_sync_step', { offset: offset, limit: limit });
                        offset = result.next_offset;
                        total = result.total;
                        success += result.success;
                        failed += result.failed;
                        if (result.message) appendLog(result.message);
                        setProgress();
                        if (result.done) break;
                        await new Promise(resolve => setTimeout(resolve, 250));
                    }
                    setStatus(running ? '完了: success ' + success + ', failed ' + failed : '停止しました: success ' + success + ', failed ' + failed);
                } catch (error) {
                    appendLog(error.message);
                    setStatus('エラー: ' + error.message);
                } finally {
                    running = false;
                    start.disabled = false;
                    stop.disabled = true;
                    setProgress();
                }
            }
            start.addEventListener('click', run);
            stop.addEventListener('click', function() {
                running = false;
                setStatus('停止中...');
            });
        })();
        </script>
        <?php
    }

    public static function sanitizeOptions($value): array
    {
        $value = is_array($value) ? $value : [];
        return [
            'api_base' => esc_url_raw(trim((string)($value['api_base'] ?? ''))),
            'api_token' => sanitize_text_field((string)($value['api_token'] ?? '')),
            'cdn_base_url' => esc_url_raw(trim((string)($value['cdn_base_url'] ?? ''))),
            'path_prefix' => self::cleanPrefix((string)($value['path_prefix'] ?? 'wp')),
            'replace_urls' => empty($value['replace_urls']) ? '0' : '1',
            'auto_sync' => empty($value['auto_sync']) ? '0' : '1',
        ];
    }

    public static function settingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $options = self::options();
        ?>
        <div class="wrap">
            <h1>CDN Drive Sync</h1>
            <?php self::renderLastResult(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('cdn_drive_sync'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cdn_drive_api_base">CDN Drive External API</label></th>
                        <td>
                            <input id="cdn_drive_api_base" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[api_base]" value="<?php echo esc_attr($options['api_base']); ?>" placeholder="https://example.com/api/external">
                            <p class="description">CDN Drive 管理画面に表示される WordPress 連携 Endpoint を入力します。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cdn_drive_api_token">API Token</label></th>
                        <td>
                            <input id="cdn_drive_api_token" class="regular-text" type="password" name="<?php echo esc_attr(self::OPTION); ?>[api_token]" value="<?php echo esc_attr($options['api_token']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cdn_drive_cdn_base">CDN Base URL</label></th>
                        <td>
                            <input id="cdn_drive_cdn_base" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[cdn_base_url]" value="<?php echo esc_attr($options['cdn_base_url']); ?>" placeholder="https://cdn.example.com">
                            <p class="description">WordPress が出力するメディア URL の置換先です。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cdn_drive_path_prefix">Remote Path Prefix</label></th>
                        <td>
                            <input id="cdn_drive_path_prefix" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[path_prefix]" value="<?php echo esc_attr($options['path_prefix']); ?>" placeholder="wp">
                            <p class="description">通常は <code>wp</code> のままで使います。CDN Drive には <code>wp/2026/07/image.jpg</code> のように保存されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Behavior</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auto_sync]" value="1" <?php checked($options['auto_sync'], '1'); ?>> Upload/Regenerate 時に自動同期する</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[replace_urls]" value="1" <?php checked($options['replace_urls'], '1'); ?>> WordPress の画像 URL と srcset を CDN URL に置換する</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>
            <h2>Connection Test</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 1em;">
                <?php wp_nonce_field('cdn_drive_test_connection'); ?>
                <input type="hidden" name="action" value="cdn_drive_test_connection">
                <?php submit_button('Test CDN Drive Connection', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 1em;">
                <?php wp_nonce_field('cdn_drive_test_upload'); ?>
                <input type="hidden" name="action" value="cdn_drive_test_upload">
                <?php submit_button('Test Upload and Delete', 'secondary', 'submit', false); ?>
            </form>

            <hr>
            <h2>Existing Media Sync</h2>
            <p>既存のメディアライブラリを CDN Drive に同期します。画像の場合は WordPress が生成した各サイズも同期されます。</p>
            <?php self::renderProgressSyncPanel(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 1em;">
                <?php wp_nonce_field('cdn_drive_sync_batch'); ?>
                <input type="hidden" name="action" value="cdn_drive_sync_batch">
                <input type="hidden" name="offset" value="<?php echo esc_attr(absint($_GET['cdn_drive_next_offset'] ?? 0)); ?>">
                <label for="cdn_drive_batch_limit">Batch size</label>
                <input id="cdn_drive_batch_limit" name="limit" type="number" min="1" max="100" value="10">
                <?php submit_button(absint($_GET['cdn_drive_next_offset'] ?? 0) > 0 ? 'Continue Batch Sync' : 'Start Batch Sync', 'primary', 'submit', false); ?>
            </form>

            <hr>
            <h2>Single Attachment Sync</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('cdn_drive_sync_attachment'); ?>
                <input type="hidden" name="action" value="cdn_drive_sync_attachment">
                <label for="cdn_drive_attachment_id">Attachment ID</label>
                <input id="cdn_drive_attachment_id" name="attachment_id" type="number" min="1">
                <?php submit_button('Sync Attachment', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    public static function handleTestConnection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('cdn_drive_test_connection');
        $result = self::requestGet('/ping');
        $message = is_wp_error($result)
            ? 'Connection failed: ' . $result->get_error_message()
            : 'Connection OK: ' . ($result['service'] ?? 'CDN Drive External API');
        self::redirectWithNotice($message, 30);
    }

    public static function handleTestUpload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('cdn_drive_test_upload');

        $temp = wp_tempnam('cdn-drive-test.txt');
        if (!$temp) {
            self::redirectWithNotice('Test upload failed: could not create a temporary file.', 30);
        }

        $remotePath = trim(self::options()['path_prefix'], '/') . '/cdn-drive-test.txt';
        file_put_contents($temp, 'CDN Drive WordPress upload check ' . gmdate('c'));
        $upload = self::uploadFile($temp, $remotePath);
        if (is_wp_error($upload)) {
            @unlink($temp);
            $message = 'Test upload failed: ' . $upload->get_error_message();
            self::debugLog($message);
            self::redirectWithNotice($message, 60);
        }

        $delete = self::requestJson('/delete', ['path' => $remotePath]);
        @unlink($temp);
        if (is_wp_error($delete)) {
            $message = 'Test upload succeeded, but cleanup failed: ' . $delete->get_error_message();
            self::debugLog($message);
            self::redirectWithNotice($message, 60);
        }

        self::redirectWithNotice('Test upload OK. CDN Drive accepted multipart upload and delete requests.', 30);
    }

    public static function syncAttachmentMetadata($metadata, int $attachmentId)
    {
        $options = self::options();
        if ($options['auto_sync'] !== '1') {
            return $metadata;
        }
        self::syncAttachment($attachmentId);
        return $metadata;
    }

    public static function syncAttachmentAdded(int $attachmentId): void
    {
        $options = self::options();
        if ($options['auto_sync'] === '1') {
            self::syncAttachment($attachmentId);
        }
    }

    public static function handleManualSync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('cdn_drive_sync_attachment');
        $attachmentId = absint($_REQUEST['attachment_id'] ?? 0);
        if ($attachmentId <= 0) {
            self::redirectWithNotice('Attachment ID が正しくありません。', 30);
        }
        $result = self::syncAttachment($attachmentId);
        $message = is_wp_error($result) ? $result->get_error_message() : 'Attachment synced: ' . (string)$attachmentId;
        self::redirectWithNotice($message, 30);
    }

    public static function ajaxProgressPrepare(): void
    {
        self::checkAjaxPermission();
        $query = self::attachmentQuery(0, 1);
        wp_send_json_success([
            'total' => (int)$query->found_posts,
        ]);
    }

    public static function ajaxProgressStep(): void
    {
        self::checkAjaxPermission();
        $offset = max(0, absint($_POST['offset'] ?? 0));
        $limit = max(1, min(10, absint($_POST['limit'] ?? 1)));
        $query = self::attachmentQuery($offset, $limit);
        $success = 0;
        $failed = 0;
        $messages = [];

        foreach ($query->posts as $attachmentId) {
            $result = self::syncAttachment((int)$attachmentId);
            if (is_wp_error($result)) {
                $failed++;
                $messages[] = '#' . (int)$attachmentId . ' ' . $result->get_error_message();
            } else {
                $success++;
            }
        }

        $processed = count($query->posts);
        $nextOffset = $offset + $processed;
        $total = (int)$query->found_posts;
        $done = $processed <= 0 || $nextOffset >= $total;
        $message = 'Step: success ' . $success . ', failed ' . $failed . ', processed ' . $nextOffset . ' / ' . $total . '.';
        if ($messages) {
            $message .= "\n" . implode("\n", $messages);
        }
        self::storeNotice($message, 10 * MINUTE_IN_SECONDS);
        wp_send_json_success([
            'next_offset' => $nextOffset,
            'total' => $total,
            'done' => $done,
            'success' => $success,
            'failed' => $failed,
            'message' => $message,
        ]);
    }

    public static function mediaColumns(array $columns): array
    {
        $columns['cdn_drive_sync'] = 'CDN Drive';
        return $columns;
    }

    public static function mediaColumnContent(string $columnName, int $attachmentId): void
    {
        if ($columnName !== 'cdn_drive_sync') {
            return;
        }
        $status = (string)get_post_meta($attachmentId, '_cdn_drive_sync_status', true);
        $message = (string)get_post_meta($attachmentId, '_cdn_drive_sync_message', true);
        $at = (string)get_post_meta($attachmentId, '_cdn_drive_sync_at', true);
        if ($status === '') {
            echo '<span style="color:#666">Not synced</span>';
            return;
        }
        $color = $status === 'synced' ? '#008a20' : '#b32d2e';
        echo '<strong style="color:' . esc_attr($color) . '">' . esc_html($status) . '</strong>';
        if ($at !== '') {
            echo '<br><small>' . esc_html($at) . '</small>';
        }
        if ($message !== '') {
            echo '<br><small title="' . esc_attr($message) . '">' . esc_html(wp_html_excerpt($message, 80, '...')) . '</small>';
        }
    }

    public static function mediaRowActions(array $actions, WP_Post $post): array
    {
        if ($post->post_type !== 'attachment' || !current_user_can('manage_options')) {
            return $actions;
        }
        $url = wp_nonce_url(
            add_query_arg([
                'action' => 'cdn_drive_sync_attachment',
                'attachment_id' => (int)$post->ID,
            ], admin_url('admin-post.php')),
            'cdn_drive_sync_attachment'
        );
        $actions['cdn_drive_sync'] = '<a href="' . esc_url($url) . '">Sync to CDN Drive</a>';
        return $actions;
    }

    public static function handleBatchSync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('cdn_drive_sync_batch');
        $offset = max(0, absint($_POST['offset'] ?? 0));
        $limit = max(1, min(100, absint($_POST['limit'] ?? 10)));
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $success = 0;
        $failed = 0;
        $messages = [];
        foreach ($query->posts as $attachmentId) {
            $result = self::syncAttachment((int)$attachmentId);
            if (is_wp_error($result)) {
                $failed++;
                $messages[] = '#' . (int)$attachmentId . ' ' . $result->get_error_message();
            } else {
                $success++;
            }
        }

        $processed = count($query->posts);
        $nextOffset = $offset + $processed;
        $total = (int)$query->found_posts;
        $message = 'Batch sync: success ' . $success . ', failed ' . $failed . ', processed ' . $nextOffset . ' / ' . $total . '.';
        if ($messages) {
            $message .= ' First errors: ' . implode(' | ', array_slice($messages, 0, 3));
        }
        if ($processed > 0 && $nextOffset < $total) {
            $message .= ' Continue the next batch.';
            $redirect = add_query_arg(['page' => 'cdn-drive-sync', 'cdn_drive_next_offset' => $nextOffset], admin_url('options-general.php'));
        } else {
            $message .= ' Completed.';
            $redirect = admin_url('options-general.php?page=cdn-drive-sync');
        }
        self::storeNotice($message, 60);
        wp_safe_redirect($redirect);
        exit;
    }

    public static function deleteAttachment(int $attachmentId): void
    {
        $paths = [];
        foreach (self::attachmentFiles($attachmentId) as $file) {
            $paths[] = $file['remote'];
        }
        if ($paths) {
            self::requestJson('/delete', ['paths' => array_values(array_unique($paths))]);
        }
    }

    public static function filterAttachmentUrl($url, $attachmentId)
    {
        return self::toCdnUrl((string)$url);
    }

    public static function filterSrcset($sources)
    {
        if (!is_array($sources)) {
            return $sources;
        }
        foreach ($sources as &$source) {
            if (isset($source['url'])) {
                $source['url'] = self::toCdnUrl((string)$source['url']);
            }
        }
        unset($source);
        return $sources;
    }

    private static function syncAttachment(int $attachmentId)
    {
        $files = self::attachmentFiles($attachmentId);
        if (!$files) {
            $error = new WP_Error('cdn_drive_no_files', '同期対象ファイルがありません。');
            self::storeSyncStatus($attachmentId, 'failed', $error->get_error_message());
            return $error;
        }
        $errors = [];
        foreach ($files as $file) {
            $result = self::uploadFile($file['local'], $file['remote']);
            if (is_wp_error($result)) {
                $errors[] = $file['remote'] . ': ' . $result->get_error_message();
            }
        }
        if ($errors) {
            $error = new WP_Error('cdn_drive_sync_failed', implode("\n", $errors));
            self::storeSyncStatus($attachmentId, 'failed', $error->get_error_message());
            self::debugLog('Attachment #' . $attachmentId . ' sync failed. ' . str_replace("\n", ' | ', $error->get_error_message()));
            return $error;
        }
        self::storeSyncStatus($attachmentId, 'synced', 'Synced ' . count($files) . ' file(s).');
        return true;
    }

    private static function attachmentQuery(int $offset, int $limit): WP_Query
    {
        return new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
    }

    private static function checkAjaxPermission(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        if (!check_ajax_referer('cdn_drive_sync_progress', false, false)) {
            wp_send_json_error(['message' => 'Invalid request token. Reload this page and try again.'], 403);
        }
    }

    private static function storeSyncStatus(int $attachmentId, string $status, string $message): void
    {
        update_post_meta($attachmentId, '_cdn_drive_sync_status', $status);
        update_post_meta($attachmentId, '_cdn_drive_sync_message', $message);
        update_post_meta($attachmentId, '_cdn_drive_sync_at', current_time('mysql'));
    }

    private static function attachmentFiles(int $attachmentId): array
    {
        $upload = wp_get_upload_dir();
        $baseDir = wp_normalize_path($upload['basedir']);
        $files = [];

        $attached = get_attached_file($attachmentId);
        if ($attached && is_file($attached)) {
            $relative = self::relativeToUploads($attached, $baseDir);
            if ($relative !== '') {
                $files[$relative] = ['local' => $attached, 'remote' => self::remotePath($relative)];
            }
        }

        $metadata = wp_get_attachment_metadata($attachmentId);
        if (is_array($metadata) && !empty($metadata['file'])) {
            $mainRelative = wp_normalize_path((string)$metadata['file']);
            $mainLocal = trailingslashit($baseDir) . $mainRelative;
            if (is_file($mainLocal)) {
                $files[$mainRelative] = ['local' => $mainLocal, 'remote' => self::remotePath($mainRelative)];
            }
            $dir = dirname($mainRelative);
            $dir = $dir === '.' ? '' : $dir . '/';
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size) {
                    if (empty($size['file'])) {
                        continue;
                    }
                    $relative = $dir . wp_normalize_path((string)$size['file']);
                    $local = trailingslashit($baseDir) . $relative;
                    if (is_file($local)) {
                        $files[$relative] = ['local' => $local, 'remote' => self::remotePath($relative)];
                    }
                }
            }
            if (!empty($metadata['original_image'])) {
                $original = wp_normalize_path((string)$metadata['original_image']);
                $originalCandidates = str_contains($original, '/')
                    ? [$original, $dir . basename($original)]
                    : [$dir . $original];
                foreach (array_unique($originalCandidates) as $relative) {
                    $local = trailingslashit($baseDir) . $relative;
                    if (is_file($local)) {
                        $files[$relative] = ['local' => $local, 'remote' => self::remotePath($relative)];
                    }
                }
            }
            $unscaledRelative = preg_replace('/-scaled(?=\.[^.]+$)/', '', $mainRelative);
            if (is_string($unscaledRelative) && $unscaledRelative !== $mainRelative) {
                $local = trailingslashit($baseDir) . $unscaledRelative;
                if (is_file($local)) {
                    $files[$unscaledRelative] = ['local' => $local, 'remote' => self::remotePath($unscaledRelative)];
                }
            }
        }

        return array_values($files);
    }

    private static function uploadFile(string $localPath, string $remotePath)
    {
        if (!function_exists('curl_init') || !function_exists('curl_file_create')) {
            return new WP_Error('cdn_drive_curl_missing', 'PHP cURL が必要です。');
        }
        $options = self::options();
        if ($options['api_base'] === '' || $options['api_token'] === '') {
            return new WP_Error('cdn_drive_not_configured', 'CDN Drive API 設定が不足しています。');
        }
        $mime = self::fileMimeType($localPath);
        $size = is_file($localPath) ? (int)filesize($localPath) : 0;
        if ($size > 1024 * 1024) {
            return self::uploadFileInChunks($localPath, $remotePath, $mime, $size, $options);
        }
        $timeout = max(300, min(3600, (int)ceil(max(1, $size) / (1024 * 1024)) * 15));
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 30);
        }
        $ch = curl_init(trailingslashit($options['api_base']) . 'upload');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $options['api_token'],
                'X-CDN-Drive-Token: ' . $options['api_token'],
                'Expect:',
            ],
            CURLOPT_POSTFIELDS => [
                'path' => $remotePath,
                'token' => $options['api_token'],
                'file' => curl_file_create($localPath, $mime, basename($localPath)),
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            return new WP_Error('cdn_drive_http_error', $error ?: 'Upload request failed.');
        }
        $data = json_decode((string)$response, true);
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['ok'])) {
            $message = is_array($data) && isset($data['error']) ? (string)$data['error'] : self::bodySnippet((string)$response);
            if ($status === 0 && $error !== '') {
                $message = $error;
            }
            $detail = 'Upload failed for ' . $remotePath . '. HTTP ' . $status . ': ' . $message;
            self::debugLog($detail);
            return new WP_Error('cdn_drive_upload_failed', $detail);
        }
        return true;
    }

    private static function requestJson(string $path, array $payload)
    {
        $options = self::options();
        if ($options['api_base'] === '' || $options['api_token'] === '') {
            return new WP_Error('cdn_drive_not_configured', 'CDN Drive API 設定が不足しています。');
        }
        $response = wp_remote_post(trailingslashit($options['api_base']) . ltrim($path, '/'), [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $options['api_token'],
                'X-CDN-Drive-Token' => $options['api_token'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['ok'])) {
            $message = is_array($data) && isset($data['error']) ? (string)$data['error'] : self::bodySnippet($body);
            $detail = 'HTTP ' . $status . ': ' . $message;
            self::debugLog('Request failed for ' . $path . '. ' . $detail);
            return new WP_Error('cdn_drive_request_failed', $detail);
        }
        return $data;
    }

    private static function fileMimeType(string $localPath): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($localPath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
        $type = wp_check_filetype($localPath);
        return is_array($type) && !empty($type['type']) ? (string)$type['type'] : 'application/octet-stream';
    }

    private static function requestGet(string $path)
    {
        $options = self::options();
        if ($options['api_base'] === '' || $options['api_token'] === '') {
            return new WP_Error('cdn_drive_not_configured', 'CDN Drive API 設定が不足しています。');
        }
        $response = wp_remote_get(trailingslashit($options['api_base']) . ltrim($path, '/'), [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $options['api_token'],
                'X-CDN-Drive-Token' => $options['api_token'],
            ],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['ok'])) {
            $message = is_array($data) && isset($data['error']) ? (string)$data['error'] : self::bodySnippet($body);
            $detail = 'HTTP ' . $status . ': ' . $message;
            self::debugLog('Request failed for ' . $path . '. ' . $detail);
            return new WP_Error('cdn_drive_request_failed', $detail);
        }
        return $data;
    }

    private static function toCdnUrl(string $url): string
    {
        $options = self::options();
        if ($options['replace_urls'] !== '1' || $options['cdn_base_url'] === '') {
            return $url;
        }
        $upload = wp_get_upload_dir();
        $baseUrl = trailingslashit((string)$upload['baseurl']);
        if (!str_starts_with($url, $baseUrl)) {
            return $url;
        }
        $relative = ltrim(substr($url, strlen($baseUrl)), '/');
        if ($relative === '') {
            return $url;
        }
        return trailingslashit($options['cdn_base_url']) . self::remotePath($relative);
    }

    private static function relativeToUploads(string $file, string $baseDir): string
    {
        $file = wp_normalize_path($file);
        $baseDir = trailingslashit(wp_normalize_path($baseDir));
        if (!str_starts_with($file, $baseDir)) {
            return '';
        }
        return ltrim(substr($file, strlen($baseDir)), '/');
    }

    private static function remotePath(string $relative): string
    {
        return trim(self::options()['path_prefix'], '/') . '/' . ltrim(wp_normalize_path($relative), '/');
    }

    private static function cleanPrefix(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        $prefix = preg_replace('#/+#', '/', $prefix) ?: 'wp';
        $prefix = preg_replace('/[^A-Za-z0-9._\/-]/', '-', $prefix) ?: 'wp';
        return trim($prefix, '/') ?: 'wp';
    }

    private static function bodySnippet(string $body): string
    {
        $body = trim(wp_strip_all_tags($body));
        if ($body === '') {
            return 'Empty response body.';
        }
        return wp_html_excerpt($body, 500, '...');
    }

    private static function redirectWithNotice(string $message, int $ttl = 60): void
    {
        self::storeNotice($message, $ttl);
        wp_safe_redirect(admin_url('options-general.php?page=cdn-drive-sync&cdn_drive_notice=1'));
        exit;
    }

    private static function storeNotice(string $message, int $ttl = 60): void
    {
        set_transient(self::NOTICE_OPTION, $message, $ttl);
        update_option(self::LAST_RESULT_OPTION, [
            'message' => $message,
            'time' => current_time('mysql'),
        ], false);
        update_option(self::NOTICE_OPTION, [
            'message' => $message,
            'expires' => time() + $ttl,
        ], false);
    }

    private static function pullNotice(): string
    {
        $notice = get_transient(self::NOTICE_OPTION);
        delete_transient(self::NOTICE_OPTION);
        if (is_string($notice) && $notice !== '') {
            delete_option(self::NOTICE_OPTION);
            return $notice;
        }

        $stored = get_option(self::NOTICE_OPTION);
        delete_option(self::NOTICE_OPTION);
        if (!is_array($stored)) {
            return '';
        }
        if ((int)($stored['expires'] ?? 0) < time()) {
            return '';
        }
        return trim((string)($stored['message'] ?? ''));
    }

    private static function noticeClass(string $message): string
    {
        return preg_match('/failed|error|invalid|required|forbidden|失敗|エラー|正しくありません/i', $message)
            ? 'notice-error'
            : 'notice-info';
    }

    private static function debugLog(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[CDN Drive Sync] ' . $message);
        }
    }

    private static function latestGitHubRelease(): ?array
    {
        $cacheKey = 'cdn_drive_sync_latest_release';
        $cached = get_site_transient($cacheKey);
        if (is_array($cached) && isset($cached['tag_name'])) {
            return $cached;
        }

        $url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_OWNER, self::GITHUB_REPO);
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/CDN-Drive-Sync',
            ],
        ]);

        if (is_wp_error($response)) {
            self::debugLog('GitHub release check failed: ' . $response->get_error_message());
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code !== 200 || trim($body) === '') {
            self::debugLog('GitHub release check returned HTTP ' . $code);
            return null;
        }

        $release = json_decode($body, true);
        if (!is_array($release) || empty($release['tag_name'])) {
            self::debugLog('GitHub release payload is invalid.');
            return null;
        }

        set_site_transient($cacheKey, $release, HOUR_IN_SECONDS);
        return $release;
    }

    private static function pluginVersion(): string
    {
        static $version = null;
        if (is_string($version) && $version !== '') {
            return $version;
        }

        $version = '0.0.0';
        if (!function_exists('get_file_data')) {
            return $version;
        }

        $headers = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
        $candidate = trim((string)($headers['Version'] ?? ''));
        if ($candidate !== '') {
            $version = $candidate;
        }

        return $version;
    }

    private static function releaseAssetUrl(array $release): string
    {
        foreach ((array)($release['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if ((string)($asset['name'] ?? '') === self::GITHUB_ASSET) {
                return (string)($asset['browser_download_url'] ?? '');
            }
        }

        return '';
    }

    private static function projectUrl(): string
    {
        return 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;
    }

    private static function options(): array
    {
        return array_merge(self::defaults(), (array)get_option(self::OPTION, []));
    }

    private static function defaults(): array
    {
        return [
            'api_base' => '',
            'api_token' => '',
            'cdn_base_url' => '',
            'path_prefix' => 'wp',
            'replace_urls' => '1',
            'auto_sync' => '1',
        ];
    }
}

CDN_Drive_Sync::boot();
