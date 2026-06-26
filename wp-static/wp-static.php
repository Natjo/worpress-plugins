<?php

/**
 * Plugin Name: WP Static
 * Description: Génère des pages statiques de votre site WordPress et les sert pour améliorer les performances.
 * Version: 1.0.1
 * Author: Lonsdale Dev Team
 */

if (!defined('ABSPATH')) exit;

define('WP_STATIC_DIR', WP_CONTENT_DIR . '/static-pages');
define('WP_STATIC_RESULT_TRANSIENT_PREFIX', 'wp_static_generation_result_');
define('WP_STATIC_ENABLED_OPTION', 'wp_static_enabled');
define('WP_STATIC_MINIFY_OPTION', 'wp_static_minify');
define('WP_STATIC_AUTO_OPTION', 'wp_static_auto');
define('WP_STATIC_MODE_OPTION', 'wp_static_mode');
define('WP_STATIC_HTACCESS_USER_OPTION', 'wp_static_htaccess_user');
define('WP_STATIC_HTACCESS_PASS_OPTION', 'wp_static_htaccess_pass');
define('WP_STATIC_EXPORT_FOLDER_OPTION', 'wp_static_export_folder');
define('WP_STATIC_HIDDEN_TYPES_OPTION', 'wp_static_hidden_post_types');
define('WP_STATIC_DEPS_OPTION', 'wp_static_url_deps');
define('WP_STATIC_DIRTY_OPTION', 'wp_static_dirty');
define('WP_STATIC_GEN_TOKEN_TRANSIENT', 'wp_static_gen_token');
define('WP_STATIC_EXCLUDED_OPTION', 'wp_static_excluded_urls');
define('WP_STATIC_EXCLUDE_PATTERNS_OPTION', 'wp_static_exclude_patterns');
define('WP_STATIC_PURGE_ORPHANS_OPTION', 'wp_static_purge_orphans');
define('WP_STATIC_CRON_FREQUENCY_OPTION', 'wp_static_cron_frequency');
define('WP_STATIC_ALWAYS_REGEN_OPTION', 'wp_static_always_regen_urls');
define('WP_STATIC_REGEN_CLASSES_OPTION', 'wp_static_regen_classes');
define('WP_STATIC_DYNAMIC_URLS_OPTION', 'wp_static_dynamic_urls');
define('WP_STATIC_INDEX_MARKER_START', '/* wp-static-cache:start */');
define('WP_STATIC_INDEX_MARKER_END', '/* wp-static-cache:end */');
define('WP_STATIC_GEN_LOCK_FILE', WP_CONTENT_DIR . '/wp-static-generating.lock');
define('WP_STATIC_GEN_PENDING_OPTION', 'wp_static_gen_pending');

/**
 * Liste des URLs explicitement exclues du statique (servies en dynamique).
 */
function wp_static_get_excluded()
{
    $excluded = get_option(WP_STATIC_EXCLUDED_OPTION, []);
    return is_array($excluded) ? $excluded : [];
}

/**
 * Clé de comparaison d'une URL pour l'exclusion : hôte + chemin sans slash final,
 * pour rester robuste aux variations de slash/scheme.
 */
function wp_static_exclude_key($url)
{
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $path = wp_parse_url($url, PHP_URL_PATH);
    $path = $path ? untrailingslashit($path) : '';
    if ($path === '') {
        $path = '/';
    }
    return $host . '|' . $path;
}

function wp_static_is_url_excluded($url)
{
    $excluded = wp_static_get_excluded();
    $key = wp_static_exclude_key($url);
    foreach (array_keys($excluded) as $stored_url) {
        if (wp_static_exclude_key($stored_url) === $key) {
            return true;
        }
    }

    return wp_static_url_matches_exclusion_patterns($url);
}

function wp_static_set_url_excluded($url, $excluded)
{
    $list = wp_static_get_excluded();
    $key = wp_static_exclude_key($url);

    foreach (array_keys($list) as $stored_url) {
        if (wp_static_exclude_key($stored_url) === $key) {
            unset($list[$stored_url]);
        }
    }

    if ($excluded) {
        $list[$url] = 1;
    }

    update_option(WP_STATIC_EXCLUDED_OPTION, $list);
}

function wp_static_get_exclusion_patterns()
{
    $raw = (string) get_option(WP_STATIC_EXCLUDE_PATTERNS_OPTION, '');
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $patterns = [];
    foreach ((array) $lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $patterns[] = $line;
        }
    }

    return $patterns;
}

function wp_static_url_matches_exclusion_patterns($url)
{
    $patterns = wp_static_get_exclusion_patterns();
    if (empty($patterns)) {
        return false;
    }

    foreach ($patterns as $pattern) {
        if (wp_static_url_matches_pattern($url, $pattern)) {
            return true;
        }
    }

    return false;
}

/**
 * Teste une URL contre un motif (joker `*`), aussi bien sur l'URL complète que
 * sur son chemin (avec ou sans slash final). Mutualisé entre exclusions et
 * URLs « à toujours régénérer ».
 */
function wp_static_url_matches_pattern($url, $pattern)
{
    $pattern = trim((string) $pattern);
    if ($pattern === '') {
        return false;
    }

    $path = wp_parse_url($url, PHP_URL_PATH);
    $path = $path ? '/' . ltrim(rawurldecode($path), '/') : '/';

    $targets = [$url, $path, untrailingslashit($path)];
    foreach ($targets as $target) {
        if (function_exists('fnmatch') && fnmatch($pattern, $target)) {
            return true;
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        if (preg_match($regex, $target)) {
            return true;
        }
    }

    return false;
}

function wp_static_should_purge_orphans()
{
    return (bool) get_option(WP_STATIC_PURGE_ORPHANS_OPTION, 0);
}

function wp_static_cron_frequency()
{
    $frequency = (string) get_option(WP_STATIC_CRON_FREQUENCY_OPTION, 'off');
    $allowed = ['off', 'twicedaily', 'daily', 'weekly'];

    return in_array($frequency, $allowed, true) ? $frequency : 'off';
}

/**
 * URLs à régénérer systématiquement à chaque enregistrement de contenu
 * (pages que la carte de dépendances ne peut pas deviner : accueil « magazine »,
 * plan du site, listing à requête personnalisée…). Une entrée par ligne.
 *
 * Chaque ligne peut être :
 * - une URL complète du site (ex. https://exemple.com/plan-du-site/) ;
 * - un motif avec joker `*` (ex. /actualites/* ou /actualites/page/*),
 *   déployé contre les URLs connues du site.
 */
function wp_static_get_always_regen_urls()
{
    $raw = (string) get_option(WP_STATIC_ALWAYS_REGEN_OPTION, '');
    $lines = preg_split('/\r\n|\r|\n/', $raw);

    $urls = [];
    $patterns = [];
    foreach ((array) $lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (strpos($line, '*') !== false) {
            // Motif : déployé plus bas contre les URLs connues.
            $patterns[] = $line;
            continue;
        }
        // URL fixe : on ne garde que celles du site (même hôte), pour ne pas
        // déclencher de requête vers un domaine arbitraire.
        if (wp_static_is_local_url($line)) {
            $urls[$line] = $line;
        }
    }

    // Déploiement des motifs contre la liste des URLs collectables. On ne fait
    // cette collecte (potentiellement coûteuse) que si au moins un motif existe.
    if (!empty($patterns)) {
        foreach (array_keys(wp_static_collect_url_items()) as $known_url) {
            foreach ($patterns as $pattern) {
                if (wp_static_url_matches_pattern($known_url, $pattern)) {
                    $urls[$known_url] = $known_url;
                    break;
                }
            }
        }
    }

    return array_values($urls);
}

/**
 * Classes CSS « marqueurs » : si une page générée contient une de ces classes,
 * elle est considérée comme ayant une remontée dynamique et sera régénérée à
 * chaque enregistrement de contenu (mode auto). Une classe par ligne.
 */
function wp_static_get_regen_classes()
{
    $raw = (string) get_option(WP_STATIC_REGEN_CLASSES_OPTION, '');
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $classes = [];
    foreach ((array) $lines as $line) {
        $line = ltrim(trim($line), '.');
        $line = preg_replace('/[^A-Za-z0-9_\-]/', '', $line);
        if ($line !== '') {
            $classes[$line] = $line;
        }
    }

    return array_values($classes);
}

/**
 * Nettoie le textarea de classes (une par ligne, sans point initial).
 */
function wp_static_sanitize_classes_text($text)
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $text);
    $clean = [];
    foreach ((array) $lines as $line) {
        $line = ltrim(trim($line), '.');
        $line = preg_replace('/[^A-Za-z0-9_\-]/', '', $line);
        if ($line !== '' && !isset($clean[$line])) {
            $clean[$line] = $line;
        }
    }

    return implode("\n", array_values($clean));
}

/**
 * URLs détectées (à la génération) comme contenant une classe marqueur.
 */
function wp_static_get_dynamic_urls()
{
    $list = get_option(WP_STATIC_DYNAMIC_URLS_OPTION, []);
    return is_array($list) ? array_values($list) : [];
}

/**
 * Détecte la présence d'une des classes marqueurs dans un attribut class= du HTML.
 * Le token doit correspondre exactement (pas de sous-chaîne accidentelle).
 */
function wp_static_html_has_marker_class($html, $classes)
{
    if (empty($classes) || $html === '') {
        return false;
    }
    foreach ((array) $classes as $class) {
        if ($class === '') {
            continue;
        }
        $token = preg_quote($class, '/');
        $regex = '/\sclass\s*=\s*("|\')[^"\']*(?<![\w-])' . $token . '(?![\w-])[^"\']*\1/i';
        if (preg_match($regex, $html)) {
            return true;
        }
    }

    return false;
}

/**
 * Indique si le service de pages statiques est activé.
 */
function wp_static_is_enabled()
{
    return (bool) get_option(WP_STATIC_ENABLED_OPTION, false);
}

function wp_static_is_minify_enabled()
{
    return (bool) get_option(WP_STATIC_MINIFY_OPTION, false);
}

/**
 * Mode automatique : si activé (par défaut), les pages impactées sont
 * régénérées automatiquement aux modifications. Sinon (mode manuel), rien n'est
 * régénéré automatiquement et le site est seulement marqué « à régénérer ».
 */
/**
 * Mode de régénération :
 * - 'manual' : rien n'est régénéré automatiquement, le site est marqué « à régénérer » ;
 * - 'auto'   : seules les pages impactées sont régénérées (dépendances, listings, classes…) ;
 * - 'full'   : l'intégralité du site est régénérée à chaque événement (petits sites).
 */
function wp_static_get_mode()
{
    $mode = get_option(WP_STATIC_MODE_OPTION, null);
    if ($mode === null) {
        // Compatibilité : on dérive de l'ancienne option booléenne.
        $mode = get_option(WP_STATIC_AUTO_OPTION, 1) ? 'auto' : 'manual';
    }

    return in_array($mode, ['manual', 'auto', 'full'], true) ? $mode : 'auto';
}

/**
 * Vrai si une régénération automatique a lieu (mode 'auto' ou 'full'), par
 * opposition au mode manuel.
 */
function wp_static_is_auto_enabled()
{
    return wp_static_get_mode() !== 'manual';
}

/**
 * Vrai si l'on est en environnement de préproduction Lonsdale (protégé par un
 * .htaccess, ce qui nécessite une authentification Basic pour la génération).
 */
function wp_static_is_preprod()
{
    return defined('ENV_PREPROD_LONSDALE') && ENV_PREPROD_LONSDALE;
}

/**
 * En-tête d'authentification Basic pour la préprod (vide si non applicable ou
 * non configuré).
 */
function wp_static_htaccess_auth_header()
{
    if (!wp_static_is_preprod()) {
        return '';
    }
    $user = (string) get_option(WP_STATIC_HTACCESS_USER_OPTION, '');
    $pass = (string) get_option(WP_STATIC_HTACCESS_PASS_OPTION, '');
    if ($user === '' && $pass === '') {
        return '';
    }

    return 'Basic ' . base64_encode($user . ':' . $pass);
}

/**
 * Garde du mode manuel. Retourne true si la régénération automatique doit se
 * poursuivre ; sinon marque le site « à régénérer » et retourne false.
 */
function wp_static_auto_active()
{
    if (wp_static_get_mode() !== 'manual') {
        return true;
    }
    wp_static_mark_dirty();
    return false;
}

/**
 * Indique si au moins une génération a déjà eu lieu (dossier statique présent).
 */
function wp_static_has_generated()
{
    return is_dir(WP_STATIC_DIR);
}

/**
 * Marque le site statique comme « à régénérer » suite à un changement structurel
 * (menu, permaliens, réglages…) dont on ne peut pas deviner l'impact précis.
 */
function wp_static_mark_dirty()
{
    if (wp_static_has_generated()) {
        update_option(WP_STATIC_DIRTY_OPTION, 1);
    }
}

/**
 * Réaction à un changement structurel dont l'impact est difficile à cibler.
 * En mode Complet : régénère l'intégralité du site. Sinon : marque « à régénérer ».
 */
function wp_static_on_structural_change()
{
    if (!wp_static_has_generated()) {
        return;
    }
    if (wp_static_get_mode() === 'full') {
        wp_static_enqueue_urls([]);
        return;
    }
    wp_static_mark_dirty();
}

function wp_static_is_dirty()
{
    return (bool) get_option(WP_STATIC_DIRTY_OPTION, false);
}

/**
 * Marque le site « à régénérer » après une régénération de miniatures.
 *
 * On ne déclenche le marqueur que pendant une opération du plugin Regenerate
 * Thumbnails (et non à chaque simple upload média, qui ne modifie pas les pages
 * déjà publiées).
 */
function wp_static_on_attachment_metadata($data, $attachment_id)
{
    if (wp_static_is_thumbnail_regeneration()) {
        wp_static_on_structural_change();
    }

    return $data;
}

function wp_static_is_thumbnail_regeneration()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if (strpos($uri, 'regenerate-thumbnails/v1/regenerate') !== false) {
        return true;
    }

    $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
    if (($action === '' || $action === '-1') && isset($_REQUEST['action2'])) {
        $action = sanitize_key($_REQUEST['action2']);
    }

    return $action === 'bulk_regenerate_thumbnails';
}

/**
 * Carte des dépendances : url => [IDs des contenus affichés sur cette page].
 */
function wp_static_get_deps()
{
    $deps = get_option(WP_STATIC_DEPS_OPTION, []);
    return is_array($deps) ? $deps : [];
}

function wp_static_save_deps($deps)
{
    // autoload='no' : cette option peut devenir volumineuse (1 entrée par URL)
    // et n'est jamais nécessaire au chargement normal des pages.
    update_option(WP_STATIC_DEPS_OPTION, $deps, false);
}

/**
 * Bascule l'option des dépendances en autoload='no' si elle a été créée avant
 * cette optimisation (sinon elle resterait chargée à chaque requête). Exécuté
 * une seule fois grâce à un drapeau.
 */
function wp_static_maybe_migrate_deps_autoload()
{
    if (get_option('wp_static_deps_autoload_migrated')) {
        return;
    }

    global $wpdb;
    $autoload = $wpdb->get_var($wpdb->prepare(
        "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
        WP_STATIC_DEPS_OPTION
    ));

    if ($autoload !== null && !in_array($autoload, ['no', 'off'], true)) {
        if (function_exists('wp_set_option_autoload')) {
            wp_set_option_autoload(WP_STATIC_DEPS_OPTION, false);
        } else {
            $wpdb->update(
                $wpdb->options,
                ['autoload' => 'no'],
                ['option_name' => WP_STATIC_DEPS_OPTION]
            );
            wp_cache_delete('alloptions', 'options');
        }
    }

    update_option('wp_static_deps_autoload_migrated', 1, false);
}

/**
 * Retourne les URLs déjà générées qui affichent le contenu $post_id
 * (page elle-même + remontées : accueil, archives, blocs « articles liés »…).
 */
function wp_static_urls_for_post($post_id)
{
    $post_id = (int) $post_id;
    $urls = [];
    foreach (wp_static_get_deps() as $url => $ids) {
        if (in_array($post_id, (array) $ids, true)) {
            $urls[] = $url;
        }
    }
    return $urls;
}

/**
 * 1. Ajoute la page d'administration pour le plugin.
 */
add_action('admin_menu', 'wp_static_add_admin_page');
// Priorité très tardive : tous les remove_menu_page() des thèmes/plugins ont eu lieu.
add_action('admin_menu', 'wp_static_cache_hidden_post_types', 99999);
add_action('admin_post_wp_static_generate', 'wp_static_handle_generate_request');
add_action('admin_post_wp_static_export', 'wp_static_handle_export');
add_action('wp_ajax_wp_static_toggle', 'wp_static_ajax_toggle');
add_action('wp_ajax_wp_static_toggle_minify', 'wp_static_ajax_toggle_minify');
add_action('wp_ajax_wp_static_toggle_purge_orphans', 'wp_static_ajax_toggle_purge_orphans');
add_action('wp_ajax_wp_static_toggle_auto', 'wp_static_ajax_toggle_auto');
add_action('wp_ajax_wp_static_save_htaccess', 'wp_static_ajax_save_htaccess');
add_action('wp_ajax_wp_static_save_advanced', 'wp_static_ajax_save_advanced');
add_action('wp_ajax_wp_static_save_always_regen', 'wp_static_ajax_save_always_regen');
add_action('wp_ajax_wp_static_clear_cache', 'wp_static_ajax_clear_cache');
add_action('wp_ajax_wp_static_regenerate_url', 'wp_static_ajax_regenerate_url');
add_action('wp_ajax_wp_static_toggle_exclude', 'wp_static_ajax_toggle_exclude');

// Régénération automatique du contenu modifié et de ses pages dépendantes.
add_action('save_post', 'wp_static_on_save_post', 20, 3);
add_action('before_delete_post', 'wp_static_on_delete_post');
add_action('wp_trash_post', 'wp_static_on_delete_post');

// (1+2) Transitions de statut : publication (y compris programmée via cron) et
// dépublication (publish -> brouillon/privé) qui doit retirer le fichier statique.
add_action('transition_post_status', 'wp_static_on_transition_post_status', 10, 3);

// (3) Changement de slug/parent : l'ancienne URL devient un fichier orphelin.
add_action('post_updated', 'wp_static_on_post_updated', 10, 3);

// (4) Commentaires : modifient la page de l'article (liste + compteur).
add_action('comment_post', 'wp_static_on_comment_change');
add_action('edit_comment', 'wp_static_on_comment_change');
add_action('trashed_comment', 'wp_static_on_comment_change');
add_action('untrashed_comment', 'wp_static_on_comment_change');
add_action('deleted_comment', 'wp_static_on_comment_change');
add_action('spammed_comment', 'wp_static_on_comment_change');
add_action('unspammed_comment', 'wp_static_on_comment_change');
add_action('wp_set_comment_status', 'wp_static_on_comment_change');

// (5) Édition de termes (catégories, étiquettes, taxonomies) : nom/slug/lien
// impactent les archives et les contenus qui les affichent.
add_action('created_term', 'wp_static_on_term_change', 10, 3);
add_action('edited_term', 'wp_static_on_term_change', 10, 3);
add_action('delete_term', 'wp_static_on_term_change', 10, 3);

// (7) Blocs réutilisables : impactent tous les contenus qui les utilisent.
add_action('save_post_wp_block', 'wp_static_on_structural_change');

// (6) Pages d'options ACF : impactent potentiellement tout le site.
add_action('acf/save_post', 'wp_static_on_acf_save_post', 20);

// (9) Filet de sécurité : régénération complète planifiée quotidienne.
add_filter('cron_schedules', 'wp_static_cron_schedules');
add_action('init', 'wp_static_maybe_schedule_cron');
add_action('wp_static_daily_regeneration', 'wp_static_cron_regenerate');

// Convertit l'option volumineuse des dépendances en autoload='no' (une fois).
add_action('admin_init', 'wp_static_maybe_migrate_deps_autoload');

// Détection des changements structurels : on ne peut pas deviner l'impact,
// on signale simplement qu'il faut régénérer le site.
add_action('wp_update_nav_menu', 'wp_static_on_structural_change');
add_action('customize_save_after', 'wp_static_on_structural_change');
add_action('update_option_permalink_structure', 'wp_static_on_structural_change');
add_action('update_option_category_base', 'wp_static_on_structural_change');
add_action('update_option_tag_base', 'wp_static_on_structural_change');
// La page des permaliens n'enregistre l'option que si la valeur change :
// update_option_* ne se déclenche alors pas. On marque donc « à régénérer »
// dès qu'une soumission du formulaire de permaliens est détectée.
add_action('load-options-permalink.php', 'wp_static_on_permalinks_saved');
add_action('update_option_blogname', 'wp_static_on_structural_change');
add_action('update_option_blogdescription', 'wp_static_on_structural_change');
add_action('update_option_show_on_front', 'wp_static_on_structural_change');
add_action('update_option_page_on_front', 'wp_static_on_structural_change');
add_action('update_option_page_for_posts', 'wp_static_on_structural_change');
add_action('update_option_sidebars_widgets', 'wp_static_on_structural_change');
add_action('update_option_siteurl', 'wp_static_on_structural_change');
add_action('update_option_home', 'wp_static_on_structural_change');

// Réglages globaux Yoast SEO (Titres & métas, Réseaux sociaux, général) : ils
// modifient les balises <head> de tout le site. Les métas SEO d'une page sont,
// elles, déjà couvertes par save_post.
add_action('update_option_wpseo', 'wp_static_on_structural_change');
add_action('update_option_wpseo_titles', 'wp_static_on_structural_change');
add_action('update_option_wpseo_social', 'wp_static_on_structural_change');

add_action('admin_notices', 'wp_static_dirty_admin_notice');
add_action('admin_bar_menu', 'wp_static_admin_bar_menu', 80);
add_action('admin_print_footer_scripts', 'wp_static_admin_bar_flash_script');

// Régénération des miniatures (plugin Regenerate Thumbnails) : les tailles
// d'images et les srcset changent, donc les pages statiques sont obsolètes.
add_filter('wp_update_attachment_metadata', 'wp_static_on_attachment_metadata', 10, 2);

// Collecte des dépendances pendant une requête de génération.
add_action('init', 'wp_static_maybe_collect_dependencies', 0);

function wp_static_add_admin_page()
{
    add_menu_page(
        'WP Static Generator',
        'WP Static',
        'manage_options',
        'wp-static-generator',
        'wp_static_admin_page_content',
        'dashicons-media-code',
        // Position élevée pour regrouper les outils maison en bas de la sidebar.
        202
    );
}

function wp_static_admin_page_content()
{
    $result_transient = WP_STATIC_RESULT_TRANSIENT_PREFIX . get_current_user_id();
    $result = get_transient($result_transient);
    delete_transient($result_transient);
    if (is_array($result)) {
        $result = wp_parse_args($result, [
            'generated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'messages' => [],
        ]);
    }
    $notice_type = is_array($result) && $result['failed'] > 0 ? 'warning' : 'success';
?>
    <div class="wrap">
        <?php if (is_array($result)) : ?>
            <div id="wp-static-notice-slot" class="wp-static-notice-slot">
                <div class="wp-static-result-notice wp-static-result-notice--<?php echo esc_attr($notice_type); ?> is-dismissible" role="alert">
                    <p>
                        <strong>Génération terminée.</strong>
                        <?php echo esc_html($result['generated']); ?> page(s) générée(s),
                        <?php echo esc_html($result['skipped']); ?> page(s) ignorée(s),
                        <?php echo esc_html($result['failed']); ?> erreur(s).
                    </p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text"><?php esc_html_e('Dismiss this notice.'); ?></span>
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <?php
        $mode = wp_static_get_mode();
        $mode_badges = [
            'manual' => 'Mode manuel',
            'auto'   => 'Mode automatique',
            'full'   => 'Mode complet',
        ];
        $badge_label = isset($mode_badges[$mode]) ? $mode_badges[$mode] : $mode_badges['auto'];
        $mode_descriptions = [
            'manual' => 'Aucune régénération automatique ; toute modification affiche la notice « régénérer le site ».',
            'auto'   => 'Régénère uniquement les pages impactées (dépendances, listings, classes, URLs forcées). Recommandé pour la plupart des sites.',
            'full'   => 'Régénère l’intégralité du site à chaque sauvegarde. Simple et toujours à jour, idéal pour les petits sites au contenu peu changeant — à éviter sur les gros sites (coûteux).',
        ];
        ?>
        <div class="wp-static-page-header">
            <div class="wp-static-page-header-top">

                <h1>Générateur de Pages Statiques WP Static</h1>

                <div class="wp-static-setting wp-static-setting--inline">
                    <label class="wp-static-toggle">
                        <input type="checkbox" id="wp-static-enabled" <?php checked(wp_static_is_enabled()); ?>>
                        <span class="wp-static-slider"></span>
                    </label>
                    <label for="wp-static-enabled" id="wp-static-enabled-label">
                        <?php echo wp_static_is_enabled() ? 'Désactiver' : 'Activer'; ?>
                    </label>
                    <span class="wp-static-status" id="wp-static-status" aria-live="polite"></span>
                </div>
            </div>
            <span id="wp-static-mode-badge" class="wp-static-mode-badge"><?php echo esc_html($badge_label); ?></span>
            <p class="description" id="wp-static-mode-desc"><?php echo esc_html($mode_descriptions[$mode]); ?></p>
        </div>
        <?php if (is_array($result)) : ?>
        <script>
        (function () {
            var slot = document.getElementById('wp-static-notice-slot');
            if (!slot) { return; }
            slot.addEventListener('click', function (event) {
                var btn = event.target.closest('.notice-dismiss');
                if (!btn) { return; }
                var notice = btn.closest('.wp-static-result-notice');
                if (notice) {
                    notice.remove();
                }
                if (!slot.querySelector('.wp-static-result-notice')) {
                    slot.remove();
                }
            });
        })();
        </script>
        <?php endif; ?>
        <br>
        <script>
            (function() {
                var input = document.getElementById('wp-static-enabled');
                var status = document.getElementById('wp-static-status');
                var actionLabel = document.getElementById('wp-static-enabled-label');
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_static_toggle_action')); ?>;

                function syncActionLabel() {
                    if (actionLabel) {
                        actionLabel.textContent = input.checked ? 'Désactiver' : 'Activer';
                    }
                }

                input.addEventListener('change', function() {
                    var enabled = input.checked ? '1' : '0';
                    input.disabled = true;
                    syncActionLabel();
                    status.textContent = 'Enregistrement…';

                    var body = new URLSearchParams();
                    body.append('action', 'wp_static_toggle');
                    body.append('nonce', nonce);
                    body.append('enabled', enabled);

                    fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: body.toString()
                        })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(data) {
                            if (data && data.success) {
                                if (data.data.warning) {
                                    status.textContent = data.data.warning;
                                } else {
                                    status.textContent = data.data.enabled ? 'Site statique activé.' : 'Site statique désactivé.';
                                }
                            } else {
                                throw new Error('save_failed');
                            }
                        })
                        .catch(function() {
                            input.checked = !input.checked;
                            syncActionLabel();
                            status.textContent = 'Erreur lors de l’enregistrement.';
                        })
                        .finally(function() {
                            input.disabled = false;
                        });
                });
            })();
        </script>

        <h2 class="nav-tab-wrapper wp-static-tabs">
            <a href="#statique" class="nav-tab nav-tab-active" data-tab="statique">Statique</a>
            <a href="#parametres" class="nav-tab" data-tab="parametres">Paramètres</a>
            <a href="#export" class="nav-tab" data-tab="export">Export</a>
        </h2>

        <style>
            .wp-static-notice-slot {
                margin: 0 0 12px 0;
            }

            .wp-static-result-notice {
                position: relative;
                background: #fff;
                border: 1px solid #c3c4c7;
                border-left-width: 4px;
                box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
                margin: 0;
                padding: 1px 38px 1px 12px;
            }

            .wp-static-result-notice p {
                margin: .5em 0;
                padding: 2px;
            }

            .wp-static-result-notice--success {
                border-left-color: #00a32a;
            }

            .wp-static-result-notice--warning {
                border-left-color: #dba617;
            }

            .wp-static-result-notice .notice-dismiss {
                position: absolute;
                top: 0;
                right: 1px;
                border: none;
                margin: 0;
                padding: 9px;
                background: 0 0;
                color: #787c82;
                cursor: pointer;
            }

            .wp-static-result-notice .notice-dismiss::before {
                background: 0 0;
                color: #787c82;
                content: "\f153";
                display: block;
                font: normal 16px/20px dashicons;
                speak: never;
                height: 20px;
                text-align: center;
                width: 20px;
                -webkit-font-smoothing: antialiased;
            }

            .wp-static-result-notice .notice-dismiss:hover,
            .wp-static-result-notice .notice-dismiss:hover::before {
                color: #d63638;
            }

            .wp-static-page-header {
                margin-bottom: 8px;
            }

            .wp-static-page-header-top {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 12px 20px;
            }

            .wp-static-page-header-top h1 {
                margin: 0;
            }

            .wp-static-setting--inline {
                margin: 0;
            }

            .wp-static-mode-badge {
                display: inline-block;
                margin-top: 10px;
                padding: 2px 8px;
                border-radius: 10px;
                background: #2271b1;
                color: #fff;
                font-size: 13px;
                font-weight: 400;
                line-height: 1.4;
            }

            #wp-static-mode-desc {
                margin: 8px 0 0 0;
                max-width: 720px;
            }

            .wp-static-tab-panel {
                margin-top: 16px;
            }

            .wp-static-setting {
                display: flex;
                align-items: center;
                gap: 12px;
                margin: 16px 0;
            }

            .wp-static-toggle {
                position: relative;
                display: inline-block;
                width: 46px;
                height: 24px;
                flex: 0 0 auto;
            }

            .wp-static-toggle input {
                position: absolute;
                opacity: 0;
                width: 0;
                height: 0;
            }

            .wp-static-slider {
                position: absolute;
                inset: 0;
                cursor: pointer;
                background-color: #8c8f94;
                border-radius: 24px;
                transition: background-color .2s ease;
            }

            .wp-static-slider::before {
                content: "";
                position: absolute;
                height: 18px;
                width: 18px;
                left: 3px;
                top: 3px;
                background-color: #fff;
                border-radius: 50%;
                transition: transform .2s ease;
            }

            .wp-static-toggle input:checked+.wp-static-slider {
                background-color: #2271b1;
            }

            .wp-static-toggle input:checked+.wp-static-slider::before {
                transform: translateX(22px);
            }

            .wp-static-toggle input:focus+.wp-static-slider {
                box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2271b1;
            }

            .wp-static-toggle input:disabled+.wp-static-slider {
                opacity: .6;
                cursor: default;
            }

            .wp-static-status {
                color: #50575e;
                font-style: italic;
            }

            .wp-static-path {
                color: #646970;
                font-size: 12px;
                margin-top: 2px;
            }

            .wp-static-exclude {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 12px;
            }

            .wp-static-status-text {
                color: #50575e;
                font-size: 12px;
                margin-top: 4px;
            }

            .wp-static-checkbox {
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .wp-static-help {
                position: relative;
                color: #787c82;
                cursor: help;
                vertical-align: middle;
                text-decoration: none;
            }

            .wp-static-help::after {
                content: attr(data-tip);
                position: absolute;
                left: 50%;
                bottom: 150%;
                transform: translateX(-50%);
                width: 260px;
                background: #1d2327;
                color: #fff;
                padding: 8px 10px;
                border-radius: 4px;
                font-size: 12px;
                line-height: 1.4;
                font-weight: 400;
                text-align: left;
                white-space: normal;
                opacity: 0;
                visibility: hidden;
                transition: opacity .15s ease;
                z-index: 100;
                pointer-events: none;
            }

            .wp-static-help:hover::after,
            .wp-static-help:focus::after {
                opacity: 1;
                visibility: visible;
            }
        </style>

        <div class="wp-static-tab-panel" data-panel="parametres" style="display:none;">
            <h2>Paramètres</h2>
            <?php $wp_static_mode = wp_static_get_mode(); ?>
            <div class="wp-static-setting" style="flex-direction: column; align-items: flex-start; gap: 6px;">
                <label for="wp-static-mode" style="font-weight: 600;">
                    Mode de régénération
                </label>
                <select id="wp-static-mode">
                    <option value="manual" <?php selected($wp_static_mode, 'manual'); ?>>Manuel</option>
                    <option value="auto" <?php selected($wp_static_mode, 'auto'); ?>>Automatique</option>
                    <option value="full" <?php selected($wp_static_mode, 'full'); ?>>Complet</option>
                </select>
            </div>
            <div id="wp-static-always-regen-wrap" <?php echo $wp_static_mode === 'auto' ? '' : ' style="display: none;"'; ?>>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wp-static-always-regen">URLs à toujours régénérer</label></th>
                        <td>
                            <textarea id="wp-static-always-regen" rows="4" class="large-text code" placeholder="<?php echo esc_attr(home_url('/')); ?>&#10;/actualites/*"><?php echo esc_textarea((string) get_option(WP_STATIC_ALWAYS_REGEN_OPTION, '')); ?></textarea>
                            <p class="description">Une entrée par ligne, régénérée à chaque enregistrement de contenu (en plus des pages détectées). Utile pour les pages que les dépendances ne devinent pas (accueil « magazine », plan du site, listing personnalisé…). Une ligne peut être une URL complète ou un motif avec joker <code>*</code> (ex. <code>/actualites/*</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wp-static-regen-classes">Classes déclenchant une régénération</label></th>
                        <td>
                            <textarea id="wp-static-regen-classes" rows="3" class="large-text code" placeholder=".remontees-auto&#10;.bloc-actualites"><?php echo esc_textarea((string) get_option(WP_STATIC_REGEN_CLASSES_OPTION, '')); ?></textarea>
                            <p class="description">Une classe CSS par ligne (ex. <code>.remontees-auto</code>). Toute page dont le HTML contient une de ces classes est détectée automatiquement à la génération et régénérée à chaque enregistrement de contenu — pas de mapping manuel.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button" id="wp-static-always-regen-save">Enregistrer</button>
                    <span class="wp-static-status" id="wp-static-always-regen-status" aria-live="polite"></span>
                </p>
            </div>
            <script>
                (function() {
                    var ta = document.getElementById('wp-static-always-regen');
                    var classesTa = document.getElementById('wp-static-regen-classes');
                    var btn = document.getElementById('wp-static-always-regen-save');
                    var status = document.getElementById('wp-static-always-regen-status');
                    if (!ta || !btn) {
                        return;
                    }
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_static_toggle_action')); ?>;

                    btn.addEventListener('click', function() {
                        btn.disabled = true;
                        status.textContent = 'Enregistrement…';

                        var body = new URLSearchParams();
                        body.append('action', 'wp_static_save_always_regen');
                        body.append('nonce', nonce);
                        body.append('always_regen', ta.value);
                        body.append('regen_classes', classesTa ? classesTa.value : '');

                        fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: body.toString()
                            })
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(data) {
                                if (data && data.success) {
                                    ta.value = data.data.value;
                                    if (classesTa) {
                                        classesTa.value = data.data.classes;
                                    }
                                    status.textContent = 'Enregistré (' + data.data.count + ' URL(s), ' + data.data.classes_count + ' classe(s)).';
                                } else {
                                    throw new Error('save_failed');
                                }
                            })
                            .catch(function() {
                                status.textContent = 'Erreur lors de l’enregistrement.';
                            })
                            .finally(function() {
                                btn.disabled = false;
                            });
                    });
                })();
            </script>
            <script>
                (function() {
                    var select = document.getElementById('wp-static-mode');
                    if (!select) {
                        return;
                    }
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_static_toggle_action')); ?>;
                    var alwaysWrap = document.getElementById('wp-static-always-regen-wrap');
                    var cronExcludeWrap = document.getElementById('wp-static-cron-exclude-wrap');
                    var previous = select.value;
                    var badge = document.getElementById('wp-static-mode-badge');
                    var badgeText = {
                        manual: 'Mode manuel',
                        auto: 'Mode automatique',
                        full: 'Mode complet'
                    };
                    var desc = document.getElementById('wp-static-mode-desc');
                    var descText = <?php echo wp_json_encode($mode_descriptions); ?>;

                    function syncAlwaysRegen() {
                        if (alwaysWrap) {
                            alwaysWrap.style.display = (select.value === 'auto') ? '' : 'none';
                        }
                        if (cronExcludeWrap) {
                            cronExcludeWrap.style.display = (select.value === 'full') ? 'none' : '';
                        }
                        if (badge && badgeText[select.value]) {
                            badge.textContent = badgeText[select.value];
                        }
                        if (desc && descText[select.value]) {
                            desc.textContent = descText[select.value];
                        }
                    }

                    select.addEventListener('change', function() {
                        var mode = select.value;
                        select.disabled = true;
                        syncAlwaysRegen();

                        var body = new URLSearchParams();
                        body.append('action', 'wp_static_toggle_auto');
                        body.append('nonce', nonce);
                        body.append('mode', mode);

                        fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: body.toString()
                            })
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(data) {
                                if (data && data.success) {
                                    previous = data.data.mode;
                                } else {
                                    throw new Error('save_failed');
                                }
                            })
                            .catch(function() {
                                select.value = previous;
                                syncAlwaysRegen();
                            })
                            .finally(function() {
                                select.disabled = false;
                            });
                    });
                })();
            </script>

            <div class="wp-static-setting">
                <label class="wp-static-toggle">
                    <input type="checkbox" id="wp-static-minify" <?php checked(wp_static_is_minify_enabled()); ?>>
                    <span class="wp-static-slider"></span>
                </label>
                <label for="wp-static-minify">
                    Minification — compresser le HTML des pages statiques générées (suppression des espaces et commentaires inutiles).
                </label>
                <span class="wp-static-status" id="wp-static-minify-status" aria-live="polite"></span>
            </div>
            <script>
                (function() {
                    var input = document.getElementById('wp-static-minify');
                    var status = document.getElementById('wp-static-minify-status');
                    if (!input) {
                        return;
                    }
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_static_toggle_action')); ?>;

                    input.addEventListener('change', function() {
                        var enabled = input.checked ? '1' : '0';
                        input.disabled = true;
                        status.textContent = 'Enregistrement…';

                        var body = new URLSearchParams();
                        body.append('action', 'wp_static_toggle_minify');
                        body.append('nonce', nonce);
                        body.append('enabled', enabled);

                        fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: body.toString()
                            })
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(data) {
                                if (data && data.success) {
                                    status.textContent = data.data.enabled ? 'Minification activée.' : 'Minification désactivée.';
                                } else {
                                    throw new Error('save_failed');
                                }
                            })
                            .catch(function() {
                                input.checked = !input.checked;
                                status.textContent = 'Erreur lors de l’enregistrement.';
                            })
                            .finally(function() {
                                input.disabled = false;
                            });
                    });
                })();
            </script>

            <div class="wp-static-setting">
                <label class="wp-static-toggle">
                    <input type="checkbox" id="wp-static-purge-orphans" <?php checked(wp_static_should_purge_orphans()); ?>>
                    <span class="wp-static-slider"></span>
                </label>
                <label for="wp-static-purge-orphans">
                    Purger les fichiers orphelins après une génération complète.
                </label>
                <span class="wp-static-status" id="wp-static-purge-orphans-status" aria-live="polite"></span>
            </div>
            <p class="description" style="margin: -8px 0 16px 0;">
                Supprime les fichiers statiques qui ne correspondent plus à une URL collectée (page supprimée, slug changé, type masqué, motif exclu…).
            </p>
            <script>
                (function() {
                    var input = document.getElementById('wp-static-purge-orphans');
                    var status = document.getElementById('wp-static-purge-orphans-status');
                    if (!input) {
                        return;
                    }
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_static_toggle_action')); ?>;

                    input.addEventListener('change', function() {
                        var enabled = input.checked ? '1' : '0';
                        input.disabled = true;
                        status.textContent = 'Enregistrement…';

                        var body = new URLSearchParams();
                        body.append('action', 'wp_static_toggle_purge_orphans');
                        body.append('nonce', nonce);
                        body.append('enabled', enabled);

                        fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: body.toString()
                            })
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(data) {
                                if (data && data.success) {
                                    status.textContent = data.data.enabled ? 'Purge des orphelins activée.' : 'Purge des orphelins désactivée.';
                                } else {
                                    throw new Error('save_failed');
                                }
                            })
                            .catch(function() {
                                input.checked = !input.checked;
                                status.textContent = 'Erreur lors de l’enregistrement.';
                            })
                            .finally(function() {
                                input.disabled = false;
                            });
                    });
                })();
            </script>

            <div id="wp-static-cron-exclude-wrap" <?php echo $wp_static_mode === 'full' ? ' style="display: none;"' : ''; ?>>
                <hr>
                <h2>Génération avancée</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wp-static-cron-frequency">Régénération planifiée</label></th>
                        <td>
                            <select id="wp-static-cron-frequency">
                                <option value="off" <?php selected(wp_static_cron_frequency(), 'off'); ?>>Désactivée</option>
                                <option value="twicedaily" <?php selected(wp_static_cron_frequency(), 'twicedaily'); ?>>Deux fois par jour</option>
                                <option value="daily" <?php selected(wp_static_cron_frequency(), 'daily'); ?>>Quotidienne</option>
                                <option value="weekly" <?php selected(wp_static_cron_frequency(), 'weekly'); ?>>Hebdomadaire</option>
                            </select>
                            <p class="description">Filet de sécurité : régénère le site selon cette fréquence si le mode Auto est actif.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wp-static-exclude-patterns">Exclusions par motif</label></th>
                        <td>
                            <textarea id="wp-static-exclude-patterns" class="large-text code" rows="5" placeholder="/feed/*&#10;*/preview/*&#10;https://example.com/private/*"><?php echo esc_textarea(get_option(WP_STATIC_EXCLUDE_PATTERNS_OPTION, '')); ?></textarea>
                            <p class="description">Un motif par ligne. Supporte <code>*</code>. Testé sur l'URL complète et sur le chemin (<code>/contact/</code>).</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button button-primary" id="wp-static-advanced-save">Enregistrer les paramètres avancés</button>
                    <span class="wp-static-status" id="wp-static-advanced-status" aria-live="polite"></span>
                </p>
            </div>
            <script>
                (function() {
                    var btn = document.getElementById('wp-static-advanced-save');
                    if (!btn) {
                        return;
                    }
                    var status = document.getElementById('wp-static-advanced-status');
                    var cron = document.getElementById('wp-static-cron-frequency');
                    var patterns = document.getElementById('wp-static-exclude-patterns');
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_static_toggle_action')); ?>;

                    btn.addEventListener('click', function() {
                        btn.disabled = true;
                        status.textContent = 'Enregistrement…';

                        var body = new URLSearchParams();
                        body.append('action', 'wp_static_save_advanced');
                        body.append('nonce', nonce);
                        body.append('cron_frequency', cron.value);
                        body.append('exclude_patterns', patterns.value);

                        fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: body.toString()
                            })
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(data) {
                                if (data && data.success) {
                                    status.textContent = 'Paramètres enregistrés.';
                                } else {
                                    throw new Error('save_failed');
                                }
                            })
                            .catch(function() {
                                status.textContent = 'Erreur lors de l’enregistrement.';
                            })
                            .finally(function() {
                                btn.disabled = false;
                            });
                    });
                })();
            </script>

            <?php if (wp_static_is_preprod()) : ?>
                <hr>
                <h2>Htaccess préprod</h2>
                <p class="description">La préproduction est protégée par une authentification <code>.htaccess</code>. Renseignez les identifiants pour que la génération puisse récupérer les pages (envoyés en authentification Basic).</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wp-static-htaccess-user">Utilisateur</label></th>
                        <td><input type="text" id="wp-static-htaccess-user" class="regular-text" autocomplete="off" value="<?php echo esc_attr(get_option(WP_STATIC_HTACCESS_USER_OPTION, '')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wp-static-htaccess-pass">Mot de passe</label></th>
                        <td>
                            <input type="password" id="wp-static-htaccess-pass" class="regular-text" autocomplete="new-password" value="" placeholder="<?php echo (get_option(WP_STATIC_HTACCESS_PASS_OPTION, '') !== '') ? '•••••••• (déjà enregistré)' : 'Aucun mot de passe enregistré'; ?>">
                            <p class="description">Laissez vide pour conserver le mot de passe actuel.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button button-primary" id="wp-static-htaccess-save">Enregistrer</button>
                    <span class="wp-static-status" id="wp-static-htaccess-status" aria-live="polite"></span>
                </p>
                <script>
                    (function() {
                        var btn = document.getElementById('wp-static-htaccess-save');
                        var userInput = document.getElementById('wp-static-htaccess-user');
                        var passInput = document.getElementById('wp-static-htaccess-pass');
                        var status = document.getElementById('wp-static-htaccess-status');
                        if (!btn) {
                            return;
                        }
                        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                        var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_static_toggle_action')); ?>;

                        btn.addEventListener('click', function() {
                            btn.disabled = true;
                            status.textContent = 'Enregistrement…';

                            var body = new URLSearchParams();
                            body.append('action', 'wp_static_save_htaccess');
                            body.append('nonce', nonce);
                            body.append('user', userInput.value);
                            body.append('pass', passInput.value);

                            fetch(ajaxUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded'
                                    },
                                    body: body.toString()
                                })
                                .then(function(r) {
                                    return r.json();
                                })
                                .then(function(data) {
                                    if (data && data.success) {
                                        status.textContent = 'Identifiants enregistrés.';
                                        if (passInput.value !== '') {
                                            passInput.value = '';
                                            passInput.placeholder = '•••••••• (déjà enregistré)';
                                        }
                                    } else {
                                        throw new Error('save_failed');
                                    }
                                })
                                .catch(function() {
                                    status.textContent = 'Erreur lors de l’enregistrement.';
                                })
                                .finally(function() {
                                    btn.disabled = false;
                                });
                        });
                    })();
                </script>
            <?php endif; ?>
        </div><!-- /panel parametres -->

        <div class="wp-static-tab-panel" data-panel="statique">
            <h2>Génération</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wp-static-generate-form">
                <input type="hidden" name="action" value="wp_static_generate">
                <?php wp_nonce_field('wp_static_generate_action', 'wp_static_nonce'); ?>
                <p>Cliquez sur le bouton ci-dessous pour générer toutes les pages statiques de votre site.</p>
                <button type="submit" name="wp_static_generate" class="button button-primary" id="wp-static-generate-btn">
                    <span class="wp-static-generate-label">Générer les pages statiques</span>
                    <span class="spinner wp-static-generate-spinner" style="display:none;float:none;margin:0 0 0 6px;vertical-align:middle;"></span>
                </button>
            </form>
            <script>
                (function() {
                    var form = document.getElementById('wp-static-generate-form');
                    if (!form) {
                        return;
                    }
                    var btn = document.getElementById('wp-static-generate-btn');
                    var spinner = btn.querySelector('.wp-static-generate-spinner');
                    form.addEventListener('submit', function() {
                        btn.disabled = true;
                        spinner.style.display = 'inline-block';
                        spinner.classList.add('is-active');
                    });
                })();
            </script>
            <br>
            <hr>

            <h2>Cache statique</h2>
            <p>Supprime tous les fichiers statiques générés. WordPress servira les pages dynamiquement jusqu'à la prochaine génération.</p>

            <button type="button" class="button" id="wp-static-clear-cache">Vider le cache statique</button>
            <span class="wp-static-status" id="wp-static-clear-cache-status" aria-live="polite"></span>

            <script>
                (function() {
                    var btn = document.getElementById('wp-static-clear-cache');
                    if (!btn) {
                        return;
                    }
                    var status = document.getElementById('wp-static-clear-cache-status');
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_static_toggle_action')); ?>;

                    btn.addEventListener('click', function() {
                        if (!window.confirm('Supprimer tous les fichiers statiques générés ?')) {
                            return;
                        }
                        btn.disabled = true;
                        status.textContent = 'Suppression…';

                        var body = new URLSearchParams();
                        body.append('action', 'wp_static_clear_cache');
                        body.append('nonce', nonce);

                        fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: body.toString()
                            })
                            .then(function(r) {
                                return r.json();
                            })
                            .then(function(data) {
                                if (data && data.success) {
                                    status.textContent = 'Cache vidé (' + data.data.deleted + ' fichier(s) supprimé(s)).';
                                    document.querySelectorAll('.wp-static-col-date').forEach(function(cell) {
                                        cell.textContent = '—';
                                    });
                                } else {
                                    throw new Error('clear_failed');
                                }
                            })
                            .catch(function() {
                                status.textContent = 'Erreur lors de la suppression.';
                            })
                            .finally(function() {
                                btn.disabled = false;
                            });
                    });
                })();
            </script>

            <br> <br>
            <hr>

            <?php
            $rows = wp_static_get_page_rows();
            $show_language = wp_static_is_wpml_active();
            // La colonne « Dépend de » n'a de sens qu'en mode auto ciblé (régénération
            // déclenchée par les dépendances). En mode manuel ou complet, on la masque.
            $show_deps = (wp_static_get_mode() === 'auto');

            // Regroupement par type : un tableau distinct par type de page.
            $groups = [];
            foreach ($rows as $row) {
                $groups[$row['type']][] = $row;
            }
            ?>
            <?php if (empty($groups)) : ?>
                <p>Aucune page publique trouvée.</p>
                <?php else : foreach ($groups as $type => $type_rows) : ?>
                    <h3 class="wp-static-group-title"><?php echo esc_html($type); ?> <span class="count">(<?php echo (int) count($type_rows); ?>)</span></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col">Page</th>
                                <?php if ($show_language) : ?>
                                    <th scope="col">Langue</th>
                                <?php endif; ?>
                                <th scope="col">Modèle</th>
                                <th scope="col">Static</th>
                                <th scope="col">Dernière génération</th>
                                <?php if ($show_deps) : ?>
                                    <th scope="col">Dépend de
                                        <span class="wp-static-help dashicons dashicons-editor-help" tabindex="0" role="img" aria-label="Aide" data-tip="Contenus (articles, pages, CPT) affichés sur cette page via une remontée. Si l’un d’eux est modifié, cette page est régénérée automatiquement (mode auto)."></span>
                                    </th>
                                <?php endif; ?>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($type_rows as $row) : ?>
                                <tr data-url="<?php echo esc_attr($row['url']); ?>">
                                    <td>
                                        <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html($row['title']); ?></strong></a>
                                    </td>
                                    <?php if ($show_language) : ?>
                                        <td><?php echo esc_html($row['language']); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo esc_html($row['template']); ?></td>
                                    <td class="wp-static-col-static">
                                        <input type="checkbox" class="wp-static-exclude-cb" <?php checked(!$row['excluded']); ?>>
                                    </td>
                                    <td class="wp-static-col-date"><?php echo $row['date'] ? esc_html($row['date']) : '—'; ?></td>
                                    <?php if ($show_deps) : ?>
                                        <td class="wp-static-col-deps"><?php echo wp_static_format_deps($row['deps']); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <button type="button" class="button wp-static-regen-btn" <?php disabled($row['excluded']); ?>>Régénérer</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
            <?php endforeach;
            endif; ?>
            <script>
                (function() {
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_static_regenerate_action')); ?>;

                    document.querySelectorAll('.wp-static-regen-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var row = btn.closest('tr');
                            var url = row.getAttribute('data-url');
                            var dateCell = row.querySelector('.wp-static-col-date');
                            var label = btn.textContent;

                            btn.disabled = true;
                            btn.textContent = 'Régénération…';

                            var body = new URLSearchParams();
                            body.append('action', 'wp_static_regenerate_url');
                            body.append('nonce', nonce);
                            body.append('url', url);

                            fetch(ajaxUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded'
                                    },
                                    body: body.toString()
                                })
                                .then(function(r) {
                                    return r.json();
                                })
                                .then(function(data) {
                                    if (data && data.success) {
                                        if (dateCell) {
                                            dateCell.textContent = data.data.date || '—';
                                        }
                                    } else {
                                        throw new Error('regen_failed');
                                    }
                                })
                                .catch(function() {})
                                .finally(function() {
                                    btn.disabled = false;
                                    btn.textContent = label;
                                });
                        });
                    });

                    document.querySelectorAll('.wp-static-exclude-cb').forEach(function(cb) {
                        cb.addEventListener('change', function() {
                            var row = cb.closest('tr');
                            var url = row.getAttribute('data-url');
                            var dateCell = row.querySelector('.wp-static-col-date');
                            var regenBtn = row.querySelector('.wp-static-regen-btn');
                            // Cochée = statique (incluse) ; décochée = non statique (exclue).
                            var excluded = cb.checked ? '0' : '1';

                            cb.disabled = true;

                            var body = new URLSearchParams();
                            body.append('action', 'wp_static_toggle_exclude');
                            body.append('nonce', nonce);
                            body.append('url', url);
                            body.append('excluded', excluded);

                            fetch(ajaxUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded'
                                    },
                                    body: body.toString()
                                })
                                .then(function(r) {
                                    return r.json();
                                })
                                .then(function(data) {
                                    if (data && data.success) {
                                        if (dateCell) {
                                            dateCell.textContent = data.data.date || '—';
                                        }
                                        if (regenBtn) {
                                            regenBtn.disabled = data.data.excluded;
                                        }
                                    } else {
                                        throw new Error('exclude_failed');
                                    }
                                })
                                .catch(function() {
                                    cb.checked = !cb.checked;
                                })
                                .finally(function() {
                                    cb.disabled = false;
                                });
                        });
                    });
                })();
            </script>
        </div><!-- /panel statique -->

        <div class="wp-static-tab-panel" data-panel="export" style="display:none;">
            <h2>Export</h2>
            <?php if (!class_exists('ZipArchive')) : ?>
                <p class="description">L’extension PHP <code>ZipArchive</code> est requise pour l’export et n’est pas disponible sur ce serveur.</p>
            <?php elseif (!wp_static_has_generated()) : ?>
                <p class="description">Générez d’abord les pages statiques (onglet <strong>Statique</strong>) avant de pouvoir exporter.</p>
            <?php else : ?>
                <?php $export_folder = get_option(WP_STATIC_EXPORT_FOLDER_OPTION, '/'); ?>
                <p>Télécharge une archive ZIP du site statique (pages + assets), avec ou sans les médias (uploads).</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wp-static-export-form">
                    <input type="hidden" name="action" value="wp_static_export">
                    <input type="hidden" name="download_token" id="wp-static-export-token" value="">
                    <?php wp_nonce_field('wp_static_export_action', 'wp_static_export_nonce'); ?>

                    <div class="wp-static-setting">
                        <label class="wp-static-toggle">
                            <input type="checkbox" name="include_uploads" value="1" checked>
                            <span class="wp-static-slider"></span>
                        </label>
                        <label>Inclure les uploads (médias de <code>wp-content/uploads</code>).</label>
                    </div>

                    <p>
                        <label for="wp-static-export-folder"><strong>Dossier de base</strong></label><br>
                        <input type="text" id="wp-static-export-folder" name="folder" class="regular-text" value="<?php echo esc_attr($export_folder); ?>" placeholder="test/ ou /">
                        <br><span class="description">Dossier racine du site dans l’archive (et base des liens). « <code>/</code> » pour la racine, « <code>test/</code> » pour un sous-dossier.</span>
                    </p>

                    <p>
                        <button type="submit" class="button button-primary" id="wp-static-export-btn">
                            <span class="wp-static-export-label">Exporter</span>
                            <span class="spinner wp-static-export-spinner" style="display:none;float:none;margin:0 0 0 6px;vertical-align:middle;"></span>
                        </button>
                    </p>
                </form>

                <script>
                    (function() {
                        var form = document.getElementById('wp-static-export-form');
                        if (!form) {
                            return;
                        }
                        var btn = document.getElementById('wp-static-export-btn');
                        var tokenIn = document.getElementById('wp-static-export-token');
                        var spinner = btn.querySelector('.wp-static-export-spinner');
                        var poll = null;

                        function stop() {
                            if (poll) {
                                clearInterval(poll);
                                poll = null;
                            }
                            btn.disabled = false;
                            spinner.style.display = 'none';
                        }

                        form.addEventListener('submit', function() {
                            var token = 'tok' + Date.now() + Math.floor(Math.random() * 1e6);
                            tokenIn.value = token;
                            btn.disabled = true;
                            spinner.style.display = 'inline-block';
                            spinner.classList.add('is-active');

                            var cookieName = 'wp_static_dl_' + token + '=';
                            var attempts = 0;
                            poll = setInterval(function() {
                                attempts++;
                                if (document.cookie.indexOf(cookieName) !== -1) {
                                    // Le serveur a commencé à envoyer le ZIP : on efface le cookie et on arrête.
                                    document.cookie = cookieName + '; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
                                    stop();
                                } else if (attempts > 1200) { // garde-fou : 10 min max
                                    stop();
                                }
                            }, 500);
                        });
                    })();
                </script>
            <?php endif; ?>
        </div>

        <script>
            (function() {
                var tabs = document.querySelectorAll('.wp-static-tabs .nav-tab');
                var panels = document.querySelectorAll('.wp-static-tab-panel');

                function activate(target) {
                    var found = false;
                    tabs.forEach(function(t) {
                        var match = t.getAttribute('data-tab') === target;
                        t.classList.toggle('nav-tab-active', match);
                        if (match) {
                            found = true;
                        }
                    });
                    if (!found) {
                        return false;
                    }
                    panels.forEach(function(p) {
                        p.style.display = (p.getAttribute('data-panel') === target) ? '' : 'none';
                    });
                    return true;
                }

                tabs.forEach(function(tab) {
                    tab.addEventListener('click', function(e) {
                        e.preventDefault();
                        var target = tab.getAttribute('data-tab');
                        activate(target);
                        // Mémorise l'onglet courant pour le retrouver au rechargement.
                        if (window.history && window.history.replaceState) {
                            window.history.replaceState(null, '', '#' + target);
                        } else {
                            window.location.hash = target;
                        }
                    });
                });

                // Au chargement : réactive l'onglet présent dans l'URL (#...).
                var initial = (window.location.hash || '').replace(/^#/, '');
                if (initial) {
                    activate(initial);
                }
            })();
        </script>
    </div>
<?php
}

function wp_static_handle_generate_request()
{
    if (!current_user_can('manage_options')) {
        wp_die('Vous n’avez pas les permissions nécessaires pour générer les pages statiques.');
    }

    check_admin_referer('wp_static_generate_action', 'wp_static_nonce');

    $result = wp_static_run_generation();
    set_transient(WP_STATIC_RESULT_TRANSIENT_PREFIX . get_current_user_id(), $result, MINUTE_IN_SECONDS);

    wp_safe_redirect(admin_url('admin.php?page=wp-static-generator'));
    exit;
}

/**
 * Exporte le site statique en archive ZIP téléchargeable :
 * pages statiques + assets du thème, uploads en option, le tout sous un dossier
 * de base au choix (« / » racine ou « test/ »). Les URLs absolues du site sont
 * réécrites vers ce dossier de base pour un export autonome.
 */
function wp_static_handle_export()
{
    if (!current_user_can('manage_options')) {
        wp_die('Vous n’avez pas les permissions nécessaires pour exporter le site statique.');
    }

    check_admin_referer('wp_static_export_action', 'wp_static_export_nonce');

    if (!class_exists('ZipArchive')) {
        wp_die('L’extension PHP ZipArchive est requise pour l’export.');
    }
    if (!wp_static_has_generated()) {
        wp_die('Aucune page statique générée à exporter.');
    }

    $include_uploads = !empty($_POST['include_uploads']);
    $folder = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : '/';
    $prefix = wp_static_export_prefix($folder);

    // Mémorise le dossier de base pour les prochains exports.
    update_option(WP_STATIC_EXPORT_FOLDER_OPTION, $prefix === '' ? '/' : $prefix);

    // Nettoie d'éventuelles archives temporaires orphelines (download interrompu, crash…).
    wp_static_cleanup_export_temps();

    // 1. Sources à archiver.
    $sources = [
        [WP_STATIC_DIR, $prefix, true], // [dir, zip_prefix, rewrite_html]
    ];
    foreach (array_unique([get_stylesheet_directory(), get_template_directory()]) as $theme_dir) {
        $assets = $theme_dir . '/assets';
        if (is_dir($assets)) {
            $sources[] = [$assets, $prefix . 'wp-content/themes/' . basename($theme_dir) . '/assets/', false];
        }
    }
    if ($include_uploads) {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['basedir']) && is_dir($uploads['basedir'])) {
            $sources[] = [$uploads['basedir'], $prefix . 'wp-content/uploads/', false];
        }
    }

    // 2. Vérifie l'espace disque AVANT de construire (le ZIP temporaire + la copie
    // déjà compressée peuvent peser lourd). On exige la taille des sources avec une
    // marge de sécurité : les médias (déjà compressés) ne se compressent quasiment pas.
    $needed = 0;
    foreach ($sources as $src) {
        $needed += wp_static_dir_size($src[0]);
    }
    $needed = (int) ($needed * 1.1); // marge 10 %
    $free   = @disk_free_space(get_temp_dir());
    if ($free !== false && $needed > 0 && $free < $needed) {
        wp_die(sprintf(
            'Espace disque insuffisant pour générer l’archive : %s requis, %s disponibles dans le dossier temporaire (%s).',
            size_format($needed),
            size_format($free),
            esc_html(get_temp_dir())
        ));
    }

    @set_time_limit(0);
    // Continue le nettoyage/suppression même si l'utilisateur annule le téléchargement.
    @ignore_user_abort(true);

    $tmp = tempnam(get_temp_dir(), 'wpstatic-export');
    if ($tmp === false) {
        wp_die('Impossible de créer un fichier temporaire pour l’export.');
    }
    // Filet de sécurité : suppression du fichier temporaire en fin de script,
    // quoi qu'il arrive (abandon, erreur, timeout géré).
    register_shutdown_function(function () use ($tmp) {
        if (is_string($tmp) && file_exists($tmp)) {
            @unlink($tmp);
        }
    });

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_die('Impossible de créer l’archive ZIP.');
    }
    foreach ($sources as $src) {
        wp_static_zip_add_dir($zip, $src[0], $src[1], $src[2] ? $prefix : null);
    }
    $zip->close();

    $filename = 'site-statique-' . gmdate('Ymd-His') . '.zip';

    // Cookie consommé par le JS pour masquer le loader dès que le download démarre.
    if (!empty($_POST['download_token'])) {
        $token = preg_replace('/[^a-z0-9]/i', '', wp_unslash($_POST['download_token']));
        if ($token !== '') {
            setcookie('wp_static_dl_' . $token, '1', time() + 300, '/');
        }
    }

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/**
 * Taille totale (octets) du contenu d'un dossier, récursivement.
 */
function wp_static_dir_size($dir)
{
    $dir = rtrim((string) $dir, '/');
    if (!is_dir($dir)) {
        return 0;
    }
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

/**
 * Supprime les archives temporaires d'export orphelines de plus d'une heure.
 */
function wp_static_cleanup_export_temps()
{
    $pattern = rtrim(get_temp_dir(), '/') . '/wpstatic-export*';
    foreach ((array) glob($pattern) as $file) {
        if (is_file($file) && (time() - filemtime($file)) > HOUR_IN_SECONDS) {
            @unlink($file);
        }
    }
}

/**
 * Normalise le dossier de base : '' pour la racine, sinon 'segment/segment/'.
 */
function wp_static_export_prefix($folder)
{
    $folder = trim((string) $folder);
    $folder = trim(str_replace('\\', '/', $folder), '/');
    if ($folder === '') {
        return '';
    }
    $segments = array_filter(array_map('sanitize_title', explode('/', $folder)));

    return $segments ? implode('/', $segments) . '/' : '';
}

/**
 * Ajoute récursivement un dossier à l'archive sous un préfixe donné.
 * Si $rewrite_prefix est fourni, les fichiers .html voient leurs URLs absolues
 * du site réécrites vers ce dossier de base (export autonome).
 */
function wp_static_zip_add_dir($zip, $dir, $zip_prefix, $rewrite_prefix = null)
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (in_array($file->getFilename(), ['.DS_Store', 'Thumbs.db'], true)) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = ltrim(substr($path, strlen($dir)), '/');
        $zip_path = $zip_prefix . $relative;

        if ($rewrite_prefix !== null && preg_match('/\.html?$/i', $relative)) {
            $html = file_get_contents($path);
            $zip->addFromString($zip_path, wp_static_export_rewrite_html($html, $rewrite_prefix));
        } else {
            $zip->addFile($path, $zip_path);
        }
    }
}

/**
 * Réécrit les URLs absolues du site (http(s)://host/ et //host/) vers le dossier
 * de base de l'export (root-relative : « /test/ » ou « / »).
 */
function wp_static_export_rewrite_html($html, $prefix)
{
    if (!is_string($html) || $html === '') {
        return $html;
    }
    $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    if (!$host) {
        return $html;
    }
    $base = '/' . $prefix; // '/' ou '/test/'

    return str_replace(
        ['https://' . $host . '/', 'http://' . $host . '/', '//' . $host . '/'],
        $base,
        $html
    );
}

function wp_static_ajax_toggle()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_toggle_action', 'nonce');

    $enabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? 1 : 0;
    update_option(WP_STATIC_ENABLED_OPTION, $enabled);

    // Injecte / retire le service « pré-WordPress » dans index.php pour servir
    // les pages statiques avant même le chargement de WordPress.
    $warning = '';
    if ($enabled) {
        if (!wp_static_inject_index()) {
            $warning = 'Site activé, mais index.php n’a pas pu être modifié (droits en écriture ?). Le service statique reste assuré par WordPress (plus lent).';
        }
    } else {
        if (!wp_static_remove_index_injection()) {
            $warning = 'Site désactivé, mais le bloc dans index.php n’a pas pu être retiré (droits en écriture ?).';
        }
    }

    wp_send_json_success([
        'enabled' => $enabled,
        'warning' => $warning,
    ]);
}

/**
 * Active / désactive la minification du HTML des pages générées (AJAX).
 */
function wp_static_ajax_toggle_minify()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_toggle_action', 'nonce');

    $enabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? 1 : 0;
    update_option(WP_STATIC_MINIFY_OPTION, $enabled);

    wp_send_json_success(['enabled' => $enabled]);
}

/**
 * Active / désactive la purge des fichiers orphelins (AJAX).
 */
function wp_static_ajax_toggle_purge_orphans()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_toggle_action', 'nonce');

    $enabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? 1 : 0;
    update_option(WP_STATIC_PURGE_ORPHANS_OPTION, $enabled);

    wp_send_json_success(['enabled' => $enabled]);
}

/**
 * Active / désactive le mode automatique de régénération (AJAX).
 */
function wp_static_ajax_toggle_auto()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_toggle_action', 'nonce');

    $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
    if (!in_array($mode, ['manual', 'auto', 'full'], true)) {
        // Rétrocompatibilité : ancien paramètre booléen 'enabled'.
        $mode = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? 'auto' : 'manual';
    }

    update_option(WP_STATIC_MODE_OPTION, $mode);
    // On garde l'ancienne option synchronisée pour toute lecture héritée.
    update_option(WP_STATIC_AUTO_OPTION, $mode === 'manual' ? 0 : 1);

    wp_send_json_success(['mode' => $mode]);
}

/**
 * Enregistre les identifiants .htaccess de la préprod (AJAX).
 */
function wp_static_ajax_save_htaccess()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_toggle_action', 'nonce');

    $user = isset($_POST['user']) ? sanitize_text_field(wp_unslash($_POST['user'])) : '';
    $pass = isset($_POST['pass']) ? wp_unslash($_POST['pass']) : '';

    update_option(WP_STATIC_HTACCESS_USER_OPTION, $user, false);

    // Le mot de passe n'est jamais renvoyé au navigateur : un champ vide signifie
    // « conserver le mot de passe actuel » (et non l'effacer).
    if ($pass !== '') {
        update_option(WP_STATIC_HTACCESS_PASS_OPTION, $pass, false);
    }

    wp_send_json_success();
}

/**
 * Enregistre les paramètres avancés de génération (AJAX).
 */
function wp_static_ajax_save_advanced()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_toggle_action', 'nonce');

    $frequency = isset($_POST['cron_frequency']) ? sanitize_key(wp_unslash($_POST['cron_frequency'])) : 'daily';
    if (!in_array($frequency, ['off', 'twicedaily', 'daily', 'weekly'], true)) {
        $frequency = 'daily';
    }
    $patterns = isset($_POST['exclude_patterns']) ? wp_unslash($_POST['exclude_patterns']) : '';
    $patterns = wp_static_sanitize_patterns_text($patterns);

    update_option(WP_STATIC_CRON_FREQUENCY_OPTION, $frequency);
    update_option(WP_STATIC_EXCLUDE_PATTERNS_OPTION, $patterns);
    wp_static_reschedule_cron($frequency);

    wp_send_json_success([
        'cron_frequency' => $frequency,
    ]);
}

/**
 * Enregistre la liste des URLs à toujours régénérer (option Auto).
 */
function wp_static_ajax_save_always_regen()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_toggle_action', 'nonce');

    $raw = isset($_POST['always_regen']) ? wp_unslash($_POST['always_regen']) : '';
    $clean = wp_static_sanitize_urls_text($raw);
    update_option(WP_STATIC_ALWAYS_REGEN_OPTION, $clean, false);

    $classes_raw = isset($_POST['regen_classes']) ? wp_unslash($_POST['regen_classes']) : '';
    $classes_clean = wp_static_sanitize_classes_text($classes_raw);
    update_option(WP_STATIC_REGEN_CLASSES_OPTION, $classes_clean, false);

    // Sans classe marqueur, l'index des pages dynamiques n'a plus de sens : on le purge.
    if ($classes_clean === '') {
        delete_option(WP_STATIC_DYNAMIC_URLS_OPTION);
    }

    wp_send_json_success([
        'value'         => $clean,
        'count'         => count(wp_static_get_always_regen_urls()),
        'classes'       => $classes_clean,
        'classes_count' => count(wp_static_get_regen_classes()),
    ]);
}

/**
 * Nettoie un textarea d'URLs (une par ligne) en conservant uniquement
 * les URLs valides du site.
 */
function wp_static_sanitize_urls_text($text)
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $text);
    $clean = [];
    foreach ((array) $lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $line = esc_url_raw($line);
        if ($line !== '' && !isset($clean[$line])) {
            $clean[$line] = $line;
        }
    }

    return implode("\n", array_values($clean));
}

function wp_static_sanitize_patterns_text($text)
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $text);
    $clean = [];
    foreach ((array) $lines as $line) {
        $line = trim(sanitize_text_field($line));
        if ($line !== '') {
            $clean[] = $line;
        }
    }

    return implode("\n", $clean);
}

/**
 * Vide tout le cache statique (AJAX).
 */
function wp_static_ajax_clear_cache()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_toggle_action', 'nonce');

    $deleted = wp_static_clear_static_dir();
    wp_static_save_deps([]);
    update_option(WP_STATIC_DIRTY_OPTION, 1);

    wp_send_json_success(['deleted' => $deleted]);
}

/**
 * Régénération d'une seule page depuis le tableau d'administration (AJAX).
 */
function wp_static_ajax_regenerate_url()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_regenerate_action', 'nonce');

    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    if (!$url || !wp_static_is_local_url($url)) {
        wp_send_json_error(['message' => 'URL invalide.'], 400);
    }

    $result = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
    $ran = wp_static_with_gen_lock(function () use ($url, &$result) {
        wp_static_generate_urls([$url], $result);
    });

    if ($ran === false) {
        wp_static_add_pending_regen([$url]);
        wp_send_json_success([
            'status'  => 'pending',
            'label'   => wp_static_status_label('pending'),
            'date'    => wp_static_format_generation_date($url),
            'message' => 'Une génération est en cours ; cette page a été mise en file d’attente.',
        ]);
    }

    $status = 'failed';
    if ($result['generated']) {
        $status = 'generated';
    } elseif ($result['skipped']) {
        $status = 'skipped';
    }

    wp_send_json_success([
        'status'  => $status,
        'label'   => wp_static_status_label($status),
        'date'    => wp_static_format_generation_date($url),
        'message' => !empty($result['messages']) ? end($result['messages']) : '',
    ]);
}

/**
 * Marque/retire une page comme « non statique » depuis le tableau (AJAX).
 * Une page exclue n'est ni générée ni servie en statique (WordPress la sert).
 */
function wp_static_ajax_toggle_exclude()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissions insuffisantes.'], 403);
    }

    check_ajax_referer('wp_static_regenerate_action', 'nonce');

    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    if (!$url || !wp_static_is_local_url($url)) {
        wp_send_json_error(['message' => 'URL invalide.'], 400);
    }

    $excluded = (isset($_POST['excluded']) && $_POST['excluded'] === '1');
    wp_static_set_url_excluded($url, $excluded);

    $status = 'pending';
    $date = '';

    if ($excluded) {
        // On retire le fichier statique existant : la page repasse en dynamique.
        wp_static_forget_url($url);
        $status = 'excluded';
    } else {
        // Réintégrée : on la régénère immédiatement.
        $result = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
        $ran = wp_static_with_gen_lock(function () use ($url, &$result) {
            wp_static_generate_urls([$url], $result);
        });
        if ($ran === false) {
            wp_static_add_pending_regen([$url]);
            $status = 'pending';
        } elseif ($result['generated']) {
            $status = 'generated';
            $date = wp_static_format_generation_date($url);
        } elseif ($result['skipped']) {
            $status = 'skipped';
        } elseif ($result['failed']) {
            $status = 'failed';
        }
    }

    wp_send_json_success([
        'excluded' => $excluded,
        'status'   => $status,
        'label'    => wp_static_status_label($status),
        'date'     => $date,
    ]);
}

/**
 * Vérifie qu'une URL appartient bien au site (même hôte que l'accueil),
 * pour éviter de déclencher une requête vers un hôte arbitraire.
 */
function wp_static_is_local_url($url)
{
    $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $url_host = wp_parse_url($url, PHP_URL_HOST);
    return $home_host && $url_host && strtolower($home_host) === strtolower($url_host);
}

/**
 * Date de dernière génération d'une URL (mtime du fichier statique), formatée
 * selon les réglages WordPress, ou chaîne vide si la page n'est pas générée.
 */
function wp_static_format_generation_date($url)
{
    $file = wp_static_path_for_url($url);
    if (!file_exists($file)) {
        return '';
    }
    $format = get_option('date_format') . ' ' . get_option('time_format');
    return wp_date($format, filemtime($file));
}

/**
 * Dépendances « remontées » d'une page : on exclut le contenu propre de la page
 * (une page qui s'affiche elle-même n'est pas une remontée à signaler).
 */
function wp_static_page_dependencies($url, $meta, $deps)
{
    $ids = isset($deps[$url]) ? array_map('intval', (array) $deps[$url]) : [];
    $own = isset($meta['id']) ? (int) $meta['id'] : 0;
    if ($own) {
        $ids = array_diff($ids, [$own]);
    }
    return array_values($ids);
}

/**
 * Rend la liste des contenus dont une page dépend (remontées détectées lors de
 * la génération), sous forme de titres cliquables vers leur écran d'édition.
 * Limité à 10 entrées, avec un compteur « +N autres » au-delà.
 */
function wp_static_format_deps($ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    if (empty($ids)) {
        return '—';
    }

    $max = 10;
    $shown = array_slice($ids, 0, $max);
    $links = [];
    foreach ($shown as $id) {
        $title = get_the_title($id);
        if ($title === '') {
            $title = '#' . $id;
        }
        $edit = get_edit_post_link($id, 'raw');
        if ($edit) {
            $links[] = '<a href="' . esc_url($edit) . '">' . esc_html($title) . '</a>';
        } else {
            $links[] = esc_html($title);
        }
    }

    $html = implode(', ', $links);
    $extra = count($ids) - count($shown);
    if ($extra > 0) {
        $html .= ' <span class="description">+' . (int) $extra . ' autre' . ($extra > 1 ? 's' : '') . '</span>';
    }

    return $html;
}

function wp_static_status_label($status)
{
    switch ($status) {
        case 'excluded':
            return 'Non statique';
        case 'generated':
            return 'Générée';
        case 'skipped':
            return 'Ignorée';
        case 'failed':
            return 'Erreur';
        case 'pending':
            return 'En file';
        default:
            return 'Non générée';
    }
}

/**
 * Indique si le type de contenu a été retiré du menu d'administration
 * (typiquement « Articles » masqué via remove_menu_page('edit.php')).
 *
 * On se base sur le menu admin global ($menu), seul disponible quand le tableau
 * est rendu. Hors contexte admin (cron, etc.), on ne masque rien.
 */
function wp_static_is_post_type_menu_hidden($post_type)
{
    if (!$post_type) {
        return false;
    }

    // En contexte d'écran d'admin, le menu est construit : on l'inspecte directement
    // (source de vérité la plus fraîche).
    global $menu;
    if (!empty($menu) && is_array($menu)) {
        $slug = ($post_type === 'post') ? 'edit.php' : 'edit.php?post_type=' . $post_type;
        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === $slug) {
                return false; // présent dans le menu => visible
            }
        }
        return true; // absent du menu => caché
    }

    // Hors écran d'admin (admin-post.php, cron…), le global $menu n'existe pas :
    // on s'appuie sur la liste mise en cache lors du dernier passage en admin.
    $hidden = get_option(WP_STATIC_HIDDEN_TYPES_OPTION, []);

    return is_array($hidden) && in_array($post_type, $hidden, true);
}

/**
 * Enregistre, lors de l'affichage de l'admin, la liste des types de contenu publics
 * dont le menu a été masqué (remove_menu_page). Cette liste sert de repli lors de
 * la génération (admin-post.php) et du cron, où le global $menu n'est pas construit.
 */
function wp_static_cache_hidden_post_types()
{
    global $menu;
    if (empty($menu) || !is_array($menu)) {
        return;
    }

    $present = [];
    foreach ($menu as $item) {
        if (isset($item[2])) {
            $present[] = $item[2];
        }
    }

    $hidden = [];
    foreach (get_post_types(['public' => true], 'names') as $post_type) {
        if ($post_type === 'attachment') {
            continue;
        }
        $slug = ($post_type === 'post') ? 'edit.php' : 'edit.php?post_type=' . $post_type;
        if (!in_array($slug, $present, true)) {
            $hidden[] = $post_type;
        }
    }

    sort($hidden);
    if (get_option(WP_STATIC_HIDDEN_TYPES_OPTION, []) !== $hidden) {
        update_option(WP_STATIC_HIDDEN_TYPES_OPTION, $hidden);
    }
}

/**
 * Lignes du tableau des pages : URL, statut, date de génération, nb de dépendances.
 */
function wp_static_get_page_rows()
{
    $rows = [];
    $deps = wp_static_get_deps();

    foreach (wp_static_collect_url_items() as $url => $meta) {
        // On masque les contenus dont le type a été retiré du menu d'administration
        // (ex. « Articles » caché via remove_menu_page) : pas de tableau pour eux.
        if (isset($meta['post_type']) && wp_static_is_post_type_menu_hidden($meta['post_type'])) {
            continue;
        }

        $file = wp_static_path_for_url($url);
        $exists = file_exists($file);
        $excluded = wp_static_is_url_excluded($url);

        if ($excluded) {
            $status = 'excluded';
        } else {
            $status = $exists ? 'generated' : 'pending';
        }

        $rows[] = [
            'url'      => $url,
            'title'    => isset($meta['title']) ? $meta['title'] : wp_static_short_path($url),
            'path'     => wp_static_short_path($url),
            'language' => isset($meta['language']) ? $meta['language'] : '',
            'type'     => isset($meta['type']) ? $meta['type'] : 'Autre',
            'template' => isset($meta['template']) ? $meta['template'] : '—',
            'excluded' => $excluded,
            'status'   => $status,
            'date'     => (!$excluded && $exists) ? wp_static_format_generation_date($url) : '',
            'deps'     => wp_static_page_dependencies($url, $meta, $deps),
        ];
    }

    return $rows;
}

/**
 * Régénération automatique (stratégie 1 + remontées via stratégie C).
 *
 * À la sauvegarde d'un contenu, on régénère sa propre page, ses pages de
 * remontée connues (carte des dépendances) et ses listings naturels (accueil,
 * archive de type de contenu, archives de ses taxonomies) — ces derniers pour
 * couvrir le cas d'un nouveau contenu pas encore présent dans la carte.
 */
function wp_static_on_save_post($post_id, $post, $update)
{
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    if (!wp_static_has_generated()) {
        return;
    }
    $post_type = get_post_type_object($post->post_type);
    if (!$post_type || empty($post_type->public)) {
        return;
    }
    if (!wp_static_auto_active()) {
        return;
    }

    $urls = wp_static_urls_for_post($post_id);
    $urls = array_merge($urls, wp_static_listing_urls_for_post($post_id));

    $permalink = get_permalink($post_id);
    if ($permalink) {
        $urls[] = $permalink;
    }

    // URLs configurées comme « toujours à régénérer » (option Auto) : URLs fixes
    // et motifs déployés, plus les pages détectées comme ayant une classe marqueur.
    $urls = array_merge($urls, wp_static_get_always_regen_urls());
    if (wp_static_get_regen_classes()) {
        $urls = array_merge($urls, wp_static_get_dynamic_urls());
    }

    wp_static_enqueue_urls($urls);
    wp_static_flag_flash();
}

/**
 * Pose un indicateur « génération en cours » pour l'utilisateur courant.
 * Utilisé pour l'éditeur classique (rechargement de page) : l'éditeur de blocs
 * est géré côté JS via l'événement de sauvegarde. On évite donc le flag en
 * contexte REST/AJAX pour ne pas le faire clignoter à tort plus tard.
 */
function wp_static_flag_flash()
{
    if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    set_transient('wp_static_flash_' . get_current_user_id(), 1, 30);
}

/**
 * À la mise à la corbeille / suppression d'un contenu : on supprime son fichier
 * statique et on régénère ses pages de remontée pour qu'il en disparaisse.
 */
function wp_static_on_delete_post($post_id)
{
    if (!wp_static_has_generated()) {
        return;
    }
    $post = get_post($post_id);
    if (!$post) {
        return;
    }
    $post_type = get_post_type_object($post->post_type);
    if (!$post_type || empty($post_type->public)) {
        return;
    }
    if (!wp_static_auto_active()) {
        return;
    }

    $urls = wp_static_urls_for_post($post_id);
    $urls = array_merge($urls, wp_static_listing_urls_for_post($post_id));

    $permalink = wp_static_public_permalink($post);
    if ($permalink) {
        wp_static_forget_url($permalink);
        $urls = array_diff($urls, [$permalink]);
    }

    wp_static_enqueue_urls($urls);
}

/**
 * Calcule le permalien « public » d'un contenu, même s'il n'est pas (ou plus)
 * publié : on raisonne comme si le statut était « publish » et on retire le
 * suffixe « __trashed » que WordPress ajoute au slug lors de la corbeille.
 * Indispensable pour retrouver le bon fichier statique à supprimer.
 */
function wp_static_public_permalink($post)
{
    $post = get_post($post);
    if (!$post) {
        return '';
    }
    $clone = clone $post;
    $clone->post_status = 'publish';
    $clone->post_name = preg_replace('/__trashed$/', '', $clone->post_name);
    if ($clone->post_name === '') {
        return '';
    }

    return get_permalink($clone);
}

/**
 * Oublie une URL : supprime son fichier statique et son entrée de dépendances.
 */
function wp_static_forget_url($url)
{
    if (!$url) {
        return;
    }
    wp_static_delete_static_file($url);
    $deps = wp_static_get_deps();
    if (isset($deps[$url])) {
        unset($deps[$url]);
        wp_static_save_deps($deps);
    }
}

/**
 * (1+2) Publication (y compris programmée) et dépublication d'un contenu.
 */
function wp_static_on_transition_post_status($new_status, $old_status, $post)
{
    if (!wp_static_has_generated()) {
        return;
    }
    if (wp_is_post_autosave($post->ID) || wp_is_post_revision($post->ID)) {
        return;
    }
    $post_type = get_post_type_object($post->post_type);
    if (!$post_type || empty($post_type->public)) {
        return;
    }
    if (!wp_static_auto_active()) {
        return;
    }

    // Passage en ligne (brouillon/programmé -> publié) : générer la page et ses listings.
    if ($new_status === 'publish') {
        $urls = wp_static_listing_urls_for_post($post->ID);
        $permalink = get_permalink($post->ID);
        if ($permalink) {
            $urls[] = $permalink;
        }
        wp_static_enqueue_urls($urls);
        return;
    }

    // Dépublication (publié -> autre) : retirer le fichier et rafraîchir les listings.
    if ($old_status === 'publish') {
        wp_static_forget_url(wp_static_public_permalink($post));
        wp_static_enqueue_urls(wp_static_listing_urls_for_post($post->ID));
    }
}

/**
 * (3) Changement de slug ou de parent : supprime l'ancienne URL devenue orpheline.
 */
function wp_static_on_post_updated($post_id, $post_after, $post_before)
{
    if (!wp_static_has_generated()) {
        return;
    }
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    $post_type = get_post_type_object($post_after->post_type);
    if (!$post_type || empty($post_type->public)) {
        return;
    }
    if (
        $post_before->post_name === $post_after->post_name
        && $post_before->post_parent === $post_after->post_parent
    ) {
        return;
    }
    if (!wp_static_auto_active()) {
        return;
    }

    $old_url = wp_static_public_permalink($post_before);
    $new_url = wp_static_public_permalink($post_after);
    if ($old_url && $old_url !== $new_url) {
        wp_static_forget_url($old_url);
    }
}

/**
 * (4) Ajout/édition/changement de statut/suppression d'un commentaire :
 * régénère la page de l'article concerné.
 */
function wp_static_on_comment_change($comment_id)
{
    if (!wp_static_has_generated()) {
        return;
    }
    $comment = get_comment($comment_id);
    if (!$comment) {
        return;
    }
    $post = get_post((int) $comment->comment_post_ID);
    if (!$post) {
        return;
    }
    $post_type = get_post_type_object($post->post_type);
    if (!$post_type || empty($post_type->public)) {
        return;
    }
    if (!wp_static_auto_active()) {
        return;
    }

    $permalink = wp_static_public_permalink($post);
    if ($permalink) {
        wp_static_enqueue_urls([$permalink]);
    }
}

/**
 * (5) Création/édition/suppression d'un terme d'une taxonomie publique.
 */
function wp_static_on_term_change($term_id, $tt_id = 0, $taxonomy = '')
{
    if ($taxonomy) {
        $tax = get_taxonomy($taxonomy);
        if ($tax && empty($tax->public)) {
            return;
        }
    }
    wp_static_on_structural_change();
}

/**
 * (6) Sauvegarde d'une page d'options ACF (post_id « options » / « option… »).
 */
function wp_static_on_acf_save_post($post_id)
{
    if (is_string($post_id) && (strpos($post_id, 'option') === 0)) {
        wp_static_on_structural_change();
    }
}

/**
 * (9) Régénération complète planifiée (filet de sécurité configurable).
 */
function wp_static_cron_schedules($schedules)
{
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => 'Une fois par semaine',
        ];
    }

    return $schedules;
}

function wp_static_maybe_schedule_cron()
{
    wp_static_reschedule_cron(wp_static_cron_frequency(), false);
}

function wp_static_reschedule_cron($frequency = null, $force = true)
{
    $frequency = $frequency ?: wp_static_cron_frequency();
    $timestamp = wp_next_scheduled('wp_static_daily_regeneration');
    $current = $timestamp ? wp_get_schedule('wp_static_daily_regeneration') : false;

    if ($frequency === 'off') {
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wp_static_daily_regeneration');
        }
        return;
    }

    if ($timestamp && $current === $frequency && !$force) {
        return;
    }

    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wp_static_daily_regeneration');
    }

    wp_schedule_event(time() + HOUR_IN_SECONDS, $frequency, 'wp_static_daily_regeneration');
}

function wp_static_cron_regenerate()
{
    if (!wp_static_is_auto_enabled()) {
        wp_static_mark_dirty();
        return;
    }
    if (wp_static_has_generated()) {
        wp_static_run_generation();
    }
}

/**
 * Listings « naturels » d'un contenu : accueil, archive de son type de contenu
 * et archives des termes de ses taxonomies.
 */
function wp_static_listing_urls_for_post($post_id)
{
    $urls = [home_url('/')];

    $post = get_post($post_id);
    if (!$post) {
        return $urls;
    }

    $current_language = null;
    $post_language = wp_static_wpml_language_for_post($post_id);
    if ($post_language) {
        $current_language = apply_filters('wpml_current_language', null);
        do_action('wpml_switch_language', $post_language);
        $urls = [home_url('/')];
    }

    if ($post->post_type === 'post') {
        $posts_base = (get_option('show_on_front') === 'page' && (int) get_option('page_for_posts'))
            ? get_permalink((int) get_option('page_for_posts'))
            : home_url('/');
        $urls[] = $posts_base;
        $urls = array_merge($urls, wp_static_paginated_urls($posts_base, wp_static_max_pages_for_query([
            'post_type'   => 'post',
            'post_status' => 'publish',
        ])));
    }

    $archive = get_post_type_archive_link($post->post_type);
    if ($archive) {
        $urls[] = $archive;
        $urls = array_merge($urls, wp_static_paginated_urls($archive, wp_static_max_pages_for_query([
            'post_type'   => $post->post_type,
            'post_status' => 'publish',
        ])));
    }

    foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                $link = get_term_link($term);
                if (!is_wp_error($link)) {
                    $urls[] = $link;
                    $urls = array_merge($urls, wp_static_paginated_urls($link, wp_static_max_pages_for_query([
                        'post_type'   => 'any',
                        'post_status' => 'publish',
                        'tax_query'   => [
                            [
                                'taxonomy' => $term->taxonomy,
                                'field'    => 'term_id',
                                'terms'    => [$term->term_id],
                            ],
                        ],
                    ])));
                }
            }
        }
    }

    // Contenus hiérarchiques (pages, CPT hiérarchiques) : la page parente et ses
    // ancêtres affichent souvent une liste de leurs enfants -> on les régénère.
    if (is_post_type_hierarchical($post->post_type)) {
        foreach (get_post_ancestors($post_id) as $ancestor_id) {
            $ancestor_link = get_permalink($ancestor_id);
            if ($ancestor_link) {
                $urls[] = $ancestor_link;
            }
        }
    }

    if ($post_language && $current_language) {
        do_action('wpml_switch_language', $current_language);
    }

    $urls = array_values(array_unique(array_filter($urls)));

    /**
     * Permet au thème de déclarer des URLs de listing supplémentaires à régénérer
     * lorsqu'un contenu est ajouté / modifié / supprimé (plan du site, navigation
     * de section, push de pages enfants, grille « nos services », etc.).
     *
     * Utile notamment pour les NOUVELLES pages : la carte de dépendances ne peut
     * pas encore connaître un contenu jamais rendu, ce filtre comble ce manque.
     *
     * @param string[] $urls    URLs déjà collectées (accueil, archives, ancêtres…).
     * @param int      $post_id ID du contenu concerné.
     */
    return apply_filters('wp_static_listing_urls_for_post', $urls, $post_id);
}

/**
 * Verrou exclusif de génération (flock) : une seule régénération à la fois.
 */
function wp_static_acquire_gen_lock()
{
    $dir = dirname(WP_STATIC_GEN_LOCK_FILE);
    if (!file_exists($dir) && !wp_mkdir_p($dir)) {
        return false;
    }

    $fp = @fopen(WP_STATIC_GEN_LOCK_FILE, 'c');
    if (!$fp) {
        return false;
    }

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }

    ftruncate($fp, 0);
    fwrite($fp, (string) getmypid() . ' ' . time());
    fflush($fp);

    $GLOBALS['wp_static_gen_lock_fp'] = $fp;

    return true;
}

function wp_static_release_gen_lock()
{
    if (!empty($GLOBALS['wp_static_gen_lock_fp'])) {
        flock($GLOBALS['wp_static_gen_lock_fp'], LOCK_UN);
        fclose($GLOBALS['wp_static_gen_lock_fp']);
        unset($GLOBALS['wp_static_gen_lock_fp']);
    }
    if (file_exists(WP_STATIC_GEN_LOCK_FILE)) {
        @unlink(WP_STATIC_GEN_LOCK_FILE);
    }
}

function wp_static_holds_gen_lock()
{
    return !empty($GLOBALS['wp_static_gen_lock_fp']);
}

function wp_static_is_gen_locked()
{
    if (wp_static_holds_gen_lock()) {
        return true;
    }

    if (!file_exists(WP_STATIC_GEN_LOCK_FILE)) {
        return false;
    }

    $fp = @fopen(WP_STATIC_GEN_LOCK_FILE, 'c');
    if (!$fp) {
        return false;
    }

    if (flock($fp, LOCK_EX | LOCK_NB)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    fclose($fp);

    return true;
}

function wp_static_get_pending_regen()
{
    $pending = get_option(WP_STATIC_GEN_PENDING_OPTION, null);
    if (!is_array($pending)) {
        return ['full' => false, 'urls' => []];
    }

    $pending['full'] = !empty($pending['full']);
    $pending['urls'] = isset($pending['urls']) && is_array($pending['urls']) ? $pending['urls'] : [];

    return $pending;
}

function wp_static_save_pending_regen(array $pending)
{
    $pending['full'] = !empty($pending['full']);
    $pending['urls'] = isset($pending['urls']) && is_array($pending['urls']) ? $pending['urls'] : [];

    if (!$pending['full'] && empty($pending['urls'])) {
        delete_option(WP_STATIC_GEN_PENDING_OPTION);
        return;
    }

    update_option(WP_STATIC_GEN_PENDING_OPTION, $pending, false);
}

function wp_static_add_pending_regen($urls)
{
    $pending = wp_static_get_pending_regen();

    if (wp_static_get_mode() === 'full') {
        $pending['full'] = true;
        $pending['urls'] = [];
    } else {
        foreach ((array) $urls as $url) {
            if ($url) {
                $pending['urls'][$url] = $url;
            }
        }
    }

    wp_static_save_pending_regen($pending);
}

/**
 * @return array{full:bool,urls:string[]}|null
 */
function wp_static_take_pending_regen_batch()
{
    $pending = wp_static_get_pending_regen();
    if (!$pending['full'] && empty($pending['urls'])) {
        return null;
    }

    delete_option(WP_STATIC_GEN_PENDING_OPTION);

    if ($pending['full']) {
        return [
            'full' => true,
            'urls' => wp_static_collect_urls(),
        ];
    }

    return [
        'full' => false,
        'urls' => array_values($pending['urls']),
    ];
}

/**
 * @param array{full:bool,urls:string[]} $batch
 */
function wp_static_restore_pending_regen_batch(array $batch)
{
    $pending = wp_static_get_pending_regen();

    if (!empty($batch['full'])) {
        $pending['full'] = true;
    }

    foreach ((array) $batch['urls'] as $url) {
        if ($url) {
            $pending['urls'][$url] = $url;
        }
    }

    wp_static_save_pending_regen($pending);
}

/**
 * @param callable():mixed $callback
 * @return mixed|false
 */
function wp_static_with_gen_lock($callback)
{
    if (!wp_static_acquire_gen_lock()) {
        return false;
    }

    try {
        @set_time_limit(0);

        return call_user_func($callback);
    } finally {
        wp_static_release_gen_lock();
        wp_static_maybe_process_pending_regen();
    }
}

function wp_static_maybe_process_pending_regen()
{
    static $depth = 0;
    if ($depth >= 5) {
        return;
    }

    $batch = wp_static_take_pending_regen_batch();
    if ($batch === null) {
        return;
    }

    if (!wp_static_acquire_gen_lock()) {
        wp_static_restore_pending_regen_batch($batch);
        return;
    }

    $depth++;
    try {
        @set_time_limit(0);

        $result = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];

        if (!empty($batch['full'])) {
            wp_static_save_deps([]);
        }

        wp_static_generate_urls($batch['urls'], $result);

        if (!empty($batch['full']) && wp_static_should_purge_orphans()) {
            wp_static_purge_orphan_static_files($batch['urls']);
        }

        if (!empty($batch['full']) && empty($result['failed'])) {
            delete_option(WP_STATIC_DIRTY_OPTION);
        }

        if (!empty($result['failed'])) {
            wp_static_mark_dirty();
        }
    } finally {
        wp_static_release_gen_lock();
        $depth--;
        wp_static_maybe_process_pending_regen();
    }
}

/**
 * File d'attente de régénération, traitée en fin de requête (shutdown) pour ne
 * pas ralentir l'enregistrement du contenu dans l'éditeur.
 */
function wp_static_enqueue_urls($urls)
{
    if (wp_static_is_gen_locked()) {
        wp_static_add_pending_regen($urls);
        return;
    }

    // Mode complet : toute modification régénère l'intégralité du site. On
    // remplace les URLs ciblées par la liste complète (collectée une seule fois
    // par requête, la file se chargeant du dédoublonnage).
    if (wp_static_get_mode() === 'full') {
        static $full_collected = false;
        if ($full_collected) {
            return;
        }
        $full_collected = true;
        $urls = wp_static_collect_urls();
    }

    if (!isset($GLOBALS['wp_static_regen_queue'])) {
        $GLOBALS['wp_static_regen_queue'] = [];
        add_action('shutdown', 'wp_static_process_regen_queue');
    }
    foreach ((array) $urls as $url) {
        if ($url) {
            $GLOBALS['wp_static_regen_queue'][$url] = $url;
        }
    }
}

function wp_static_process_regen_queue()
{
    if (empty($GLOBALS['wp_static_regen_queue'])) {
        return;
    }

    $urls = array_values($GLOBALS['wp_static_regen_queue']);
    $GLOBALS['wp_static_regen_queue'] = [];

    $ran = wp_static_with_gen_lock(function () use ($urls) {
        $result = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
        wp_static_generate_urls($urls, $result);

        if (!empty($result['failed'])) {
            wp_static_mark_dirty();
        }

        return $result;
    });

    if ($ran === false) {
        wp_static_add_pending_regen($urls);
    }
}

/**
 * Notice d'administration invitant à régénérer après un changement structurel.
 */
/**
 * La page des permaliens (options-permalink.php) ré-enregistre les réglages à
 * chaque clic sur « Enregistrer », même sans modification : on marque alors le
 * site statique « à régénérer » dès qu'une soumission valide est détectée.
 */
function wp_static_on_permalinks_saved()
{
    if (empty($_POST) || !isset($_POST['_wpnonce'])) {
        return;
    }
    if (!wp_verify_nonce($_POST['_wpnonce'], 'update-permalink')) {
        return;
    }
    wp_static_on_structural_change();
}

/**
 * Élément « WP Statique » dans la barre d'administration (header), visible dès
 * que le plugin est actif. Indique l'état du service statique et, en rouge,
 * lorsqu'une régénération est nécessaire.
 */
function wp_static_admin_bar_menu($wp_admin_bar)
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $enabled = wp_static_is_enabled();
    $dirty   = wp_static_is_dirty();
    $url     = admin_url('admin.php?page=wp-static-generator');

    if ($dirty) {
        $color = '#d63638'; // rouge : à régénérer
        $state = 'à régénérer';
    } elseif ($enabled) {
        $color = '#46b450'; // vert : service actif
        $state = 'actif';
    } else {
        $color = '#a7aaad'; // gris : service désactivé
        $state = 'inactif';
    }

    $wp_admin_bar->add_node([
        'id'    => 'wp-static',
        'title' => 'WP Statique <span style="color:' . esc_attr($color) . ';font-weight:600;">&bull; ' . esc_html($state) . '</span>',
        'href'  => $url,
    ]);

    $wp_admin_bar->add_node([
        'parent' => 'wp-static',
        'id'     => 'wp-static-state',
        'title'  => $enabled ? 'Service statique : activé' : 'Service statique : désactivé',
        'href'   => $url,
    ]);

    if ($dirty) {
        $wp_admin_bar->add_node([
            'parent' => 'wp-static',
            'id'     => 'wp-static-regen',
            'title'  => 'Régénérer le site',
            'href'   => $url,
        ]);
    }
}

/**
 * Fait clignoter l'onglet « WP Statique » de la barre d'admin en orange
 * (« génération… ») pendant 2 s lorsqu'un contenu vient d'être enregistré.
 * - Éditeur classique : déclenché au rechargement via un flag transient.
 * - Éditeur de blocs : déclenché en JS à la fin de la sauvegarde (pas de reload).
 */
function wp_static_admin_bar_flash_script()
{
    if (!current_user_can('manage_options') || !is_admin_bar_showing()) {
        return;
    }

    $flash_now = false;
    $key = 'wp_static_flash_' . get_current_user_id();
    if (get_transient($key)) {
        delete_transient($key);
        $flash_now = true;
    }
?>
    <script>
        (function() {
            var node = document.getElementById('wp-admin-bar-wp-static');
            if (!node) {
                return;
            }
            var link = node.querySelector('a.ab-item');
            if (!link) {
                return;
            }

            var original = link.innerHTML;
            var timer = null;

            function flash() {
                if (timer) {
                    clearTimeout(timer);
                }
                link.innerHTML = 'WP Statique <span style="color:#dba617;font-weight:600;">&bull; génération…</span>';
                timer = setTimeout(function() {
                    link.innerHTML = original;
                    timer = null;
                }, 2000);
            }

            <?php if ($flash_now) : ?>
                flash();
            <?php endif; ?>

            // Éditeur de blocs (Gutenberg) : pas de rechargement, on écoute la fin de sauvegarde.
            if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
                var wasSaving = false;
                wp.data.subscribe(function() {
                    var editor = wp.data.select('core/editor');
                    if (!editor || typeof editor.isSavingPost !== 'function') {
                        return;
                    }
                    var saving = editor.isSavingPost() && !editor.isAutosavingPost();
                    if (wasSaving && !saving) {
                        flash();
                    }
                    wasSaving = saving;
                });
            }
        })();
    </script>
<?php
}

function wp_static_dirty_admin_notice()
{
    if (!current_user_can('manage_options') || !wp_static_is_dirty()) {
        return;
    }
    $url = admin_url('admin.php?page=wp-static-generator');
    echo '<div class="notice notice-error"><p style="color:#d63638;"><strong>WP Statique :</strong> '
        . 'des changements de structure (menu, permaliens ou réglages) ont été détectés. '
        . 'Le site statique est peut-être obsolète. '
        . '<a href="' . esc_url($url) . '"><strong>Régénérer le site</strong></a>.</p></div>';
}

/**
 * 2. Logique de génération des pages statiques.
 */

/**
 * Construit la liste des URLs publiques à générer, avec leurs métadonnées
 * (type de contenu, modèle de page) : accueil, contenus (articles, pages, CPT),
 * archives de type de contenu et archives de taxonomies.
 *
 * Retourne un tableau associatif : url => ['type' => …, 'template' => …].
 */
function wp_static_collect_url_items()
{
    $languages = wp_static_wpml_languages();
    if (empty($languages)) {
        return apply_filters('wp_static_url_items', wp_static_collect_url_items_for_current_language());
    }

    $items = [];
    $current_language = apply_filters('wpml_current_language', null);

    foreach ($languages as $language) {
        if (empty($language['code'])) {
            continue;
        }

        do_action('wpml_switch_language', $language['code']);

        foreach (wp_static_collect_url_items_for_current_language($language) as $url => $item) {
            $item['language'] = !empty($language['translated_name']) ? $language['translated_name'] : $language['code'];
            $item['language_code'] = $language['code'];
            $items[$url] = $item;
        }
    }

    if ($current_language) {
        do_action('wpml_switch_language', $current_language);
    }

    return apply_filters('wp_static_url_items', $items);
}

function wp_static_collect_url_items_for_current_language($language = null)
{
    $items = [];

    // Accueil.
    $home_url = (!empty($language['url'])) ? $language['url'] : home_url('/');
    $items[$home_url] = [
        'title'    => 'Accueil',
        'type'     => 'Page',
        'template' => wp_static_front_template(),
        'id'       => (get_option('show_on_front') === 'page') ? (int) get_option('page_on_front') : 0,
    ];

    // Page des articles WordPress (accueil si le site liste les articles en home,
    // ou page configurée dans Réglages > Lecture).
    $posts_base = (get_option('show_on_front') === 'page' && (int) get_option('page_for_posts'))
        ? get_permalink((int) get_option('page_for_posts'))
        : $home_url;
    if ($posts_base) {
        wp_static_add_paginated_items($items, $posts_base, wp_static_max_pages_for_query([
            'post_type'   => 'post',
            'post_status' => 'publish',
        ]), [
            'title'     => 'Articles',
            'type'      => 'Archive : Articles',
            'template'  => '—',
            'post_type' => 'post',
        ]);
    }

    // Contenus (articles, pages, CPT publics).
    $post_types = get_post_types(['public' => true], 'names');
    unset($post_types['attachment']);

    if (!empty($post_types)) {
        $query = new WP_Query([
            'post_type'      => array_values($post_types),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        // Amorce les caches (post + meta) en une fois : évite des requêtes N+1
        // lors des get_permalink / get_the_title / get_post_type qui suivent.
        if (!empty($query->posts)) {
            _prime_post_caches($query->posts, true, true);
        }
        foreach ($query->posts as $post_id) {
            $link = get_permalink($post_id);
            if (!$link || isset($items[$link])) {
                continue;
            }
            $title = get_the_title($post_id);
            $items[$link] = [
                'title'     => ($title !== '') ? $title : '(sans titre)',
                'type'      => wp_static_post_type_label($post_id),
                'template'  => wp_static_template_name($post_id),
                'id'        => (int) $post_id,
                'post_type' => get_post_type($post_id),
            ];
        }
    }

    // Archives de type de contenu (ex. /portfolio/).
    foreach (get_post_types(['public' => true, 'has_archive' => true], 'objects') as $post_type) {
        $link = get_post_type_archive_link($post_type->name);
        $label = isset($post_type->labels->name) ? $post_type->labels->name : $post_type->name;
        if ($link && !isset($items[$link])) {
            $items[$link] = [
                'title'     => $label,
                'type'      => 'Archive : ' . $label,
                'template'  => '—',
                'post_type' => $post_type->name,
            ];
        }
        if ($link) {
            wp_static_add_paginated_items($items, $link, wp_static_max_pages_for_query([
                'post_type'   => $post_type->name,
                'post_status' => 'publish',
            ]), [
                'title'     => $label,
                'type'      => 'Archive : ' . $label,
                'template'  => '—',
                'post_type' => $post_type->name,
            ]);
        }
    }

    // Archives de taxonomies (catégories, étiquettes, taxonomies perso).
    $taxonomies = get_taxonomies(['public' => true], 'objects');
    if (!empty($taxonomies)) {
        $terms = get_terms([
            'taxonomy'   => array_keys($taxonomies),
            'hide_empty' => true,
        ]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $link = get_term_link($term);
                if (is_wp_error($link) || isset($items[$link])) {
                    continue;
                }
                $tax = isset($taxonomies[$term->taxonomy]->labels->singular_name)
                    ? $taxonomies[$term->taxonomy]->labels->singular_name
                    : $term->taxonomy;
                $items[$link] = [
                    'title'    => $term->name,
                    'type'     => 'Taxonomie : ' . $tax,
                    'template' => '—',
                ];
                wp_static_add_paginated_items($items, $link, wp_static_max_pages_for_query([
                    'post_type'   => 'any',
                    'post_status' => 'publish',
                    'tax_query'   => [
                        [
                            'taxonomy' => $term->taxonomy,
                            'field'    => 'term_id',
                            'terms'    => [$term->term_id],
                        ],
                    ],
                ]), [
                    'title'    => $term->name,
                    'type'     => 'Taxonomie : ' . $tax,
                    'template' => '—',
                ]);
            }
        }
    }

    return $items;
}

function wp_static_wpml_languages()
{
    if (!wp_static_is_wpml_active()) {
        return [];
    }

    $languages = apply_filters('wpml_active_languages', null, [
        'skip_missing' => 0,
        'orderby'      => 'code',
    ]);

    return is_array($languages) ? $languages : [];
}

function wp_static_wpml_language_for_post($post_id)
{
    if (!wp_static_is_wpml_active()) {
        return null;
    }

    $details = apply_filters('wpml_post_language_details', null, $post_id);
    if (is_array($details) && !empty($details['language_code'])) {
        return $details['language_code'];
    }

    return null;
}

function wp_static_is_wpml_active()
{
    return defined('ICL_SITEPRESS_VERSION') || has_filter('wpml_active_languages');
}

/**
 * Ajoute les pages paginées d'une archive au collecteur (page/2, page/3…).
 */
function wp_static_add_paginated_items(&$items, $base_url, $max_pages, $meta)
{
    foreach (wp_static_paginated_urls($base_url, $max_pages) as $page => $url) {
        if (!$url || isset($items[$url])) {
            continue;
        }

        $items[$url] = [
            'title'    => $meta['title'] . ' - page ' . $page,
            'type'     => $meta['type'],
            'template' => $meta['template'],
        ];
    }
}

function wp_static_paginated_urls($base_url, $max_pages)
{
    $urls = [];
    $max_pages = (int) $max_pages;

    if ($max_pages < 2) {
        return $urls;
    }

    for ($page = 2; $page <= $max_pages; $page++) {
        $urls[$page] = wp_static_paginated_url($base_url, $page);
    }

    return $urls;
}

/**
 * Calcule le nombre de pages d'une requête d'archive avec les réglages WP.
 */
function wp_static_max_pages_for_query($args)
{
    $args = array_merge([
        'posts_per_page'         => (int) get_option('posts_per_page'),
        'paged'                  => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => false,
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ], $args);

    $query = new WP_Query($args);

    return (int) $query->max_num_pages;
}

/**
 * Construit une URL paginée compatible permaliens (ex. /actualites/page/2/).
 */
function wp_static_paginated_url($base_url, $page)
{
    if ((int) $page < 2) {
        return $base_url;
    }

    if (get_option('permalink_structure')) {
        return trailingslashit($base_url) . user_trailingslashit('page/' . (int) $page, 'paged');
    }

    return add_query_arg('paged', (int) $page, $base_url);
}

/**
 * Liste des URLs publiques à générer (clés de la carte des métadonnées).
 */
function wp_static_collect_urls()
{
    $urls = [];

    foreach (wp_static_collect_url_items() as $url => $meta) {
        // On ne génère pas les contenus dont le type a été retiré du menu
        // d'administration (ex. « Articles » caché via remove_menu_page) :
        // ils ne sont pas utilisés, comme dans le tableau.
        if (isset($meta['post_type']) && wp_static_is_post_type_menu_hidden($meta['post_type'])) {
            continue;
        }
        $urls[] = $url;
    }

    return apply_filters('wp_static_urls', $urls);
}

/**
 * Libellé du type de contenu : « Article », « Page » ou « <Nom> (CPT) ».
 */
function wp_static_post_type_label($post_id)
{
    $post_type = get_post_type($post_id);
    if ($post_type === 'post') {
        return 'Article';
    }
    if ($post_type === 'page') {
        return 'Page';
    }
    $obj = get_post_type_object($post_type);
    $singular = ($obj && isset($obj->labels->singular_name)) ? $obj->labels->singular_name : $post_type;

    return $singular . ' (CPT)';
}

/**
 * Nom lisible du modèle de page associé à un contenu (« Par défaut » sinon).
 */
function wp_static_template_name($post_id)
{
    $slug = get_page_template_slug($post_id);
    if (empty($slug)) {
        return 'Par défaut';
    }

    // Les modèles d'un type de contenu sont mis en cache pour la durée de la
    // requête : get_page_templates() scanne les fichiers du thème et serait
    // sinon rappelé pour chaque contenu lors de la collecte (centaines d'appels).
    static $templates_by_type = [];
    $post_type = get_post_type($post_id);
    if (!isset($templates_by_type[$post_type])) {
        $templates_by_type[$post_type] = wp_get_theme()->get_page_templates(null, $post_type);
    }
    $templates = $templates_by_type[$post_type];

    return isset($templates[$slug]) ? $templates[$slug] : $slug;
}

/**
 * Chemin simplifié d'une URL pour l'affichage (ex. « /contact/ », « / »).
 */
function wp_static_short_path($url)
{
    $path = wp_parse_url($url, PHP_URL_PATH);
    if (!$path) {
        return '/';
    }
    return rawurldecode($path);
}

/**
 * Modèle utilisé par la page d'accueil (page statique le cas échéant).
 */
function wp_static_front_template()
{
    if (get_option('show_on_front') === 'page') {
        $front_id = (int) get_option('page_on_front');
        if ($front_id) {
            return wp_static_template_name($front_id);
        }
    }

    return '—';
}

/**
 * Génération complète : régénère tout le site et reconstruit la carte des
 * dépendances depuis zéro.
 */
function wp_static_do_full_generation()
{
    $result = [
        'generated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'messages' => [],
    ];

    wp_static_save_deps([]);

    $urls = wp_static_collect_urls();
    wp_static_generate_urls($urls, $result);

    if (wp_static_should_purge_orphans()) {
        $purged = wp_static_purge_orphan_static_files($urls);
        if ($purged > 0) {
            $result['messages'][] = $purged . ' fichier(s) statique(s) orphelin(s) supprimé(s).';
        }
    }

    if (empty($result['failed'])) {
        delete_option(WP_STATIC_DIRTY_OPTION);
    }

    return $result;
}

function wp_static_run_generation()
{
    if (!file_exists(WP_STATIC_DIR) && !wp_mkdir_p(WP_STATIC_DIR)) {
        return [
            'generated' => 0,
            'skipped' => 0,
            'failed' => 1,
            'messages' => ['Impossible de créer le dossier statique : ' . WP_STATIC_DIR],
        ];
    }

    $result = wp_static_with_gen_lock('wp_static_do_full_generation');

    if ($result === false) {
        wp_static_add_pending_regen([]);

        return [
            'generated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'messages' => ['Une génération est déjà en cours ; celle-ci a été mise en file d’attente.'],
        ];
    }

    return $result;
}

/**
 * Génère un ensemble d'URLs : récupère le HTML, écrit le fichier statique et
 * met à jour la carte des dépendances. Utilisé par la génération complète et
 * par la régénération automatique partielle.
 */
/**
 * Minification HTML « sûre » : réduit les espaces entre balises, les espaces
 * multiples et supprime les commentaires HTML, tout en préservant le contenu
 * des balises <pre>, <textarea>, <script> et <style> (où les espaces comptent).
 * Les commentaires conditionnels IE (<!--[if …]-->) sont conservés.
 */
function wp_static_minify_html($html)
{
    if (!is_string($html) || $html === '') {
        return $html;
    }

    // Met de côté les blocs dont le contenu ne doit pas être modifié.
    $placeholders = [];
    $html = preg_replace_callback(
        '#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is',
        function ($m) use (&$placeholders) {
            $key = "\x01WPSTATIC" . count($placeholders) . "\x01";
            $placeholders[$key] = $m[0];
            return $key;
        },
        $html
    );

    // Supprime les commentaires HTML (hors conditionnels IE).
    $html = preg_replace('/<!--(?!\s*\[if)(?!\s*<!).*?-->/s', '', $html);

    // Réduit les blancs entre balises puis les blancs multiples.
    $html = preg_replace('/>\s+</', '><', $html);
    $html = preg_replace('/\s{2,}/', ' ', $html);
    $html = preg_replace('/[\t\r\n]+/', ' ', $html);

    // Restaure les blocs protégés.
    if (!empty($placeholders)) {
        $html = strtr($html, $placeholders);
    }

    return trim($html);
}

function wp_static_generate_urls($urls, &$result)
{
    $token = wp_static_start_generation_token();
    $deps = wp_static_get_deps();

    // Index des pages contenant une classe « marqueur » de remontée dynamique.
    // Maintenu incrémentalement (génération complète comme régénération partielle).
    $marker_classes = wp_static_get_regen_classes();
    $dynamic = [];
    $dynamic_changed = false;
    if (!empty($marker_classes)) {
        foreach (wp_static_get_dynamic_urls() as $u) {
            $dynamic[$u] = true;
        }
    }

    foreach (array_unique($urls) as $url) {
        // Page marquée « non statique » : on s'assure qu'aucun fichier ne subsiste.
        if (wp_static_is_url_excluded($url)) {
            wp_static_forget_url($url);
            if (isset($dynamic[$url])) {
                unset($dynamic[$url]);
                $dynamic_changed = true;
            }
            $result['skipped']++;
            $result['messages'][] = 'Page exclue (non statique) : ' . $url;
            continue;
        }

        $dep_key = md5($url);
        $headers = [
            'X-WP-Static-Token'    => $token,
            'X-WP-Static-Deps-Key' => $dep_key,
        ];

        $fetch = wp_static_fetch_url($url, $headers);
        $response = $fetch['response'];

        if (is_wp_error($response)) {
            $result['failed']++;
            $result['messages'][] = 'Erreur lors de la génération de ' . $url . ' : ' . $response->get_error_message();
            continue;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 404) {
            $result['skipped']++;
            $result['messages'][] = 'Page ignorée (404) : ' . $url;
            unset($deps[$url]);
            if (isset($dynamic[$url])) {
                unset($dynamic[$url]);
                $dynamic_changed = true;
            }
            wp_static_delete_static_file($url);
            continue;
        }

        // Une redirection (301/302/307/308) n'a pas de contenu statique propre :
        // on ignore l'URL et on retire un éventuel fichier obsolète.
        if ($status_code >= 300 && $status_code < 400) {
            $result['skipped']++;
            $result['messages'][] = 'Redirection ignorée (' . $status_code . ') : ' . $url;
            unset($deps[$url]);
            if (isset($dynamic[$url])) {
                unset($dynamic[$url]);
                $dynamic_changed = true;
            }
            wp_static_delete_static_file($url);
            continue;
        }

        if ($status_code < 200 || $status_code >= 300) {
            $result['failed']++;
            $result['messages'][] = 'Erreur lors de la génération de ' . $url . ' : réponse HTTP ' . $status_code;
            continue;
        }

        $html = wp_remote_retrieve_body($response);

        // Extrait puis retire le marqueur de dépendances inséré pendant le rendu.
        $page_ids = [];
        if (preg_match('/<!--\s*wp-static-deps:([0-9,]*)\s*-->/', $html, $m)) {
            $page_ids = array_filter(array_map('intval', explode(',', $m[1])));
            $html = preg_replace('/<!--\s*wp-static-deps:[0-9,]*\s*-->/', '', $html);
        }

        // Détection des classes marqueurs (remontée dynamique) sur le HTML rendu,
        // avant minification (la minification ne touche pas aux attributs class).
        if (!empty($marker_classes)) {
            $has_marker = wp_static_html_has_marker_class($html, $marker_classes);
            if ($has_marker && !isset($dynamic[$url])) {
                $dynamic[$url] = true;
                $dynamic_changed = true;
            } elseif (!$has_marker && isset($dynamic[$url])) {
                unset($dynamic[$url]);
                $dynamic_changed = true;
            }
        }

        // Point d'accroche pour post-traiter le HTML généré (ex. réécriture des
        // URLs d'assets vers un CDN). Appliqué avant la minification.
        $html = apply_filters('wp_static_html', $html, $url);

        if (wp_static_is_minify_enabled()) {
            $html = wp_static_minify_html($html);
        }

        $static_file = wp_static_path_for_url($url);
        $file_dir = dirname($static_file);

        if (!file_exists($file_dir) && !wp_mkdir_p($file_dir)) {
            $result['failed']++;
            $result['messages'][] = 'Impossible de créer le dossier pour ' . $url;
            continue;
        }

        $written = file_put_contents($static_file, $html);
        if ($written === false) {
            $result['failed']++;
            $result['messages'][] = 'Impossible d’écrire le fichier statique pour ' . $url;
            continue;
        }

        $deps[$url] = array_values(array_unique($page_ids));

        $result['generated']++;
        $message = 'Page générée : ' . $url;
        if ($fetch['fallback_url']) {
            $message .= ' (récupérée via ' . $fetch['fallback_url'] . ')';
        }
        $result['messages'][] = $message;
    }

    if ($dynamic_changed) {
        update_option(WP_STATIC_DYNAMIC_URLS_OPTION, array_values(array_keys($dynamic)), false);
    }

    wp_static_save_deps($deps);
    wp_static_end_generation_token();

    return $result;
}

/**
 * Calcule le chemin absolu du fichier statique (index.html) pour une URL.
 */
function wp_static_path_for_url($url)
{
    $path = parse_url($url, PHP_URL_PATH);
    $path = $path ? trim(rawurldecode($path), '/') : '';

    if (wp_static_should_namespace_by_host($url)) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $host = $host ? sanitize_file_name(strtolower($host)) : 'default';
        $file_dir = WP_STATIC_DIR . '/_hosts/' . $host;
        $file_dir = $path === '' ? $file_dir : $file_dir . '/' . $path;
    } else {
        $file_dir = $path === '' ? WP_STATIC_DIR : WP_STATIC_DIR . '/' . $path;
    }

    return trailingslashit($file_dir) . 'index.html';
}

function wp_static_should_namespace_by_host($url)
{
    if (!wp_static_uses_multiple_hosts()) {
        return false;
    }

    return (bool) wp_parse_url($url, PHP_URL_HOST);
}

function wp_static_uses_multiple_hosts()
{
    static $uses_multiple_hosts = null;
    if ($uses_multiple_hosts !== null) {
        return $uses_multiple_hosts;
    }

    $hosts = [];
    foreach (wp_static_wpml_languages() as $language) {
        if (empty($language['url'])) {
            continue;
        }
        $host = wp_parse_url($language['url'], PHP_URL_HOST);
        if ($host) {
            $hosts[strtolower($host)] = true;
        }
    }

    $uses_multiple_hosts = count($hosts) > 1;

    return $uses_multiple_hosts;
}

/**
 * Supprime le fichier statique correspondant à une URL (et le dossier s'il est vide).
 */
function wp_static_delete_static_file($url)
{
    $static_file = wp_static_path_for_url($url);
    if (file_exists($static_file)) {
        unlink($static_file);
        $dir = dirname($static_file);
        if (is_dir($dir) && $dir !== WP_STATIC_DIR && count(scandir($dir)) === 2) {
            rmdir($dir);
        }
    }
}

function wp_static_clear_static_dir()
{
    return wp_static_delete_dir_contents(WP_STATIC_DIR, true);
}

function wp_static_delete_dir_contents($dir, $remove_root = false)
{
    if (!is_dir($dir)) {
        return 0;
    }

    $deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            if (@unlink($file->getPathname())) {
                $deleted++;
            }
        }
    }

    if ($remove_root) {
        @rmdir($dir);
    }

    return $deleted;
}

function wp_static_purge_orphan_static_files($expected_urls)
{
    if (!is_dir(WP_STATIC_DIR)) {
        return 0;
    }

    $expected = [];
    foreach ((array) $expected_urls as $url) {
        if (wp_static_is_url_excluded($url)) {
            continue;
        }
        $expected[wp_normalize_path(wp_static_path_for_url($url))] = true;
    }

    $deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(WP_STATIC_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        $path = wp_normalize_path($file->getPathname());
        if ($file->isFile()) {
            if (strtolower($file->getFilename()) !== 'index.html') {
                continue;
            }
            if (!isset($expected[$path]) && @unlink($file->getPathname())) {
                $deleted++;
            }
            continue;
        }

        if ($file->isDir()) {
            @rmdir($file->getPathname());
        }
    }

    return $deleted;
}

function wp_static_fetch_url($url, $headers = [])
{
    // Préprod protégée par .htaccess : on ajoute l'authentification Basic.
    $auth = wp_static_htaccess_auth_header();
    if ($auth) {
        $headers['Authorization'] = $auth;
    }

    $args = [
        'timeout' => 30,
        'redirection' => 5,
    ];
    if (!empty($headers)) {
        $args['headers'] = $headers;
    }

    $response = wp_remote_get($url, $args);
    if (!is_wp_error($response)) {
        return [
            'response' => $response,
            'fallback_url' => null,
        ];
    }

    // La requête loopback a échoué. C'est fréquent en local (Docker) : le
    // domaine public n'est résolu que par le navigateur de la machine hôte,
    // pas depuis le conteneur PHP. On réessaie alors via le service web
    // interne, en conservant l'en-tête Host public pour servir la bonne page.
    $internal_host = wp_static_get_internal_host();
    if (!$internal_host) {
        return [
            'response' => $response,
            'fallback_url' => null,
        ];
    }

    $parts = wp_parse_url($url);
    if (empty($parts['host'])) {
        return [
            'response' => $response,
            'fallback_url' => null,
        ];
    }

    $public_host = $parts['host'];
    $path = isset($parts['path']) ? $parts['path'] : '/';
    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }

    $internal_args = $args;
    $internal_args['sslverify'] = false;
    // On ne suit pas les redirections sur l'hôte interne : une éventuelle
    // redirection pointe vers le domaine public (injoignable depuis le
    // conteneur) et sera de toute façon ignorée par la génération.
    $internal_args['redirection'] = 0;
    $internal_args['headers'] = array_merge(
        isset($args['headers']) ? $args['headers'] : [],
        ['Host' => $public_host]
    );

    foreach (['https', 'http'] as $scheme) {
        $internal_url = $scheme . '://' . $internal_host . $path;
        $internal_response = wp_remote_get($internal_url, $internal_args);

        if (!is_wp_error($internal_response)) {
            return [
                'response' => $internal_response,
                'fallback_url' => $internal_url,
            ];
        }

        $response = $internal_response;
    }

    return [
        'response' => $response,
        'fallback_url' => null,
    ];
}

/**
 * Détermine l'hôte interne à utiliser pour les requêtes de génération.
 *
 * En environnement local Docker, le domaine public n'est pas résolvable
 * depuis le conteneur PHP : on cible alors le service web interne (nginx).
 * Surchargez la constante WP_STATIC_INTERNAL_HOST ou le filtre
 * 'wp_static_internal_host' si votre service porte un autre nom.
 */
function wp_static_get_internal_host()
{
    $host = '';

    if (wp_static_is_preprod()) {
        // En préprod, on appelle directement l'URL publique du site (qui est
        // résolvable depuis le serveur) : pas de service interne type nginx.
        $host = '';
    } elseif (defined('WP_STATIC_INTERNAL_HOST') && WP_STATIC_INTERNAL_HOST) {
        $host = WP_STATIC_INTERNAL_HOST;
    } elseif (defined('ENV_LOCAL') && ENV_LOCAL) {
        $host = 'nginx';
    }

    return apply_filters('wp_static_internal_host', $host);
}

/**
 * Collecte des dépendances (stratégie C).
 *
 * Pendant une requête de génération (identifiée par un jeton secret), on
 * enregistre tous les contenus affichés via l'action `the_post` — y compris
 * ceux des « remontées » (accueil, archives, blocs d'articles liés…) — puis on
 * insère la liste de leurs IDs dans un commentaire HTML que le générateur lit.
 */
function wp_static_start_generation_token()
{
    $token = wp_generate_password(32, false, false);
    set_transient(WP_STATIC_GEN_TOKEN_TRANSIENT, $token, 10 * MINUTE_IN_SECONDS);
    return $token;
}

function wp_static_end_generation_token()
{
    delete_transient(WP_STATIC_GEN_TOKEN_TRANSIENT);
}

function wp_static_is_generation_request()
{
    if (empty($_SERVER['HTTP_X_WP_STATIC_TOKEN'])) {
        return false;
    }
    $token = get_transient(WP_STATIC_GEN_TOKEN_TRANSIENT);
    return $token && hash_equals($token, (string) $_SERVER['HTTP_X_WP_STATIC_TOKEN']);
}

function wp_static_maybe_collect_dependencies()
{
    if (!wp_static_is_generation_request()) {
        return;
    }
    $GLOBALS['wp_static_collected_ids'] = [];
    add_action('the_post', 'wp_static_collect_post_id');

    // Pendant la génération, on ne suit pas les redirections WordPress
    // (canonique, ancien slug). Une URL canonique qui redirige n'a pas de
    // contenu propre : elle doit rendre son vrai statut (souvent 404) plutôt
    // que de renvoyer un 301 qu'on transformerait en page statique inutile —
    // d'autant qu'en local la cible publique du 301 est injoignable.
    add_filter('redirect_canonical', '__return_false');
    add_filter('old_slug_redirect_url', '__return_false');

    ob_start('wp_static_append_deps_marker');
}

function wp_static_collect_post_id($post)
{
    $id = (is_object($post) && isset($post->ID)) ? (int) $post->ID : (int) get_the_ID();
    if ($id) {
        wp_static_register_dependency($id);
    }
}

/**
 * Enregistre manuellement une (ou plusieurs) dépendance(s) de contenu pour la
 * page en cours de génération.
 *
 * À utiliser dans les composants « push / cards / remontées » personnalisés qui
 * n'utilisent pas une boucle WordPress standard (donc non détectés via the_post) :
 * sélection d'articles ACF, requête SQL custom, get_posts() sans setup_postdata()…
 *
 * Hors d'une génération WP Static, l'appel ne fait rien : il est donc sans risque
 * de l'appeler en permanence dans le thème.
 *
 * Exemple :
 *   foreach ($ids_selectionnes as $id) {
 *       wp_static_register_dependency($id);
 *       // … rendu de la carte …
 *   }
 *
 * @param int|int[]|WP_Post|WP_Post[] $post_ids Un ID, un WP_Post, ou un tableau de ceux-ci.
 */
function wp_static_register_dependency($post_ids)
{
    // Le tableau global n'existe que pendant une requête de génération.
    if (!isset($GLOBALS['wp_static_collected_ids'])) {
        return;
    }

    foreach ((array) $post_ids as $post) {
        if (is_object($post) && isset($post->ID)) {
            $id = (int) $post->ID;
        } else {
            $id = (int) $post;
        }
        if ($id) {
            $GLOBALS['wp_static_collected_ids'][] = $id;
        }
    }
}

function wp_static_append_deps_marker($buffer)
{
    $ids = isset($GLOBALS['wp_static_collected_ids']) ? array_unique($GLOBALS['wp_static_collected_ids']) : [];
    return $buffer . "\n<!-- wp-static-deps:" . implode(',', $ids) . " -->";
}

/**
 * 3. Logique de service des pages statiques.
 *
 * Le hook n'est branché que si le site statique est activé : lorsqu'il est
 * désactivé, aucun code n'intervient dans le flux de chargement de WordPress.
 */
if (wp_static_is_enabled()) {
    add_action('init', 'wp_static_serve_static_page', 1);
}

function wp_static_serve_static_page()
{
    // Pendant une génération, on laisse WordPress rendre la page « fraîche »
    // (sinon on capturerait l'ancien fichier statique et aucune dépendance).
    if (wp_static_is_generation_request()) {
        return;
    }

    // Ne pas servir de statique pour l'admin, les recherches, les requêtes POST ou les pages non GET
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    if (is_admin() || is_search() || $request_method !== 'GET') {
        return;
    }

    // Une URL avec chaîne de requête (recherche, filtres, pagination ?paged=…)
    // ne correspond pas forcément au fichier statique de son chemin : on laisse
    // WordPress la traiter (cohérent avec le bloc « pré-WordPress »).
    if (!empty($_SERVER['QUERY_STRING'])) {
        return;
    }

    if (empty($_SERVER['REQUEST_URI'])) {
        return;
    }
    $request_uri = $_SERVER['REQUEST_URI'];
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : wp_parse_url(home_url('/'), PHP_URL_HOST);
    $scheme = is_ssl() ? 'https' : 'http';
    $current_url = $scheme . '://' . $host . strtok($request_uri, '?');

    // Page marquée « non statique » : laisser WordPress la servir dynamiquement.
    if (wp_static_is_url_excluded($current_url)) {
        return;
    }

    $static_file = wp_static_path_for_url($current_url);

    // Fallback pour les fichiers générés avant l'ajout du namespace par host.
    if (!file_exists($static_file) && wp_static_uses_multiple_hosts()) {
        $path = strtok($request_uri, '?');
        $legacy_file = WP_STATIC_DIR . $path;
        $legacy_file = substr($legacy_file, -1) === '/'
            ? $legacy_file . 'index.html'
            : trailingslashit($legacy_file) . 'index.html';
        if (file_exists($legacy_file)) {
            $static_file = $legacy_file;
        }
    }

    if (file_exists($static_file)) {
        // Sécurité : on confirme que le fichier résolu est bien sous le dossier
        // statique (protection contre une éventuelle traversée de chemin via
        // une URI forgée « ../ » qui ne serait pas normalisée par le serveur).
        $real_file = realpath($static_file);
        $real_base = realpath(WP_STATIC_DIR);
        if ($real_file === false || $real_base === false || strpos($real_file, $real_base . DIRECTORY_SEPARATOR) !== 0) {
            return;
        }

        // Les fichiers générés sont toujours du HTML : on évite l'ouverture de
        // la base fileinfo à chaque requête.
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Static-Cache: HIT'); // Indique que la page est servie statiquement

        // En-têtes de cache : permet aux navigateurs / proxies de revalider et
        // de répondre 304 sans retélécharger la page.
        $mtime = filemtime($real_file);
        $etag = '"' . md5($mtime . '-' . filesize($real_file)) . '"';
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=0, must-revalidate');

        $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
        $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
            ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])
            : false;

        if (($if_none_match && $if_none_match === $etag)
            || ($if_modified_since !== false && $if_modified_since >= $mtime)
        ) {
            status_header(304);
            exit;
        }

        readfile($real_file);
        exit; // Arrête l'exécution de WordPress
    }
}

/**
 * Chemin du contrôleur frontal (index.php) dans lequel injecter le service
 * « pré-WordPress ». On le déduit du dossier parent de wp-content pour rester
 * compatible avec une installation dans un sous-dossier.
 */
function wp_static_front_controller_path()
{
    return dirname(WP_CONTENT_DIR) . '/index.php';
}

/**
 * Chemin relatif du dossier statique depuis le contrôleur frontal
 * (ex. « wp-content/static-pages »), utilisé dans le bloc injecté via __DIR__.
 */
function wp_static_static_dir_relative()
{
    $index_dir = dirname(wp_static_front_controller_path());
    $rel = ltrim(str_replace($index_dir, '', WP_STATIC_DIR), '/\\');

    return $rel !== '' ? $rel : 'wp-content/static-pages';
}

/**
 * Chemin relatif du service autonome (pre-wp-cache.php) depuis le contrôleur
 * frontal (ex. « wp-content/plugins/wp-static/pre-wp-cache.php »).
 */
function wp_static_pre_wp_file_relative()
{
    $index_dir = dirname(wp_static_front_controller_path());
    $file = dirname(__FILE__) . '/pre-wp-cache.php';
    $rel = ltrim(str_replace($index_dir, '', $file), '/\\');

    return $rel !== '' ? $rel : 'wp-content/plugins/wp-static/pre-wp-cache.php';
}

/**
 * Bloc PHP injecté en tête d'index.php : un simple « stub » qui inclut le
 * service autonome du plugin (pre-wp-cache.php) et l'exécute AVANT WordPress.
 *
 * Toute la logique vit dans le plugin : on peut donc la faire évoluer sans
 * jamais réécrire index.php. Le stub est minimal et stable.
 */
function wp_static_index_snippet()
{
    $start    = WP_STATIC_INDEX_MARKER_START;
    $end      = WP_STATIC_INDEX_MARKER_END;
    $service  = var_export(wp_static_pre_wp_file_relative(), true);
    $base_rel = var_export(wp_static_static_dir_relative(), true);

    return <<<PHP
{$start}
// WP Static : sert la page statique (si elle existe) AVANT de charger WordPress.
// Ajouté/retiré automatiquement par l'extension — ne pas modifier à la main.
// Logique : wp-content/plugins/wp-static/pre-wp-cache.php
\$wpsc_service = __DIR__ . '/' . {$service};
if (is_file(\$wpsc_service)) {
    require_once \$wpsc_service;
    if (function_exists('wp_static_serve_pre_wp')) {
        wp_static_serve_pre_wp(__DIR__ . '/' . {$base_rel});
    }
}
{$end}
PHP;
}

/**
 * Retire le bloc WP Static d'un contenu index.php (entre les marqueurs).
 */
function wp_static_strip_index_snippet($content)
{
    $start = preg_quote(WP_STATIC_INDEX_MARKER_START, '#');
    $end   = preg_quote(WP_STATIC_INDEX_MARKER_END, '#');

    // Retire le bloc ainsi que les sauts de ligne ajoutés autour de lui à
    // l'injection, pour un retour à l'identique du fichier d'origine.
    $content = preg_replace('#\R?' . $start . '.*?' . $end . '\R?#s', '', $content);

    // Nettoie aussi un éventuel ancien bloc expérimental « /* wp-static */ … */ »
    // (ajouté manuellement avant la version par marqueurs), avec son indentation.
    $content = preg_replace('#\R?[ \t]*/\* wp-static \*/.*?/\* end-wp-static \*/\R?#s', '', $content);

    return $content;
}

/**
 * Insère (ou met à jour) le bloc de service « pré-WordPress » dans index.php.
 * Retourne true en cas de succès, false sinon (fichier introuvable, non
 * inscriptible, ou format inattendu).
 */
function wp_static_inject_index()
{
    $file = wp_static_front_controller_path();
    if (!is_file($file) || !is_writable($file)) {
        return false;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        return false;
    }

    // Nettoie un éventuel bloc précédent pour réinjecter à jour.
    $content = wp_static_strip_index_snippet($content);

    // Le bloc doit s'exécuter juste après la balise d'ouverture PHP, sans
    // produire de sortie avant le chargement de WordPress.
    $pos = strpos($content, '<?php');
    if ($pos === false) {
        return false;
    }

    $insert_at = $pos + strlen('<?php');
    $snippet = "\n" . wp_static_index_snippet() . "\n";
    $new_content = substr($content, 0, $insert_at) . $snippet . substr($content, $insert_at);

    return (bool) file_put_contents($file, $new_content);
}

/**
 * Retire le bloc de service « pré-WordPress » d'index.php.
 */
function wp_static_remove_index_injection()
{
    $file = wp_static_front_controller_path();
    if (!is_file($file) || !is_writable($file)) {
        return false;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        return false;
    }

    if (strpos($content, WP_STATIC_INDEX_MARKER_START) === false) {
        return true; // rien à retirer
    }

    $new_content = wp_static_strip_index_snippet($content);

    return (bool) file_put_contents($file, $new_content);
}

/**
 * Indique si le bloc est actuellement présent dans index.php.
 */
function wp_static_index_is_injected()
{
    $file = wp_static_front_controller_path();
    if (!is_file($file)) {
        return false;
    }
    $content = file_get_contents($file);

    return is_string($content) && strpos($content, WP_STATIC_INDEX_MARKER_START) !== false;
}

/**
 * Fonction pour nettoyer le dossier statique lors de la désactivation du plugin.
 */
register_deactivation_hook(__FILE__, 'wp_static_cleanup_on_deactivation');

function wp_static_cleanup_on_deactivation()
{
    // Retire le bloc de service « pré-WordPress » d'index.php.
    wp_static_remove_index_injection();

    // Retire la tâche planifiée de régénération.
    $timestamp = wp_next_scheduled('wp_static_daily_regeneration');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wp_static_daily_regeneration');
    }

    if (file_exists(WP_STATIC_DIR)) {
        $it = new RecursiveDirectoryIterator(WP_STATIC_DIR, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir(WP_STATIC_DIR);
    }
}
