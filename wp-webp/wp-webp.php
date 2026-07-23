<?php
/**
 * Plugin Name: WP WebP
 * Description: Génère une version WebP de chaque image téléversée (et de toutes ses déclinaisons add_image_size), compatible Regenerate Thumbnails. Conversion via Imagick avec détection graphique near-lossless.
 * Version: 1.1.1
 * Author: Lonsdale studio
 */

if (!defined('ABSPATH')) exit;

define('WP_WEBP_PROFILE_OPTION', 'wp_webp_profile');
define('WP_WEBP_DISABLED_SIZES_OPTION', 'wp_webp_disabled_sizes');

/**
 * Profils de qualité. Chaque profil règle :
 * - quality : qualité de compression WebP (0-100) ;
 * - sharpen : sigma passé à Imagick::sharpenImage (0 = pas d'accentuation) ;
 * - method  : effort de compression WebP (0-6, 6 = meilleure compression).
 *
 * Best    : qualité maximale, accentuation marquée (fichiers plus lourds).
 * Optimal : meilleur compromis qualité / poids (recommandé).
 * Green   : poids minimal, accentuation légère (fichiers très légers).
 */
function wp_webp_profiles() {
    return [
        'best' => [
            'label'          => 'Best',
            'desc'           => 'Qualité maximale, avec priorité au rendu.',
            'quality'        => 85,
            'radius'         => 0,
            'sigma'          => 0.8,
            'blur'           => 0.8,
            'filter'         => 'lanczos',
            'method'         => 6,
            'near_lossless'  => 85,
            'graphic_colors' => 8192,
        ],
        'optimal' => [
            'label'          => 'Optimal',
            'desc'           => 'Meilleur compromis qualité / poids (recommandé).',
            'quality'        => 80,
            'radius'         => 0,
            'sigma'          => 0.6,
            'blur'           => 0.9,
            'filter'         => 'lanczos',
            'method'         => 6,
            'near_lossless'  => 60,
            'graphic_colors' => 8192,
        ],
        'green' => [
            'label'          => 'Green',
            'desc'           => 'Poids minimal, accentuation légère (fichiers très légers).',
            'quality'        => 70,
            'radius'         => 0,
            'sigma'          => 0.5,
            'blur'           => 1.0,
            'filter'         => 'triangle',
            'method'         => 6,
            'near_lossless'  => 40,
            'graphic_colors' => 8192,
        ],
    ];
}

function wp_webp_get_profile_key() {
    $key = (string) get_option(WP_WEBP_PROFILE_OPTION, 'optimal');
    $profiles = wp_webp_profiles();

    return isset($profiles[$key]) ? $key : 'optimal';
}

function wp_webp_get_profile() {
    $profiles = wp_webp_profiles();

    return $profiles[wp_webp_get_profile_key()];
}

function wp_webp_imagick_available() {
    return extension_loaded('imagick') && class_exists('Imagick');
}

/**
 * Vérifie qu'ImageMagick peut encoder le format WebP sur ce serveur.
 */
function wp_webp_imagick_webp_supported() {
    if (!wp_webp_imagick_available()) {
        return false;
    }

    try {
        $formats = Imagick::queryFormats('WEBP');

        return !empty($formats);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Prépare l'environnement PHP pour un traitement par lots (mémoire / timeout).
 */
function wp_webp_prepare_batch_environment() {
    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    } elseif (function_exists('ini_set')) {
        @ini_set('memory_limit', '512M');
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
}

/**
 * Enregistre un handler pour remonter les erreurs fatales PHP en JSON.
 */
function wp_webp_ajax_begin() {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    register_shutdown_function(static function () {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        error_log('[WP WebP] fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);

        if (headers_sent()) {
            return;
        }

        status_header(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo wp_json_encode([
            'success' => false,
            'data'    => [
                'message' => 'Erreur fatale PHP : ' . $error['message'],
            ],
        ]);
    });
}

/**
 * ID de l'attachement image suivant (après $after_id).
 */
function wp_webp_get_next_attachment_id($after_id = 0, $max_id = 0) {
    global $wpdb;

    if ((int) $max_id > 0) {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status = 'inherit'
             AND post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png')
             AND ID > %d
             AND ID <= %d
             ORDER BY ID ASC
             LIMIT 1",
            max(0, (int) $after_id),
            (int) $max_id
        ));
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_status = 'inherit'
         AND post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png')
         AND ID > %d
         ORDER BY ID ASC
         LIMIT 1",
        max(0, (int) $after_id)
    ));
}

/**
 * Liste des conversions à effectuer pour un attachement (original + déclinaisons).
 */
function wp_webp_jobs_for_attachment($attachment_id) {
    $jobs = [
        ['type' => 'original'],
    ];
    $registered = array_fill_keys(array_keys(wp_webp_get_image_sizes()), true);

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size_name => $size) {
            $size = wp_webp_normalize_size_metadata($size_name, $size);
            if (
                $size === null
                || !isset($registered[$size_name])
                || !wp_webp_size_enabled($size_name)
            ) {
                continue;
            }
            $jobs[] = [
                'type'   => 'size',
                'name'   => $size_name,
                'width'  => (int) $size['width'],
                'height' => (int) $size['height'],
                'file'   => $size['file'],
            ];
        }
    }

    return $jobs;
}

/**
 * Génère un seul WebP (original ou déclinaison) pour un attachement.
 */
function wp_webp_process_job($attachment_id, $job_index, &$failures = null) {
    $original = get_attached_file($attachment_id);
    if (!$original || !file_exists($original)) {
        wp_webp_record_failure($failures, 'attachment #' . (int) $attachment_id, 'Fichier source introuvable');
        return 0;
    }
    if (!wp_webp_attachment_supported($attachment_id, $original)) {
        return 0;
    }

    $jobs = wp_webp_jobs_for_attachment($attachment_id);
    if (!isset($jobs[$job_index])) {
        wp_webp_record_failure($failures, 'attachment #' . (int) $attachment_id, 'Déclinaison introuvable');
        return 0;
    }

    $job = $jobs[$job_index];

    if ($job['type'] === 'original') {
        return wp_webp_make_webp($original, 0, 0, false, $original, $failures, $attachment_id);
    }

    $registered = wp_webp_get_image_sizes();
    $crop = isset($registered[$job['name']]) ? $registered[$job['name']]['crop'] : false;
    $dir = trailingslashit(dirname($original));

    return wp_webp_make_webp(
        $original,
        (int) $job['width'],
        (int) $job['height'],
        $crop,
        $dir . $job['file'],
        $failures,
        $attachment_id
    );
}

/**
 * Passe au job suivant (déclinaison suivante ou attachement suivant).
 */
function wp_webp_advance_cursor($attachment_id, $job_index, $max_attachment_id = 0) {
    $jobs = wp_webp_jobs_for_attachment($attachment_id);
    $job_index++;

    if (isset($jobs[$job_index])) {
        return [
            'attachment_id' => $attachment_id,
            'job_index'     => $job_index,
            'done'          => false,
        ];
    }

    $next_attachment = wp_webp_get_next_attachment_id($attachment_id, $max_attachment_id);
    if ($next_attachment > 0) {
        return [
            'attachment_id' => $next_attachment,
            'job_index'     => 0,
            'done'          => false,
        ];
    }

    return [
        'attachment_id' => 0,
        'job_index'     => 0,
        'done'          => true,
    ];
}

/**
 * Liste des tailles d'images enregistrées : tailles par défaut (thumbnail,
 * medium, medium_large, large) + celles ajoutées via add_image_size.
 * Retourne un tableau [ nom => ['width' => …, 'height' => …, 'crop' => bool|array] ].
 */
function wp_webp_get_image_sizes() {
    $sizes = [];
    $additional = function_exists('wp_get_additional_image_sizes') ? wp_get_additional_image_sizes() : [];

    foreach (get_intermediate_image_sizes() as $name) {
        if (isset($additional[$name])) {
            $sizes[$name] = [
                'width'  => (int) $additional[$name]['width'],
                'height' => (int) $additional[$name]['height'],
                'crop'   => wp_webp_normalize_crop($additional[$name]['crop']),
            ];
        } else {
            $sizes[$name] = [
                'width'  => (int) get_option($name . '_size_w'),
                'height' => (int) get_option($name . '_size_h'),
                'crop'   => wp_webp_normalize_crop(get_option($name . '_crop')),
            ];
        }
    }

    return $sizes;
}

function wp_webp_normalize_crop($crop) {
    if (!is_array($crop)) {
        return (bool) $crop;
    }

    $x = isset($crop[0]) && in_array($crop[0], ['left', 'center', 'right'], true)
        ? $crop[0]
        : 'center';
    $y = isset($crop[1]) && in_array($crop[1], ['top', 'center', 'bottom'], true)
        ? $crop[1]
        : 'center';

    return [$x, $y];
}

function wp_webp_crop_label($crop) {
    if (!is_array($crop)) {
        return $crop ? 'Oui (centré)' : 'Non';
    }

    return ucfirst($crop[0]) . ' / ' . $crop[1];
}

function wp_webp_normalize_size_metadata($size_name, $size, &$failures = null) {
    if (!is_array($size)) {
        wp_webp_record_failure($failures, (string) $size_name, 'Métadonnées de taille invalides');
        return null;
    }

    $file = wp_webp_normalize_metadata_filename($size['file'] ?? '');
    $width = isset($size['width']) && is_numeric($size['width']) ? (int) $size['width'] : 0;
    $height = isset($size['height']) && is_numeric($size['height']) ? (int) $size['height'] : 0;

    if ($file === '') {
        wp_webp_record_failure($failures, (string) $size_name, 'Nom de fichier de taille invalide');
        return null;
    }

    if ($width <= 0 || $height <= 0) {
        wp_webp_record_failure($failures, $file, 'Dimensions de taille invalides');
        return null;
    }

    return [
        'file'   => $file,
        'width'  => $width,
        'height' => $height,
    ];
}

function wp_webp_normalize_metadata_filename($file) {
    if (!is_scalar($file)) {
        return '';
    }

    $file = trim((string) $file);

    if (
        $file === ''
        || $file === '.'
        || $file === '..'
        || str_contains($file, '/')
        || str_contains($file, '\\')
        || str_contains($file, "\0")
    ) {
        return '';
    }

    return $file;
}

/**
 * Noms des formats désactivés (aucun WebP généré pour eux). Par défaut, tous
 * les formats sont activés.
 */
function wp_webp_get_disabled_sizes() {
    $list = get_option(WP_WEBP_DISABLED_SIZES_OPTION, []);

    return is_array($list) ? $list : [];
}

function wp_webp_size_enabled($name) {
    return !in_array($name, wp_webp_get_disabled_sizes(), true);
}

/* -------------------------------------------------------------------------
 * Réglages (Settings API)
 * ---------------------------------------------------------------------- */

add_action('admin_menu', 'wp_webp_admin_menu');
add_action('admin_init', 'wp_webp_register_settings');

function wp_webp_admin_menu() {
    add_menu_page(
        'WP WebP',
        'WP WebP',
        'manage_options',
        'wp-webp',
        'wp_webp_admin_page',
        'dashicons-images-alt2',
        // Position élevée pour regrouper les outils maison en bas de la sidebar.
        201
    );
}

function wp_webp_register_settings() {
    register_setting('wp_webp_settings', WP_WEBP_PROFILE_OPTION, [
        'type'              => 'string',
        'sanitize_callback' => 'wp_webp_sanitize_profile',
        'default'           => 'optimal',
    ]);
}

add_action('wp_ajax_wp_webp_save_profile', 'wp_webp_ajax_save_profile');

function wp_webp_ajax_save_profile() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_profile_action', 'nonce');

    $profile = wp_webp_sanitize_profile(isset($_POST['profile']) ? wp_unslash($_POST['profile']) : '');
    update_option(WP_WEBP_PROFILE_OPTION, $profile);

    wp_send_json_success(['profile' => $profile]);
}

function wp_webp_sanitize_profile($value) {
    $profiles = wp_webp_profiles();
    $value = is_string($value) ? $value : '';

    return isset($profiles[$value]) ? $value : 'optimal';
}

add_action('wp_ajax_wp_webp_save_size', 'wp_webp_ajax_save_size');
add_action('wp_ajax_wp_webp_cleanup_size', 'wp_webp_ajax_cleanup_size');

function wp_webp_ajax_save_size() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_size_action', 'nonce');

    $size = isset($_POST['size']) ? sanitize_key(wp_unslash($_POST['size'])) : '';
    $enabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1');

    $valid = array_keys(wp_webp_get_image_sizes());
    if ($size === '' || !in_array($size, $valid, true)) {
        wp_send_json_error(['message' => 'Format inconnu.'], 400);
    }

    $disabled = wp_webp_get_disabled_sizes();
    if ($enabled) {
        $disabled = array_values(array_diff($disabled, [$size]));
    } elseif (!in_array($size, $disabled, true)) {
        $disabled[] = $size;
    }

    update_option(WP_WEBP_DISABLED_SIZES_OPTION, $disabled);

    wp_send_json_success([
        'size'             => $size,
        'enabled'          => $enabled,
        'cleanup_required' => !$enabled,
    ]);
}

function wp_webp_ajax_cleanup_size() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_size_action', 'nonce');
    wp_webp_prepare_batch_environment();

    $size = isset($_POST['size']) ? sanitize_key(wp_unslash($_POST['size'])) : '';
    $after_id = isset($_POST['after_id']) ? max(0, (int) $_POST['after_id']) : 0;
    $valid = array_keys(wp_webp_get_image_sizes());

    if ($size === '' || !in_array($size, $valid, true)) {
        wp_send_json_error(['message' => 'Format inconnu.'], 400);
    }

    if (wp_webp_size_enabled($size)) {
        wp_send_json_error(['message' => 'Ce format est encore actif.'], 409);
    }

    $ids = wp_webp_get_attachment_ids_after($after_id, 100);
    $deleted = 0;
    $failures = [];

    foreach ($ids as $attachment_id) {
        $deleted += wp_webp_delete_attachment_size($attachment_id, $size, $failures);
    }

    $next_after_id = $ids !== [] ? (int) end($ids) : $after_id;

    wp_send_json_success([
        'after_id' => $next_after_id,
        'deleted'  => $deleted,
        'done'     => count($ids) < 100,
        'failures' => $failures,
    ]);
}

function wp_webp_get_attachment_ids_after($after_id, $limit) {
    global $wpdb;

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_status = 'inherit'
         AND post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png')
         AND ID > %d
         ORDER BY ID ASC
         LIMIT %d",
        max(0, (int) $after_id),
        max(1, min(500, (int) $limit))
    ));

    return array_map('intval', is_array($ids) ? $ids : []);
}

function wp_webp_delete_attachment_size($attachment_id, $size_name, &$failures = null) {
    $original = get_attached_file($attachment_id);
    $metadata = wp_get_attachment_metadata($attachment_id);
    $size = is_array($metadata) && isset($metadata['sizes'][$size_name])
        ? $metadata['sizes'][$size_name]
        : null;

    if (!$original || !is_array($size) || empty($size['file'])) {
        return 0;
    }

    $file = basename((string) $size['file']);
    $target = wp_webp_target_path(trailingslashit(dirname($original)) . $file);

    if ($target === '' || !file_exists($target)) {
        return 0;
    }

    if (!@unlink($target)) {
        wp_webp_record_failure(
            $failures,
            wp_webp_relative_upload_path($target),
            'Suppression du fichier WebP impossible'
        );

        return 0;
    }

    return 1;
}

function wp_webp_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $current = wp_webp_get_profile_key();
    ?>
    <div class="wrap">
        <h1>WP WebP</h1>

        <?php if (!wp_webp_imagick_available()) : ?>
            <div class="notice notice-error"><p>
                <strong>Imagick n’est pas disponible</strong> sur ce serveur : la génération WebP est désactivée.
            </p></div>
        <?php elseif (!wp_webp_imagick_webp_supported()) : ?>
            <div class="notice notice-error"><p>
                <strong>ImageMagick est installé mais le format WebP n’est pas pris en charge.</strong>
                Contactez l’hébergeur pour activer le delegate WebP dans ImageMagick.
            </p></div>
        <?php endif; ?>

        <h2>Qualité des images</h2>
        <fieldset id="wp-webp-profile">
            <?php foreach (wp_webp_profiles() as $key => $profile) : ?>
                <label style="display:block; margin-bottom:8px;">
                    <input type="radio" name="<?php echo esc_attr(WP_WEBP_PROFILE_OPTION); ?>" value="<?php echo esc_attr($key); ?>" <?php checked($current, $key); ?>>
                    <strong><?php echo esc_html($profile['label']); ?></strong>
                    — <?php echo esc_html($profile['desc']); ?>
                </label>
            <?php endforeach; ?>
            <span id="wp-webp-profile-status" style="margin-left:4px; font-style:italic; color:#50575e;"></span>
        </fieldset>
        <script>
        (function () {
            var fieldset = document.getElementById('wp-webp-profile');
            var status = document.getElementById('wp-webp-profile-status');
            if (!fieldset) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_webp_profile_action')); ?>;
            var radios = fieldset.querySelectorAll('input[type="radio"]');

            radios.forEach(function (radio) {
                radio.addEventListener('change', function () {
                    if (!radio.checked) { return; }
                    status.textContent = 'Enregistrement…';

                    var body = new URLSearchParams();
                    body.append('action', 'wp_webp_save_profile');
                    body.append('nonce', nonce);
                    body.append('profile', radio.value);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            status.textContent = 'Enregistré.';
                        } else {
                            throw new Error('failed');
                        }
                    })
                    .catch(function () {
                        status.textContent = 'Erreur lors de l’enregistrement.';
                    });
                });
            });
        })();
        </script>

        <hr>

        <h2>Liste des formats</h2>
        <p class="description">Tailles d’images enregistrées (par défaut + <code>add_image_size</code>). Un fichier <code>.webp</code> est généré pour chacune de ces déclinaisons.</p>
        <?php $wp_webp_sizes = wp_webp_get_image_sizes(); ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:720px;">
            <thead>
                <tr>
                    <th scope="col">Nom</th>
                    <th scope="col">Largeur</th>
                    <th scope="col">Hauteur</th>
                    <th scope="col">Recadrage (crop)</th>
                    <th scope="col">Générer ?</th>
                </tr>
            </thead>
            <tbody id="wp-webp-sizes">
                <?php if (empty($wp_webp_sizes)) : ?>
                    <tr><td colspan="5">Aucun format enregistré.</td></tr>
                <?php else : foreach ($wp_webp_sizes as $name => $size) : ?>
                    <tr class="wp-webp-size-row<?php echo wp_webp_size_enabled($name) ? '' : ' wp-webp-dim'; ?>">
                        <td><strong><?php echo esc_html($name); ?></strong></td>
                        <td><?php echo $size['width'] ? (int) $size['width'] . ' px' : '—'; ?></td>
                        <td><?php echo $size['height'] ? (int) $size['height'] . ' px' : '—'; ?></td>
                        <td><?php echo esc_html(wp_webp_crop_label($size['crop'])); ?></td>
                        <td class="wp-webp-keep">
                            <input type="checkbox" class="wp-webp-size-cb" data-size="<?php echo esc_attr($name); ?>" <?php checked(wp_webp_size_enabled($name)); ?>>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <style>
            tr.wp-webp-dim td:not(.wp-webp-keep) { opacity: .45; }
        </style>
        <p><span id="wp-webp-sizes-status" style="font-style:italic; color:#50575e;"></span></p>
        <script>
        (function () {
            var tbody = document.getElementById('wp-webp-sizes');
            var status = document.getElementById('wp-webp-sizes-status');
            if (!tbody) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_webp_size_action')); ?>;

            function syncRow(cb) {
                var row = cb.closest('tr');
                if (row) { row.classList.toggle('wp-webp-dim', !cb.checked); }
            }

            function cleanupSize(size, afterId, deleted, failures) {
                var body = new URLSearchParams();
                body.append('action', 'wp_webp_cleanup_size');
                body.append('nonce', nonce);
                body.append('size', size);
                body.append('after_id', String(afterId));

                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error('failed');
                    }

                    deleted += data.data.deleted || 0;
                    failures += (data.data.failures || []).length;
                    status.textContent = 'Nettoyage des anciens WebP… ' + deleted + ' supprimé(s).';

                    if (!data.data.done) {
                        return cleanupSize(size, data.data.after_id, deleted, failures);
                    }

                    status.textContent = 'Enregistré : ' + deleted + ' ancien(s) WebP supprimé(s)'
                        + (failures ? ', ' + failures + ' erreur(s).' : '.');
                })
                .catch(function () {
                    status.textContent = 'Format désactivé, mais nettoyage incomplet. Relancez sa désactivation.';
                });
            }

            tbody.querySelectorAll('.wp-webp-size-cb').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    cb.disabled = true;
                    syncRow(cb);
                    status.textContent = 'Enregistrement…';

                    var body = new URLSearchParams();
                    body.append('action', 'wp_webp_save_size');
                    body.append('nonce', nonce);
                    body.append('size', cb.getAttribute('data-size'));
                    body.append('enabled', cb.checked ? '1' : '0');

                    fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            status.textContent = 'Enregistré.';
                            if (data.data.cleanup_required) {
                                return cleanupSize(data.data.size, 0, 0, 0);
                            }
                        } else {
                            throw new Error('failed');
                        }
                    })
                    .catch(function () {
                        cb.checked = !cb.checked;
                        syncRow(cb);
                        status.textContent = 'Erreur lors de l’enregistrement.';
                    })
                    .finally(function () {
                        cb.disabled = false;
                    });
                });
            });
        })();
        </script>

        <hr>

        <h2>Generate images</h2>
        <p class="description">Génère (ou régénère) les fichiers <code>.webp</code> pour <strong>toutes</strong> les images déjà présentes dans la médiathèque, ainsi que toutes leurs déclinaisons. <strong>Un fichier WebP est traité par requête</strong> pour limiter la charge serveur.</p>
        <p>
            <button type="button" class="button button-primary" id="wp-webp-generate"<?php disabled(!wp_webp_imagick_available() || !wp_webp_imagick_webp_supported()); ?>>Générer les WebP</button>
        </p>
        <div id="wp-webp-generate-progress" style="display:none; max-width:520px; margin:8px 0;">
            <div style="background:#e2e4e7; border-radius:999px; height:18px; overflow:hidden;">
                <div id="wp-webp-generate-bar" style="height:100%; width:0; background:linear-gradient(135deg,#10b981,#047857); transition:width .2s ease;"></div>
            </div>
            <p id="wp-webp-generate-status" style="margin:6px 0 0 0; font-style:italic; color:#50575e;"></p>
        </div>
        <script>
        (function () {
            var btn = document.getElementById('wp-webp-generate');
            var status = document.getElementById('wp-webp-generate-status');
            var progress = document.getElementById('wp-webp-generate-progress');
            var bar = document.getElementById('wp-webp-generate-bar');
            if (!btn) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_webp_generate_action')); ?>;

            function setBar(processed, total) {
                var pct = total > 0 ? Math.round((processed / total) * 100) : 0;
                bar.style.width = pct + '%';
                return pct;
            }

            function renderFailures(failures) {
                if (!failures.length) {
                    return '';
                }
                var preview = failures.slice(0, 8).map(function (failure) {
                    return failure.file + (failure.error ? ' (' + failure.error + ')' : '');
                }).join(' | ');

                return ' — ' + failures.length + ' fichier(s) en erreur : ' + preview + (failures.length > 8 ? '…' : '');
            }

            function runBatch(runId, attachmentId, jobIndex, processedJobs, processedAttachments, generated, failures) {
                var body = new URLSearchParams();
                body.append('action', 'wp_webp_generate_webp');
                body.append('nonce', nonce);
                body.append('run_id', runId);
                body.append('attachment_id', String(attachmentId));
                body.append('job_index', String(jobIndex));
                body.append('processed_jobs', String(processedJobs));
                body.append('processed_attachments', String(processedAttachments));
                body.append('generated', String(generated));

                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) {
                    return r.text().then(function (text) {
                        var data = null;
                        try {
                            data = JSON.parse(text);
                        } catch (e) {
                            throw new Error(text ? text.slice(0, 300) : ('HTTP ' + r.status));
                        }
                        if (!r.ok && data && data.data && data.data.message) {
                            throw new Error(data.data.message);
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error((data && data.data && data.data.message) ? data.data.message : 'failed');
                    }
                    runId = data.data.run_id;
                    processedJobs = data.data.processed_jobs;
                    processedAttachments = data.data.processed_attachments;
                    generated = data.data.generated;
                    failures = failures.concat(data.data.failures || []);
                    var total = data.data.total_attachments;
                    var pct = setBar(processedAttachments, total);
                    status.textContent = 'Traitement… ' + pct + '% (' + processedAttachments + ' / ' + total + ' image(s), ' + processedJobs + ' fichier(s), ' + generated + ' WebP généré(s)).';
                    if (!data.data.done) {
                        return runBatch(
                            runId,
                            data.data.attachment_id,
                            data.data.job_index,
                            processedJobs,
                            processedAttachments,
                            generated,
                            failures
                        );
                    }
                    setBar(1, 1);
                    status.textContent = 'Terminé : ' + processedJobs + ' fichier(s) traité(s), ' + generated + ' WebP généré(s), ' + failures.length + ' erreur(s).' + renderFailures(failures);
                });
            }

            btn.addEventListener('click', function () {
                btn.disabled = true;
                progress.style.display = '';
                bar.style.width = '0';
                status.textContent = 'Initialisation…';
                runBatch('', 0, 0, 0, 0, 0, []).catch(function (err) {
                    status.textContent = 'Erreur : ' + (err && err.message ? err.message : 'lors de la génération.');
                }).finally(function () {
                    btn.disabled = false;
                });
            });
        })();
        </script>

        <hr>

        <h2>Developer</h2>
        <p class="description">Supprime les fichiers <code>.webp</code> générés à partir des JPEG/PNG présents dans <code>uploads</code>. Les médias WebP téléversés directement sont conservés. Action irréversible (les WebP générés seront recréés à la prochaine génération / Regenerate Thumbnails).</p>
        <p>
            <button type="button" class="button button-secondary" id="wp-webp-clear">Effacer tous les WebP des uploads</button>
            <span id="wp-webp-clear-status" style="margin-left:8px; font-style:italic; color:#50575e;"></span>
        </p>
        <script>
        (function () {
            var btn = document.getElementById('wp-webp-clear');
            var status = document.getElementById('wp-webp-clear-status');
            if (!btn) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_webp_clear_action')); ?>;

            function clearBatch(runId, deleted, failures) {
                var body = new URLSearchParams();
                body.append('action', 'wp_webp_clear_webp');
                body.append('nonce', nonce);
                body.append('run_id', runId);

                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error('failed');
                    }

                    runId = data.data.run_id;
                    deleted += data.data.deleted || 0;
                    failures += (data.data.failures || []).length;
                    status.textContent = 'Suppression… ' + deleted + ' fichier(s) WebP supprimé(s).';

                    if (!data.data.done) {
                        return clearBatch(runId, deleted, failures);
                    }

                    status.textContent = deleted + ' fichier(s) WebP supprimé(s)'
                        + (failures ? ', ' + failures + ' erreur(s).' : '.');
                })
                .catch(function () {
                    status.textContent = 'Erreur lors de la suppression.';
                });
            }

            btn.addEventListener('click', function () {
                if (!window.confirm('Supprimer les fichiers WebP générés depuis les JPEG/PNG ?')) {
                    return;
                }
                btn.disabled = true;
                status.textContent = 'Initialisation…';

                clearBatch('', 0, 0).finally(function () {
                    btn.disabled = false;
                });
            });
        })();
        </script>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Generate images : (re)génération des WebP de la médiathèque par lots.
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_wp_webp_generate_webp', 'wp_webp_ajax_generate_webp');

function wp_webp_generation_run_key($run_id) {
    return 'wp_webp_run_' . get_current_user_id() . '_' . md5((string) $run_id);
}

function wp_webp_create_generation_run() {
    global $wpdb;

    $run_id = strtolower(wp_generate_password(20, false, false));
    $snapshot = $wpdb->get_row(
        "SELECT COUNT(ID) AS total, MAX(ID) AS max_id
         FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_status = 'inherit'
         AND post_mime_type IN ('image/jpeg', 'image/jpg', 'image/png')",
        ARRAY_A
    );
    $state = [
        'total_attachments' => (int) ($snapshot['total'] ?? 0),
        'max_attachment_id' => (int) ($snapshot['max_id'] ?? 0),
    ];

    set_transient(wp_webp_generation_run_key($run_id), $state, HOUR_IN_SECONDS);

    return [$run_id, $state];
}

function wp_webp_get_generation_run($run_id) {
    if (!is_string($run_id) || !preg_match('/^[a-z0-9]{20}$/', $run_id)) {
        return null;
    }

    $state = get_transient(wp_webp_generation_run_key($run_id));

    return is_array($state) ? $state : null;
}

function wp_webp_ajax_generate_webp() {
    wp_webp_ajax_begin();
    $run_id = '';

    try {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
        }

        check_ajax_referer('wp_webp_generate_action', 'nonce');

        if (!wp_webp_imagick_available()) {
            wp_send_json_error(['message' => 'Imagick indisponible.'], 500);
        }

        if (!wp_webp_imagick_webp_supported()) {
            wp_send_json_error(['message' => 'ImageMagick ne supporte pas le format WebP sur ce serveur.'], 500);
        }

        wp_webp_prepare_batch_environment();

        $attachment_id = isset($_POST['attachment_id']) ? max(0, (int) $_POST['attachment_id']) : 0;
        $job_index = isset($_POST['job_index']) ? max(0, (int) $_POST['job_index']) : 0;
        $processed_jobs = isset($_POST['processed_jobs']) ? max(0, (int) $_POST['processed_jobs']) : 0;
        $processed_attachments = isset($_POST['processed_attachments'])
            ? max(0, (int) $_POST['processed_attachments'])
            : 0;
        $generated_total = isset($_POST['generated']) ? max(0, (int) $_POST['generated']) : 0;
        $run_id = isset($_POST['run_id'])
            ? strtolower(preg_replace('/[^a-z0-9]/i', '', (string) wp_unslash($_POST['run_id'])))
            : '';

        if ($attachment_id === 0) {
            [$run_id, $run_state] = wp_webp_create_generation_run();
            $attachment_id = wp_webp_get_next_attachment_id(
                0,
                (int) ($run_state['max_attachment_id'] ?? 0)
            );
            $job_index = 0;

            if ($attachment_id === 0) {
                delete_transient(wp_webp_generation_run_key($run_id));
                wp_send_json_success([
                    'run_id'         => $run_id,
                    'attachment_id'  => 0,
                    'job_index'      => 0,
                    'processed_jobs' => 0,
                    'processed_attachments' => 0,
                    'generated'      => 0,
                    'total_attachments' => 0,
                    'done'           => true,
                    'failures'       => [],
                ]);
            }
        } else {
            $run_state = wp_webp_get_generation_run($run_id);
            if ($run_state === null) {
                wp_send_json_error(['message' => 'Session de génération expirée. Relancez la génération.'], 410);
            }
            set_transient(wp_webp_generation_run_key($run_id), $run_state, HOUR_IN_SECONDS);
        }

        $total_attachments = (int) ($run_state['total_attachments'] ?? 0);
        $failures = [];
        $generated = 0;

        try {
            $generated = wp_webp_process_job($attachment_id, $job_index, $failures);
        } catch (Throwable $e) {
            $failures[] = [
                'file'  => 'attachment #' . (int) $attachment_id,
                'error' => $e->getMessage(),
            ];
            error_log('[WP WebP] job attachment #' . (int) $attachment_id . ' index ' . (int) $job_index . ': ' . $e->getMessage());
        }

        $cursor = wp_webp_advance_cursor(
            $attachment_id,
            $job_index,
            (int) ($run_state['max_attachment_id'] ?? 0)
        );
        $processed_jobs++;
        if ($cursor['attachment_id'] !== $attachment_id) {
            $processed_attachments++;
        }

        if ($cursor['done']) {
            delete_transient(wp_webp_generation_run_key($run_id));
        }

        wp_send_json_success([
            'run_id'         => $run_id,
            'attachment_id'  => $cursor['attachment_id'],
            'job_index'      => $cursor['job_index'],
            'processed_jobs' => $processed_jobs,
            'processed_attachments' => $processed_attachments,
            'generated'      => $generated_total + $generated,
            'total_attachments' => $total_attachments,
            'done'           => $cursor['done'],
            'failures'       => $failures,
        ]);
    } catch (Throwable $e) {
        if ($run_id !== '') {
            delete_transient(wp_webp_generation_run_key($run_id));
        }
        error_log('[WP WebP] generate batch failed: ' . $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
}

function wp_webp_relative_upload_path($path) {
    $uploads = wp_get_upload_dir();
    $basedir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
    $path = wp_normalize_path($path);
    if ($basedir !== '' && strpos($path, trailingslashit($basedir)) === 0) {
        return ltrim(substr($path, strlen(trailingslashit($basedir))), '/');
    }

    return basename($path);
}

/* -------------------------------------------------------------------------
 * Developer : suppression de tous les WebP du dossier uploads.
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_wp_webp_clear_webp', 'wp_webp_ajax_clear_webp');

function wp_webp_clear_run_key($run_id) {
    return 'wp_webp_clear_' . get_current_user_id() . '_' . md5((string) $run_id);
}

function wp_webp_ajax_clear_webp() {
    wp_webp_ajax_begin();

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_clear_action', 'nonce');
    wp_webp_prepare_batch_environment();
    $run_id = isset($_POST['run_id'])
        ? strtolower(preg_replace('/[^a-z0-9]/i', '', (string) wp_unslash($_POST['run_id'])))
        : '';

    if ($run_id === '') {
        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';

        if ($basedir === '' || !is_dir($basedir)) {
            wp_send_json_error(['message' => 'Dossier uploads introuvable.'], 500);
        }

        $run_id = strtolower(wp_generate_password(20, false, false));
        $state = [
            'protected' => wp_webp_get_native_webp_keys(),
            'directories' => [$basedir],
            'current_directory' => '',
            'after_name' => '',
        ];
    } elseif (!preg_match('/^[a-z0-9]{20}$/', $run_id)) {
        wp_send_json_error(['message' => 'Session de suppression invalide.'], 400);
    } else {
        $state = get_transient(wp_webp_clear_run_key($run_id));
        if (!is_array($state)) {
            wp_send_json_error(['message' => 'Session de suppression expirée.'], 410);
        }
    }

    $failures = [];
    $result = wp_webp_clear_batch($state, 250, $failures);

    if ($result['done']) {
        delete_transient(wp_webp_clear_run_key($run_id));
    } else {
        set_transient(wp_webp_clear_run_key($run_id), $state, HOUR_IN_SECONDS);
    }

    wp_send_json_success([
        'run_id'   => $run_id,
        'deleted'  => $result['deleted'],
        'done'     => $result['done'],
        'failures' => $failures,
    ]);
}

function wp_webp_clear_batch(array &$state, $limit = 250, &$failures = null) {
    $limit = max(1, min(1000, (int) $limit));
    $processed = 0;
    $deleted = 0;
    $protected = isset($state['protected']) && is_array($state['protected'])
        ? $state['protected']
        : [];
    $state['directories'] = isset($state['directories']) && is_array($state['directories'])
        ? array_values($state['directories'])
        : [];
    $state['current_directory'] = isset($state['current_directory'])
        ? (string) $state['current_directory']
        : '';
    $state['after_name'] = isset($state['after_name'])
        ? (string) $state['after_name']
        : '';

    while ($processed < $limit) {
        if ($state['current_directory'] === '') {
            if ($state['directories'] === []) {
                break;
            }

            $state['current_directory'] = (string) array_shift($state['directories']);
            $state['after_name'] = '';
        }

        $directory = $state['current_directory'];
        $entries = @scandir($directory);

        if (!is_array($entries)) {
            wp_webp_record_failure(
                $failures,
                wp_webp_relative_upload_path($directory),
                'Lecture du dossier impossible'
            );
            $state['current_directory'] = '';
            $state['after_name'] = '';
            $processed++;
            continue;
        }

        $after_name = $state['after_name'];
        $pending = array_values(array_filter(
            $entries,
            static function ($entry) use ($after_name) {
                return $entry !== '.'
                    && $entry !== '..'
                    && strcmp($entry, $after_name) > 0;
            }
        ));

        if ($pending === []) {
            $state['current_directory'] = '';
            $state['after_name'] = '';
            continue;
        }

        $batch = array_slice($pending, 0, $limit - $processed);

        foreach ($batch as $entry) {
            $path = wp_normalize_path(trailingslashit($directory) . $entry);
            $state['after_name'] = $entry;
            $processed++;

            if (is_dir($path) && !is_link($path)) {
                $state['directories'][] = $path;
                continue;
            }

            if (
                !is_file($path)
                || strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'webp'
                || isset($protected[wp_webp_file_key($path)])
            ) {
                continue;
            }

            if (@unlink($path)) {
                $deleted++;
            } else {
                wp_webp_record_failure(
                    $failures,
                    wp_webp_relative_upload_path($path),
                    'Suppression du fichier WebP impossible'
                );
            }
        }

        if (count($batch) === count($pending)) {
            $state['current_directory'] = '';
            $state['after_name'] = '';
        }
    }

    return [
        'deleted' => $deleted,
        'done' => $state['current_directory'] === '' && $state['directories'] === [],
    ];
}

function wp_webp_file_key($path) {
    return md5(wp_normalize_path((string) $path));
}

function wp_webp_get_native_webp_keys() {
    global $wpdb;

    $protected = [];
    $uploads = wp_get_upload_dir();
    $basedir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
    $rows = $wpdb->get_results(
        "SELECT attached.meta_value AS file, metadata.meta_value AS metadata
         FROM {$wpdb->posts} AS posts
         INNER JOIN {$wpdb->postmeta} AS attached
            ON attached.post_id = posts.ID
            AND attached.meta_key = '_wp_attached_file'
         LEFT JOIN {$wpdb->postmeta} AS metadata
            ON metadata.post_id = posts.ID
            AND metadata.meta_key = '_wp_attachment_metadata'
         WHERE posts.post_type = 'attachment'
         AND posts.post_status = 'inherit'
         AND posts.post_mime_type = 'image/webp'",
        ARRAY_A
    );

    foreach ($rows as $row) {
        $file = isset($row['file']) ? wp_normalize_path((string) $row['file']) : '';
        $absolute = $file !== '' && (str_starts_with($file, '/') || preg_match('/^[A-Za-z]:\//', $file));
        $original = $absolute
            ? $file
            : ($file !== '' && $basedir !== ''
                ? wp_normalize_path(trailingslashit($basedir) . ltrim($file, '/'))
                : '');

        if ($original === '') {
            continue;
        }

        $protected[wp_webp_file_key($original)] = true;
        $metadata = isset($row['metadata']) ? maybe_unserialize($row['metadata']) : null;

        if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            continue;
        }

        $dir = trailingslashit(dirname($original));
        foreach ($metadata['sizes'] as $size) {
            if (!empty($size['file'])) {
                $protected[wp_webp_file_key($dir . basename((string) $size['file']))] = true;
            }
        }
    }

    return $protected;
}

/* -------------------------------------------------------------------------
 * Génération des fichiers WebP
 * ---------------------------------------------------------------------- */

// wp_generate_attachment_metadata est déclenché à l'upload ET par Regenerate
// Thumbnails : on couvre donc les deux cas, y compris toutes les déclinaisons
// (tailles par défaut + add_image_size).
add_filter('wp_generate_attachment_metadata', 'wp_webp_on_generate_metadata', 10, 2);

function wp_webp_attachment_supported($attachment_id, $path = '') {
    $mime = (string) get_post_mime_type($attachment_id);

    if (!in_array($mime, ['image/jpeg', 'image/jpg', 'image/png'], true)) {
        return false;
    }

    if ($path === '') {
        $path = (string) get_attached_file($attachment_id);
    }

    return $path !== '' && wp_webp_target_path($path) !== '';
}

function wp_webp_on_generate_metadata($metadata, $attachment_id) {
    if (!wp_webp_attachment_supported($attachment_id)) {
        return $metadata;
    }

    wp_webp_clear_graphic_cache($attachment_id);

    if (wp_webp_imagick_available() && wp_webp_imagick_webp_supported()) {
        $failures = [];

        try {
            $stored_metadata = wp_get_attachment_metadata($attachment_id);
            $registered_sizes = array_keys(wp_webp_get_image_sizes());
            $processing_metadata = wp_webp_merge_metadata_for_processing(
                $metadata,
                $stored_metadata,
                $registered_sizes,
                dirname((string) get_attached_file($attachment_id))
            );

            wp_webp_cleanup_obsolete_webps(
                $attachment_id,
                $metadata,
                $stored_metadata,
                $registered_sizes,
                $failures
            );
            wp_webp_process_attachment($attachment_id, $failures, $processing_metadata);
        } catch (Throwable $e) {
            error_log(
                '[WP WebP] attachment #' . (int) $attachment_id
                . ' metadata generation failed: ' . $e->getMessage()
            );
        }

        wp_webp_log_failures('metadata attachment #' . (int) $attachment_id, $failures);
    }

    return $metadata;
}

function wp_webp_merge_metadata_for_processing($fresh, $stored, $registered_sizes, $directory) {
    $fresh = is_array($fresh) ? $fresh : [];
    $stored = is_array($stored) ? $stored : [];
    $fresh['sizes'] = isset($fresh['sizes']) && is_array($fresh['sizes']) ? $fresh['sizes'] : [];
    $stored_sizes = isset($stored['sizes']) && is_array($stored['sizes']) ? $stored['sizes'] : [];
    $registered = array_fill_keys(array_map('strval', $registered_sizes), true);

    foreach ($stored_sizes as $size_name => $size) {
        if (isset($fresh['sizes'][$size_name]) || !isset($registered[$size_name])) {
            continue;
        }

        $normalized = wp_webp_normalize_size_metadata($size_name, $size);
        if ($normalized === null || !is_file(trailingslashit($directory) . $normalized['file'])) {
            continue;
        }

        $fresh['sizes'][$size_name] = $size;
    }

    return $fresh;
}

function wp_webp_cleanup_obsolete_webps(
    $attachment_id,
    $fresh,
    $stored,
    $registered_sizes,
    &$failures = null
) {
    $original = get_attached_file($attachment_id);
    if (!$original || !is_array($stored) || empty($stored['sizes']) || !is_array($stored['sizes'])) {
        return 0;
    }

    $fresh_sizes = is_array($fresh) && !empty($fresh['sizes']) && is_array($fresh['sizes'])
        ? $fresh['sizes']
        : [];
    $registered = array_fill_keys(array_map('strval', $registered_sizes), true);
    $directory = trailingslashit(dirname($original));
    $deleted = 0;

    foreach ($stored['sizes'] as $size_name => $old_size) {
        $old = wp_webp_normalize_size_metadata($size_name, $old_size);
        if ($old === null) {
            continue;
        }

        $obsolete = !isset($registered[$size_name]);
        $fresh_size = isset($fresh_sizes[$size_name])
            ? wp_webp_normalize_size_metadata($size_name, $fresh_sizes[$size_name])
            : null;

        if ($fresh_size !== null && $fresh_size['file'] !== $old['file']) {
            $obsolete = true;
        }

        if (!$obsolete && is_file($directory . $old['file'])) {
            continue;
        }

        $target = wp_webp_target_path($directory . $old['file']);
        if ($target === '' || !is_file($target)) {
            continue;
        }

        if (@unlink($target)) {
            $deleted++;
        } else {
            wp_webp_record_failure(
                $failures,
                wp_webp_relative_upload_path($target),
                'Suppression de l’ancien WebP impossible'
            );
        }
    }

    return $deleted;
}

/**
 * Traite un attachement : génère le WebP de l'original puis RECRÉE chaque
 * format à partir de l'image originale (resizeImage), au lieu de convertir les
 * vignettes déjà produites par WordPress (souvent un peu floues).
 *
 * @param int             $attachment_id
 * @param array|null      $failures  Rempli (par référence) avec les fichiers en échec.
 * @param array|null|false $metadata Métadonnées fraîches (filtre wp_generate_attachment_metadata).
 * @return int Nombre de fichiers WebP réellement générés.
 */
function wp_webp_process_attachment($attachment_id, &$failures = null, $metadata = null) {
    $original = get_attached_file($attachment_id);
    if (!$original || !file_exists($original)) {
        wp_webp_record_failure($failures, 'attachment #' . (int) $attachment_id, 'Fichier source introuvable');
        return 0;
    }
    if (!wp_webp_attachment_supported($attachment_id, $original)) {
        return 0;
    }

    if ($metadata === null) {
        $metadata = wp_get_attachment_metadata($attachment_id);
    }
    $registered = wp_webp_get_image_sizes();
    $variants = [[
        'width' => 0,
        'height' => 0,
        'crop' => false,
        'output_source' => $original,
    ]];

    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        $dir = trailingslashit(dirname($original));
        foreach ($metadata['sizes'] as $size_name => $size) {
            $size = wp_webp_normalize_size_metadata($size_name, $size, $failures);
            if ($size === null) {
                continue;
            }

            if (!isset($registered[$size_name])) {
                continue;
            }

            if (!wp_webp_size_enabled($size_name)) {
                wp_webp_delete_attachment_size($attachment_id, $size_name, $failures);
                continue;
            }

            $crop = $registered[$size_name]['crop'];
            $variants[] = [
                'width' => (int) $size['width'],
                'height' => (int) $size['height'],
                'crop' => $crop,
                'output_source' => $dir . $size['file'],
            ];
        }
    }

    $generated = 0;

    if (!wp_webp_should_reuse_source($original)) {
        foreach ($variants as $variant) {
            $generated += wp_webp_make_webp(
                $original,
                $variant['width'],
                $variant['height'],
                $variant['crop'],
                $variant['output_source'],
                $failures,
                $attachment_id
            );
        }

        return $generated;
    }

    $source = null;

    try {
        $source = new Imagick($original);
        $profile = wp_webp_get_profile();
        $near_lossless = wp_webp_resolve_graphic_image(
            $attachment_id,
            $original,
            $profile,
            $source
        );

        foreach ($variants as $variant) {
            $generated += wp_webp_make_webp_from_source(
                $source,
                $variant['width'],
                $variant['height'],
                $variant['crop'],
                $variant['output_source'],
                $profile,
                $near_lossless,
                $failures
            );
        }
    } catch (Throwable $e) {
        wp_webp_record_failure(
            $failures,
            wp_webp_relative_upload_path($original),
            $e->getMessage()
        );
    } finally {
        if ($source instanceof Imagick) {
            $source->clear();
            $source->destroy();
        }
    }

    return $generated;
}

function wp_webp_should_reuse_source($path) {
    $dimensions = @getimagesize($path);
    if (!$dimensions || empty($dimensions[0]) || empty($dimensions[1])) {
        return false;
    }

    $max_pixels = 12000000;

    try {
        $imagick_memory = (int) Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY);
        if ($imagick_memory > 0) {
            // Source + clone de travail + marge pour le resize et l'encodeur.
            $max_pixels = min($max_pixels, (int) floor($imagick_memory / 32));
        }
    } catch (Throwable $e) {
        // La limite fixe reste suffisamment prudente.
    }

    $max_pixels = (int) apply_filters(
        'wp_webp_reuse_source_max_pixels',
        max(250000, $max_pixels)
    );

    return ((int) $dimensions[0] * (int) $dimensions[1]) <= max(0, $max_pixels);
}

/**
 * Chemin WebP cible : image.jpg -> image.webp (l'extension est remplacée).
 * Retourne '' si le format source n'est pas pris en charge.
 */
function wp_webp_target_path($path) {
    if (!preg_match('/\.(jpe?g|png)$/i', $path)) {
        return '';
    }

    return preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
}

/**
 * Filtres de rééchantillonnage disponibles (nom lisible => constante Imagick).
 */
function wp_webp_filters() {
    return [
        'lanczos'  => 'Imagick::FILTER_LANCZOS',
        'triangle' => 'Imagick::FILTER_TRIANGLE',
        'mitchell' => 'Imagick::FILTER_MITCHELL',
        'catrom'   => 'Imagick::FILTER_CATROM',
        'point'    => 'Imagick::FILTER_POINT',
    ];
}

/**
 * Résout un nom de filtre en constante Imagick (fallback Lanczos).
 */
function wp_webp_resolve_filter($name) {
    $map = wp_webp_filters();
    $const = isset($map[$name]) ? $map[$name] : 'Imagick::FILTER_LANCZOS';
    if (defined($const)) {
        return constant($const);
    }
    return defined('Imagick::FILTER_LANCZOS') ? constant('Imagick::FILTER_LANCZOS') : 0;
}

/**
 * Clé de cache (transient) pour la détection graphique d'un attachement.
 */
function wp_webp_graphic_cache_key($attachment_id) {
    return 'wp_webp_gfx_' . max(0, (int) $attachment_id);
}

/**
 * Invalide le cache de détection graphique.
 */
function wp_webp_clear_graphic_cache($attachment_id) {
    if ($attachment_id > 0) {
        delete_transient(wp_webp_graphic_cache_key($attachment_id));
    }
}

/**
 * Détecte une image « graphique » JPG/PNG (aplats de couleur, peu de teintes distinctes).
 * Analyse une miniature pour limiter le coût CPU.
 */
function wp_webp_is_graphic_image(Imagick $img, array $profile) {
    $format = strtolower($img->getImageFormat());
    if (!in_array($format, ['jpeg', 'jpg', 'png'], true)) {
        return false;
    }

    $max_colors = isset($profile['graphic_colors']) ? (int) $profile['graphic_colors'] : 8192;
    $sample = null;

    try {
        $sample = clone $img;
        $sample->thumbnailImage(160, 160, true);
        $colors = (int) $sample->getImageColors();

        return $colors > 0 && $colors <= $max_colors;
    } catch (Throwable $e) {
        return false;
    } finally {
        if ($sample instanceof Imagick) {
            $sample->clear();
            $sample->destroy();
        }
    }
}

/**
 * Analyse le fichier original JPG/PNG (sans redimensionnement).
 */
function wp_webp_detect_graphic_file($path, array $profile) {
    if (!preg_match('/\.(jpe?g|png)$/i', $path) || !file_exists($path)) {
        return false;
    }

    $img = null;

    try {
        $img = new Imagick($path);
        return wp_webp_is_graphic_image($img, $profile);
    } catch (Throwable $e) {
        return false;
    } finally {
        if ($img instanceof Imagick) {
            $img->clear();
            $img->destroy();
        }
    }
}

/**
 * Résultat graphique / photo, mis en cache par attachement (requête + transient).
 */
function wp_webp_resolve_graphic_image(
    $attachment_id,
    $original_path,
    array $profile,
    ?Imagick $source_image = null
) {
    static $request_cache = [];

    $attachment_id = (int) $attachment_id;
    $cache_key = $attachment_id > 0
        ? 'a' . $attachment_id
        : 'p' . md5($original_path . '|' . (file_exists($original_path) ? (string) filemtime($original_path) : ''));

    if (array_key_exists($cache_key, $request_cache)) {
        return $request_cache[$cache_key];
    }

    if ($attachment_id > 0) {
        $cached = get_transient(wp_webp_graphic_cache_key($attachment_id));
        if ($cached !== false) {
            $request_cache[$cache_key] = ((int) $cached) === 1;
            return $request_cache[$cache_key];
        }
    }

    $result = $source_image instanceof Imagick
        ? wp_webp_is_graphic_image($source_image, $profile)
        : wp_webp_detect_graphic_file($original_path, $profile);
    $request_cache[$cache_key] = $result;

    if ($attachment_id > 0) {
        set_transient(wp_webp_graphic_cache_key($attachment_id), $result ? 1 : 0, HOUR_IN_SECONDS);
    }

    return $result;
}

/**
 * Applique les options Imagick WebP (lossy ou near-lossless).
 */
function wp_webp_apply_webp_options(Imagick $img, array $profile, $near_lossless = false) {
    $img->setImageFormat('webp');

    if (!method_exists($img, 'setOption')) {
        return;
    }

    try {
        $img->setOption('webp:method', (string) $profile['method']);
    } catch (Throwable $e) {
        // Option non supportée sur cette build ImageMagick.
    }

    if (!$near_lossless) {
        return;
    }

    $level = isset($profile['near_lossless']) ? max(0, min(100, (int) $profile['near_lossless'])) : 40;

    try {
        $img->setOption('webp:near-lossless', (string) $level);
        $img->setImageCompressionQuality(100);
    } catch (Throwable $e) {
        return;
    }

    try {
        $img->setOption('webp:use-sharp-yuv', 'true');
    } catch (Throwable $e) {
        // Option absente sur certaines versions.
    }
}

function wp_webp_write_temp_image(Imagick $img, $target) {
    $directory = dirname($target);

    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Dossier cible inaccessible en écriture');
    }

    $temporary = tempnam($directory, '.wp-webp-');
    if ($temporary === false) {
        throw new RuntimeException('Création du fichier temporaire impossible');
    }

    try {
        if (!$img->writeImage($temporary)) {
            throw new RuntimeException('Écriture du fichier WebP temporaire impossible');
        }

        clearstatcache(true, $temporary);
        if (!is_file($temporary) || filesize($temporary) === 0) {
            throw new RuntimeException('Le fichier WebP temporaire est vide');
        }

        @chmod($temporary, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);

        return $temporary;
    } catch (Throwable $e) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }

        throw $e;
    }
}

function wp_webp_commit_temp_image($temporary, $target) {
    if (!@rename($temporary, $target)) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }

        throw new RuntimeException('Installation atomique du fichier WebP impossible');
    }
}

function wp_webp_write_image_atomic(Imagick $img, $target) {
    $temporary = wp_webp_write_temp_image($img, $target);

    try {
        wp_webp_commit_temp_image($temporary, $target);
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function wp_webp_crop_offset($overflow, $position) {
    $overflow = max(0, (int) $overflow);

    if (in_array($position, ['left', 'top'], true)) {
        return 0;
    }
    if (in_array($position, ['right', 'bottom'], true)) {
        return $overflow;
    }

    return (int) floor($overflow / 2);
}

/**
 * Génère un fichier WebP à partir de l'image ORIGINALE.
 *
 * @param string     $original_path Chemin de l'image originale (source des pixels).
 * @param int        $width         Largeur cible (0 = pas de redimensionnement).
 * @param int        $height        Hauteur cible (0 = pas de redimensionnement).
 * @param bool|array $crop          Recadrage WordPress ou ajustement sans crop.
 * @param string     $output_source Chemin du fichier WP correspondant : sert à
 *                                  nommer le .webp et de référence pour le plafond.
 * @param array|null $failures      Rempli en cas d'échec.
 * @param int        $attachment_id ID WordPress (cache détection graphique).
 * @return int 1 si généré, 0 sinon.
 */
function wp_webp_make_webp($original_path, $width, $height, $crop, $output_source, &$failures = null, $attachment_id = 0) {
    if (!file_exists($original_path)) {
        wp_webp_record_failure($failures, wp_webp_relative_upload_path($original_path), 'Fichier source introuvable');
        return 0;
    }

    $source = null;

    try {
        $source = new Imagick($original_path);
        $profile = wp_webp_get_profile();
        $near_lossless = wp_webp_resolve_graphic_image(
            $attachment_id,
            $original_path,
            $profile,
            $source
        );

        return wp_webp_make_webp_from_source(
            $source,
            $width,
            $height,
            $crop,
            $output_source,
            $profile,
            $near_lossless,
            $failures
        );
    } catch (Throwable $e) {
        wp_webp_record_failure($failures, wp_webp_relative_upload_path($output_source), $e->getMessage());
        return 0;
    } finally {
        if ($source instanceof Imagick) {
            $source->clear();
            $source->destroy();
        }
    }
}

function wp_webp_make_webp_from_source(
    Imagick $source,
    $width,
    $height,
    $crop,
    $output_source,
    array $profile,
    $near_lossless,
    &$failures = null
) {
    $target = wp_webp_target_path($output_source);
    if ($target === '') {
        wp_webp_record_failure($failures, wp_webp_relative_upload_path($output_source), 'Format non pris en charge');
        return 0;
    }

    $img = null;

    try {
        $img = clone $source;
        $img->stripImage();

        // Recréation du format depuis l'original via resizeImage (contrôle du
        // blur pour un rendu plus net que les vignettes WordPress).
        if ($width > 0 && $height > 0) {
            $blur = isset($profile['blur']) ? (float) $profile['blur'] : 1.0;
            $filter = wp_webp_resolve_filter(isset($profile['filter']) ? $profile['filter'] : 'lanczos');

            if ($crop) {
                $ow = $img->getImageWidth();
                $oh = $img->getImageHeight();
                if ($ow > 0 && $oh > 0) {
                    $crop = wp_webp_normalize_crop($crop);
                    $crop_x = is_array($crop) ? $crop[0] : 'center';
                    $crop_y = is_array($crop) ? $crop[1] : 'center';
                    $scale = max($width / $ow, $height / $oh);
                    $rw = (int) ceil($ow * $scale);
                    $rh = (int) ceil($oh * $scale);
                    $img->resizeImage($rw, $rh, $filter, $blur);
                    $img->cropImage(
                        $width,
                        $height,
                        wp_webp_crop_offset($rw - $width, $crop_x),
                        wp_webp_crop_offset($rh - $height, $crop_y)
                    );
                    $img->setImagePage($width, $height, 0, 0);
                }
            } else {
                $img->resizeImage($width, $height, $filter, $blur, true);
            }
        }

        // Accentuation : éviter les halos sur les aplats (near-lossless suffit).
        $radius = isset($profile['radius']) ? (float) $profile['radius'] : 0;
        $sigma = isset($profile['sigma']) ? (float) $profile['sigma'] : 0;
        if (!$near_lossless && $sigma > 0) {
            $pixels = $img->getImageWidth() * $img->getImageHeight();
            if ($pixels > 0 && $pixels <= 20000000) {
                $img->sharpenImage($radius, $sigma);
            }
        }

        wp_webp_apply_webp_options($img, $profile, $near_lossless);

        $quality = (int) $profile['quality'];
        if (!$near_lossless) {
            $img->setImageCompressionQuality($quality);
        }

        // Plafonnement : inutile en near-lossless (priorité à la netteté des aplats).
        if (!$near_lossless && !empty($profile['cap_to_original']) && file_exists($output_source)) {
            $ref = filesize($output_source);
            $floor = isset($profile['quality_floor']) ? (int) $profile['quality_floor'] : 60;
            $attempts = 0;
            while ($ref && $attempts < 6 && $quality > $floor) {
                $tmp = wp_webp_write_temp_image($img, $target);
                if (filesize($tmp) <= $ref) {
                    wp_webp_commit_temp_image($tmp, $target);
                    return 1;
                }
                @unlink($tmp);
                $quality = max($floor, $quality - 5);
                $img->setImageCompressionQuality($quality);
                $attempts++;
            }
        }

        wp_webp_write_image_atomic($img, $target);

        return 1;
    } catch (Throwable $e) {
        wp_webp_record_failure($failures, wp_webp_relative_upload_path($output_source), $e->getMessage());
        return 0;
    } finally {
        if ($img instanceof Imagick) {
            $img->clear();
            $img->destroy();
        }
    }
}

/**
 * Ajoute une ligne au rapport d'échecs (si un rapport est suivi).
 */
function wp_webp_record_failure(&$failures, $file, $error) {
    if (is_array($failures)) {
        $failures[] = [
            'file'  => $file,
            'error' => $error ?: 'Conversion impossible',
        ];
    }
}

function wp_webp_log_failures($context, $failures) {
    if (!is_array($failures) || $failures === []) {
        return;
    }

    $preview = array_slice($failures, 0, 5);
    $messages = [];

    foreach ($preview as $failure) {
        $messages[] = (string) ($failure['file'] ?? 'fichier inconnu')
            . ': ' . (string) ($failure['error'] ?? 'Conversion impossible');
    }

    $remaining = count($failures) - count($preview);
    if ($remaining > 0) {
        $messages[] = '+' . $remaining . ' autre(s) erreur(s)';
    }

    error_log('[WP WebP] ' . $context . ' — ' . implode(' | ', $messages));
}

/* -------------------------------------------------------------------------
 * Nettoyage : supprime les WebP associés quand l'image est supprimée.
 * ---------------------------------------------------------------------- */

add_action('delete_attachment', 'wp_webp_on_delete_attachment');

function wp_webp_on_delete_attachment($attachment_id) {
    wp_webp_clear_graphic_cache($attachment_id);

    $original = get_attached_file($attachment_id);
    if ($original) {
        wp_webp_delete_for($original);
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!empty($metadata['sizes']) && is_array($metadata['sizes']) && $original) {
        $dir = trailingslashit(dirname($original));
        foreach ($metadata['sizes'] as $size) {
            if (!empty($size['file']) && basename((string) $size['file']) === (string) $size['file']) {
                wp_webp_delete_for($dir . $size['file']);
            }
        }
    }
}

function wp_webp_delete_for($path) {
    $target = wp_webp_target_path($path);
    if ($target !== '' && file_exists($target)) {
        @unlink($target);
    }
}
