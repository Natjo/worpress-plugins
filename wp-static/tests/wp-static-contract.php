<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'starterkit-lonsdale-2027.code';
$_SERVER['HTTPS'] = 'on';

require '/var/www/html/wp-load.php';

$failures = [];
$defaultHosts = wp_static_get_internal_hosts();

if (
    wp_static_path_for_url(home_url('/../../outside/')) !== ''
    || wp_static_path_for_url(home_url('/%2e%2e/outside/')) !== ''
    || strpos(
        wp_normalize_path(wp_static_path_for_url(home_url('/contract-safe/'))),
        wp_normalize_path(WP_STATIC_DIR) . '/'
    ) !== 0
) {
    $failures[] = 'Les chemins statiques ne sont pas correctement confinés.';
}

if (
    wp_static_minify_html('<p><span>Bonjour</span> <strong>monde</strong></p>')
        !== '<p><span>Bonjour</span> <strong>monde</strong></p>'
    || wp_static_minify_html('<p>Texte</p><!-- commentaire --><p>Suite</p>')
        !== '<p>Texte</p><p>Suite</p>'
) {
    $failures[] = 'La minification modifie les espaces utiles ou conserve les commentaires.';
}

$previousLastGeneration = get_option(WP_STATIC_LAST_RESULT_OPTION, null);
$storedGeneration = wp_static_store_last_generation_result([
    'generated' => 3,
    'skipped' => 1,
    'failed' => 0,
    'messages' => ['Page générée : https://example.test/'],
], microtime(true) - 1);
$lastGeneration = wp_static_get_last_generation_result();
if (
    $storedGeneration['generated'] !== 3
    || !is_array($lastGeneration)
    || $lastGeneration['generated'] !== 3
    || $lastGeneration['skipped'] !== 1
    || $lastGeneration['failed'] !== 0
    || $lastGeneration['duration'] < 0.9
    || $lastGeneration['completed_at'] <= 0
    || $lastGeneration['messages'] !== ['Page générée : https://example.test/']
) {
    $failures[] = 'Le résultat persistant de la dernière génération est incomplet.';
}
if ($previousLastGeneration === null) {
    delete_option(WP_STATIC_LAST_RESULT_OPTION);
} else {
    update_option(WP_STATIC_LAST_RESULT_OPTION, $previousLastGeneration, false);
}

if (
    !wp_static_request_has_private_cookie(['wordpress_logged_in_contract' => '1'])
    || !wp_static_request_has_private_cookie(['comment_author_contract' => '1'])
    || !wp_static_request_has_private_cookie(['wp-postpass_contract' => '1'])
    || wp_static_request_has_private_cookie(['wordpress_logged_in_contract' => '1'], true)
    || !wp_static_request_has_private_cookie(['comment_author_contract' => '1'], true)
    || !wp_static_request_has_private_cookie(['wp-postpass_contract' => '1'], true)
    || wp_static_request_has_private_cookie(['contract_public' => '1'])
) {
    $failures[] = 'Les cookies privés WordPress ne sont pas correctement détectés.';
}

require_once WP_PLUGIN_DIR . '/wp-static/pre-wp-cache.php';
if (
    !wp_static_pre_wp_has_private_cookie(['wordpress_logged_in_contract' => '1'])
    || wp_static_pre_wp_has_private_cookie(['wordpress_logged_in_contract' => '1'], true)
    || !wp_static_pre_wp_has_private_cookie(['comment_author_contract' => '1'], true)
    || !wp_static_pre_wp_has_private_cookie(['wp-postpass_contract' => '1'], true)
) {
    $failures[] = 'Le service pré-WordPress ne respecte pas le réglage des utilisateurs connectés.';
}

$previousServeLoggedIn = get_option(WP_STATIC_SERVE_LOGGED_IN_OPTION, null);
$previousServeLoggedInMode = get_option(WP_STATIC_MODE_OPTION, null);
delete_option(WP_STATIC_SERVE_LOGGED_IN_OPTION);
update_option(WP_STATIC_MODE_OPTION, 'auto');
$autoSnippet = wp_static_index_snippet();
update_option(WP_STATIC_SERVE_LOGGED_IN_OPTION, 1, false);
$enabledSnippet = wp_static_index_snippet();
delete_option(WP_STATIC_SERVE_LOGGED_IN_OPTION);
update_option(WP_STATIC_MODE_OPTION, 'full');
$fullSnippet = wp_static_index_snippet();
if (
    wp_static_should_serve_logged_in('auto')
    || !wp_static_should_serve_logged_in('full')
    || strpos($autoSnippet, "wp_static_serve_pre_wp(__DIR__ . '/' . 'wp-content/static-pages', false);") === false
    || strpos($enabledSnippet, "wp_static_serve_pre_wp(__DIR__ . '/' . 'wp-content/static-pages', true);") === false
    || strpos($fullSnippet, "wp_static_serve_pre_wp(__DIR__ . '/' . 'wp-content/static-pages', true);") === false
) {
    $failures[] = 'Le mode Complet ne force pas correctement le statique pour les utilisateurs connectés.';
}
if ($previousServeLoggedIn === null) {
    delete_option(WP_STATIC_SERVE_LOGGED_IN_OPTION);
} else {
    update_option(WP_STATIC_SERVE_LOGGED_IN_OPTION, $previousServeLoggedIn, false);
}
if ($previousServeLoggedInMode === null) {
    delete_option(WP_STATIC_MODE_OPTION);
} else {
    update_option(WP_STATIC_MODE_OPTION, $previousServeLoggedInMode, false);
}

$generationToken = wp_static_start_generation_token();
if (
    $generationToken === ''
    || !wp_static_pre_wp_valid_generation_token(WP_STATIC_DIR, $generationToken)
    || wp_static_pre_wp_valid_generation_token(WP_STATIC_DIR, 'forged-token')
) {
    $failures[] = 'Le service pré-WordPress ne valide pas correctement le jeton de génération.';
}
wp_static_end_generation_token();
if (wp_static_pre_wp_valid_generation_token(WP_STATIC_DIR, $generationToken)) {
    $failures[] = 'Le jeton de génération reste valide après son nettoyage.';
}

$atomicFile = get_temp_dir() . 'wp-static-atomic-contract-' . getmypid() . '.html';
if (
    !wp_static_atomic_write($atomicFile, '<html>atomic</html>')
    || file_get_contents($atomicFile) !== '<html>atomic</html>'
) {
    $failures[] = 'L’écriture atomique ne produit pas le fichier complet attendu.';
}
@unlink($atomicFile);

$emptyCacheDir = get_temp_dir() . 'wp-static-empty-cache-contract-' . getmypid();
$generatedCacheDir = $emptyCacheDir . '/_hosts/example.test';
wp_mkdir_p($generatedCacheDir);
if (wp_static_dir_has_generated_pages($emptyCacheDir)) {
    $failures[] = 'Un dossier statique vide est considéré comme généré.';
}
file_put_contents($generatedCacheDir . '/index.html', '<html>generated</html>');
if (!wp_static_dir_has_generated_pages($emptyCacheDir)) {
    $failures[] = 'Une page statique imbriquée n’est pas détectée.';
}
@unlink($generatedCacheDir . '/index.html');
@rmdir($generatedCacheDir);
@rmdir(dirname($generatedCacheDir));
@rmdir($emptyCacheDir);

$manifestDir = get_temp_dir() . 'wp-static-manifest-contract-' . getmypid();
wp_mkdir_p($manifestDir);
file_put_contents($manifestDir . '/index.html', '<html>manifest</html>');
file_put_contents($manifestDir . '/asset.css', 'body{}');
file_put_contents($manifestDir . '/.DS_Store', 'ignored');
$manifest = wp_static_export_manifest([
    [$manifestDir, 'export/', true],
], 'export/');
if (
    count($manifest['files']) !== 2
    || $manifest['size'] !== strlen('<html>manifest</html>') + strlen('body{}')
    || $manifest['files'][0]['rewrite_prefix'] !== 'export/'
) {
    $failures[] = 'Le manifeste d’export ne remplace pas correctement le double parcours des sources.';
}
@unlink($manifestDir . '/index.html');
@unlink($manifestDir . '/asset.css');
@unlink($manifestDir . '/.DS_Store');
@rmdir($manifestDir);

register_post_type('wpstatic_contract_hidden_ui', [
    'public' => true,
    'show_ui' => false,
]);
if (wp_static_is_post_type_menu_hidden('wpstatic_contract_hidden_ui')) {
    $failures[] = 'Un CPT public volontairement sans interface est encore exclu du statique.';
}
unregister_post_type('wpstatic_contract_hidden_ui');

if ($defaultHosts !== ['apache', 'nginx']) {
    $failures[] = 'Les fallbacks Docker Apache/nginx ne sont pas correctement ordonnés.';
}

$identityHostFilter = static function ($host) {
    return $host;
};
add_filter('wp_static_internal_host', $identityHostFilter);
if (wp_static_get_internal_hosts() !== ['apache', 'nginx']) {
    $failures[] = 'Un filtre historique neutre supprime encore le fallback nginx.';
}
remove_filter('wp_static_internal_host', $identityHostFilter);

if (wp_static_get_internal_host() !== 'apache') {
    $failures[] = 'L’ancienne API ne retourne pas le premier hôte interne.';
}

if (
    !wp_static_htaccess_credentials_complete('user', 'password')
    || wp_static_htaccess_credentials_complete('', 'password')
    || wp_static_htaccess_credentials_complete('user', '')
    || wp_static_preprod_credentials_missing()
) {
    $failures[] = 'La validation des identifiants Basic Auth est incorrecte.';
}

$cacheableItems = wp_static_filter_cacheable_url_items([
    'https://example.test/' => ['title' => 'Accueil'],
    'https://example.test/?page_id=1' => ['title' => 'Page'],
    'https://example.test/#section' => ['title' => 'Fragment'],
]);

if (
    array_keys($cacheableItems) !== ['https://example.test/']
    || !wp_static_url_is_cacheable('https://example.test/page/')
    || wp_static_url_is_cacheable('https://example.test/?page_id=1')
    || wp_static_url_is_cacheable('https://example.test/#section')
) {
    $failures[] = 'Les URLs avec query string ou fragment ne sont pas correctement exclues.';
}

$hostFilter = static function () {
    return ['contract-one', 'contract-two', 'contract-one', 'https://invalid'];
};
add_filter('wp_static_internal_host', $hostFilter);

if (wp_static_get_internal_hosts() !== ['contract-one', 'contract-two']) {
    $failures[] = 'Le filtre des hôtes internes ne normalise pas correctement une liste.';
}

$calls = [];
$callArgs = [];
$httpFilter = static function ($preempt, $args, $url) use (&$calls, &$callArgs) {
    $calls[] = $url;
    $callArgs[] = $args;
    $host = wp_parse_url($url, PHP_URL_HOST);

    if ($host === 'contract-two') {
        return [
            'headers' => [],
            'body' => '<html>contract</html>',
            'response' => [
                'code' => 200,
                'message' => 'OK',
            ],
            'cookies' => [],
            'filename' => null,
        ];
    }

    return new WP_Error('contract_unreachable', 'Hôte indisponible pour le contrat.');
};
add_filter('pre_http_request', $httpFilter, 10, 3);

$fetch = wp_static_fetch_url('https://starterkit-lonsdale-2027.code/contract/');

remove_filter('pre_http_request', $httpFilter, 10);
remove_filter('wp_static_internal_host', $hostFilter);

$expectedCalls = [
    'https://starterkit-lonsdale-2027.code/contract/',
    'https://contract-one/contract/',
    'http://contract-one/contract/',
    'https://contract-two/contract/',
];

if (
    is_wp_error($fetch['response'])
    || wp_remote_retrieve_response_code($fetch['response']) !== 200
    || $fetch['fallback_url'] !== 'https://contract-two/contract/'
    || $calls !== $expectedCalls
) {
    $failures[] = 'Le fallback HTTP ne passe pas correctement au serveur interne suivant.';
}

foreach ($callArgs as $args) {
    if (!isset($args['redirection']) || $args['redirection'] !== 0) {
        $failures[] = 'Les requêtes de génération peuvent encore suivre une redirection.';
        break;
    }
}

$serverErrorFilter = static function ($preempt, $args, $url) {
    $host = wp_parse_url($url, PHP_URL_HOST);
    if ($host === 'apache') {
        return [
            'headers' => [],
            'body' => '<html>fallback after 503</html>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    return [
        'headers' => [],
        'body' => 'Unavailable',
        'response' => ['code' => 503, 'message' => 'Unavailable'],
        'cookies' => [],
        'filename' => null,
    ];
};
add_filter('pre_http_request', $serverErrorFilter, 10, 3);
$serverErrorFetch = wp_static_fetch_url(home_url('/wp-static-503-contract/'));
remove_filter('pre_http_request', $serverErrorFilter, 10);
if (
    wp_remote_retrieve_response_code($serverErrorFetch['response']) !== 200
    || $serverErrorFetch['fallback_url'] !== 'https://apache/wp-static-503-contract/'
) {
    $failures[] = 'Une erreur HTTP 5xx ne bascule pas vers le serveur interne suivant.';
}

$wpmlLanguagesFilter = static function () {
    return [
        'fr' => ['url' => 'https://fr.example.test/'],
        'en' => ['url' => 'https://en.example.test/'],
    ];
};
add_filter('wpml_active_languages', $wpmlLanguagesFilter);
$rewrittenExport = wp_static_export_rewrite_html(
    '<a href="https://fr.example.test/page/">FR</a><a href="https://en.example.test/page/">EN</a>',
    'export/'
);
remove_filter('wpml_active_languages', $wpmlLanguagesFilter);
if (substr_count($rewrittenExport, 'href="/export/page/"') !== 2) {
    $failures[] = 'L’export ne réécrit pas tous les domaines WPML.';
}

$previousPending = get_option(WP_STATIC_GEN_PENDING_OPTION, null);
$previousPendingCron = wp_next_scheduled(WP_STATIC_PENDING_CRON_HOOK);
$previousMode = get_option(WP_STATIC_MODE_OPTION, null);
$previousCronFrequency = get_option(WP_STATIC_CRON_FREQUENCY_OPTION, null);
update_option(WP_STATIC_CRON_FREQUENCY_OPTION, 'daily');
if (
    wp_static_effective_cron_frequency('manual') !== 'off'
    || wp_static_effective_cron_frequency('auto') !== 'daily'
    || wp_static_effective_cron_frequency('full') !== 'daily'
) {
    $failures[] = 'La fréquence planifiée n’est pas correctement suspendue en mode Manuel.';
}
update_option(WP_STATIC_MODE_OPTION, 'auto');
delete_option(WP_STATIC_GEN_PENDING_OPTION);

$pendingUrlOne = home_url('/wp-static-pending-one/');
$pendingUrlTwo = home_url('/wp-static-pending-two/');
wp_static_add_pending_regen([$pendingUrlOne]);
wp_static_add_pending_regen([$pendingUrlTwo, $pendingUrlOne]);
$pending = wp_static_get_pending_regen();
if (
    $pending['full']
    || array_values($pending['urls']) !== [$pendingUrlOne, $pendingUrlTwo]
    || !wp_next_scheduled(WP_STATIC_PENDING_CRON_HOOK)
) {
    $failures[] = 'Les générations ciblées en attente ne sont pas fusionnées ou planifiées durablement.';
}

// Le mode Complet ne doit transformer que les événements automatiques en
// génération complète. Une action explicite « Régénérer » reste ciblée.
update_option(WP_STATIC_MODE_OPTION, 'full');
$pendingExplicitUrl = home_url('/wp-static-pending-explicit/');
wp_static_add_pending_regen([$pendingExplicitUrl]);
$pending = wp_static_get_pending_regen();
if (
    $pending['full']
    || !isset($pending['urls'][$pendingExplicitUrl])
) {
    $failures[] = 'Une régénération explicite ciblée devient complète en mode Complet.';
}

$previousRequestQueue = $GLOBALS['wp_static_regen_queue'] ?? null;
$previousRequestFull = $GLOBALS['wp_static_regen_full'] ?? null;
unset($GLOBALS['wp_static_regen_queue'], $GLOBALS['wp_static_regen_full']);
wp_static_enqueue_urls([$pendingExplicitUrl]);
if (
    empty($GLOBALS['wp_static_regen_full'])
    || !isset($GLOBALS['wp_static_regen_queue'])
    || $GLOBALS['wp_static_regen_queue'] !== []
) {
    $failures[] = 'Le mode Complet collecte encore les URLs avant le traitement de la file.';
}
remove_action('shutdown', 'wp_static_process_regen_queue');
if ($previousRequestQueue === null) {
    unset($GLOBALS['wp_static_regen_queue']);
} else {
    $GLOBALS['wp_static_regen_queue'] = $previousRequestQueue;
}
if ($previousRequestFull === null) {
    unset($GLOBALS['wp_static_regen_full']);
} else {
    $GLOBALS['wp_static_regen_full'] = $previousRequestFull;
}

wp_static_add_pending_regen([], true);
$pending = wp_static_get_pending_regen();
if (!$pending['full'] || $pending['urls'] !== []) {
    $failures[] = 'Une génération complète ne reste pas en file d’attente.';
}

$previousDirty = get_option(WP_STATIC_DIRTY_OPTION, null);
$throwDuringCollection = static function () {
    throw new RuntimeException('Exception attendue par le contrat de file d’attente.');
};
add_filter('wp_static_urls', $throwDuringCollection);
wp_static_maybe_process_pending_regen();
remove_filter('wp_static_urls', $throwDuringCollection);

$pendingAfterException = wp_static_get_pending_regen();
if (!$pendingAfterException['full'] || wp_static_is_gen_locked()) {
    $failures[] = 'Une génération en attente est perdue ou reste verrouillée après une exception.';
}

if ($previousPending === null) {
    delete_option(WP_STATIC_GEN_PENDING_OPTION);
} else {
    update_option(WP_STATIC_GEN_PENDING_OPTION, $previousPending, false);
}
if ($previousMode === null) {
    delete_option(WP_STATIC_MODE_OPTION);
} else {
    update_option(WP_STATIC_MODE_OPTION, $previousMode);
}
if ($previousCronFrequency === null) {
    delete_option(WP_STATIC_CRON_FREQUENCY_OPTION);
} else {
    update_option(WP_STATIC_CRON_FREQUENCY_OPTION, $previousCronFrequency);
}
if ($previousDirty === null) {
    delete_option(WP_STATIC_DIRTY_OPTION);
} else {
    update_option(WP_STATIC_DIRTY_OPTION, $previousDirty);
}
if (!$previousPendingCron) {
    wp_clear_scheduled_hook(WP_STATIC_PENDING_CRON_HOOK);
}

if (!wp_static_acquire_gen_lock()) {
    $failures[] = 'Le verrou principal ne peut pas être acquis.';
} else {
    $probe = fopen(WP_STATIC_GEN_LOCK_FILE, 'c');
    $secondLock = $probe ? flock($probe, LOCK_EX | LOCK_NB) : false;
    if ($secondLock) {
        flock($probe, LOCK_UN);
        $failures[] = 'Le verrou principal autorise deux générations simultanées.';
    }
    if ($probe) {
        fclose($probe);
    }
    wp_static_release_gen_lock();

    if (!file_exists(WP_STATIC_GEN_LOCK_FILE)) {
        $failures[] = 'Le fichier de verrou est supprimé et peut recréer une course entre processus.';
    }
}

$successUrl = home_url('/wp-static-contract-success/');
$failureUrl = home_url('/wp-static-contract-failure/');
$staleUrl = home_url('/wp-static-contract-stale/');
$previousDeps = wp_static_get_deps();
$previousDynamic = get_option(WP_STATIC_DYNAMIC_URLS_OPTION, null);
$previousMarkerClasses = get_option(WP_STATIC_REGEN_CLASSES_OPTION, null);
wp_static_save_deps([
    $successUrl => [10],
    $failureUrl => [20],
    $staleUrl => [30],
]);
update_option(WP_STATIC_DYNAMIC_URLS_OPTION, [$successUrl, $failureUrl, $staleUrl], false);
update_option(WP_STATIC_REGEN_CLASSES_OPTION, 'contract-marker', false);

$generationHttpFilter = static function ($preempt, $args, $url) use ($successUrl) {
    if ($url === $successUrl) {
        return [
            'headers' => [],
            'body' => '<html><body class="contract-marker">contract</body></html><!-- wp-static-deps:99 -->',
            'response' => [
                'code' => 200,
                'message' => 'OK',
            ],
            'cookies' => [],
            'filename' => null,
        ];
    }

    return new WP_Error('contract_generation_failure', 'Échec attendu par le contrat.');
};
$noInternalHosts = static function () {
    return [];
};
add_filter('pre_http_request', $generationHttpFilter, 10, 3);
add_filter('wp_static_internal_host', $noInternalHosts);

$generationResult = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
wp_static_generate_urls([$successUrl, $failureUrl], $generationResult, true);

remove_filter('pre_http_request', $generationHttpFilter, 10);
remove_filter('wp_static_internal_host', $noInternalHosts);

$rebuiltDeps = wp_static_get_deps();
$rebuiltDynamic = wp_static_get_dynamic_urls();
sort($rebuiltDynamic);
$expectedDynamic = [$failureUrl, $successUrl];
sort($expectedDynamic);
if (
    ($rebuiltDeps[$successUrl] ?? null) !== [99]
    || ($rebuiltDeps[$failureUrl] ?? null) !== [20]
    || isset($rebuiltDeps[$staleUrl])
    || $rebuiltDynamic !== $expectedDynamic
    || $generationResult['generated'] !== 1
    || $generationResult['failed'] !== 1
) {
    $failures[] = 'La reconstruction des dépendances ne préserve pas correctement les échecs.';
}

wp_static_delete_static_file($successUrl);
wp_static_save_deps($previousDeps);
if ($previousDynamic === null) {
    delete_option(WP_STATIC_DYNAMIC_URLS_OPTION);
} else {
    update_option(WP_STATIC_DYNAMIC_URLS_OPTION, $previousDynamic, false);
}
if ($previousMarkerClasses === null) {
    delete_option(WP_STATIC_REGEN_CLASSES_OPTION);
} else {
    update_option(WP_STATIC_REGEN_CLASSES_OPTION, $previousMarkerClasses, false);
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "WP Static contract: OK\n";
