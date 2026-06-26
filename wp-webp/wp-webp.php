<?php
/**
 * Plugin Name: WP WebP
 * Description: Génère une version WebP de chaque image téléversée (et de toutes ses déclinaisons add_image_size), compatible Regenerate Thumbnails. Conversion via Imagick avec accentuation (sharpen).
 * Version: 1.0
 * Author: Votre Nom
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
            'label'           => 'Best',
            'desc'            => 'Qualité maximale, sans jamais dépasser le poids de l’image originale.',
            'quality'         => 85,
            'radius'          => 0,
            'sigma'           => 0.8,
            'blur'            => 0.8,
            'filter'          => 'lanczos',
            'method'          => 6,
            // Réduit la qualité juste ce qu'il faut pour que le WebP ne soit
            // jamais plus lourd que le fichier source.
            'cap_to_original' => true,
            'quality_floor'   => 70,
        ],
        'optimal' => [
            'label'   => 'Optimal',
            'desc'    => 'Meilleur compromis qualité / poids (recommandé).',
            'quality' => 80,
            'radius'  => 0,
            'sigma'   => 0.6,
            'blur'    => 0.9,
            'filter'  => 'lanczos',
            'method'  => 6,
        ],
        'green' => [
            'label'   => 'Green',
            'desc'    => 'Poids minimal, accentuation légère (fichiers très légers).',
            'quality' => 70,
            'radius'  => 0,
            'sigma'   => 0.5,
            'blur'    => 1.0,
            'filter'  => 'triangle',
            'method'  => 6,
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
 * Liste des tailles d'images enregistrées : tailles par défaut (thumbnail,
 * medium, medium_large, large) + celles ajoutées via add_image_size.
 * Retourne un tableau [ nom => ['width' => …, 'height' => …, 'crop' => bool] ].
 */
function wp_webp_get_image_sizes() {
    $sizes = [];
    $additional = function_exists('wp_get_additional_image_sizes') ? wp_get_additional_image_sizes() : [];

    foreach (get_intermediate_image_sizes() as $name) {
        if (isset($additional[$name])) {
            $sizes[$name] = [
                'width'  => (int) $additional[$name]['width'],
                'height' => (int) $additional[$name]['height'],
                'crop'   => (bool) $additional[$name]['crop'],
            ];
        } else {
            $sizes[$name] = [
                'width'  => (int) get_option($name . '_size_w'),
                'height' => (int) get_option($name . '_size_h'),
                'crop'   => (bool) get_option($name . '_crop'),
            ];
        }
    }

    return $sizes;
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

    wp_send_json_success(['size' => $size, 'enabled' => $enabled]);
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
        <?php endif; ?>

        <h2>Qualité des images</h2>
        <fieldset id="wp-webp-profile">
            <?php foreach (wp_webp_profiles() as $key => $profile) : ?>
                <label style="display:block; margin-bottom:8px;">
                    <input type="radio" name="<?php echo esc_attr(WP_WEBP_PROFILE_OPTION); ?>" value="<?php echo esc_attr($key); ?>" <?php checked($current, $key); ?>>
                    <strong><?php echo esc_html($profile['label']); ?></strong>
                    — <?php echo esc_html($profile['desc']); ?>
                    <span style="color:#646970;">
                        (qualité <?php echo (int) $profile['quality']; ?>,
                        filtre <?php echo esc_html(wp_webp_filter_label(isset($profile['filter']) ? $profile['filter'] : 'lanczos')); ?>,
                        resize blur <?php echo esc_html((string) $profile['blur']); ?>,
                        sharpen radius <?php echo esc_html((string) $profile['radius']); ?> / sigma <?php echo esc_html((string) $profile['sigma']); ?>,
                        method <?php echo (int) $profile['method']; ?><?php echo !empty($profile['cap_to_original']) ? ', max original' : ''; ?>)
                    </span>
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
                        <td><?php echo $size['crop'] ? 'Oui' : 'Non'; ?></td>
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

        <h2>Generate images</h2>
        <p class="description">Génère (ou régénère) les fichiers <code>.webp</code> pour <strong>toutes</strong> les images déjà présentes dans la médiathèque, ainsi que toutes leurs déclinaisons. Le traitement se fait par lots.</p>
        <p>
            <button type="button" class="button button-primary" id="wp-webp-generate"<?php disabled(!wp_webp_imagick_available()); ?>>Générer les WebP</button>
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

            function runBatch(page, processed, generated, failures) {
                var body = new URLSearchParams();
                body.append('action', 'wp_webp_generate_webp');
                body.append('nonce', nonce);
                body.append('page', String(page));

                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) { throw new Error('failed'); }
                    processed += data.data.processed;
                    generated += data.data.generated;
                    failures = failures.concat(data.data.failures || []);
                    var total = data.data.total;
                    var pct = setBar(processed, total);
                    status.textContent = 'Traitement… ' + pct + '% (' + processed + ' / ' + total + ' image(s), ' + generated + ' WebP généré(s)).';
                    if (!data.data.done) {
                        return runBatch(page + 1, processed, generated, failures);
                    }
                    setBar(1, 1);
                    status.textContent = 'Terminé : ' + processed + ' image(s) traitée(s), ' + generated + ' WebP généré(s), ' + failures.length + ' erreur(s).' + renderFailures(failures);
                });
            }

            btn.addEventListener('click', function () {
                btn.disabled = true;
                progress.style.display = '';
                bar.style.width = '0';
                status.textContent = 'Traitement…';
                runBatch(1, 0, 0, []).catch(function () {
                    status.textContent = 'Erreur lors de la génération.';
                }).finally(function () {
                    btn.disabled = false;
                });
            });
        })();
        </script>

        <hr>

        <h2>Developer</h2>
        <p class="description">Supprime <strong>tous</strong> les fichiers <code>.webp</code> présents dans le dossier <code>uploads</code>. Action irréversible (les WebP seront recréés à la prochaine génération / Regenerate Thumbnails).</p>
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

            btn.addEventListener('click', function () {
                if (!window.confirm('Supprimer tous les fichiers .webp du dossier uploads ?')) {
                    return;
                }
                btn.disabled = true;
                status.textContent = 'Suppression…';

                var body = new URLSearchParams();
                body.append('action', 'wp_webp_clear_webp');
                body.append('nonce', nonce);

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        status.textContent = data.data.deleted + ' fichier(s) .webp supprimé(s).';
                    } else {
                        throw new Error('failed');
                    }
                })
                .catch(function () {
                    status.textContent = 'Erreur lors de la suppression.';
                })
                .finally(function () {
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

function wp_webp_ajax_generate_webp() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_generate_action', 'nonce');

    if (!wp_webp_imagick_available()) {
        wp_send_json_error(['message' => 'Imagick indisponible.'], 500);
    }

    $batch = 10;
    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;

    $query = new WP_Query([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => ['image/jpeg', 'image/png'],
        'fields'         => 'ids',
        'posts_per_page' => $batch,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $processed = 0;
    $generated = 0;
    $failures = [];
    foreach ($query->posts as $attachment_id) {
        $generated += wp_webp_generate_for_attachment($attachment_id, $failures);
        $processed++;
    }

    $total = (int) $query->found_posts;
    $done = ($page * $batch) >= $total || empty($query->posts);

    wp_send_json_success([
        'processed' => $processed,
        'generated' => $generated,
        'total'     => $total,
        'page'      => $page,
        'done'      => $done,
        'failures'  => $failures,
    ]);
}

/**
 * Génère les WebP (original + déclinaisons) d'un attachement existant.
 * Délègue au traitement commun (utilisé aussi à l'upload / Regenerate Thumbnails).
 */
function wp_webp_generate_for_attachment($attachment_id, &$failures = []) {
    return wp_webp_process_attachment($attachment_id, $failures);
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

function wp_webp_ajax_clear_webp() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_webp_clear_action', 'nonce');

    $uploads = wp_get_upload_dir();
    $basedir = isset($uploads['basedir']) ? $uploads['basedir'] : '';
    if ($basedir === '' || !is_dir($basedir)) {
        wp_send_json_error(['message' => 'Dossier uploads introuvable.'], 500);
    }

    $deleted = wp_webp_delete_all_webp($basedir);

    wp_send_json_success(['deleted' => $deleted]);
}

/**
 * Supprime récursivement tous les fichiers .webp d'un dossier.
 */
function wp_webp_delete_all_webp($dir) {
    $deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'webp') {
            if (@unlink($file->getPathname())) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

/* -------------------------------------------------------------------------
 * Génération des fichiers WebP
 * ---------------------------------------------------------------------- */

// wp_generate_attachment_metadata est déclenché à l'upload ET par Regenerate
// Thumbnails : on couvre donc les deux cas, y compris toutes les déclinaisons
// (tailles par défaut + add_image_size).
add_filter('wp_generate_attachment_metadata', 'wp_webp_on_generate_metadata', 10, 2);

function wp_webp_on_generate_metadata($metadata, $attachment_id) {
    if (wp_webp_imagick_available()) {
        wp_webp_process_attachment($attachment_id);
    }

    return $metadata;
}

/**
 * Traite un attachement : génère le WebP de l'original puis RECRÉE chaque
 * format à partir de l'image originale (resizeImage), au lieu de convertir les
 * vignettes déjà produites par WordPress (souvent un peu floues).
 *
 * @param int        $attachment_id
 * @param array|null $failures Rempli (par référence) avec les fichiers en échec.
 * @return int Nombre de fichiers WebP réellement générés.
 */
function wp_webp_process_attachment($attachment_id, &$failures = null) {
    $original = get_attached_file($attachment_id);
    if (!$original || !file_exists($original)) {
        wp_webp_record_failure($failures, 'attachment #' . (int) $attachment_id, 'Fichier source introuvable');
        return 0;
    }

    // Image originale : conversion directe (pas de redimensionnement).
    $generated = wp_webp_make_webp($original, 0, 0, false, $original, $failures);

    $metadata = wp_get_attachment_metadata($attachment_id);
    $registered = wp_webp_get_image_sizes();

    if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
        $dir = trailingslashit(dirname($original));
        foreach ($metadata['sizes'] as $size_name => $size) {
            if (empty($size['file']) || !wp_webp_size_enabled($size_name)) {
                continue;
            }
            $crop = isset($registered[$size_name]) ? (bool) $registered[$size_name]['crop'] : false;
            $generated += wp_webp_make_webp(
                $original,
                (int) $size['width'],
                (int) $size['height'],
                $crop,
                $dir . $size['file'],
                $failures
            );
        }
    }

    return $generated;
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
 * Libellé lisible d'un filtre (ex. "lanczos" => "Lanczos").
 */
function wp_webp_filter_label($name) {
    return ucfirst(strtolower((string) $name));
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
 * Génère un fichier WebP à partir de l'image ORIGINALE.
 *
 * @param string     $original_path Chemin de l'image originale (source des pixels).
 * @param int        $width         Largeur cible (0 = pas de redimensionnement).
 * @param int        $height        Hauteur cible (0 = pas de redimensionnement).
 * @param bool       $crop          Recadrage centré (true) ou ajustement (false).
 * @param string     $output_source Chemin du fichier WP correspondant : sert à
 *                                  nommer le .webp et de référence pour le plafond.
 * @param array|null $failures      Rempli en cas d'échec.
 * @return int 1 si généré, 0 sinon.
 */
function wp_webp_make_webp($original_path, $width, $height, $crop, $output_source, &$failures = null) {
    $target = wp_webp_target_path($output_source);
    if ($target === '') {
        wp_webp_record_failure($failures, wp_webp_relative_upload_path($output_source), 'Format non pris en charge');
        return 0;
    }
    if (!file_exists($original_path)) {
        wp_webp_record_failure($failures, wp_webp_relative_upload_path($original_path), 'Fichier source introuvable');
        return 0;
    }

    $profile = wp_webp_get_profile();

    try {
        $imagick_class = 'Imagick';
        $img = new $imagick_class($original_path);
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
                    $scale = max($width / $ow, $height / $oh);
                    $rw = (int) ceil($ow * $scale);
                    $rh = (int) ceil($oh * $scale);
                    $img->resizeImage($rw, $rh, $filter, $blur);
                    $img->cropImage($width, $height, (int) floor(($rw - $width) / 2), (int) floor(($rh - $height) / 2));
                    $img->setImagePage($width, $height, 0, 0);
                }
            } else {
                $img->resizeImage($width, $height, $filter, $blur, true);
            }
        }

        // Accentuation : Imagick::sharpenImage($radius, $sigma).
        $radius = isset($profile['radius']) ? (float) $profile['radius'] : 0;
        $sigma = isset($profile['sigma']) ? (float) $profile['sigma'] : 0;
        if ($sigma > 0) {
            $img->sharpenImage($radius, $sigma);
        }

        $img->setImageFormat('webp');
        $img->setOption('webp:method', (string) $profile['method']);

        $quality = (int) $profile['quality'];
        $img->setImageCompressionQuality($quality);
        $blob = $img->getImageBlob();

        // Plafonnement : le WebP ne doit pas dépasser le poids du fichier de
        // référence (l'image originale ou la vignette WP correspondante).
        if (!empty($profile['cap_to_original']) && file_exists($output_source)) {
            $ref = filesize($output_source);
            $floor = isset($profile['quality_floor']) ? (int) $profile['quality_floor'] : 60;
            while ($ref && strlen($blob) > $ref && $quality > $floor) {
                $quality = max($floor, $quality - 5);
                $img->setImageCompressionQuality($quality);
                $blob = $img->getImageBlob();
            }
        }

        $written = file_put_contents($target, $blob);
        $img->clear();
        $img->destroy();

        if ($written === false) {
            wp_webp_record_failure($failures, wp_webp_relative_upload_path($target), 'Écriture du fichier WebP impossible');
            return 0;
        }

        return 1;
    } catch (Exception $e) {
        wp_webp_record_failure($failures, wp_webp_relative_upload_path($output_source), $e->getMessage());
        return 0;
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

/* -------------------------------------------------------------------------
 * Nettoyage : supprime les WebP associés quand l'image est supprimée.
 * ---------------------------------------------------------------------- */

add_action('delete_attachment', 'wp_webp_on_delete_attachment');

function wp_webp_on_delete_attachment($attachment_id) {
    $original = get_attached_file($attachment_id);
    if ($original) {
        wp_webp_delete_for($original);
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!empty($metadata['sizes']) && is_array($metadata['sizes']) && $original) {
        $dir = trailingslashit(dirname($original));
        foreach ($metadata['sizes'] as $size) {
            if (!empty($size['file'])) {
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
