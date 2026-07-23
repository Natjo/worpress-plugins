<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'starterkit-lonsdale-2027.code';

require '/var/www/html/wp-load.php';

$directory = sys_get_temp_dir() . '/wp-webp-contract-' . bin2hex(random_bytes(4));
if (!mkdir($directory, 0700)) {
    fwrite(STDERR, "Impossible de créer le dossier temporaire.\n");
    exit(1);
}

$image = new Imagick();
$cropImage = new Imagick();
$failures = [];

try {
    $profiles = wp_webp_profiles();
    if (
        ($profiles['best']['quality'] ?? 0) !== 85
        || ($profiles['optimal']['quality'] ?? 0) !== 80
        || ($profiles['green']['quality'] ?? 0) !== 70
        || ($profiles['best']['near_lossless'] ?? 0) !== 85
        || ($profiles['optimal']['near_lossless'] ?? 0) !== 60
        || ($profiles['green']['near_lossless'] ?? 0) !== 40
        || !empty($profiles['best']['cap_to_original'])
    ) {
        $failures[] = 'Les profils Best, Optimal et Green sont incohérents.';
    }

    $image->newImage(32, 24, new ImagickPixel('#c0392b'));
    $image->setImageFormat('webp');

    wp_webp_write_image_atomic($image, $directory . '/atomic.webp');
    wp_webp_write_image_atomic($image, $directory . '/generated.webp');
    wp_webp_write_image_atomic($image, $directory . '/native.webp');

    $image->setImageFormat('jpeg');
    $image->writeImage($directory . '/generated.jpg');
    $image->writeImage($directory . '/native.jpg');
    $image->writeImage($directory . '/existing.jpg');
    $image->writeImage($directory . '/obsolete.jpg');

    $cropImage->newImage(100, 50, new ImagickPixel('#ff0000'));
    $cropImage->setImageFormat('png');
    $cropImage->drawImage((static function () {
        $draw = new ImagickDraw();
        $draw->setFillColor('#0000ff');
        $draw->rectangle(50, 0, 99, 49);

        return $draw;
    })());
    $cropImage->writeImage($directory . '/crop-source.png');

    $cropFailures = [];
    $cropSource = new Imagick($directory . '/crop-source.png');
    $cropProfile = wp_webp_get_profile();
    $cropNearLossless = wp_webp_resolve_graphic_image(
        0,
        $directory . '/crop-source.png',
        $cropProfile,
        $cropSource
    );
    wp_webp_make_webp_from_source(
        $cropSource,
        25,
        25,
        ['left', 'center'],
        $directory . '/crop-left.jpg',
        $cropProfile,
        $cropNearLossless,
        $cropFailures
    );
    wp_webp_make_webp_from_source(
        $cropSource,
        25,
        25,
        ['right', 'center'],
        $directory . '/crop-right.jpg',
        $cropProfile,
        $cropNearLossless,
        $cropFailures
    );
    $cropSource->clear();
    $cropSource->destroy();

    $leftCrop = new Imagick($directory . '/crop-left.webp');
    $rightCrop = new Imagick($directory . '/crop-right.webp');
    $leftColor = $leftCrop->getImagePixelColor(12, 12)->getColor();
    $rightColor = $rightCrop->getImagePixelColor(12, 12)->getColor();
    $leftCrop->clear();
    $leftCrop->destroy();
    $rightCrop->clear();
    $rightCrop->destroy();

    $clearState = [
        'protected' => [
            wp_webp_file_key($directory . '/atomic.webp') => true,
            wp_webp_file_key($directory . '/native.webp') => true,
        ],
        'directories' => [wp_normalize_path($directory)],
        'current_directory' => '',
        'after_name' => '',
    ];
    $deleted = 0;
    $clearFailures = [];
    $clearDone = false;

    for ($batch = 0; $batch < 100 && !$clearDone; $batch++) {
        $clearResult = wp_webp_clear_batch($clearState, 2, $clearFailures);
        $deleted += $clearResult['deleted'];
        $clearDone = $clearResult['done'];
    }
    $temporary = glob($directory . '/.wp-webp-*') ?: [];

    if (!$clearDone || $deleted < 3 || $clearFailures !== []) {
        $failures[] = 'La suppression globale par lots a échoué.';
    }
    if (!is_file($directory . '/atomic.webp')) {
        $failures[] = 'L’écriture atomique n’a pas produit de fichier.';
    }
    if (is_file($directory . '/generated.webp')) {
        $failures[] = 'Le WebP associé au JPEG devait être supprimé.';
    }
    if (!is_file($directory . '/native.webp')) {
        $failures[] = 'Le WebP sans source JPEG/PNG devait être conservé.';
    }
    if ($temporary !== []) {
        $failures[] = 'Un fichier temporaire résiduel est présent.';
    }
    if ($cropFailures !== []) {
        $failures[] = 'La génération des crops de test a échoué.';
    }
    if (($leftColor['r'] ?? 0) <= ($leftColor['b'] ?? 0)) {
        $failures[] = 'Le crop gauche ne conserve pas la partie gauche de l’image.';
    }
    if (($rightColor['b'] ?? 0) <= ($rightColor['r'] ?? 0)) {
        $failures[] = 'Le crop droit ne conserve pas la partie droite de l’image.';
    }
    if (wp_webp_normalize_crop(['left', 'bottom']) !== ['left', 'bottom']) {
        $failures[] = 'La position de crop WordPress n’est pas conservée.';
    }
    if (wp_webp_crop_offset(100, 'center') !== 50 || wp_webp_crop_offset(100, 'right') !== 100) {
        $failures[] = 'Le calcul de la position de crop est incorrect.';
    }
    if (wp_webp_normalize_size_metadata('unsafe', [
        'file' => '../outside.jpg',
        'width' => 100,
        'height' => 100,
    ]) !== null) {
        $failures[] = 'Un chemin de métadonnées non sûr a été accepté.';
    }
    if (wp_webp_normalize_size_metadata('invalid', [
        'file' => 'invalid.jpg',
        'width' => 0,
        'height' => 100,
    ]) !== null) {
        $failures[] = 'Des dimensions invalides ont été acceptées.';
    }
    if (wp_webp_attachment_supported(0)) {
        $failures[] = 'Un attachement sans MIME pris en charge a été accepté.';
    }
    if (!wp_webp_should_reuse_source($directory . '/crop-source.png')) {
        $failures[] = 'Une petite image devrait réutiliser son décodage source.';
    }

    $disableSourceReuse = static function () {
        return 0;
    };
    add_filter('wp_webp_reuse_source_max_pixels', $disableSourceReuse);
    if (wp_webp_should_reuse_source($directory . '/crop-source.png')) {
        $failures[] = 'Le filtre de garde mémoire ne désactive pas le décodage partagé.';
    }
    remove_filter('wp_webp_reuse_source_max_pixels', $disableSourceReuse);

    [$runIdA, $runStateA] = wp_webp_create_generation_run();
    [$runIdB, $runStateB] = wp_webp_create_generation_run();
    if (
        $runIdA === $runIdB
        || wp_webp_generation_run_key($runIdA) === wp_webp_generation_run_key($runIdB)
        || wp_webp_get_generation_run($runIdA) !== $runStateA
        || wp_webp_get_generation_run($runIdB) !== $runStateB
    ) {
        $failures[] = 'Les sessions de génération ne sont pas isolées.';
    }
    delete_transient(wp_webp_generation_run_key($runIdA));
    delete_transient(wp_webp_generation_run_key($runIdB));

    foreach ([
        'wp_webp_count_attachments',
        'wp_webp_get_attachment_ids',
        'wp_webp_count_total_jobs',
        'wp_webp_generate_for_attachment',
    ] as $removedFunction) {
        if (function_exists($removedFunction)) {
            $failures[] = 'L’ancienne fonction ' . $removedFunction . ' existe encore.';
        }
    }

    $merged = wp_webp_merge_metadata_for_processing(
        ['sizes' => []],
        [
            'sizes' => [
                'medium' => [
                    'file' => 'existing.jpg',
                    'width' => 300,
                    'height' => 200,
                ],
                'legacy' => [
                    'file' => 'obsolete.jpg',
                    'width' => 120,
                    'height' => 80,
                ],
            ],
        ],
        ['medium'],
        $directory
    );

    if (!isset($merged['sizes']['medium']) || isset($merged['sizes']['legacy'])) {
        $failures[] = 'La fusion des métadonnées partielles est incorrecte.';
    }

    $image->setImageFormat('webp');
    wp_webp_write_image_atomic($image, $directory . '/obsolete.webp');

    $fakeAttachmentId = PHP_INT_MAX;
    $attachedFileFilter = static function ($file, $attachmentId) use ($fakeAttachmentId, $directory) {
        return (int) $attachmentId === $fakeAttachmentId
            ? $directory . '/source.jpg'
            : $file;
    };
    add_filter('get_attached_file', $attachedFileFilter, 10, 2);
    $obsoleteFailures = [];
    $obsoleteDeleted = wp_webp_cleanup_obsolete_webps(
        $fakeAttachmentId,
        ['sizes' => []],
        [
            'sizes' => [
                'legacy' => [
                    'file' => 'obsolete.jpg',
                    'width' => 120,
                    'height' => 80,
                ],
            ],
        ],
        ['medium'],
        $obsoleteFailures
    );
    remove_filter('get_attached_file', $attachedFileFilter, 10);

    if ($obsoleteDeleted !== 1 || is_file($directory . '/obsolete.webp') || $obsoleteFailures !== []) {
        $failures[] = 'Le nettoyage des WebP obsolètes a échoué.';
    }
} finally {
    $image->clear();
    $image->destroy();
    $cropImage->clear();
    $cropImage->destroy();

    foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob($directory . '/.*') ?: [] as $file) {
        if (!in_array(basename($file), ['.', '..'], true)) {
            @unlink($file);
        }
    }
    @rmdir($directory);
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "WP WebP contract: OK\n";
