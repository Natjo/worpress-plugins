<?php
/**
 * Plugin Name: WP WebP
 * Description: Génère une version WebP de chaque image téléversée (et de toutes ses déclinaisons add_image_size), compatible Regenerate Thumbnails. Conversion via Imagick avec détection graphique near-lossless.
 * Version: 1.2.0
 * Requires PHP: 8.0
 * Author: Lonsdale studio
 */

if (!defined('ABSPATH')) exit;

define('WP_WEBP_PROFILE_OPTION', 'wp_webp_profile');
define('WP_WEBP_DISABLED_SIZES_OPTION', 'wp_webp_disabled_sizes');
define('WP_WEBP_SHOW_ORIGINAL_OPTION', 'wp_webp_show_original');
define('WP_WEBP_SIZE_GENERATED_OPTION', 'wp_webp_size_generated_at');
define('WP_WEBP_SIZE_ERRORS_OPTION', 'wp_webp_size_errors');
define('WP_WEBP_OPERATION_LOCK_OPTION', 'wp_webp_operation_lock');
define('WP_WEBP_ORIGINAL_SIZE', 'original');
define('WP_WEBP_MAX_FULL_DIMENSION', 2500);
define('WP_WEBP_BATCH_TIME_BUDGET', 15);
define('WP_WEBP_SIZE_ERRORS_LIMIT', 200);
define('WP_WEBP_OPERATION_LOCK_TTL', HOUR_IN_SECONDS);
define('WP_WEBP_TEMP_FILE_MAX_AGE', HOUR_IN_SECONDS);

/**
 * Dimension maximale du côté le plus long pour le WebP pleine taille.
 * Retourner 0 via le filtre pour conserver les dimensions de la source.
 */
function wp_webp_get_max_full_dimension() {
    return max(0, (int) apply_filters(
        'wp_webp_max_full_dimension',
        WP_WEBP_MAX_FULL_DIMENSION
    ));
}

/**
 * Timestamps Unix de dernière génération batch par format.
 *
 * @return array<string,int>
 */
function wp_webp_get_size_generated_map() {
    $map = get_option(WP_WEBP_SIZE_GENERATED_OPTION, []);
    return is_array($map) ? $map : [];
}

function wp_webp_get_size_generated_at($size) {
    $map = wp_webp_get_size_generated_map();
    return isset($map[$size]) ? (int) $map[$size] : 0;
}

function wp_webp_format_generated_at($timestamp) {
    $timestamp = (int) $timestamp;
    if ($timestamp <= 0) {
        return '—';
    }

    if (function_exists('wp_date')) {
        return wp_date('d/m/Y H:i', $timestamp);
    }

    return date_i18n('d/m/Y H:i', $timestamp);
}

/**
 * Erreurs de génération batch indexées par format.
 *
 * @return array<string, array<int, array{file:string,error:string}>>
 */
function wp_webp_get_size_errors_map() {
    $map = get_option(WP_WEBP_SIZE_ERRORS_OPTION, []);
    return is_array($map) ? $map : [];
}

/**
 * @return array<int, array{file:string,error:string}>
 */
function wp_webp_get_size_errors($size) {
    $map = wp_webp_get_size_errors_map();
    $size = (string) $size;
    return isset($map[$size]) && is_array($map[$size]) ? array_values($map[$size]) : [];
}

function wp_webp_get_size_error_count($size) {
    return count(wp_webp_get_size_errors($size));
}

/**
 * @param string[] $sizes
 */
function wp_webp_clear_size_errors(array $sizes) {
    $sizes = array_values(array_unique(array_filter(array_map('strval', $sizes))));
    if ($sizes === []) {
        return;
    }

    $map = wp_webp_get_size_errors_map();
    $changed = false;
    foreach ($sizes as $size) {
        if (isset($map[$size])) {
            unset($map[$size]);
            $changed = true;
        }
    }

    if ($changed) {
        update_option(WP_WEBP_SIZE_ERRORS_OPTION, $map, false);
    }
}

/**
 * Ajoute des échecs au journal par format (plafonné par taille).
 *
 * @param array<int, array{file?:string,error?:string,size?:string}> $failures
 */
function wp_webp_append_size_errors(array $failures) {
    if ($failures === []) {
        return;
    }

    $map = wp_webp_get_size_errors_map();
    $limit = max(1, (int) apply_filters('wp_webp_size_errors_limit', WP_WEBP_SIZE_ERRORS_LIMIT));
    $changed = false;

    foreach ($failures as $failure) {
        if (!is_array($failure)) {
            continue;
        }

        $size = isset($failure['size']) ? (string) $failure['size'] : '';
        if ($size === '') {
            continue;
        }

        if (!isset($map[$size]) || !is_array($map[$size])) {
            $map[$size] = [];
        }

        $map[$size][] = [
            'file'  => (string) ($failure['file'] ?? 'fichier inconnu'),
            'error' => (string) ($failure['error'] ?? 'Conversion impossible'),
        ];

        if (count($map[$size]) > $limit) {
            $map[$size] = array_slice($map[$size], -$limit);
        }

        $changed = true;
    }

    if ($changed) {
        update_option(WP_WEBP_SIZE_ERRORS_OPTION, $map, false);
    }
}

/**
 * Formats concernés par un run (pour reset des erreurs / dates).
 *
 * @return string[]
 */
function wp_webp_sizes_for_generation_scope($only_size = '') {
    $only_size = wp_webp_sanitize_only_size($only_size);
    if ($only_size !== '') {
        return [$only_size];
    }

    $sizes = [];
    if (wp_webp_size_enabled(WP_WEBP_ORIGINAL_SIZE)) {
        $sizes[] = WP_WEBP_ORIGINAL_SIZE;
    }
    foreach (array_keys(wp_webp_get_image_sizes()) as $name) {
        if (wp_webp_size_enabled($name)) {
            $sizes[] = $name;
        }
    }

    return $sizes;
}

/**
 * Compteurs d’erreurs pour l’UI (tous les formats gérés).
 *
 * @return array<string,int>
 */
function wp_webp_get_size_error_counts() {
    $counts = [];
    foreach (wp_webp_sizes_for_generation_scope('') as $size) {
        $counts[$size] = wp_webp_get_size_error_count($size);
    }
    // Inclure aussi les formats désactivés qui ont encore un journal.
    foreach (array_keys(wp_webp_get_size_errors_map()) as $size) {
        if (!isset($counts[$size])) {
            $counts[$size] = wp_webp_get_size_error_count($size);
        }
    }
    // Toujours exposer original même s’il est désactivé sans erreur.
    if (!isset($counts[WP_WEBP_ORIGINAL_SIZE])) {
        $counts[WP_WEBP_ORIGINAL_SIZE] = wp_webp_get_size_error_count(WP_WEBP_ORIGINAL_SIZE);
    }
    foreach (array_keys(wp_webp_get_image_sizes()) as $name) {
        if (!isset($counts[$name])) {
            $counts[$name] = wp_webp_get_size_error_count($name);
        }
    }

    return $counts;
}

/**
 * Enregistre la date de fin de génération pour un ou plusieurs formats.
 *
 * @param string[] $sizes
 * @return array{sizes:string[],timestamp:int,label:string}
 */
function wp_webp_touch_size_generated(array $sizes) {
    $sizes = array_values(array_unique(array_filter(array_map('strval', $sizes))));
    $timestamp = time();
    if ($sizes === []) {
        return [
            'sizes'     => [],
            'timestamp' => $timestamp,
            'label'     => wp_webp_format_generated_at($timestamp),
        ];
    }

    $map = wp_webp_get_size_generated_map();
    foreach ($sizes as $size) {
        $map[$size] = $timestamp;
    }
    update_option(WP_WEBP_SIZE_GENERATED_OPTION, $map, false);

    return [
        'sizes'     => $sizes,
        'timestamp' => $timestamp,
        'label'     => wp_webp_format_generated_at($timestamp),
    ];
}

/**
 * Marque la fin d’un run AJAX (format ciblé ou original + formats activés).
 */
function wp_webp_mark_generation_complete($only_size = '') {
    return wp_webp_touch_size_generated(wp_webp_sizes_for_generation_scope($only_size));
}

function wp_webp_generation_completion($only_size, $failure_count) {
    if ((int) $failure_count > 0) {
        return [
            'sizes'     => [],
            'timestamp' => 0,
            'label'     => '—',
        ];
    }

    return wp_webp_mark_generation_complete($only_size);
}

/**
 * Profils de qualité. Chaque profil règle :
 * - quality : qualité de compression WebP (0-100) ;
 * - sigma   : accentuation Imagick::sharpenImage (0 = pas d'accentuation) ;
 * - method  : effort de compression WebP (0-6, 6 = meilleure compression).
 *
 * Finest  : qualité maximale, accentuation marquée (fichiers plus lourds).
 * Natural : haute qualité sans sharpen.
 * Optimal : meilleur compromis qualité / poids (recommandé).
 * Green   : poids minimal, accentuation légère (fichiers très légers).
 */
function wp_webp_profiles() {
    return [
        'finest' => [
            'label'           => 'Finest',
            'desc'            => 'Qualité maximale, accentuation marquée (fichiers plus lourds).',
            'quality'         => 85,
            'radius'          => 0,
            'sigma'           => 0.8,
            'blur'            => 0.8,
            'filter'          => 'lanczos',
            'method'          => 6,
            'near_lossless'   => 85,
            'graphic_colors'  => 8192,
            'cap_to_original' => true,
            'quality_floor'   => 70,
        ],
        'natural' => [
            'label'           => 'Natural',
            'desc'            => 'Haute qualité sans accentuation.',
            'quality'         => 80,
            'radius'          => 0,
            'sigma'           => 0,
            'blur'            => 0.95,
            'filter'          => 'lanczos',
            'method'          => 6,
            'near_lossless'   => 70,
            'graphic_colors'  => 8192,
            'cap_to_original' => true,
            'quality_floor'   => 60,
        ],
        'optimal' => [
            'label'           => 'Optimal',
            'desc'            => 'Meilleur compromis qualité / poids (recommandé).',
            'quality'         => 75,
            'radius'          => 0,
            'sigma'           => 0.6,
            'blur'            => 0.9,
            'filter'          => 'lanczos',
            'method'          => 6,
            'near_lossless'   => 55,
            'graphic_colors'  => 8192,
            'cap_to_original' => true,
            'quality_floor'   => 55,
        ],
        'green' => [
            'label'           => 'Green',
            'desc'            => 'Poids minimal, accentuation légère (fichiers très légers).',
            'quality'         => 68,
            'radius'          => 0,
            'sigma'           => 0.5,
            'blur'            => 1.0,
            'filter'          => 'triangle',
            'method'          => 6,
            'near_lossless'   => 40,
            'graphic_colors'  => 8192,
            'cap_to_original' => true,
            'quality_floor'   => 45,
        ],
    ];
}

/**
 * Normalise une clé de profil (dont l’ancien alias `best` → `finest`).
 */
function wp_webp_normalize_profile_key($value) {
    $value = is_string($value) ? $value : '';
    if ($value === 'best') {
        $value = 'finest';
    }

    $profiles = wp_webp_profiles();
    return isset($profiles[$value]) ? $value : 'optimal';
}

function wp_webp_get_profile_key() {
    return wp_webp_normalize_profile_key(get_option(WP_WEBP_PROFILE_OPTION, 'optimal'));
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
 * Budget de conversion d'une requête AJAX, en secondes.
 *
 * La limite utile n'est pas celle de PHP mais celle du serveur web : passé son
 * délai, Apache renvoie sa propre page 500 et la réponse JSON est perdue. On
 * s'arrête donc entre deux déclinaisons pour rendre la main bien avant.
 */
function wp_webp_batch_time_budget() {
    $budget = (float) apply_filters('wp_webp_batch_time_budget', WP_WEBP_BATCH_TIME_BUDGET);

    return max(1.0, $budget);
}

function wp_webp_batch_deadline() {
    return microtime(true) + wp_webp_batch_time_budget();
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
 * Valide un nom de format pour une génération ciblée.
 * Chaîne vide = original + tous les formats activés.
 * `original` = fichier source non redimensionné uniquement.
 */
function wp_webp_sanitize_only_size($size) {
    $size = is_string($size) ? trim($size) : '';
    if ($size === '') {
        return '';
    }

    if ($size === WP_WEBP_ORIGINAL_SIZE) {
        return WP_WEBP_ORIGINAL_SIZE;
    }

    $registered = wp_webp_get_image_sizes();
    return isset($registered[$size]) ? $size : '';
}

/**
 * Liste des conversions à effectuer pour un attachement (original + déclinaisons).
 *
 * @param string $only_size `original`, un nom de format enregistré, ou '' (global).
 */
function wp_webp_jobs_for_attachment($attachment_id, $only_size = '') {
    $only_size = wp_webp_sanitize_only_size($only_size);
    $jobs = [];
    $sizes_meta = wp_webp_get_image_sizes();
    $registered = array_fill_keys(array_keys($sizes_meta), true);
    $seen_sizes = [];

    if ($only_size === '' || $only_size === WP_WEBP_ORIGINAL_SIZE) {
        if ($only_size === WP_WEBP_ORIGINAL_SIZE || wp_webp_size_enabled(WP_WEBP_ORIGINAL_SIZE)) {
            $jobs[] = ['type' => 'original'];
        }
    }

    if ($only_size === WP_WEBP_ORIGINAL_SIZE) {
        return $jobs;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size_name => $size) {
            if ($only_size !== '' && $size_name !== $only_size) {
                continue;
            }

            $size = wp_webp_normalize_size_metadata($size_name, $size);
            if (
                $size === null
                || !isset($registered[$size_name])
                || ($only_size === '' && !wp_webp_size_enabled($size_name))
            ) {
                continue;
            }
            $jobs[] = [
                'type'   => 'size',
                'name'   => $size_name,
                'width'  => (int) $sizes_meta[$size_name]['width'],
                'height' => (int) $sizes_meta[$size_name]['height'],
                'file'   => $size['file'],
            ];
            $seen_sizes[$size_name] = true;
        }
    }

    // Déclinaisons présentes sur le disque mais absentes des meta (ex. regenerate
    // partiel, ou crop non listé car l'original avait déjà les mêmes dims).
    $original = get_attached_file($attachment_id);
    if ($original && is_file($original)) {
        $dir = trailingslashit(dirname($original));
        $base = pathinfo($original, PATHINFO_FILENAME);
        $ext = pathinfo($original, PATHINFO_EXTENSION);
        $candidates = $only_size !== ''
            ? [$only_size => $sizes_meta[$only_size] ?? null]
            : $sizes_meta;

        foreach ($candidates as $size_name => $reg) {
            if ($reg === null || isset($seen_sizes[$size_name])) {
                continue;
            }
            if ($only_size === '' && !wp_webp_size_enabled($size_name)) {
                continue;
            }

            $w = (int) $reg['width'];
            $h = (int) $reg['height'];
            if ($w <= 0 || $h <= 0 || $ext === '') {
                continue;
            }

            $file = $base . '-' . $w . 'x' . $h . '.' . $ext;
            if (!is_file($dir . $file)) {
                continue;
            }

            $jobs[] = [
                'type'   => 'size',
                'name'   => $size_name,
                'width'  => $w,
                'height' => $h,
                'file'   => $file,
            ];
            $seen_sizes[$size_name] = true;
        }
    }

    return $jobs;
}

/**
 * Nom du format associé à un job de génération.
 */
function wp_webp_job_size(array $job, $fallback = '') {
    if (($job['type'] ?? '') === 'original') {
        return WP_WEBP_ORIGINAL_SIZE;
    }

    $name = isset($job['name']) ? (string) $job['name'] : '';
    return $name !== '' ? $name : (string) $fallback;
}

/**
 * Génère un seul WebP (original ou déclinaison) pour un attachement.
 */
function wp_webp_process_job($attachment_id, $job_index, &$failures = null, $only_size = '') {
    $original = get_attached_file($attachment_id);
    if (!$original || !file_exists($original)) {
        wp_webp_record_failure(
            $failures,
            'attachment #' . (int) $attachment_id,
            'Fichier source introuvable',
            wp_webp_sanitize_only_size($only_size)
        );
        return 0;
    }
    if (!wp_webp_attachment_supported($attachment_id, $original)) {
        return 0;
    }

    $jobs = wp_webp_jobs_for_attachment($attachment_id, $only_size);
    if (!isset($jobs[$job_index])) {
        wp_webp_record_failure(
            $failures,
            'attachment #' . (int) $attachment_id,
            'Déclinaison introuvable',
            wp_webp_sanitize_only_size($only_size)
        );
        return 0;
    }

    $job = $jobs[$job_index];
    $job_size = wp_webp_job_size($job, wp_webp_sanitize_only_size($only_size));
    $failure_offset = is_array($failures) ? count($failures) : 0;

    if ($job['type'] === 'original') {
        $generated = wp_webp_make_webp($original, 0, 0, false, $original, $failures, $attachment_id);
        wp_webp_tag_failures($failures, $failure_offset, $job_size);
        return $generated;
    }

    $registered = wp_webp_get_image_sizes();
    $crop = isset($registered[$job['name']]) ? $registered[$job['name']]['crop'] : false;
    $dir = trailingslashit(dirname($original));

    $generated = wp_webp_make_webp(
        $original,
        (int) $job['width'],
        (int) $job['height'],
        $crop,
        $dir . $job['file'],
        $failures,
        $attachment_id
    );
    wp_webp_tag_failures($failures, $failure_offset, $job_size);

    return $generated;
}

/**
 * Traduit un job en variante exploitable par les encodeurs.
 *
 * @return array{width:int,height:int,crop:bool|array,output_source:string}|null
 */
function wp_webp_variant_for_job(array $job, $original, $directory, array $registered) {
    if (($job['type'] ?? '') === 'original') {
        return [
            'width' => 0,
            'height' => 0,
            'crop' => false,
            'output_source' => (string) $original,
        ];
    }

    $name = isset($job['name']) ? (string) $job['name'] : '';
    if ($name === '' || !isset($registered[$name]) || empty($job['file'])) {
        return null;
    }

    return [
        'width' => (int) $job['width'],
        'height' => (int) $job['height'],
        'crop' => $registered[$name]['crop'],
        'output_source' => $directory . $job['file'],
    ];
}

/**
 * Traite les jobs d'un attachement avec un seul décodage Imagick lorsque
 * la garde mémoire l'autorise.
 *
 * Le traitement peut s'arrêter avant la fin lorsque `$deadline` est dépassée :
 * `next_index` indique alors la déclinaison à reprendre au prochain lot. Au
 * moins un job est toujours traité, afin qu'un curseur ne puisse pas piétiner.
 *
 * @param float $deadline Timestamp `microtime(true)` limite, `0` = pas de limite.
 * @return array{generated:int,processed:int,next_index:int,complete:bool}
 */
function wp_webp_process_attachment_jobs(
    $attachment_id,
    &$failures = null,
    $only_size = '',
    $start_index = 0,
    $deadline = 0.0
) {
    $original = get_attached_file($attachment_id);
    if (!$original || !is_file($original)) {
        wp_webp_record_failure($failures, 'attachment #' . (int) $attachment_id, 'Fichier source introuvable');
        return ['generated' => 0, 'processed' => 0, 'next_index' => 0, 'complete' => true];
    }
    if (!wp_webp_attachment_supported($attachment_id, $original)) {
        wp_webp_record_failure($failures, 'attachment #' . (int) $attachment_id, 'Format source non pris en charge');
        return ['generated' => 0, 'processed' => 0, 'next_index' => 0, 'complete' => true];
    }

    $jobs = wp_webp_jobs_for_attachment($attachment_id, $only_size);
    $total_jobs = count($jobs);
    $start_index = max(0, (int) $start_index);
    if ($total_jobs === 0 || $start_index >= $total_jobs) {
        return ['generated' => 0, 'processed' => 0, 'next_index' => 0, 'complete' => true];
    }

    $registered = wp_webp_get_image_sizes();
    $directory = trailingslashit(dirname($original));
    $reuse_source = wp_webp_should_reuse_source($original);
    $deadline = (float) $deadline;

    $generated = 0;
    $processed = 0;
    $next_index = $start_index;
    $complete = true;
    $source = null;
    $near_lossless = false;

    try {
        $profile = wp_webp_get_profile();

        if ($reuse_source) {
            $source = new Imagick($original);
            $near_lossless = wp_webp_resolve_graphic_image(
                $attachment_id,
                $original,
                $profile,
                $source
            );
        }

        for ($index = $start_index; $index < $total_jobs; $index++) {
            if ($index > $start_index && $deadline > 0 && microtime(true) >= $deadline) {
                $complete = false;
                break;
            }

            $variant = wp_webp_variant_for_job($jobs[$index], $original, $directory, $registered);
            $next_index = $index + 1;
            $processed++;
            $job_size = wp_webp_job_size($jobs[$index]);
            $failure_offset = is_array($failures) ? count($failures) : 0;

            if ($variant === null) {
                wp_webp_record_failure(
                    $failures,
                    'attachment #' . (int) $attachment_id,
                    'Déclinaison invalide',
                    $job_size
                );
                continue;
            }

            if ($source instanceof Imagick) {
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
                wp_webp_tag_failures($failures, $failure_offset, $job_size);
                continue;
            }

            $generated += wp_webp_make_webp(
                $original,
                $variant['width'],
                $variant['height'],
                $variant['crop'],
                $variant['output_source'],
                $failures,
                $attachment_id
            );
            wp_webp_tag_failures($failures, $failure_offset, $job_size);
        }
    } catch (Throwable $e) {
        $fallback_size = '';
        if (isset($jobs[$next_index > 0 ? $next_index - 1 : $start_index])) {
            $job = $jobs[$next_index > 0 ? $next_index - 1 : $start_index];
            $fallback_size = wp_webp_job_size($job);
        }
        wp_webp_record_failure(
            $failures,
            wp_webp_relative_upload_path($original),
            $e->getMessage(),
            $fallback_size
        );
    } finally {
        if ($source instanceof Imagick) {
            $source->clear();
            $source->destroy();
        }
    }

    if ($next_index >= $total_jobs) {
        $complete = true;
    }

    return [
        'generated' => $generated,
        'processed' => $processed,
        'next_index' => $complete ? 0 : $next_index,
        'complete' => $complete,
    ];
}

/**
 * Passe au job suivant (déclinaison suivante ou attachement suivant).
 */
function wp_webp_advance_cursor($attachment_id, $job_index, $max_attachment_id = 0, $only_size = '') {
    $jobs = wp_webp_jobs_for_attachment($attachment_id, $only_size);
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
 * Avance jusqu’au prochain job réellement traitable (ignore les médias
 * sans la déclinaison demandée), avec une limite par requête AJAX.
 */
function wp_webp_seek_processable_job($attachment_id, $job_index, $max_attachment_id, $only_size, &$processed_attachments) {
    $guard = 0;

    while ($attachment_id > 0 && $guard < 80) {
        $guard++;
        $jobs = wp_webp_jobs_for_attachment($attachment_id, $only_size);
        if (isset($jobs[$job_index])) {
            return [
                'attachment_id' => $attachment_id,
                'job_index'     => $job_index,
                'done'          => false,
            ];
        }

        $processed_attachments++;
        $next_attachment = wp_webp_get_next_attachment_id($attachment_id, $max_attachment_id);
        if ($next_attachment <= 0) {
            return [
                'attachment_id' => 0,
                'job_index'     => 0,
                'done'          => true,
            ];
        }

        $attachment_id = $next_attachment;
        $job_index = 0;
    }

    return [
        'attachment_id' => $attachment_id,
        'job_index'     => $job_index,
        'done'          => false,
        'seeking'       => true,
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
        return $crop ? 'Centré' : 'Non';
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

/**
 * L'original reste affiché par défaut pour préserver le comportement existant.
 */
function wp_webp_show_original_size() {
    return get_option(WP_WEBP_SHOW_ORIGINAL_OPTION, '1') !== '0';
}

function wp_webp_size_enabled($name) {
    if ($name === WP_WEBP_ORIGINAL_SIZE && !wp_webp_show_original_size()) {
        return true;
    }

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
    if (wp_webp_operation_is_locked()) {
        wp_send_json_error(['message' => 'Une opération WP WebP est en cours.'], 409);
    }

    $profile = wp_webp_sanitize_profile(isset($_POST['profile']) ? wp_unslash($_POST['profile']) : '');
    update_option(WP_WEBP_PROFILE_OPTION, $profile);

    wp_send_json_success(['profile' => $profile]);
}

function wp_webp_sanitize_profile($value) {
    return wp_webp_normalize_profile_key($value);
}

add_action('wp_ajax_wp_webp_save_size', 'wp_webp_ajax_save_size');

function wp_webp_ajax_save_size() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_size_action', 'nonce');
    if (wp_webp_operation_is_locked()) {
        wp_send_json_error(['message' => 'Une opération WP WebP est en cours.'], 409);
    }

    $size = isset($_POST['size']) ? sanitize_key(wp_unslash($_POST['size'])) : '';
    $enabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1');

    $valid = array_merge([WP_WEBP_ORIGINAL_SIZE], array_keys(wp_webp_get_image_sizes()));
    if ($size === '' || !in_array($size, $valid, true)) {
        wp_send_json_error(['message' => 'Format inconnu.'], 400);
    }
    if ($size === WP_WEBP_ORIGINAL_SIZE && !$enabled && !wp_webp_show_original_size()) {
        wp_send_json_error(['message' => 'Le format original masqué reste toujours actif.'], 409);
    }

    $disabled = wp_webp_get_disabled_sizes();
    if ($enabled) {
        $disabled = array_values(array_diff($disabled, [$size]));
    } elseif (!in_array($size, $disabled, true)) {
        $disabled[] = $size;
    }

    update_option(WP_WEBP_DISABLED_SIZES_OPTION, $disabled);

    wp_send_json_success([
        'size'    => $size,
        'enabled' => $enabled,
    ]);
}

add_action('wp_ajax_wp_webp_save_original_visibility', 'wp_webp_ajax_save_original_visibility');

function wp_webp_ajax_save_original_visibility() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_original_visibility_action', 'nonce');
    if (wp_webp_operation_is_locked()) {
        wp_send_json_error(['message' => 'Une opération WP WebP est en cours.'], 409);
    }

    $visible = isset($_POST['visible']) && $_POST['visible'] === '1';
    update_option(WP_WEBP_SHOW_ORIGINAL_OPTION, $visible ? '1' : '0');

    if (!$visible) {
        $disabled = array_values(array_diff(
            wp_webp_get_disabled_sizes(),
            [WP_WEBP_ORIGINAL_SIZE]
        ));
        update_option(WP_WEBP_DISABLED_SIZES_OPTION, $disabled);
    }

    wp_send_json_success([
        'visible' => $visible,
        'enabled' => wp_webp_size_enabled(WP_WEBP_ORIGINAL_SIZE),
    ]);
}

/**
 * Formats gérés par le plugin et actuellement désactivés (Compresser décoché).
 *
 * @return string[]
 */
function wp_webp_get_disabled_managed_sizes() {
    $sizes = array_merge([WP_WEBP_ORIGINAL_SIZE], array_keys(wp_webp_get_image_sizes()));
    $disabled = [];

    foreach ($sizes as $name) {
        if (!wp_webp_size_enabled($name)) {
            $disabled[] = $name;
        }
    }

    return $disabled;
}

add_action('wp_ajax_wp_webp_cleanup_unused', 'wp_webp_ajax_cleanup_unused');

function wp_webp_ajax_cleanup_unused() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_clear_action', 'nonce');
    wp_webp_prepare_batch_environment();

    $after_id = isset($_POST['after_id']) ? max(0, (int) $_POST['after_id']) : 0;
    $run_id = isset($_POST['run_id'])
        ? strtolower(preg_replace('/[^a-z0-9]/i', '', (string) wp_unslash($_POST['run_id'])))
        : '';
    $disabled = wp_webp_get_disabled_managed_sizes();

    if ($disabled === []) {
        if ($run_id !== '') {
            wp_webp_release_operation_lock($run_id);
        }
        wp_send_json_success([
            'run_id'  => $run_id,
            'after_id' => $after_id,
            'deleted'  => 0,
            'done'     => true,
            'empty'    => true,
            'failures' => [],
            'sizes'    => [],
        ]);
    }

    if ($run_id === '') {
        if ($after_id > 0) {
            wp_send_json_error(['message' => 'Session de nettoyage invalide.'], 400);
        }
        $run_id = strtolower(wp_generate_password(20, false, false));
        if (!wp_webp_acquire_operation_lock($run_id)) {
            wp_send_json_error(['message' => 'Une autre opération WP WebP est déjà en cours.'], 409);
        }
    } elseif (!preg_match('/^[a-z0-9]{20}$/', $run_id)) {
        wp_send_json_error(['message' => 'Session de nettoyage invalide.'], 400);
    } elseif (!wp_webp_ensure_operation_lock($run_id)) {
        wp_send_json_error(['message' => 'Une autre opération WP WebP est déjà en cours.'], 409);
    }

    try {
        $ids = wp_webp_get_attachment_ids_after($after_id, 100);
        $deleted = 0;
        $failures = [];

        foreach ($ids as $attachment_id) {
            $deleted += wp_webp_delete_attachment_sizes($attachment_id, $disabled, $failures);
        }

        $next_after_id = $ids !== [] ? (int) end($ids) : $after_id;
        $done = count($ids) < 100;
        if ($done) {
            wp_webp_release_operation_lock($run_id);
        }

        wp_send_json_success([
            'run_id'  => $run_id,
            'after_id' => $next_after_id,
            'deleted'  => $deleted,
            'done'     => $done,
            'empty'    => false,
            'failures' => $failures,
            'sizes'    => $disabled,
        ]);
    } catch (Throwable $e) {
        wp_webp_release_operation_lock($run_id);
        error_log('[WP WebP] cleanup unused failed: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Nettoyage des formats impossible.'], 500);
    }
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
    return wp_webp_delete_attachment_sizes($attachment_id, [(string) $size_name], $failures);
}

/**
 * Supprime plusieurs formats d’un attachement en ne chargeant son fichier et
 * ses métadonnées qu’une seule fois.
 *
 * @param string[] $size_names
 */
function wp_webp_delete_attachment_sizes($attachment_id, array $size_names, &$failures = null) {
    $original = get_attached_file($attachment_id);
    if (!$original) {
        return 0;
    }

    $size_names = array_values(array_unique(array_filter(array_map('strval', $size_names))));
    $metadata = in_array(WP_WEBP_ORIGINAL_SIZE, $size_names, true) && count($size_names) === 1
        ? null
        : wp_get_attachment_metadata($attachment_id);
    $deleted = 0;

    foreach ($size_names as $size_name) {
        if ($size_name === WP_WEBP_ORIGINAL_SIZE) {
            $target = wp_webp_target_path($original);
        } else {
            $size = is_array($metadata) && isset($metadata['sizes'][$size_name])
                ? $metadata['sizes'][$size_name]
                : null;

            if (!is_array($size) || empty($size['file'])) {
                continue;
            }

            $file = basename((string) $size['file']);
            $target = wp_webp_target_path(trailingslashit(dirname($original)) . $file);
        }

        if ($target === '' || !file_exists($target)) {
            continue;
        }

        if (!@unlink($target)) {
            wp_webp_record_failure(
                $failures,
                wp_webp_relative_upload_path($target),
                'Suppression du fichier WebP impossible'
            );
            continue;
        }

        $deleted++;
    }

    return $deleted;
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
        <?php $wp_webp_profiles = wp_webp_profiles(); ?>
        <style>
            #wp-webp-profile {
                padding-right: 2em;
                min-width: 10em;
            }
        </style>
        <p style="margin-bottom:4px;">
            <select id="wp-webp-profile" name="<?php echo esc_attr(WP_WEBP_PROFILE_OPTION); ?>">
                <?php foreach ($wp_webp_profiles as $key => $profile) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($current, $key); ?>>
                        <?php echo esc_html($profile['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p id="wp-webp-profile-desc" style="margin-top:0; color:#50575e;">
            <?php echo esc_html($wp_webp_profiles[$current]['desc'] ?? ''); ?>
        </p>
        <script>
        (function () {
            var select = document.getElementById('wp-webp-profile');
            var desc = document.getElementById('wp-webp-profile-desc');
            if (!select) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_webp_profile_action')); ?>;
            var savedValue = select.value;
            var descriptions = <?php
                $descs = [];
                foreach ($wp_webp_profiles as $key => $profile) {
                    $descs[$key] = (string) ($profile['desc'] ?? '');
                }
                echo wp_json_encode($descs);
            ?>;

            select.addEventListener('change', function () {
                if (desc) {
                    desc.textContent = descriptions[select.value] || '';
                }

                var body = new URLSearchParams();
                body.append('action', 'wp_webp_save_profile');
                body.append('nonce', nonce);
                body.append('profile', select.value);

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error('failed');
                    }
                    savedValue = select.value;
                })
                .catch(function () {
                    select.value = savedValue;
                    if (desc) {
                        desc.textContent = descriptions[savedValue] || '';
                    }
                });
            });
        })();
        </script>

        <hr>

        <?php
        $wp_webp_can_generate = wp_webp_imagick_available() && wp_webp_imagick_webp_supported();
        ?>
        <h2>Generate images</h2>
       <p>
            <button type="button" class="button button-primary" id="wp-webp-generate"<?php disabled(!$wp_webp_can_generate); ?>>Générer les WebP</button>
        </p>
        <div id="wp-webp-generate-progress" style="display:none; max-width:520px; margin:8px 0;">
            <div style="background:#e2e4e7; border-radius:999px; height:10px; overflow:hidden;">
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
            var imagickOk = <?php echo $wp_webp_can_generate ? 'true' : 'false'; ?>;
            var MAX_ATTEMPTS = 3;
            var RETRY_DELAY = 2000;
            var FAILURE_PREVIEW_LIMIT = 50;
            var STORAGE_KEY = 'wp_webp_generate_run_' + <?php echo (int) get_current_user_id(); ?>;

            var state = {
                running: false,
                paused: false,
                pausing: false,
                cursor: null,
                failures: [],
                failureCount: 0,
                totalAttachments: 0
            };

            window.wpWebpSetOperationControls = function (locked) {
                document.querySelectorAll(
                    '#wp-webp-generate, #wp-webp-profile, .wp-webp-size-cb, #wp-webp-show-original, #wp-webp-clear, #wp-webp-cleanup-unused'
                ).forEach(function (control) {
                    control.disabled = control.id === 'wp-webp-generate'
                        ? (!!locked || !imagickOk)
                        : !!locked;
                });
            };

            function setBar(processed, total) {
                var pct = total > 0 ? Math.round((processed / total) * 100) : 0;
                bar.style.width = pct + '%';
                return pct;
            }

            function renderFailures(failures, total) {
                if (!total) {
                    return '';
                }
                var preview = failures.slice(0, 8).map(function (failure) {
                    return failure.file + (failure.error ? ' (' + failure.error + ')' : '');
                }).join(' | ');

                return ' — ' + total + ' fichier(s) en erreur'
                    + (preview ? ' : ' + preview + (total > 8 ? '…' : '') : '');
            }

            function persistState() {
                if (!state.running || !state.cursor || !state.cursor.runId) {
                    return;
                }

                try {
                    sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                        cursor: state.cursor,
                        failures: state.failures,
                        failureCount: state.failureCount,
                        totalAttachments: state.totalAttachments,
                        paused: true
                    }));
                } catch (e) {}
            }

            function clearPersistedState() {
                try {
                    sessionStorage.removeItem(STORAGE_KEY);
                } catch (e) {}
            }

            function loadPersistedState() {
                try {
                    var raw = sessionStorage.getItem(STORAGE_KEY);
                    if (!raw) {
                        return null;
                    }
                    var data = JSON.parse(raw);
                    if (!data || !data.cursor || !data.cursor.runId) {
                        return null;
                    }
                    return data;
                } catch (e) {
                    return null;
                }
            }

            function syncGenerateButton() {
                window.wpWebpSetOperationControls(state.running);
                if (!state.running) {
                    btn.textContent = 'Générer les WebP';
                    btn.classList.add('button-primary');
                    btn.disabled = !imagickOk;
                    return;
                }

                btn.classList.remove('button-primary');
                if (state.pausing) {
                    btn.textContent = 'Pause…';
                    btn.disabled = true;
                    return;
                }

                if (state.paused) {
                    btn.textContent = 'Reprendre';
                    btn.disabled = false;
                    return;
                }

                btn.textContent = 'Pause';
                btn.disabled = false;
            }

            function stopGeneration(clearStorage) {
                state.running = false;
                state.paused = false;
                state.pausing = false;
                state.cursor = null;
                state.totalAttachments = 0;
                if (clearStorage !== false) {
                    clearPersistedState();
                }
                syncGenerateButton();
            }

            function wait(delay) {
                return new Promise(function (resolve) { setTimeout(resolve, delay); });
            }

            function postBatch(cursor, skipReason) {
                var body = new URLSearchParams();
                body.append('action', 'wp_webp_generate_webp');
                body.append('nonce', nonce);
                body.append('run_id', cursor.runId || '');
                body.append('attachment_id', String(cursor.attachmentId || 0));
                body.append('job_index', String(cursor.jobIndex || 0));
                body.append('processed_jobs', String(cursor.processedJobs || 0));
                body.append('processed_attachments', String(cursor.processedAttachments || 0));
                body.append('generated', String(cursor.generated || 0));
                if (skipReason) {
                    body.append('skip_attachment', '1');
                    body.append('skip_reason', skipReason);
                }

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
                            var responseError = new Error(data.data.message);
                            responseError.status = r.status;
                            throw responseError;
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error((data && data.data && data.data.message) ? data.data.message : 'failed');
                    }
                    return data;
                });
            }

            // Une image peut faire tomber le serveur avant qu'il puisse répondre
            // en JSON (timeout ou mémoire). On réessaie, puis on la fait ignorer
            // par le serveur pour que le reste de la médiathèque soit traité.
            function fetchBatch(cursor, attempt) {
                return postBatch(cursor, '').catch(function (error) {
                    var message = (error && error.message) ? error.message : 'Erreur serveur';

                    if (!state.running || state.paused) {
                        throw error;
                    }

                    if (error && error.status && error.status < 500) {
                        throw error;
                    }

                    if (attempt < MAX_ATTEMPTS) {
                        status.textContent = 'Réponse serveur invalide, nouvelle tentative ('
                            + attempt + '/' + MAX_ATTEMPTS + ')…';
                        return wait(RETRY_DELAY).then(function () {
                            return fetchBatch(cursor, attempt + 1);
                        });
                    }

                    if (!cursor.attachmentId) {
                        throw error;
                    }

                    status.textContent = 'Image #' + cursor.attachmentId
                        + ' ignorée après ' + MAX_ATTEMPTS + ' tentatives.';

                    return postBatch(cursor, message);
                });
            }

            function applyCursorFromResponse(data) {
                state.cursor = {
                    runId: data.data.run_id,
                    attachmentId: data.data.attachment_id,
                    jobIndex: data.data.job_index,
                    processedJobs: data.data.processed_jobs,
                    processedAttachments: data.data.processed_attachments,
                    generated: data.data.generated
                };
                var incomingFailures = Array.isArray(data.data.failures) ? data.data.failures : [];
                state.failures = state.failures.concat(incomingFailures).slice(0, FAILURE_PREVIEW_LIMIT);
                if (Number.isFinite(Number(data.data.failure_count))) {
                    state.failureCount = Math.max(0, Number(data.data.failure_count));
                } else {
                    state.failureCount += incomingFailures.length;
                }
                state.totalAttachments = data.data.total_attachments || state.totalAttachments;
                persistState();
                return setBar(data.data.processed_attachments, state.totalAttachments);
            }

            function markPaused(pct) {
                state.pausing = false;
                state.paused = true;
                persistState();

                var total = state.totalAttachments;
                var processed = state.cursor ? state.cursor.processedAttachments : 0;
                var generated = state.cursor ? state.cursor.generated : 0;
                if (typeof pct !== 'number') {
                    pct = total > 0 ? Math.round((processed / total) * 100) : 0;
                }

                status.textContent = 'En pause — '
                    + pct + '% (' + processed + ' / ' + total
                    + ' image(s), ' + generated + ' WebP généré(s)). Cliquez sur Reprendre.';
                syncGenerateButton();
            }

            async function runBatch() {
                while (state.running && !state.paused && state.cursor) {
                    var data = await fetchBatch(state.cursor, 1);
                    var pct = applyCursorFromResponse(data);

                    status.textContent = 'Traitement… ' + pct + '% ('
                        + data.data.processed_attachments + ' / ' + state.totalAttachments
                        + ' image(s), ' + data.data.processed_jobs + ' fichier(s), '
                        + data.data.generated + ' WebP généré(s)).';

                    if (data.data.done) {
                        setBar(1, 1);
                        status.textContent = 'Terminé : '
                            + data.data.processed_jobs + ' fichier(s) traité(s), '
                            + data.data.generated + ' WebP généré(s), '
                            + state.failureCount + ' erreur(s).'
                            + renderFailures(state.failures, state.failureCount);
                        if (data.data.touched_sizes && data.data.generated_at_label) {
                            data.data.touched_sizes.forEach(function (sizeName) {
                                var cell = document.querySelector('.wp-webp-generated-at[data-size="' + sizeName + '"]');
                                if (cell) {
                                    cell.textContent = data.data.generated_at_label;
                                }
                            });
                        }
                        if (data.data.size_error_counts && window.wpWebpUpdateErrorCounts) {
                            window.wpWebpUpdateErrorCounts(data.data.size_error_counts);
                        }
                        stopGeneration();
                        return;
                    }

                    if (data.data.size_error_counts && window.wpWebpUpdateErrorCounts) {
                        window.wpWebpUpdateErrorCounts(data.data.size_error_counts);
                    }

                    if (state.paused || state.pausing) {
                        markPaused(pct);
                        return;
                    }
                }
            }

            function startGeneration() {
                if (state.running) {
                    return;
                }

                clearPersistedState();
                state.running = true;
                state.paused = false;
                state.pausing = false;
                state.failures = [];
                state.failureCount = 0;
                state.totalAttachments = 0;
                state.cursor = {
                    runId: '',
                    attachmentId: 0,
                    jobIndex: 0,
                    processedJobs: 0,
                    processedAttachments: 0,
                    generated: 0
                };

                progress.style.display = '';
                bar.style.width = '0';
                status.textContent = 'Initialisation…';
                syncGenerateButton();

                runBatch().catch(function (err) {
                    if (state.paused) {
                        return;
                    }
                    status.textContent = 'Erreur : ' + (err && err.message ? err.message : 'lors de la génération.');
                    if (state.cursor && state.cursor.runId) {
                        markPaused();
                    } else {
                        stopGeneration();
                    }
                });
            }

            function resumeGeneration() {
                if (!state.running || !state.paused || !state.cursor) {
                    return;
                }

                state.paused = false;
                state.pausing = false;
                persistState();
                status.textContent = 'Reprise…';
                syncGenerateButton();

                runBatch().catch(function (err) {
                    if (state.paused) {
                        return;
                    }
                    status.textContent = 'Erreur : ' + (err && err.message ? err.message : 'lors de la génération.');
                    if (state.cursor && state.cursor.runId) {
                        markPaused();
                    } else {
                        stopGeneration();
                    }
                });
            }

            function requestPause() {
                if (!state.running || state.paused || state.pausing || !state.cursor) {
                    return;
                }

                state.pausing = true;
                syncGenerateButton();
            }

            function restorePausedSession() {
                var saved = loadPersistedState();
                if (!saved || !imagickOk) {
                    return;
                }

                state.running = true;
                state.paused = true;
                state.pausing = false;
                state.cursor = saved.cursor;
                state.failures = Array.isArray(saved.failures) ? saved.failures : [];
                state.failureCount = Math.max(0, Number(saved.failureCount) || state.failures.length);
                state.totalAttachments = saved.totalAttachments || 0;

                progress.style.display = '';
                setBar(
                    state.cursor.processedAttachments || 0,
                    state.totalAttachments || 0
                );
                markPaused();
            }

            btn.addEventListener('click', function () {
                if (!state.running) {
                    startGeneration();
                    return;
                }

                if (state.paused) {
                    resumeGeneration();
                    return;
                }

                requestPause();
            });

            window.addEventListener('beforeunload', function (event) {
                if (!state.running) {
                    return;
                }
                event.preventDefault();
                event.returnValue = '';
            });

            // Confirmation « Quitter » : coupe la génération (pas de reprise au retour).
            window.addEventListener('pagehide', function () {
                if (!state.running) {
                    return;
                }

                var runId = state.cursor && state.cursor.runId ? state.cursor.runId : '';
                clearPersistedState();
                state.running = false;
                state.paused = false;
                state.pausing = false;
                state.cursor = null;

                if (!runId) {
                    return;
                }

                var body = new URLSearchParams();
                body.append('action', 'wp_webp_abort_generate');
                body.append('nonce', nonce);
                body.append('run_id', runId);

                var payload = new Blob([body.toString()], {
                    type: 'application/x-www-form-urlencoded;charset=UTF-8'
                });

                if (navigator.sendBeacon) {
                    navigator.sendBeacon(ajaxUrl, payload);
                    return;
                }

                try {
                    fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        keepalive: true,
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    });
                } catch (e) {}
            });

            restorePausedSession();
            document.addEventListener('DOMContentLoaded', function () {
                window.wpWebpSetOperationControls(state.running);
            });
        })();
        </script>

        <hr>

        <h2>Liste des formats</h2>
        <?php $wp_webp_sizes = wp_webp_get_image_sizes(); ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:1040px;">
            <thead>
                <tr>
                    <th scope="col" class="wp-webp-col-name">Nom</th>
                    <th scope="col">Largeur</th>
                    <th scope="col">Hauteur</th>
                    <th scope="col">Crop</th>
                    <th scope="col">Compresser</th>
                    <th scope="col" class="wp-webp-col-generated">Dernière génération</th>
                    <th scope="col" class="wp-webp-col-errors">Erreurs</th>
                </tr>
            </thead>
            <tbody id="wp-webp-sizes">
                <?php
                $wp_webp_render_error_cell = static function ($size_name) {
                    $count = wp_webp_get_size_error_count($size_name);
                    ?>
                    <td class="wp-webp-keep wp-webp-errors-cell" data-size="<?php echo esc_attr($size_name); ?>">
                        <?php if ($count <= 0) : ?>
                            <span class="wp-webp-no-errors">—</span>
                        <?php else : ?>
                            <span class="wp-webp-error-count"><?php echo (int) $count; ?></span>
                            <button
                                type="button"
                                class="button-link wp-webp-view-errors"
                                data-size="<?php echo esc_attr($size_name); ?>"
                            >Afficher</button>
                        <?php endif; ?>
                    </td>
                    <?php
                };
                ?>
                <?php
                $original_enabled = wp_webp_size_enabled(WP_WEBP_ORIGINAL_SIZE);
                $original_visible = wp_webp_show_original_size();
                ?>
                <tr id="wp-webp-original-row" class="wp-webp-size-row<?php echo $original_enabled ? '' : ' wp-webp-dim'; ?>"<?php echo $original_visible ? '' : ' hidden'; ?>>
                    <td class="wp-webp-col-name"><strong><?php echo esc_html(WP_WEBP_ORIGINAL_SIZE); ?></strong></td>
                    <td>—</td>
                    <td>—</td>
                    <td>—</td>
                    <td class="wp-webp-keep">
                        <input type="checkbox" class="wp-webp-size-cb" data-size="<?php echo esc_attr(WP_WEBP_ORIGINAL_SIZE); ?>" <?php checked($original_enabled); ?>>
                    </td>
                    <td class="wp-webp-col-generated wp-webp-generated-at" data-size="<?php echo esc_attr(WP_WEBP_ORIGINAL_SIZE); ?>">
                        <?php echo esc_html(wp_webp_format_generated_at(wp_webp_get_size_generated_at(WP_WEBP_ORIGINAL_SIZE))); ?>
                    </td>
                    <?php $wp_webp_render_error_cell(WP_WEBP_ORIGINAL_SIZE); ?>
                </tr>
                <?php foreach ($wp_webp_sizes as $name => $size) : ?>
                    <?php $size_enabled = wp_webp_size_enabled($name); ?>
                    <tr class="wp-webp-size-row<?php echo $size_enabled ? '' : ' wp-webp-dim'; ?>">
                        <td class="wp-webp-col-name"><strong><?php echo esc_html($name); ?></strong></td>
                        <td><?php echo $size['width'] ? (int) $size['width'] . ' px' : '—'; ?></td>
                        <td><?php echo $size['height'] ? (int) $size['height'] . ' px' : '—'; ?></td>
                        <td><?php echo esc_html(wp_webp_crop_label($size['crop'])); ?></td>
                        <td class="wp-webp-keep">
                            <input type="checkbox" class="wp-webp-size-cb" data-size="<?php echo esc_attr($name); ?>" <?php checked($size_enabled); ?>>
                        </td>
                        <td class="wp-webp-col-generated wp-webp-generated-at" data-size="<?php echo esc_attr($name); ?>">
                            <?php echo esc_html(wp_webp_format_generated_at(wp_webp_get_size_generated_at($name))); ?>
                        </td>
                        <?php $wp_webp_render_error_cell($name); ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <style>
            tr.wp-webp-dim td:not(.wp-webp-keep) { opacity: .45; }
            .wp-list-table .wp-webp-col-name { width: 20%; }
            .wp-list-table .wp-webp-col-generated { width: 22%; white-space: nowrap; }
            .wp-list-table .wp-webp-col-errors { width: 12%; white-space: nowrap; }
            .wp-webp-errors-cell .wp-webp-error-count { display: inline-block; min-width: 1.5em; margin-right: 6px; }
            .wp-webp-view-errors {
                color: #2271b1 !important;
                text-decoration: underline !important;
            }
            .wp-webp-view-errors:not(:disabled):hover,
            .wp-webp-view-errors:not(:disabled):focus { color: #135e96 !important; }
            #wp-webp-errors-modal[hidden] { display: none !important; }
            #wp-webp-errors-modal {
                position: fixed; inset: 0; z-index: 100000;
                display: flex; align-items: center; justify-content: center;
            }
            #wp-webp-errors-modal .wp-webp-modal-backdrop {
                position: absolute; inset: 0; background: rgba(0,0,0,.55);
            }
            #wp-webp-errors-modal .wp-webp-modal-dialog {
                position: relative; z-index: 1;
                background: #fff; border-radius: 4px;
                width: min(720px, calc(100vw - 32px));
                max-height: min(80vh, 640px);
                display: flex; flex-direction: column;
                box-shadow: 0 8px 30px rgba(0,0,0,.25);
            }
            #wp-webp-errors-modal .wp-webp-modal-header {
                display: flex; align-items: center; justify-content: space-between;
                gap: 12px; padding: 12px 16px; border-bottom: 1px solid #dcdcde;
            }
            #wp-webp-errors-modal .wp-webp-modal-header h3 { margin: 0; font-size: 15px; }
            #wp-webp-errors-modal .wp-webp-modal-body {
                padding: 12px 16px; overflow: auto; flex: 1;
            }
            #wp-webp-errors-modal .wp-webp-modal-list {
                margin: 0; padding-left: 18px;
            }
            #wp-webp-errors-modal .wp-webp-modal-list li {
                margin: 0 0 8px; word-break: break-word;
            }
            #wp-webp-errors-modal .wp-webp-modal-list code {
                font-size: 12px;
            }
        </style>
        <div id="wp-webp-errors-modal" hidden>
            <div class="wp-webp-modal-backdrop" data-close="1"></div>
            <div class="wp-webp-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="wp-webp-errors-modal-title">
                <div class="wp-webp-modal-header">
                    <h3 id="wp-webp-errors-modal-title">Erreurs</h3>
                    <button type="button" class="button" id="wp-webp-errors-modal-close">Fermer</button>
                </div>
                <div class="wp-webp-modal-body">
                    <p id="wp-webp-errors-modal-status" style="margin-top:0; font-style:italic; color:#50575e;"></p>
                    <ol class="wp-webp-modal-list" id="wp-webp-errors-modal-list"></ol>
                </div>
            </div>
        </div>
        <p><span id="wp-webp-sizes-status" style="font-style:italic; color:#50575e;"></span></p>
        <script>
        (function () {
            var tbody = document.getElementById('wp-webp-sizes');
            var status = document.getElementById('wp-webp-sizes-status');
            var modal = document.getElementById('wp-webp-errors-modal');
            var modalTitle = document.getElementById('wp-webp-errors-modal-title');
            var modalStatus = document.getElementById('wp-webp-errors-modal-status');
            var modalList = document.getElementById('wp-webp-errors-modal-list');
            var modalClose = document.getElementById('wp-webp-errors-modal-close');
            if (!tbody) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_webp_size_action')); ?>;

            function syncRow(cb) {
                var row = cb.closest('tr');
                if (!row) { return; }
                row.classList.toggle('wp-webp-dim', !cb.checked);
            }

            window.wpWebpUpdateErrorCounts = function (counts) {
                if (!counts || typeof counts !== 'object') {
                    return;
                }
                Object.keys(counts).forEach(function (sizeName) {
                    var cell = tbody.querySelector('.wp-webp-errors-cell[data-size="' + sizeName + '"]');
                    if (!cell) {
                        return;
                    }
                    var count = parseInt(counts[sizeName], 10) || 0;
                    cell.replaceChildren();
                    if (count <= 0) {
                        var empty = document.createElement('span');
                        empty.className = 'wp-webp-no-errors';
                        empty.textContent = '—';
                        cell.appendChild(empty);
                        return;
                    }

                    var label = document.createElement('span');
                    label.className = 'wp-webp-error-count';
                    label.textContent = String(count);

                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'button-link wp-webp-view-errors';
                    btn.dataset.size = sizeName;
                    btn.textContent = 'Afficher';

                    cell.append(label, btn);
                });
            };

            function closeModal() {
                if (!modal) {
                    return;
                }
                modal.hidden = true;
                document.body.style.overflow = '';
            }

            function openModal(sizeName) {
                if (!modal) {
                    return;
                }
                modalTitle.textContent = 'Erreurs — ' + sizeName;
                modalStatus.textContent = 'Chargement…';
                modalList.innerHTML = '';
                modal.hidden = false;
                document.body.style.overflow = 'hidden';

                var body = new URLSearchParams();
                body.append('action', 'wp_webp_get_size_errors');
                body.append('nonce', nonce);
                body.append('size', sizeName);

                fetch(ajaxUrl, {
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
                    var errors = data.data.errors || [];
                    modalStatus.textContent = errors.length
                        ? (errors.length + ' erreur(s)')
                        : 'Aucune erreur enregistrée.';
                    modalList.innerHTML = '';
                    errors.forEach(function (item) {
                        var li = document.createElement('li');
                        var code = document.createElement('code');
                        code.textContent = item.file || 'fichier inconnu';
                        li.appendChild(code);
                        li.appendChild(document.createTextNode(' — ' + (item.error || 'Conversion impossible')));
                        modalList.appendChild(li);
                    });
                })
                .catch(function () {
                    modalStatus.textContent = 'Impossible de charger les erreurs.';
                });
            }

            tbody.addEventListener('click', function (event) {
                var btn = event.target.closest('.wp-webp-view-errors');
                if (!btn || btn.disabled) {
                    return;
                }
                openModal(btn.getAttribute('data-size') || '');
            });

            if (modalClose) {
                modalClose.addEventListener('click', closeModal);
            }
            if (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target && event.target.getAttribute('data-close') === '1') {
                        closeModal();
                    }
                });
            }
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && modal && !modal.hidden) {
                    closeModal();
                }
            });

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

        <h2>Developer</h2>
        <p>
            <label>
                <input type="checkbox" id="wp-webp-show-original" <?php checked(wp_webp_show_original_size()); ?>>
                Afficher le format original dans la liste des formats
            </label>
            <span id="wp-webp-show-original-status" style="margin-left:8px; font-style:italic; color:#50575e;"></span>
        </p>
       <p>
            <button type="button" class="button button-secondary" id="wp-webp-clear">Effacer tous les WebP des uploads</button>
            <span id="wp-webp-clear-status" style="margin-left:8px; font-style:italic; color:#50575e;"></span>
        </p>
       <p>
            <button type="button" class="button button-secondary" id="wp-webp-cleanup-unused">Effacer les WebP des formats non utilisés</button>
            <span id="wp-webp-cleanup-unused-status" style="margin-left:8px; font-style:italic; color:#50575e;"></span>
        </p>
        <script>
        (function () {
            var checkbox = document.getElementById('wp-webp-show-original');
            var row = document.getElementById('wp-webp-original-row');
            var status = document.getElementById('wp-webp-show-original-status');
            if (!checkbox || !row) { return; }

            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_webp_original_visibility_action')); ?>;
            var savedVisible = checkbox.checked;

            checkbox.addEventListener('change', function () {
                var requestedVisible = checkbox.checked;
                var originalCheckbox = row.querySelector('.wp-webp-size-cb');
                var originalEnabled = originalCheckbox ? originalCheckbox.checked : true;
                checkbox.disabled = true;
                row.hidden = !requestedVisible;
                status.textContent = 'Enregistrement…';

                if (!requestedVisible && originalCheckbox) {
                    originalCheckbox.checked = true;
                    row.classList.remove('wp-webp-dim');
                }

                var body = new URLSearchParams();
                body.append('action', 'wp_webp_save_original_visibility');
                body.append('nonce', nonce);
                body.append('visible', requestedVisible ? '1' : '0');

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error('failed');
                    }
                    savedVisible = requestedVisible;
                    status.textContent = 'Enregistré.';
                })
                .catch(function () {
                    checkbox.checked = savedVisible;
                    row.hidden = !savedVisible;
                    if (originalCheckbox) {
                        originalCheckbox.checked = originalEnabled;
                        row.classList.toggle('wp-webp-dim', !originalEnabled);
                    }
                    status.textContent = 'Erreur lors de l’enregistrement.';
                })
                .finally(function () {
                    checkbox.disabled = false;
                });
            });
        })();
        </script>
        <script>
        (function () {
            var btn = document.getElementById('wp-webp-clear');
            var status = document.getElementById('wp-webp-clear-status');
            if (!btn) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_webp_clear_action')); ?>;

            async function clearBatch(runId, deleted, failures) {
                try {
                    while (true) {
                        var body = new URLSearchParams();
                        body.append('action', 'wp_webp_clear_webp');
                        body.append('nonce', nonce);
                        body.append('run_id', runId);

                        var response = await fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body.toString()
                        });
                        var data = await response.json();
                    if (!data || !data.success) {
                        throw new Error('failed');
                    }

                    runId = data.data.run_id;
                    deleted += data.data.deleted || 0;
                    failures += (data.data.failures || []).length;
                    status.textContent = 'Suppression… ' + deleted + ' fichier(s) WebP supprimé(s).';

                        if (data.data.done) {
                            status.textContent = deleted + ' fichier(s) WebP supprimé(s)'
                                + (failures ? ', ' + failures + ' erreur(s).' : '.');
                            return;
                        }
                    }
                } catch (error) {
                    status.textContent = 'Erreur lors de la suppression.';
                }
            }

            btn.addEventListener('click', function () {
                if (!window.confirm('Supprimer les fichiers WebP générés depuis les JPEG/PNG ?')) {
                    return;
                }
                btn.disabled = true;
                if (window.wpWebpSetOperationControls) {
                    window.wpWebpSetOperationControls(true);
                }
                status.textContent = 'Initialisation…';

                clearBatch('', 0, 0).finally(function () {
                    if (window.wpWebpSetOperationControls) {
                        window.wpWebpSetOperationControls(false);
                    } else {
                        btn.disabled = false;
                    }
                });
            });
        })();
        </script>
        <script>
        (function () {
            var btn = document.getElementById('wp-webp-cleanup-unused');
            var status = document.getElementById('wp-webp-cleanup-unused-status');
            if (!btn) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_webp_clear_action')); ?>;

            async function cleanupBatch(afterId, deleted, failures) {
                var runId = '';
                try {
                    while (true) {
                        var body = new URLSearchParams();
                        body.append('action', 'wp_webp_cleanup_unused');
                        body.append('nonce', nonce);
                        body.append('after_id', String(afterId));
                        body.append('run_id', runId);

                        var response = await fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body.toString()
                        });
                        var data = await response.json();
                    if (!data || !data.success) {
                        throw new Error('failed');
                    }

                    runId = data.data.run_id || runId;

                    if (data.data.empty) {
                        status.textContent = 'Aucun format désactivé : rien à supprimer.';
                        return;
                    }

                    deleted += data.data.deleted || 0;
                    failures += (data.data.failures || []).length;
                    status.textContent = 'Nettoyage… ' + deleted + ' fichier(s) WebP supprimé(s).';

                        if (data.data.done) {
                            status.textContent = deleted + ' fichier(s) WebP des formats non utilisés supprimé(s)'
                                + (failures ? ', ' + failures + ' erreur(s).' : '.');
                            return;
                        }

                        afterId = data.data.after_id;
                    }
                } catch (error) {
                    status.textContent = 'Erreur lors du nettoyage des formats non utilisés.';
                }
            }

            btn.addEventListener('click', function () {
                if (!window.confirm('Supprimer les WebP des formats dont Compresser est décoché ?')) {
                    return;
                }
                btn.disabled = true;
                if (window.wpWebpSetOperationControls) {
                    window.wpWebpSetOperationControls(true);
                }
                status.textContent = 'Initialisation…';

                cleanupBatch(0, 0, 0).finally(function () {
                    if (window.wpWebpSetOperationControls) {
                        window.wpWebpSetOperationControls(false);
                    } else {
                        btn.disabled = false;
                    }
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
add_action('wp_ajax_wp_webp_abort_generate', 'wp_webp_ajax_abort_generate');
add_action('wp_ajax_wp_webp_get_size_errors', 'wp_webp_ajax_get_size_errors');

function wp_webp_ajax_get_size_errors() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_size_action', 'nonce');

    $size = isset($_POST['size']) ? sanitize_key(wp_unslash($_POST['size'])) : '';
    $valid = array_merge([WP_WEBP_ORIGINAL_SIZE], array_keys(wp_webp_get_image_sizes()));
    if ($size === '' || !in_array($size, $valid, true)) {
        wp_send_json_error(['message' => 'Format inconnu.'], 400);
    }

    $errors = wp_webp_get_size_errors($size);
    wp_send_json_success([
        'size'   => $size,
        'count'  => count($errors),
        'errors' => $errors,
    ]);
}

function wp_webp_ajax_abort_generate() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_generate_action', 'nonce');

    $run_id = isset($_POST['run_id'])
        ? strtolower(preg_replace('/[^a-z0-9]/i', '', (string) wp_unslash($_POST['run_id'])))
        : '';

    if ($run_id === '' || !preg_match('/^[a-z0-9]{20}$/', $run_id)) {
        wp_send_json_error(['message' => 'Run invalide.'], 400);
    }

    delete_transient(wp_webp_generation_run_key($run_id));
    wp_webp_release_operation_lock($run_id);
    wp_send_json_success(['aborted' => true]);
}

function wp_webp_generation_run_key($run_id) {
    return 'wp_webp_run_' . get_current_user_id() . '_' . md5((string) $run_id);
}

/**
 * Verrou global des générations manuelles afin de ne pas saturer PHP-FPM et
 * de ne pas faire concourir les écritures de journaux et de fichiers.
 *
 * @return array{run_id:string,user_id:int,expires:int}|null
 */
function wp_webp_operation_lock_option_name() {
    $name = sanitize_key((string) apply_filters(
        'wp_webp_operation_lock_option_name',
        WP_WEBP_OPERATION_LOCK_OPTION
    ));

    return $name !== '' ? $name : WP_WEBP_OPERATION_LOCK_OPTION;
}

function wp_webp_get_operation_lock() {
    $lock = get_option(wp_webp_operation_lock_option_name(), null);
    if (!is_array($lock) || empty($lock['run_id'])) {
        return null;
    }

    return [
        'run_id' => (string) $lock['run_id'],
        'user_id' => (int) ($lock['user_id'] ?? 0),
        'expires' => (int) ($lock['expires'] ?? 0),
    ];
}

function wp_webp_operation_is_locked() {
    $lock = wp_webp_get_operation_lock();
    if ($lock === null) {
        return false;
    }
    if ($lock['expires'] > time()) {
        return true;
    }

    delete_option(wp_webp_operation_lock_option_name());
    return false;
}

function wp_webp_acquire_operation_lock($run_id) {
    $run_id = (string) $run_id;
    if (!preg_match('/^[a-z0-9]{20}$/', $run_id)) {
        return false;
    }

    $now = time();
    $lock = wp_webp_get_operation_lock();
    if ($lock !== null && $lock['run_id'] === $run_id) {
        return wp_webp_refresh_operation_lock($run_id);
    }
    if ($lock !== null && $lock['expires'] > $now) {
        return false;
    }
    if ($lock !== null) {
        delete_option(wp_webp_operation_lock_option_name());
    }

    return add_option(wp_webp_operation_lock_option_name(), [
        'run_id' => $run_id,
        'user_id' => get_current_user_id(),
        'expires' => $now + WP_WEBP_OPERATION_LOCK_TTL,
    ], '', false);
}

function wp_webp_refresh_operation_lock($run_id) {
    $lock = wp_webp_get_operation_lock();
    if ($lock === null || $lock['run_id'] !== (string) $run_id) {
        return false;
    }

    $lock['expires'] = time() + WP_WEBP_OPERATION_LOCK_TTL;
    update_option(wp_webp_operation_lock_option_name(), $lock, false);

    return true;
}

function wp_webp_ensure_operation_lock($run_id) {
    return wp_webp_refresh_operation_lock($run_id)
        || wp_webp_acquire_operation_lock($run_id);
}

function wp_webp_release_operation_lock($run_id) {
    $lock = wp_webp_get_operation_lock();
    if ($lock === null || $lock['run_id'] !== (string) $run_id) {
        return false;
    }

    return delete_option(wp_webp_operation_lock_option_name());
}

function wp_webp_create_generation_run($only_size = '') {
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
        'only_size'         => wp_webp_sanitize_only_size($only_size),
        'failure_count'     => 0,
    ];

    if (!wp_webp_acquire_operation_lock($run_id)) {
        throw new RuntimeException('Une opération WP WebP est déjà en cours.', 409);
    }

    try {
        set_transient(wp_webp_generation_run_key($run_id), $state, HOUR_IN_SECONDS);
        wp_webp_clear_size_errors(wp_webp_sizes_for_generation_scope($only_size));
    } catch (Throwable $e) {
        delete_transient(wp_webp_generation_run_key($run_id));
        wp_webp_release_operation_lock($run_id);
        throw $e;
    }

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
        $requested_size = isset($_POST['only_size'])
            ? wp_webp_sanitize_only_size(wp_unslash($_POST['only_size']))
            : '';
        $skip_attachment = isset($_POST['skip_attachment'])
            && $_POST['skip_attachment'] === '1'
            && $attachment_id > 0;
        $skip_reason = isset($_POST['skip_reason'])
            ? substr(sanitize_text_field(wp_unslash($_POST['skip_reason'])), 0, 200)
            : '';

        if ($attachment_id === 0) {
            [$run_id, $run_state] = wp_webp_create_generation_run($requested_size);
            $attachment_id = wp_webp_get_next_attachment_id(
                0,
                (int) ($run_state['max_attachment_id'] ?? 0)
            );
            $job_index = 0;

            if ($attachment_id === 0) {
                delete_transient(wp_webp_generation_run_key($run_id));
                wp_webp_release_operation_lock($run_id);
                $touched = wp_webp_generation_completion(
                    $run_state['only_size'] ?? '',
                    $run_state['failure_count'] ?? 0
                );
                wp_send_json_success([
                    'run_id'         => $run_id,
                    'attachment_id'  => 0,
                    'job_index'      => 0,
                    'processed_jobs' => 0,
                    'processed_attachments' => 0,
                    'generated'      => 0,
                    'total_attachments' => 0,
                    'only_size'      => $run_state['only_size'] ?? '',
                    'done'           => true,
                    'failures'       => [],
                    'touched_sizes'  => $touched['sizes'],
                    'generated_at'   => $touched['timestamp'],
                    'generated_at_label' => $touched['label'],
                    'size_error_counts' => wp_webp_get_size_error_counts(),
                ]);
            }
        } else {
            $run_state = wp_webp_get_generation_run($run_id);
            if ($run_state === null) {
                wp_webp_release_operation_lock($run_id);
                wp_send_json_error(['message' => 'Session de génération expirée. Relancez la génération.'], 410);
            }
            if (!wp_webp_ensure_operation_lock($run_id)) {
                wp_send_json_error(['message' => 'Une autre opération WP WebP est déjà en cours.'], 409);
            }
            set_transient(wp_webp_generation_run_key($run_id), $run_state, HOUR_IN_SECONDS);
        }

        $only_size = isset($run_state['only_size'])
            ? wp_webp_sanitize_only_size($run_state['only_size'])
            : '';
        $total_attachments = (int) ($run_state['total_attachments'] ?? 0);
        $max_attachment_id = (int) ($run_state['max_attachment_id'] ?? 0);
        $failures = [];
        $generated = 0;

        $seek = wp_webp_seek_processable_job(
            $attachment_id,
            $job_index,
            $max_attachment_id,
            $only_size,
            $processed_attachments
        );

        if (!empty($seek['done'])) {
            delete_transient(wp_webp_generation_run_key($run_id));
            wp_webp_release_operation_lock($run_id);
            $touched = wp_webp_generation_completion(
                $only_size,
                $run_state['failure_count'] ?? 0
            );
            wp_send_json_success([
                'run_id'                => $run_id,
                'attachment_id'         => 0,
                'job_index'             => 0,
                'processed_jobs'        => $processed_jobs,
                'processed_attachments' => $processed_attachments,
                'generated'             => $generated_total,
                'total_attachments'     => $total_attachments,
                'only_size'             => $only_size,
                'done'                  => true,
                'failures'              => [],
                'touched_sizes'         => $touched['sizes'],
                'generated_at'          => $touched['timestamp'],
                'generated_at_label'    => $touched['label'],
                'size_error_counts'     => wp_webp_get_size_error_counts(),
            ]);
        }

        $attachment_id = (int) $seek['attachment_id'];
        $job_index = (int) $seek['job_index'];

        // Trop d’attachements sans ce format : on renvoie le curseur au client.
        if (!empty($seek['seeking'])) {
            wp_send_json_success([
                'run_id'                => $run_id,
                'attachment_id'         => $attachment_id,
                'job_index'             => $job_index,
                'processed_jobs'        => $processed_jobs,
                'processed_attachments' => $processed_attachments,
                'generated'             => $generated_total,
                'total_attachments'     => $total_attachments,
                'only_size'             => $only_size,
                'done'                  => false,
                'failures'              => [],
                'size_error_counts'     => wp_webp_get_size_error_counts(),
            ]);
        }

        // Le client demande de passer l'attachement qui vient de faire échouer
        // le serveur (timeout ou mémoire) : sans cela, le run reste bloqué.
        if ($skip_attachment) {
            $skip_message = 'Ignoré après un échec serveur'
                . ($skip_reason !== '' ? ' : ' . $skip_reason : '');
            $skip_jobs = wp_webp_jobs_for_attachment($attachment_id, $only_size);
            $skip_size = isset($skip_jobs[$job_index])
                ? wp_webp_job_size($skip_jobs[$job_index], $only_size)
                : ($only_size !== '' ? $only_size : WP_WEBP_ORIGINAL_SIZE);
            wp_webp_record_failure(
                $failures,
                'attachment #' . (int) $attachment_id,
                $skip_message,
                $skip_size
            );
            error_log('[WP WebP] attachment #' . (int) $attachment_id . ' skipped — ' . $skip_message);

            $next_attachment = wp_webp_get_next_attachment_id($attachment_id, $max_attachment_id);
            $cursor = [
                'attachment_id' => $next_attachment,
                'job_index'     => 0,
                'done'          => $next_attachment <= 0,
            ];
        } else {
            try {
                if ($only_size === '') {
                    $attachment_result = wp_webp_process_attachment_jobs(
                        $attachment_id,
                        $failures,
                        '',
                        $job_index,
                        wp_webp_batch_deadline()
                    );
                    $generated = (int) $attachment_result['generated'];
                    $processed_jobs += (int) $attachment_result['processed'];

                    if (empty($attachment_result['complete'])) {
                        $cursor = [
                            'attachment_id' => $attachment_id,
                            'job_index'     => (int) $attachment_result['next_index'],
                            'done'          => false,
                        ];
                    } else {
                        $next_attachment = wp_webp_get_next_attachment_id($attachment_id, $max_attachment_id);
                        $cursor = [
                            'attachment_id' => $next_attachment,
                            'job_index'     => 0,
                            'done'          => $next_attachment <= 0,
                        ];
                    }
                } else {
                    $generated = wp_webp_process_job($attachment_id, $job_index, $failures, $only_size);
                    $cursor = wp_webp_advance_cursor(
                        $attachment_id,
                        $job_index,
                        $max_attachment_id,
                        $only_size
                    );
                    $processed_jobs++;
                }
            } catch (Throwable $e) {
                $failed_jobs = wp_webp_jobs_for_attachment($attachment_id, $only_size);
                $failed_size = isset($failed_jobs[$job_index])
                    ? wp_webp_job_size($failed_jobs[$job_index], $only_size)
                    : ($only_size !== '' ? $only_size : WP_WEBP_ORIGINAL_SIZE);
                $failures[] = [
                    'file'  => 'attachment #' . (int) $attachment_id,
                    'error' => $e->getMessage(),
                    'size'  => $failed_size,
                ];
                error_log('[WP WebP] attachment #' . (int) $attachment_id . ': ' . $e->getMessage());

                if ($only_size === '') {
                    $next_attachment = wp_webp_get_next_attachment_id($attachment_id, $max_attachment_id);
                    $cursor = [
                        'attachment_id' => $next_attachment,
                        'job_index'     => 0,
                        'done'          => $next_attachment <= 0,
                    ];
                } else {
                    $cursor = wp_webp_advance_cursor(
                        $attachment_id,
                        $job_index,
                        $max_attachment_id,
                        $only_size
                    );
                    $processed_jobs++;
                }
            }
        }

        if ($failures !== []) {
            wp_webp_append_size_errors($failures);
        }

        $run_state['failure_count'] = (int) ($run_state['failure_count'] ?? 0) + count($failures);

        // Un attachement peut occuper plusieurs lots : il n'est compté comme
        // traité que lorsque le curseur quitte réellement son identifiant.
        if ($cursor['attachment_id'] !== $attachment_id) {
            $processed_attachments++;
        }

        if ($cursor['done']) {
            delete_transient(wp_webp_generation_run_key($run_id));
            wp_webp_release_operation_lock($run_id);
            $touched = wp_webp_generation_completion($only_size, $run_state['failure_count']);
        } else {
            set_transient(wp_webp_generation_run_key($run_id), $run_state, HOUR_IN_SECONDS);
            $touched = [
                'sizes'     => [],
                'timestamp' => 0,
                'label'     => '—',
            ];
        }

        wp_send_json_success([
            'run_id'         => $run_id,
            'attachment_id'  => $cursor['attachment_id'],
            'job_index'      => $cursor['job_index'],
            'processed_jobs' => $processed_jobs,
            'processed_attachments' => $processed_attachments,
            'generated'      => $generated_total + $generated,
            'total_attachments' => $total_attachments,
            'only_size'      => $only_size,
            'done'           => $cursor['done'],
            'failures'       => $failures,
            'failure_count'  => $run_state['failure_count'],
            'touched_sizes'  => $touched['sizes'],
            'generated_at'   => $touched['timestamp'],
            'generated_at_label' => $touched['label'],
            'size_error_counts' => wp_webp_get_size_error_counts(),
        ]);
    } catch (Throwable $e) {
        if ($run_id !== '') {
            delete_transient(wp_webp_generation_run_key($run_id));
            wp_webp_release_operation_lock($run_id);
        }
        error_log('[WP WebP] generate batch failed: ' . $e->getMessage());
        $status = (int) $e->getCode() === 409 ? 409 : 500;
        wp_send_json_error(['message' => $e->getMessage()], $status);
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
            'current_entries' => null,
            'current_index' => 0,
            'after_name' => '',
        ];
        if (!wp_webp_acquire_operation_lock($run_id)) {
            wp_send_json_error(['message' => 'Une autre opération WP WebP est déjà en cours.'], 409);
        }
    } elseif (!preg_match('/^[a-z0-9]{20}$/', $run_id)) {
        wp_send_json_error(['message' => 'Session de suppression invalide.'], 400);
    } else {
        $state = get_transient(wp_webp_clear_run_key($run_id));
        if (!is_array($state)) {
            wp_webp_release_operation_lock($run_id);
            wp_send_json_error(['message' => 'Session de suppression expirée.'], 410);
        }
        if (!wp_webp_ensure_operation_lock($run_id)) {
            wp_send_json_error(['message' => 'Une autre opération WP WebP est déjà en cours.'], 409);
        }
    }

    try {
        $failures = [];
        $result = wp_webp_clear_batch($state, 250, $failures);

        if ($result['done']) {
            delete_transient(wp_webp_clear_run_key($run_id));
            wp_webp_release_operation_lock($run_id);
        } else {
            set_transient(wp_webp_clear_run_key($run_id), $state, HOUR_IN_SECONDS);
        }

        wp_send_json_success([
            'run_id'   => $run_id,
            'deleted'  => $result['deleted'],
            'done'     => $result['done'],
            'failures' => $failures,
        ]);
    } catch (Throwable $e) {
        delete_transient(wp_webp_clear_run_key($run_id));
        wp_webp_release_operation_lock($run_id);
        error_log('[WP WebP] clear uploads failed: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Suppression globale impossible.'], 500);
    }
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
    $state['current_entries'] = isset($state['current_entries']) && is_array($state['current_entries'])
        ? array_values($state['current_entries'])
        : null;
    $state['current_index'] = isset($state['current_index'])
        ? max(0, (int) $state['current_index'])
        : 0;
    $state['after_name'] = isset($state['after_name'])
        ? (string) $state['after_name']
        : '';

    while ($processed < $limit) {
        if ($state['current_directory'] === '') {
            if ($state['directories'] === []) {
                break;
            }

            $state['current_directory'] = (string) array_shift($state['directories']);
            $state['current_entries'] = null;
            $state['current_index'] = 0;
        }

        $directory = $state['current_directory'];
        if ($state['current_entries'] === null) {
            wp_webp_cleanup_stale_temp_files($directory, 250);
            $entries = @scandir($directory);

            if (!is_array($entries)) {
                wp_webp_record_failure(
                    $failures,
                    wp_webp_relative_upload_path($directory),
                    'Lecture du dossier impossible'
                );
                $state['current_directory'] = '';
                $state['current_entries'] = null;
                $state['current_index'] = 0;
                $state['after_name'] = '';
                $processed++;
                continue;
            }

            $after_name = $state['after_name'];
            $state['current_entries'] = array_values(array_filter(
                $entries,
                static function ($entry) use ($after_name) {
                    return $entry !== '.'
                        && $entry !== '..'
                        && ($after_name === '' || strcmp($entry, $after_name) > 0);
                }
            ));
            $state['current_index'] = 0;
            $state['after_name'] = '';
        }

        $entry_count = count($state['current_entries']);
        while ($processed < $limit && $state['current_index'] < $entry_count) {
            $entry = $state['current_entries'][$state['current_index']];
            $state['current_index']++;
            $path = wp_normalize_path(trailingslashit($directory) . $entry);
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

        if ($state['current_index'] >= $entry_count) {
            $state['current_directory'] = '';
            $state['current_entries'] = null;
            $state['current_index'] = 0;
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
add_filter('wp_generate_attachment_metadata', 'wp_webp_on_generate_metadata', 1000, 2);

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
    $variants = [];

    if (wp_webp_size_enabled(WP_WEBP_ORIGINAL_SIZE)) {
        $variants[] = [
            'width' => 0,
            'height' => 0,
            'crop' => false,
            'output_source' => $original,
        ];
    }

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
                continue;
            }

            $crop = $registered[$size_name]['crop'];
            $variants[] = [
                'width' => (int) $registered[$size_name]['width'],
                'height' => (int) $registered[$size_name]['height'],
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
 * Empêche deux sources comme photo.jpg et photo.png de partager photo.webp.
 */
function wp_webp_has_alternate_source($path) {
    $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return false;
    }

    $directory = dirname((string) $path);
    $filename = pathinfo((string) $path, PATHINFO_FILENAME);
    $current = basename((string) $path);
    foreach (glob($directory . '/' . $filename . '.*', GLOB_NOSORT) ?: [] as $candidate) {
        if (basename($candidate) === $current) {
            continue;
        }
        if (
            is_file($candidate)
            && in_array(strtolower(pathinfo($candidate, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)
        ) {
            return true;
        }
    }

    return false;
}

function wp_webp_upload_name_conflicts($directory, $filename) {
    $path = trailingslashit((string) $directory) . (string) $filename;
    $target = wp_webp_target_path($path);

    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'webp') {
        return wp_webp_has_alternate_source($path);
    }

    return $target !== '' && (is_file($target) || wp_webp_has_alternate_source($path));
}

/**
 * Réserve un basename unique pour la source et son futur WebP.
 */
function wp_webp_unique_filename($filename, $extension, $directory, $unique_filename_callback, $alternate_filenames, $number) {
    $extension = strtolower((string) $extension);
    if (!in_array($extension, ['.jpg', '.jpeg', '.png', '.webp'], true)) {
        return $filename;
    }

    $filename = (string) $filename;
    if (!wp_webp_upload_name_conflicts($directory, $filename)) {
        return $filename;
    }

    $stem = pathinfo($filename, PATHINFO_FILENAME);
    $root = $stem;
    $next = max(1, (int) $number + 1);
    if (preg_match('/^(.*)-(\d+)$/', $stem, $matches)) {
        $root = $matches[1];
        $next = max($next, (int) $matches[2] + 1);
    }

    do {
        $candidate = $root . '-' . $next . $extension;
        $next++;
    } while (
        is_file(trailingslashit((string) $directory) . $candidate)
        || wp_webp_upload_name_conflicts($directory, $candidate)
    );

    return $candidate;
}

add_filter('wp_unique_filename', 'wp_webp_unique_filename', 10, 6);

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

/**
 * Nettoie un nombre borné de fichiers temporaires abandonnés après un crash.
 * Les fichiers récents sont conservés pour ne pas perturber une écriture en
 * cours dans une autre requête.
 */
function wp_webp_cleanup_stale_temp_files($directory, $limit = 50) {
    $directory = (string) $directory;
    $limit = max(1, min(500, (int) $limit));
    if (!is_dir($directory)) {
        return 0;
    }

    $deleted = 0;
    $cutoff = time() - WP_WEBP_TEMP_FILE_MAX_AGE;
    foreach (glob(trailingslashit($directory) . '.wp-webp-*', GLOB_NOSORT) ?: [] as $temporary) {
        if ($deleted >= $limit) {
            break;
        }

        $modified = @filemtime($temporary);
        if (!is_file($temporary) || $modified === false || $modified > $cutoff) {
            continue;
        }

        if (@unlink($temporary)) {
            $deleted++;
        }
    }

    return $deleted;
}

function wp_webp_write_temp_image(Imagick $img, $target) {
    $directory = dirname($target);

    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Dossier cible inaccessible en écriture');
    }

    static $cleaned_directories = [];
    $directory_key = wp_normalize_path($directory);
    if (!isset($cleaned_directories[$directory_key])) {
        wp_webp_cleanup_stale_temp_files($directory, 50);
        $cleaned_directories[$directory_key] = true;
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
 * Calcule la même géométrie que l'éditeur WordPress, filtres de crop inclus.
 *
 * @return array{0:int,1:int,2:int,3:int,4:int,5:int,6:int,7:int}|null
 */
function wp_webp_resize_dimensions($original_width, $original_height, $target_width, $target_height, $crop) {
    if (!function_exists('image_resize_dimensions')) {
        return null;
    }

    $dimensions = image_resize_dimensions(
        max(0, (int) $original_width),
        max(0, (int) $original_height),
        max(0, (int) $target_width),
        max(0, (int) $target_height),
        wp_webp_normalize_crop($crop)
    );

    if (!is_array($dimensions) || count($dimensions) !== 8) {
        return null;
    }

    $dimensions = array_map('intval', array_values($dimensions));
    foreach (array_slice($dimensions, 4) as $dimension) {
        if ($dimension <= 0) {
            return null;
        }
    }

    return $dimensions;
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

    if (wp_webp_has_alternate_source($output_source)) {
        wp_webp_record_failure(
            $failures,
            wp_webp_relative_upload_path($output_source),
            'Collision de nom avec une autre source JPEG/PNG'
        );
        return 0;
    }

    $img = null;
    $lossy_fallback = null;
    $best_temporary = null;
    $best_temporary_size = null;

    try {
        $img = clone $source;
        $img->stripImage();

        // Le WebP pleine taille reste indépendant du fichier JPG/PNG source :
        // on limite seulement sa plus grande dimension pour fiabiliser et
        // accélérer l'encodage des très grandes images.
        if ((int) $width <= 0 && (int) $height <= 0) {
            $max_dimension = wp_webp_get_max_full_dimension();
            $source_width = (int) $img->getImageWidth();
            $source_height = (int) $img->getImageHeight();
            $largest_dimension = max($source_width, $source_height);

            if ($max_dimension > 0 && $largest_dimension > $max_dimension) {
                $ratio = $max_dimension / $largest_dimension;
                $destination_width = max(1, (int) round($source_width * $ratio));
                $destination_height = max(1, (int) round($source_height * $ratio));
                $blur = isset($profile['blur']) ? (float) $profile['blur'] : 1.0;
                $filter = wp_webp_resolve_filter(isset($profile['filter']) ? $profile['filter'] : 'lanczos');

                $img->resizeImage($destination_width, $destination_height, $filter, $blur);
                $img->setImagePage($destination_width, $destination_height, 0, 0);
            }
        }

        // Recréation du format depuis l'original via resizeImage (contrôle du
        // blur pour un rendu plus net que les vignettes WordPress).
        // image_resize_dimensions() renvoie false quand WP n'a rien à faire
        // (taille déjà atteinte) ou refuse d'upscaler : on encode alors tel quel.
        if ($width > 0 || $height > 0) {
            $blur = isset($profile['blur']) ? (float) $profile['blur'] : 1.0;
            $filter = wp_webp_resolve_filter(isset($profile['filter']) ? $profile['filter'] : 'lanczos');

            $dimensions = wp_webp_resize_dimensions(
                $img->getImageWidth(),
                $img->getImageHeight(),
                $width,
                $height,
                $crop
            );

            if ($dimensions !== null) {
                [, , $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h] = $dimensions;
                if ($crop) {
                    $img->cropImage($src_w, $src_h, $src_x, $src_y);
                    $img->setImagePage($src_w, $src_h, 0, 0);
                }

                $img->resizeImage($dst_w, $dst_h, $filter, $blur);
                $img->setImagePage($dst_w, $dst_h, 0, 0);
            }
        }

        // Conserver la géométrie calculée avant l'encodage pour pouvoir passer
        // proprement de near-lossless à lossy si le premier résultat est trop lourd.
        if ($near_lossless) {
            $lossy_fallback = clone $img;
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

        // Chercher d'abord un WebP plus léger. Si ce n'est pas possible sans
        // dépasser le plancher du profil, publier le plus petit essai WebP.
        if (!empty($profile['cap_to_original']) && file_exists($output_source)) {
            $ref = filesize($output_source);
            $floor = isset($profile['quality_floor']) ? (int) $profile['quality_floor'] : 60;
            $attempts = 0;
            while ($ref) {
                $tmp = wp_webp_write_temp_image($img, $target);
                $temporary_size = filesize($tmp);
                if ($temporary_size === false || $temporary_size <= 0) {
                    @unlink($tmp);
                    throw new RuntimeException('Lecture du poids du WebP temporaire impossible');
                }
                if ($temporary_size < $ref) {
                    if (is_string($best_temporary) && is_file($best_temporary)) {
                        @unlink($best_temporary);
                    }
                    wp_webp_commit_temp_image($tmp, $target);
                    $best_temporary = null;
                    return 1;
                }

                if ($best_temporary_size === null || $temporary_size < $best_temporary_size) {
                    if (is_string($best_temporary) && is_file($best_temporary)) {
                        @unlink($best_temporary);
                    }
                    $best_temporary = $tmp;
                    $best_temporary_size = $temporary_size;
                } else {
                    @unlink($tmp);
                }

                if ($near_lossless && $lossy_fallback instanceof Imagick) {
                    $img->clear();
                    $img->destroy();
                    $img = $lossy_fallback;
                    $lossy_fallback = null;
                    $near_lossless = false;

                    if ($sigma > 0) {
                        $pixels = $img->getImageWidth() * $img->getImageHeight();
                        if ($pixels > 0 && $pixels <= 20000000) {
                            $img->sharpenImage($radius, $sigma);
                        }
                    }

                    wp_webp_apply_webp_options($img, $profile, false);
                    $img->setImageCompressionQuality($quality);
                    $attempts = 0;
                    continue;
                }

                if ($attempts >= 6 || $quality <= $floor) {
                    if (!is_string($best_temporary) || !is_file($best_temporary)) {
                        throw new RuntimeException('Aucun essai WebP publiable');
                    }
                    wp_webp_commit_temp_image($best_temporary, $target);
                    $best_temporary = null;
                    return 1;
                }

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
        if ($lossy_fallback instanceof Imagick) {
            $lossy_fallback->clear();
            $lossy_fallback->destroy();
        }
        if (is_string($best_temporary) && is_file($best_temporary)) {
            @unlink($best_temporary);
        }
    }
}

/**
 * Ajoute une ligne au rapport d'échecs (si un rapport est suivi).
 */
function wp_webp_record_failure(&$failures, $file, $error, $size = '') {
    if (is_array($failures)) {
        $entry = [
            'file'  => $file,
            'error' => $error ?: 'Conversion impossible',
        ];
        $size = is_string($size) ? $size : '';
        if ($size !== '') {
            $entry['size'] = $size;
        }
        $failures[] = $entry;
    }
}

/**
 * Attribue un format aux échecs ajoutés depuis `$from_index`.
 */
function wp_webp_tag_failures(&$failures, $from_index, $size) {
    if (!is_array($failures) || $size === '') {
        return;
    }

    $from_index = max(0, (int) $from_index);
    $total = count($failures);
    for ($i = $from_index; $i < $total; $i++) {
        if (empty($failures[$i]['size'])) {
            $failures[$i]['size'] = $size;
        }
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
