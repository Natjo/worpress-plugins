<?php
/**
 * Plugin Name: WP CDN
 * Description: Synchronise les assets (uploads + thème) vers Cloudflare R2 et réécrit leurs URLs dans les pages statiques générées par WP Static.
 * Version: 1.0
 * Author: Votre Nom
 */

if (!defined('ABSPATH')) exit;

define('WP_CDN_OPTION', 'wp_cdn_settings');
define('WP_CDN_SYNC_TRANSIENT', 'wp_cdn_sync_manifest');
define('WP_CDN_SYNC_BATCH', 20);

/* -------------------------------------------------------------------------
 * Réglages
 * ---------------------------------------------------------------------- */

function wp_cdn_default_settings() {
    return [
        'enabled'        => false,
        'account_id'     => '',
        'access_key'     => '',
        'secret_key'     => '',
        'bucket'         => '',
        'cdn_url'        => '',
        'sync_uploads'   => true,
        'sync_theme'     => true,
    ];
}

function wp_cdn_get_settings() {
    $saved = get_option(WP_CDN_OPTION, []);
    if (!is_array($saved)) {
        $saved = [];
    }

    return array_merge(wp_cdn_default_settings(), $saved);
}

function wp_cdn_is_enabled() {
    $settings = wp_cdn_get_settings();
    return !empty($settings['enabled'])
        && $settings['account_id'] !== ''
        && $settings['access_key'] !== ''
        && $settings['secret_key'] !== ''
        && $settings['bucket'] !== ''
        && $settings['cdn_url'] !== '';
}

function wp_cdn_sanitize_settings($input) {
    $defaults = wp_cdn_default_settings();
    $current = wp_cdn_get_settings();
    $out = [];

    $out['enabled'] = !empty($input['enabled']);
    $out['account_id'] = isset($input['account_id']) ? sanitize_text_field($input['account_id']) : $defaults['account_id'];
    $out['access_key'] = isset($input['access_key']) ? sanitize_text_field($input['access_key']) : $defaults['access_key'];
    $out['bucket'] = isset($input['bucket']) ? sanitize_text_field($input['bucket']) : $defaults['bucket'];
    $out['cdn_url'] = isset($input['cdn_url']) ? esc_url_raw(trim($input['cdn_url'])) : $defaults['cdn_url'];
    $out['sync_uploads'] = !empty($input['sync_uploads']);
    $out['sync_theme'] = !empty($input['sync_theme']);

    // Ne pas écraser le secret si le champ est laissé vide (mot de passe masqué).
    $secret = isset($input['secret_key']) ? (string) $input['secret_key'] : '';
    $out['secret_key'] = ($secret !== '') ? $secret : $current['secret_key'];

    return $out;
}

function wp_cdn_cdn_base_url() {
    $settings = wp_cdn_get_settings();
    return untrailingslashit($settings['cdn_url']);
}

/* -------------------------------------------------------------------------
 * Racines d'assets (uploads + thème parent/enfant)
 * ---------------------------------------------------------------------- */

function wp_cdn_allowed_extensions() {
    return [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico',
        'css', 'js', 'mjs', 'map',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'json', 'xml', 'txt',
    ];
}

/**
 * @return array<int, array{dir:string, uri:string, prefix:string}>
 */
function wp_cdn_asset_roots() {
    $settings = wp_cdn_get_settings();
    $roots = [];

    if (!empty($settings['sync_uploads'])) {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['basedir']) && !empty($uploads['baseurl']) && is_dir($uploads['basedir'])) {
            $roots[] = [
                'dir'    => wp_normalize_path($uploads['basedir']),
                'uri'    => untrailingslashit($uploads['baseurl']),
                'prefix' => 'uploads',
            ];
        }
    }

    if (!empty($settings['sync_theme'])) {
        $template_dir = get_template_directory();
        $stylesheet_dir = get_stylesheet_directory();
        $template_uri = get_template_directory_uri();
        $stylesheet_uri = get_stylesheet_directory_uri();

        if ($template_dir && is_dir($template_dir)) {
            $roots[] = [
                'dir'    => wp_normalize_path($template_dir),
                'uri'    => untrailingslashit($template_uri),
                'prefix' => 'theme/' . basename($template_dir),
            ];
        }

        if ($stylesheet_dir && $stylesheet_dir !== $template_dir && is_dir($stylesheet_dir)) {
            $roots[] = [
                'dir'    => wp_normalize_path($stylesheet_dir),
                'uri'    => untrailingslashit($stylesheet_uri),
                'prefix' => 'theme/' . basename($stylesheet_dir),
            ];
        }
    }

    return $roots;
}

/**
 * Convertit une URL locale connue (uploads ou thème) vers son URL CDN.
 * Si le CDN est désactivé ou si l'URL ne fait pas partie du périmètre, elle
 * est retournée inchangée.
 */
function wp_cdn_url($url) {
    if (!is_string($url) || $url === '' || !wp_cdn_is_enabled()) {
        return $url;
    }

    $cdn = wp_cdn_cdn_base_url();
    if ($cdn === '') {
        return $url;
    }

    foreach (wp_cdn_asset_roots() as $root) {
        $origin = untrailingslashit($root['uri']);
        if (strpos($url, $origin . '/') !== 0) {
            continue;
        }

        $relative = ltrim(substr($url, strlen($origin) + 1), '/');
        if ($relative === '') {
            return $url;
        }

        return $cdn . '/' . $root['prefix'] . '/' . $relative;
    }

    return $url;
}

function wp_cdn_is_allowed_file($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, wp_cdn_allowed_extensions(), true);
}

/**
 * Construit la liste des fichiers à synchroniser.
 *
 * @return array<int, array{path:string, key:string, uri:string}>
 */
function wp_cdn_build_manifest() {
    $manifest = [];
    $seen = [];

    foreach (wp_cdn_asset_roots() as $root) {
        if (!is_dir($root['dir'])) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root['dir'], FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !wp_cdn_is_allowed_file($file->getPathname())) {
                continue;
            }

            $path = wp_normalize_path($file->getPathname());
            $relative = ltrim(substr($path, strlen(trailingslashit($root['dir']))), '/');
            if ($relative === '') {
                continue;
            }

            $key = $root['prefix'] . '/' . $relative;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $manifest[] = [
                'path' => $path,
                'key'  => $key,
                'uri'  => $root['uri'] . '/' . $relative,
            ];
        }
    }

    usort($manifest, function ($a, $b) {
        return strcmp($a['key'], $b['key']);
    });

    return $manifest;
}

/* -------------------------------------------------------------------------
 * Client R2 (API S3-compatible, signature AWS SigV4)
 * ---------------------------------------------------------------------- */

function wp_cdn_r2_endpoint() {
    $settings = wp_cdn_get_settings();
    return 'https://' . $settings['account_id'] . '.r2.cloudflarestorage.com';
}

function wp_cdn_r2_canonical_uri($key) {
    $segments = array_filter(explode('/', (string) $key), 'strlen');
    if (empty($segments)) {
        return '';
    }

    return '/' . implode('/', array_map('rawurlencode', $segments));
}

function wp_cdn_r2_sign($method, $key, $query = [], $headers = [], $payload_hash = 'UNSIGNED-PAYLOAD') {
    $settings = wp_cdn_get_settings();
    $host = $settings['account_id'] . '.r2.cloudflarestorage.com';
    $bucket = $settings['bucket'];
    $access_key = $settings['access_key'];
    $secret_key = $settings['secret_key'];

    $now = gmdate('Ymd\THis\Z');
    $date = gmdate('Ymd');
    $region = 'auto';
    $service = 's3';

    $canonical_uri = '/' . rawurlencode($bucket) . wp_cdn_r2_canonical_uri($key);

    ksort($query);
    $canonical_query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $headers['host'] = $host;
    $headers['x-amz-content-sha256'] = $payload_hash;
    $headers['x-amz-date'] = $now;
    ksort($headers);

    $canonical_headers = '';
    $signed_headers_list = [];
    foreach ($headers as $name => $value) {
        $name = strtolower($name);
        $canonical_headers .= $name . ':' . trim((string) $value) . "\n";
        $signed_headers_list[] = $name;
    }
    $signed_headers = implode(';', $signed_headers_list);

    $canonical_request = implode("\n", [
        strtoupper($method),
        $canonical_uri,
        $canonical_query,
        $canonical_headers,
        $signed_headers,
        $payload_hash,
    ]);

    $credential_scope = $date . '/' . $region . '/' . $service . '/aws4_request';
    $string_to_sign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $now,
        $credential_scope,
        hash('sha256', $canonical_request),
    ]);

    $k_date = hash_hmac('sha256', $date, 'AWS4' . $secret_key, true);
    $k_region = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', $service, $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

    $authorization = 'AWS4-HMAC-SHA256 Credential=' . $access_key . '/' . $credential_scope
        . ', SignedHeaders=' . $signed_headers
        . ', Signature=' . $signature;

    $url = wp_cdn_r2_endpoint() . $canonical_uri;
    if ($canonical_query !== '') {
        $url .= '?' . $canonical_query;
    }

    $request_headers = [
        'Authorization'            => $authorization,
        'x-amz-date'               => $now,
        'x-amz-content-sha256'     => $payload_hash,
    ];
    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'host') {
            continue;
        }
        $request_headers[$name] = $value;
    }

    return [
        'url'     => $url,
        'headers' => $request_headers,
    ];
}

/**
 * @return array{ok:bool, code:int, message:string}
 */
function wp_cdn_r2_request($method, $key, $args = []) {
    $body = isset($args['body']) ? $args['body'] : null;
    $query = isset($args['query']) ? $args['query'] : [];
    $content_type = isset($args['content_type']) ? $args['content_type'] : '';

    $headers = [];
    if ($content_type !== '') {
        $headers['content-type'] = $content_type;
    }

    if ($body === null) {
        $payload_hash = 'UNSIGNED-PAYLOAD';
    } else {
        $payload_hash = hash('sha256', $body);
        $headers['content-length'] = (string) strlen($body);
    }

    $signed = wp_cdn_r2_sign($method, $key, $query, $headers, $payload_hash);

    $request_args = [
        'method'  => strtoupper($method),
        'headers' => $signed['headers'],
        'timeout' => 60,
    ];
    if ($body !== null) {
        $request_args['body'] = $body;
    }

    $response = wp_remote_request($signed['url'], $request_args);
    if (is_wp_error($response)) {
        return [
            'ok'      => false,
            'code'    => 0,
            'message' => $response->get_error_message(),
        ];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $ok = ($code >= 200 && $code < 300) || ($method === 'HEAD' && $code === 404);

    $message = '';
    if (!$ok) {
        $body_resp = wp_remote_retrieve_body($response);
        $message = $body_resp !== '' ? wp_strip_all_tags($body_resp) : 'Erreur HTTP ' . $code;
        if (strlen($message) > 300) {
            $message = substr($message, 0, 300) . '…';
        }
    }

    return [
        'ok'      => $ok,
        'code'    => $code,
        'message' => $message,
    ];
}

function wp_cdn_mime_type($path) {
    $type = wp_check_filetype(basename($path));
    if (!empty($type['type'])) {
        return $type['type'];
    }

    return 'application/octet-stream';
}

/**
 * @return array{ok:bool, message:string}
 */
function wp_cdn_r2_upload_file($local_path, $key) {
    if (!file_exists($local_path) || !is_readable($local_path)) {
        return ['ok' => false, 'message' => 'Fichier local introuvable'];
    }

    $size = filesize($local_path);
    if ($size === false) {
        return ['ok' => false, 'message' => 'Impossible de lire la taille du fichier'];
    }
    if ($size > 50 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Fichier trop volumineux (> 50 Mo)'];
    }

    $body = file_get_contents($local_path);
    if ($body === false) {
        return ['ok' => false, 'message' => 'Lecture du fichier impossible'];
    }

    $result = wp_cdn_r2_request('PUT', $key, [
        'body'         => $body,
        'content_type' => wp_cdn_mime_type($local_path),
    ]);

    return [
        'ok'      => $result['ok'],
        'message' => $result['message'],
    ];
}

/**
 * @return array{ok:bool, message:string}
 */
function wp_cdn_r2_test_connection() {
    $settings = wp_cdn_get_settings();
    if ($settings['account_id'] === '' || $settings['access_key'] === '' || $settings['secret_key'] === '' || $settings['bucket'] === '') {
        return ['ok' => false, 'message' => 'Renseignez Account ID, clés API et bucket.'];
    }

    $result = wp_cdn_r2_request('HEAD', '');
    if ($result['ok']) {
        return ['ok' => true, 'message' => 'Connexion R2 OK (bucket accessible).'];
    }

    return ['ok' => false, 'message' => $result['message'] ?: 'Connexion impossible'];
}

/* -------------------------------------------------------------------------
 * Réécriture des URLs dans le HTML statique (WP Static)
 * ---------------------------------------------------------------------- */

add_filter('wp_static_html', 'wp_cdn_rewrite_static_html', 10, 2);

function wp_cdn_rewrite_static_html($html, $url = '') {
    if (!wp_cdn_is_enabled() || !is_string($html) || $html === '') {
        return $html;
    }

    $cdn = wp_cdn_cdn_base_url();
    if ($cdn === '') {
        return $html;
    }

    foreach (wp_cdn_asset_roots() as $root) {
        $html = wp_cdn_replace_asset_urls($html, $root['uri'], $cdn . '/' . $root['prefix']);
    }

    return $html;
}

/* -------------------------------------------------------------------------
 * Réécriture en dynamique : assets enqueued (CSS / JS du thème).
 * ---------------------------------------------------------------------- */

add_filter('style_loader_src', 'wp_cdn_rewrite_enqueued_src', 20);
add_filter('script_loader_src', 'wp_cdn_rewrite_enqueued_src', 20);

function wp_cdn_rewrite_enqueued_src($src) {
    if (is_admin() || !is_string($src) || $src === '' || !wp_cdn_is_enabled()) {
        return $src;
    }

    return wp_cdn_url($src);
}

function wp_cdn_replace_asset_urls($html, $origin_uri, $cdn_prefix) {
    $origin_uri = untrailingslashit($origin_uri);
    $cdn_prefix = untrailingslashit($cdn_prefix);

    $origin_quoted = preg_quote($origin_uri, '/');
    $patterns = [
        '/' . $origin_quoted . '\/([^"\'\s>?#]+)/i',
        '/' . $origin_quoted . '\\\\\/([^"\'\s>?#]+)/i',
    ];

    foreach ($patterns as $pattern) {
        $html = preg_replace_callback($pattern, function ($m) use ($cdn_prefix) {
            $relative = str_replace('\\/', '/', $m[1]);
            return $cdn_prefix . '/' . $relative;
        }, $html);
    }

    return $html;
}

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'wp_cdn_admin_menu');
add_action('admin_init', 'wp_cdn_register_settings');

function wp_cdn_admin_menu() {
    add_menu_page(
        'WP CDN',
        'WP CDN',
        'manage_options',
        'wp-cdn',
        'wp_cdn_admin_page',
        'dashicons-cloud',
        // Position élevée pour regrouper les outils maison en bas de la sidebar.
        200
    );
}

function wp_cdn_register_settings() {
    register_setting('wp_cdn_settings_group', WP_CDN_OPTION, [
        'type'              => 'array',
        'sanitize_callback' => 'wp_cdn_sanitize_settings',
        'default'           => wp_cdn_default_settings(),
    ]);
}

function wp_cdn_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = wp_cdn_get_settings();
    $manifest_count = count(wp_cdn_build_manifest());
    ?>
    <div class="wrap">
        <h1>WP CDN</h1>

        <?php if (isset($_GET['settings-updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Paramètres enregistrés.</p></div>
        <?php endif; ?>

        <form method="post" action="options.php" id="wp-cdn-settings-form">
            <?php settings_fields('wp_cdn_settings_group'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Activer le CDN</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(WP_CDN_OPTION); ?>[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                            Réécrire les URLs des assets vers le CDN dans les pages statiques
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp-cdn-account">Account ID</label></th>
                    <td>
                        <input type="text" class="regular-text" id="wp-cdn-account" name="<?php echo esc_attr(WP_CDN_OPTION); ?>[account_id]" value="<?php echo esc_attr($settings['account_id']); ?>" autocomplete="off">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp-cdn-access-key">Access Key ID</label></th>
                    <td>
                        <input type="text" class="regular-text" id="wp-cdn-access-key" name="<?php echo esc_attr(WP_CDN_OPTION); ?>[access_key]" value="<?php echo esc_attr($settings['access_key']); ?>" autocomplete="off">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp-cdn-secret-key">Secret Access Key</label></th>
                    <td>
                        <input type="password" class="regular-text" id="wp-cdn-secret-key" name="<?php echo esc_attr(WP_CDN_OPTION); ?>[secret_key]" value="" placeholder="<?php echo $settings['secret_key'] !== '' ? '••••••••••••' : ''; ?>" autocomplete="new-password">
                        <p class="description">Laissez vide pour conserver la clé actuelle.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp-cdn-bucket">Bucket R2</label></th>
                    <td>
                        <input type="text" class="regular-text" id="wp-cdn-bucket" name="<?php echo esc_attr(WP_CDN_OPTION); ?>[bucket]" value="<?php echo esc_attr($settings['bucket']); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wp-cdn-url">URL publique du CDN</label></th>
                    <td>
                        <input type="url" class="regular-text" id="wp-cdn-url" name="<?php echo esc_attr(WP_CDN_OPTION); ?>[cdn_url]" value="<?php echo esc_attr($settings['cdn_url']); ?>" placeholder="https://cdn.example.com">
                        <p class="description">Domaine public lié au bucket R2 (custom domain Cloudflare).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Assets à synchroniser</th>
                    <td>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="<?php echo esc_attr(WP_CDN_OPTION); ?>[sync_uploads]" value="1" <?php checked(!empty($settings['sync_uploads'])); ?>>
                            Dossier <code>uploads</code>
                        </label>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(WP_CDN_OPTION); ?>[sync_theme]" value="1" <?php checked(!empty($settings['sync_theme'])); ?>>
                            Assets du thème (parent + enfant)
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button('Enregistrer les paramètres'); ?>
        </form>

        <hr>

        <h2>Connexion</h2>
        <p>
            <button type="button" class="button" id="wp-cdn-test">Tester la connexion R2</button>
            <span id="wp-cdn-test-status" style="margin-left:10px;"></span>
        </p>

        <h2>Synchronisation</h2>
        <p>
            <strong><?php echo (int) $manifest_count; ?></strong> fichier(s) éligible(s) (uploads + thème).
        </p>
        <p>
            <button type="button" class="button button-primary" id="wp-cdn-sync">Synchroniser les assets vers R2</button>
        </p>
        <div id="wp-cdn-sync-progress" style="display:none;max-width:480px;margin-top:12px;">
            <div style="background:#dcdcde;border-radius:3px;height:18px;overflow:hidden;">
                <div id="wp-cdn-sync-bar" style="background:#2271b1;height:100%;width:0%;transition:width .2s;"></div>
            </div>
            <p id="wp-cdn-sync-status" style="margin-top:8px;"></p>
        </div>
        <div id="wp-cdn-sync-failures" style="margin-top:10px;"></div>
    </div>

    <script>
    (function () {
        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const testNonce = <?php echo wp_json_encode(wp_create_nonce('wp_cdn_test')); ?>;
        const syncNonce = <?php echo wp_json_encode(wp_create_nonce('wp_cdn_sync')); ?>;

        const testBtn = document.getElementById('wp-cdn-test');
        const testStatus = document.getElementById('wp-cdn-test-status');
        const syncBtn = document.getElementById('wp-cdn-sync');
        const progressWrap = document.getElementById('wp-cdn-sync-progress');
        const progressBar = document.getElementById('wp-cdn-sync-bar');
        const syncStatus = document.getElementById('wp-cdn-sync-status');
        const failuresEl = document.getElementById('wp-cdn-sync-failures');

        if (testBtn) {
            testBtn.addEventListener('click', function () {
                testBtn.disabled = true;
                testStatus.textContent = 'Test en cours…';
                const body = new URLSearchParams({ action: 'wp_cdn_test', nonce: testNonce });
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
                    .then(r => r.json())
                    .then(data => {
                        testStatus.textContent = data.success ? data.data.message : (data.data && data.data.message ? data.data.message : 'Erreur');
                        testStatus.style.color = data.success ? '#00a32a' : '#d63638';
                    })
                    .catch(() => { testStatus.textContent = 'Erreur réseau'; testStatus.style.color = '#d63638'; })
                    .finally(() => { testBtn.disabled = false; });
            });
        }

        function runSyncBatch(offset, total, uploaded, failures) {
            const body = new URLSearchParams({
                action: 'wp_cdn_sync',
                nonce: syncNonce,
                offset: String(offset),
            });

            return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error((data.data && data.data.message) ? data.data.message : 'Synchronisation impossible');
                    }
                    const d = data.data;
                    uploaded += d.uploaded || 0;
                    failures = failures.concat(d.failures || []);
                    const done = Math.min(d.offset || 0, total);
                    const pct = total ? Math.round((done / total) * 100) : 100;
                    progressBar.style.width = pct + '%';
                    syncStatus.textContent = done + ' / ' + total + ' fichier(s), ' + uploaded + ' envoyé(s)';

                    if (!d.done) {
                        return runSyncBatch(d.offset, total, uploaded, failures);
                    }

                    let html = '<p><strong>Terminé.</strong> ' + uploaded + ' fichier(s) synchronisé(s).</p>';
                    if (failures.length) {
                        html += '<p style="color:#d63638;">' + failures.length + ' échec(s) :</p><ul style="list-style:disc;margin-left:20px;">';
                        failures.slice(0, 20).forEach(f => {
                            html += '<li><code>' + f.key + '</code> — ' + f.error + '</li>';
                        });
                        if (failures.length > 20) {
                            html += '<li>… et ' + (failures.length - 20) + ' autre(s)</li>';
                        }
                        html += '</ul>';
                    }
                    failuresEl.innerHTML = html;
                    syncBtn.disabled = false;
                });
        }

        if (syncBtn) {
            syncBtn.addEventListener('click', function () {
                syncBtn.disabled = true;
                failuresEl.innerHTML = '';
                progressWrap.style.display = 'block';
                progressBar.style.width = '0%';
                syncStatus.textContent = 'Préparation…';

                const body = new URLSearchParams({ action: 'wp_cdn_sync', nonce: syncNonce, offset: '0' });
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error((data.data && data.data.message) ? data.data.message : 'Synchronisation impossible');
                        }
                        const d = data.data;
                        if (d.done) {
                            progressBar.style.width = '100%';
                            syncStatus.textContent = 'Aucun fichier à synchroniser.';
                            syncBtn.disabled = false;
                            return;
                        }
                        return runSyncBatch(d.offset, d.total, d.uploaded || 0, d.failures || []);
                    })
                    .catch(err => {
                        syncStatus.textContent = err.message || 'Erreur';
                        syncStatus.style.color = '#d63638';
                        syncBtn.disabled = false;
                    });
            });
        }
    })();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_wp_cdn_test', 'wp_cdn_ajax_test');

function wp_cdn_ajax_test() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }
    check_ajax_referer('wp_cdn_test', 'nonce');

    $result = wp_cdn_r2_test_connection();
    if ($result['ok']) {
        wp_send_json_success(['message' => $result['message']]);
    }
    wp_send_json_error(['message' => $result['message']]);
}

add_action('wp_ajax_wp_cdn_sync', 'wp_cdn_ajax_sync');

function wp_cdn_ajax_sync() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }
    check_ajax_referer('wp_cdn_sync', 'nonce');

    $settings = wp_cdn_get_settings();
    if ($settings['account_id'] === '' || $settings['access_key'] === '' || $settings['secret_key'] === '' || $settings['bucket'] === '') {
        wp_send_json_error(['message' => 'Configurez Account ID, clés API et bucket avant de synchroniser.']);
    }

    $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;

    if ($offset === 0) {
        $manifest = wp_cdn_build_manifest();
        set_transient(WP_CDN_SYNC_TRANSIENT, $manifest, HOUR_IN_SECONDS);
    } else {
        $manifest = get_transient(WP_CDN_SYNC_TRANSIENT);
        if (!is_array($manifest)) {
            wp_send_json_error(['message' => 'Manifeste expiré, relancez la synchronisation.']);
        }
    }

    $total = count($manifest);
    if ($total === 0) {
        delete_transient(WP_CDN_SYNC_TRANSIENT);
        wp_send_json_success([
            'total'    => 0,
            'offset'   => 0,
            'uploaded' => 0,
            'done'     => true,
            'failures' => [],
        ]);
    }

    $batch = array_slice($manifest, $offset, WP_CDN_SYNC_BATCH);
    $uploaded = 0;
    $failures = [];

    foreach ($batch as $item) {
        $result = wp_cdn_r2_upload_file($item['path'], $item['key']);
        if ($result['ok']) {
            $uploaded++;
        } else {
            $failures[] = [
                'key'   => $item['key'],
                'error' => $result['message'],
            ];
        }
    }

    $new_offset = $offset + count($batch);
    $done = $new_offset >= $total;

    if ($done) {
        delete_transient(WP_CDN_SYNC_TRANSIENT);
    }

    wp_send_json_success([
        'total'    => $total,
        'offset'   => $new_offset,
        'uploaded' => $uploaded,
        'done'     => $done,
        'failures' => $failures,
    ]);
}
