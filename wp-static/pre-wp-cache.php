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

if (!function_exists('wp_static_pre_wp_valid_generation_token')) {
    function wp_static_pre_wp_valid_generation_token($base_dir, $request_token) {
        if (!is_string($request_token) || $request_token === '') {
            return false;
        }

        $content_dir = dirname(rtrim($base_dir, '/\\'));
        $real_content_dir = realpath($content_dir);
        $token_root = $real_content_dir !== false ? $real_content_dir : $content_dir;
        $token_file = rtrim(sys_get_temp_dir(), '/\\')
            . '/wp-static-' . sha1(str_replace('\\', '/', $token_root)) . '.token';
        $stored_token = is_file($token_file) ? trim((string) @file_get_contents($token_file)) : '';

        return $stored_token !== '' && hash_equals($stored_token, $request_token);
    }
}

if (!function_exists('wp_static_pre_wp_has_private_cookie')) {
    /**
     * Les cookies de commentaire et de page protégée restent toujours privés.
     * Le cookie de connexion peut être ignoré explicitement pour les petits
     * sites servis intégralement en statique.
     */
    function wp_static_pre_wp_has_private_cookie($cookies, $serve_logged_in = false) {
        $cookies = is_array($cookies) ? $cookies : array();
        foreach (array_keys($cookies) as $cookie_name) {
            if ((!$serve_logged_in && strpos($cookie_name, 'wordpress_logged_in') === 0)
                || strpos($cookie_name, 'comment_author') === 0
                || strpos($cookie_name, 'wp-postpass') === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('wp_static_serve_pre_wp')) {
    /**
     * Sert le fichier statique correspondant à l'URL courante puis arrête le
     * script (exit). Ne fait rien — et laisse donc WordPress se charger — si
     * aucune page statique ne correspond ou si la requête ne doit pas être
     * mise en cache.
     *
     * @param string $base_dir Dossier racine des pages statiques (chemin absolu).
     * @param bool   $serve_logged_in Autorise le statique malgré le cookie de connexion.
     */
    function wp_static_serve_pre_wp($base_dir, $serve_logged_in = false) {
        // Uniquement les requêtes GET, sans chaîne de requête.
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        // Requête de génération : le jeton doit aussi correspondre au fichier
        // temporaire écrit par le plugin. Un simple en-tête forgé ne suffit pas.
        if (!empty($_SERVER['HTTP_X_WP_STATIC_TOKEN'])) {
            if (wp_static_pre_wp_valid_generation_token(
                $base_dir,
                (string) $_SERVER['HTTP_X_WP_STATIC_TOKEN']
            )) {
                return;
            }
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

        // Les pages protégées et les sessions de commentaire restent
        // dynamiques. Le mode Complet peut autoriser le cookie de connexion.
        if (wp_static_pre_wp_has_private_cookie($_COOKIE, $serve_logged_in)) {
            return;
        }

        $real_base = realpath($base_dir);
        if ($real_base === false) {
            return;
        }

        $rel = trim(rawurldecode($path), '/');

        // En multi-domaines, ne jamais retomber sur la racine lorsqu'un arbre
        // `_hosts` existe : cela pourrait servir la langue principale sur un
        // autre domaine.
        $candidates = array();
        $host = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $parsed_host = parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST);
            if (is_string($parsed_host)) {
                $host = preg_replace('/[^a-z0-9.\-]/i', '', strtolower($parsed_host));
            }
        }
        $hosts_base = $base_dir . '/_hosts';
        if ($host !== '' && is_dir($hosts_base)) {
            $host_base = $base_dir . '/_hosts/' . $host;
            $candidates[] = ($rel === '') ? $host_base . '/index.html' : $host_base . '/' . $rel . '/index.html';
        } else {
            $candidates[] = ($rel === '') ? $base_dir . '/index.html' : $base_dir . '/' . $rel . '/index.html';
        }

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

            $not_modified = $if_none_match !== ''
                ? $if_none_match === $etag
                : ($if_modified_since !== false && $if_modified_since >= $mtime);

            if ($not_modified) {
                http_response_code(304);
                exit;
            }

            readfile($real);
            exit;
        }
    }
}
