<?php
/**
 * Service « pré-WordPress » de WP Static.
 *
 * Ce fichier est volontairement AUTONOME : il ne dépend d'aucune fonction de
 * WordPress car il est inclus tout en haut de index.php, AVANT le chargement de
 * WordPress, pour servir une page statique sans bootstrap WP (performance max).
 *
 * Il est inclus par le petit bloc injecté dans index.php (entre les marqueurs
 * « wp-static-cache »). Toute la logique vit ici, dans le plugin, pour rester
 * maintenable et mise à jour sans réécrire index.php.
 */

if (!function_exists('wp_static_serve_pre_wp')) {
    /**
     * Sert le fichier statique correspondant à l'URL courante puis arrête le
     * script (exit). Ne fait rien — et laisse donc WordPress se charger — si
     * aucune page statique ne correspond ou si la requête ne doit pas être
     * mise en cache.
     *
     * @param string $base_dir Dossier racine des pages statiques (chemin absolu).
     */
    function wp_static_serve_pre_wp($base_dir) {
        // Uniquement les requêtes GET, sans chaîne de requête.
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        // Requête de génération (jeton) : laisser WordPress rendre la page fraîche.
        if (!empty($_SERVER['HTTP_X_WP_STATIC_TOKEN'])) {
            return;
        }
        if (!empty($_SERVER['QUERY_STRING'])) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        // Jamais pour l'admin, la connexion, l'API REST, le cron ou XML-RPC.
        if (strpos($path, '/wp-admin') === 0
            || strpos($path, '/wp-login') === 0
            || strpos($path, '/wp-json') === 0
            || strpos($path, '/wp-cron') === 0
            || strpos($path, '/xmlrpc') === 0) {
            return;
        }

        // Jamais pour un utilisateur connecté (ou ayant commenté / saisi un mot
        // de passe de page protégée) : il doit voir le rendu dynamique.
        foreach (array_keys($_COOKIE) as $cookie_name) {
            if (strpos($cookie_name, 'wordpress_logged_in') === 0
                || strpos($cookie_name, 'comment_author') === 0
                || strpos($cookie_name, 'wp-postpass') === 0) {
                return;
            }
        }

        $real_base = realpath($base_dir);
        if ($real_base === false) {
            return;
        }

        $rel = trim(rawurldecode($path), '/');

        // Candidats : d'abord le dossier spécifique à l'hôte (WPML multi-domaines),
        // puis le dossier racine.
        $candidates = array();
        $host = isset($_SERVER['HTTP_HOST'])
            ? preg_replace('/[^a-z0-9.\-]/i', '', strtolower($_SERVER['HTTP_HOST']))
            : '';
        if ($host !== '') {
            $host_base = $base_dir . '/_hosts/' . $host;
            $candidates[] = ($rel === '') ? $host_base . '/index.html' : $host_base . '/' . $rel . '/index.html';
        }
        $candidates[] = ($rel === '') ? $base_dir . '/index.html' : $base_dir . '/' . $rel . '/index.html';

        foreach ($candidates as $file) {
            $real = realpath($file);
            // Protection contre la traversée de chemin : le fichier résolu doit
            // rester sous le dossier statique.
            if ($real === false || strpos($real, $real_base . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }

            $mtime = filemtime($real);
            $etag = '"' . md5($mtime . '-' . filesize($real)) . '"';

            header('Content-Type: text/html; charset=UTF-8');
            header('X-Static-Cache: HIT-PRE-WP');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=0, must-revalidate');

            $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
            $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;

            if (($if_none_match !== '' && $if_none_match === $etag)
                || ($if_modified_since !== false && $if_modified_since >= $mtime)) {
                http_response_code(304);
                exit;
            }

            readfile($real);
            exit;
        }
    }
}
