<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$_SERVER['HTTP_HOST'] = 'starterkit-lonsdale-2027.code';

require '/var/www/html/wp-load.php';

$output = isset($argv[1]) ? (string) $argv[1] : '';
$outputDirectory = $output !== '' ? realpath(dirname($output)) : false;
$temporaryDirectory = realpath(sys_get_temp_dir());

if ($outputDirectory === false || $temporaryDirectory === false || $outputDirectory !== $temporaryDirectory) {
    fwrite(STDERR, "Le fichier de sortie doit être placé dans le dossier temporaire.\n");
    exit(1);
}

$administrators = get_users([
    'role'   => 'administrator',
    'number' => 1,
    'fields' => 'ID',
]);
if ($administrators === []) {
    fwrite(STDERR, "Aucun administrateur WordPress disponible pour rendre la fixture.\n");
    exit(1);
}

wp_set_current_user((int) $administrators[0]);

ob_start();
wp_webp_admin_page();
$page = (string) ob_get_clean();

$prelude = <<<'HTML'
<script>
window.__wpWebpGenerationResolve = null;
window.__wpWebpGenerationCalls = 0;
window.confirm = function () { return true; };
window.fetch = function (url, options) {
    var params = new URLSearchParams(options && options.body ? options.body : '');
    var action = params.get('action') || '';
    var payload = { success: true, data: {} };

    if (action === 'wp_webp_generate_webp') {
        window.__wpWebpGenerationCalls++;
        payload.data = window.__wpWebpGenerationCalls === 1 ? {
            run_id: 'abcdefghijklmnopqrst', attachment_id: 1, job_index: 1,
            processed_jobs: 1, processed_attachments: 0, generated: 1,
            total_attachments: 1, done: false, failures: [], failure_count: 0,
            size_error_counts: {}
        } : {
            run_id: 'abcdefghijklmnopqrst', attachment_id: 0, job_index: 0,
            processed_jobs: 2, processed_attachments: 1, generated: 2,
            total_attachments: 1, done: true, failures: [], failure_count: 0,
            size_error_counts: {}, touched_sizes: ['original'],
            generated_at_label: '02/09/2026 16:00'
        };

        if (window.__wpWebpGenerationCalls === 1) {
            return new Promise(function (resolve) {
                window.__wpWebpGenerationResolve = function () {
                    resolve(window.__wpWebpResponse(payload));
                };
            });
        }
    } else if (action === 'wp_webp_get_size_errors') {
        payload.data = { errors: [{ file: 'image.jpg', error: 'Échec simulé' }] };
    } else if (action === 'wp_webp_clear_webp') {
        payload.data = { run_id: 'clearabcdefghijklmn', deleted: 3, done: true, failures: [] };
    } else if (action === 'wp_webp_cleanup_unused') {
        payload.data = { after_id: 0, deleted: 2, done: true, empty: false, failures: [] };
    }

    return Promise.resolve(window.__wpWebpResponse(payload));
};
window.__wpWebpResponse = function (payload) {
    return {
        ok: true,
        status: 200,
        json: function () { return Promise.resolve(payload); },
        text: function () { return Promise.resolve(JSON.stringify(payload)); }
    };
};
</script>
HTML;

$contract = <<<'HTML'
<script>
(async function () {
    var failures = [];
    function assert(condition, message) {
        if (!condition) { failures.push(message); }
    }
    function flush() {
        return new Promise(function (resolve) { setTimeout(resolve, 0); });
    }

    document.body.dataset.wpWebpContract = 'RUNNING';

    var profile = document.getElementById('wp-webp-profile');
    profile.value = 'natural';
    profile.dispatchEvent(new Event('change', { bubbles: true }));
    await flush();
    assert(document.getElementById('wp-webp-profile-desc').textContent.indexOf('sans accentuation') !== -1, 'Profil non synchronisé');

    var generate = document.getElementById('wp-webp-generate');
    generate.click();
    assert(generate.textContent === 'Pause', 'Démarrage de génération incorrect');
    generate.click();
    assert(generate.textContent === 'Pause…', 'Demande de pause incorrecte');
    window.__wpWebpGenerationResolve();
    await flush();
    await flush();
    assert(generate.textContent === 'Reprendre', 'Mise en pause incorrecte');
    generate.click();
    await flush();
    await flush();
    assert(generate.textContent === 'Générer les WebP', 'Reprise ou fin de génération incorrecte');
    assert(document.getElementById('wp-webp-generate-status').textContent.indexOf('Terminé') === 0, 'Statut final absent');

    assert(typeof window.wpWebpUpdateErrorCounts === 'function', 'Mise à jour dynamique absente');
    if (typeof window.wpWebpUpdateErrorCounts === 'function') {
        window.wpWebpUpdateErrorCounts({ original: 2 });
        var cell = document.querySelector('.wp-webp-errors-cell[data-size="original"]');
        var button = cell ? cell.querySelector('.wp-webp-view-errors') : null;
        assert(cell && cell.textContent.replace(/\s+/g, '') === '2Afficher', 'État avec erreurs incorrect');
        assert(button && getComputedStyle(button).color === 'rgb(34, 113, 177)', 'Lien Afficher non bleu');
        assert(button && getComputedStyle(button).textDecorationLine.indexOf('underline') !== -1, 'Lien Afficher non souligné');
        button.click();
        await flush();
        assert(!document.getElementById('wp-webp-errors-modal').hidden, 'Modale non ouverte');
        assert(document.getElementById('wp-webp-errors-modal-list').textContent.indexOf('Échec simulé') !== -1, 'Détail d’erreur absent');
        document.getElementById('wp-webp-errors-modal-close').click();
        assert(document.getElementById('wp-webp-errors-modal').hidden, 'Modale non fermée');

        window.wpWebpUpdateErrorCounts({ original: 0 });
        cell = document.querySelector('.wp-webp-errors-cell[data-size="original"]');
        assert(cell && cell.textContent.trim() === '—', 'État sans erreur incorrect');
        assert(cell && !cell.querySelector('.wp-webp-view-errors'), 'Lien présent sans erreur');
    }

    var checkbox = document.querySelector('.wp-webp-size-cb');
    checkbox.checked = !checkbox.checked;
    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
    await flush();
    assert(document.getElementById('wp-webp-sizes-status').textContent === 'Enregistré.', 'Sauvegarde de format incorrecte');

    var originalVisibility = document.getElementById('wp-webp-show-original');
    var originalRow = document.getElementById('wp-webp-original-row');
    var originalCheckbox = originalRow.querySelector('.wp-webp-size-cb');
    originalVisibility.checked = false;
    originalVisibility.dispatchEvent(new Event('change', { bubbles: true }));
    await flush();
    assert(originalRow.hidden, 'Le format original reste visible après masquage');
    assert(originalCheckbox.checked, 'Le format original masqué n’est pas forcé actif');
    originalVisibility.checked = true;
    originalVisibility.dispatchEvent(new Event('change', { bubbles: true }));
    await flush();
    assert(!originalRow.hidden, 'Le format original ne réapparaît pas');
    assert(document.getElementById('wp-webp-show-original-status').textContent === 'Enregistré.', 'Sauvegarde de visibilité incorrecte');

    document.getElementById('wp-webp-clear').click();
    await flush();
    assert(document.getElementById('wp-webp-clear-status').textContent.indexOf('3 fichier(s)') === 0, 'Suppression globale incorrecte');

    document.getElementById('wp-webp-cleanup-unused').click();
    await flush();
    assert(document.getElementById('wp-webp-cleanup-unused-status').textContent.indexOf('2 fichier(s)') === 0, 'Nettoyage des formats incorrect');

    document.body.dataset.wpWebpContract = failures.length ? failures.join(' | ') : 'OK';
})().catch(function (error) {
    document.body.dataset.wpWebpContract = 'Exception: ' + error.message;
});
</script>
HTML;

$html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>WP WebP browser contract</title></head><body>'
    . $prelude
    . $page
    . $contract
    . '</body></html>';

if (file_put_contents($output, $html) === false) {
    fwrite(STDERR, "Impossible d’écrire la fixture navigateur.\n");
    exit(1);
}

echo "WP WebP browser fixture: OK\n";
