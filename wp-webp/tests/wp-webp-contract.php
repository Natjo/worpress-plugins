<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

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
$generationLockOption = 'wp_webp_operation_lock_contract';
$generationLockOptionFilter = static function () use ($generationLockOption) {
    return $generationLockOption;
};
add_filter('wp_webp_operation_lock_option_name', $generationLockOptionFilter);
$generationLockBefore = get_option($generationLockOption, null);
$generationLockExisted = $generationLockBefore !== null;
$disabledSizesBefore = get_option(WP_WEBP_DISABLED_SIZES_OPTION, null);
$disabledSizesExisted = $disabledSizesBefore !== null;
$showOriginalBefore = get_option(WP_WEBP_SHOW_ORIGINAL_OPTION, null);
$showOriginalExisted = $showOriginalBefore !== null;
delete_option($generationLockOption);

try {
    $profiles = wp_webp_profiles();
    if (
        ($profiles['finest']['quality'] ?? 0) !== 85
        || ($profiles['natural']['quality'] ?? 0) !== 80
        || ($profiles['optimal']['quality'] ?? 0) !== 75
        || ($profiles['green']['quality'] ?? 0) !== 68
        || ($profiles['finest']['near_lossless'] ?? 0) !== 85
        || ($profiles['natural']['near_lossless'] ?? 0) !== 70
        || ($profiles['optimal']['near_lossless'] ?? 0) !== 55
        || ($profiles['green']['near_lossless'] ?? 0) !== 40
        || (float) ($profiles['natural']['sigma'] ?? 1) !== 0.0
        || empty($profiles['finest']['cap_to_original'])
        || empty($profiles['natural']['cap_to_original'])
        || empty($profiles['optimal']['cap_to_original'])
        || empty($profiles['green']['cap_to_original'])
        || wp_webp_normalize_profile_key('best') !== 'finest'
    ) {
        $failures[] = 'Les profils Finest, Natural, Optimal et Green sont incohérents.';
    }

    update_option(WP_WEBP_DISABLED_SIZES_OPTION, [WP_WEBP_ORIGINAL_SIZE]);
    update_option(WP_WEBP_SHOW_ORIGINAL_OPTION, '0');
    if (
        wp_webp_show_original_size()
        || !wp_webp_size_enabled(WP_WEBP_ORIGINAL_SIZE)
        || in_array(WP_WEBP_ORIGINAL_SIZE, wp_webp_get_disabled_managed_sizes(), true)
    ) {
        $failures[] = 'Le format original masqué n’est pas forcé actif.';
    }
    update_option(WP_WEBP_SHOW_ORIGINAL_OPTION, '1');
    if (
        !wp_webp_show_original_size()
        || wp_webp_size_enabled(WP_WEBP_ORIGINAL_SIZE)
    ) {
        $failures[] = 'Le format original affiché ne respecte pas la sélection Compresser.';
    }
    if ($disabledSizesExisted) {
        update_option(WP_WEBP_DISABLED_SIZES_OPTION, $disabledSizesBefore);
    } else {
        delete_option(WP_WEBP_DISABLED_SIZES_OPTION);
    }
    if ($showOriginalExisted) {
        update_option(WP_WEBP_SHOW_ORIGINAL_OPTION, $showOriginalBefore);
    } else {
        delete_option(WP_WEBP_SHOW_ORIGINAL_OPTION);
    }
    if (
        wp_webp_job_size(['type' => 'original']) !== WP_WEBP_ORIGINAL_SIZE
        || wp_webp_job_size(['type' => 'size', 'name' => 'thumbnail']) !== 'thumbnail'
        || wp_webp_job_size(['type' => 'size'], 'medium') !== 'medium'
    ) {
        $failures[] = 'L’attribution d’un job à son format est incorrecte.';
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

    $image->setImageFormat('jpeg');
    $image->writeImage($directory . '/collision.jpg');
    $image->setImageFormat('png');
    $image->writeImage($directory . '/collision.png');

    file_put_contents($directory . '/.wp-webp-stale', 'stale');
    file_put_contents($directory . '/.wp-webp-fresh', 'fresh');
    touch($directory . '/.wp-webp-stale', time() - WP_WEBP_TEMP_FILE_MAX_AGE - 10);
    $staleDeleted = wp_webp_cleanup_stale_temp_files($directory);
    if (
        $staleDeleted !== 1
        || is_file($directory . '/.wp-webp-stale')
        || !is_file($directory . '/.wp-webp-fresh')
    ) {
        $failures[] = 'Le nettoyage borné des fichiers temporaires est incorrect.';
    }
    @unlink($directory . '/.wp-webp-fresh');

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
    if (
        wp_webp_crop_label(true) !== 'Centré'
        || wp_webp_crop_label(false) !== 'Non'
        || wp_webp_crop_label(['left', 'bottom']) !== 'Left / bottom'
    ) {
        $failures[] = 'Le libellé de la colonne Crop est incorrect.';
    }
    if (wp_webp_crop_offset(100, 'center') !== 50 || wp_webp_crop_offset(100, 'right') !== 100) {
        $failures[] = 'Le calcul de la position de crop est incorrect.';
    }
    if (
        wp_webp_resize_dimensions(600, 300, 400, 400, true)
        !== array_map('intval', image_resize_dimensions(600, 300, 400, 400, true))
    ) {
        $failures[] = 'La géométrie de crop ne correspond pas à WordPress.';
    }

    $largeSource = new Imagick();
    $largeSource->newImage(3000, 1800, new ImagickPixel('#456789'));
    $largeSource->setImageFormat('jpeg');
    $largeFailures = [];
    $largeGenerated = wp_webp_make_webp_from_source(
        $largeSource,
        0,
        0,
        false,
        $directory . '/large.jpg',
        wp_webp_get_profile(),
        false,
        $largeFailures
    );
    $largeSource->clear();
    $largeSource->destroy();

    if ($largeGenerated !== 1 || !is_file($directory . '/large.webp')) {
        $failures[] = 'Le WebP pleine taille plafonné n’a pas été généré.';
    } else {
        $largeWebp = new Imagick($directory . '/large.webp');
        $largeWidth = $largeWebp->getImageWidth();
        $largeHeight = $largeWebp->getImageHeight();
        $largeWebp->clear();
        $largeWebp->destroy();

        if ($largeWidth !== 2500 || $largeHeight !== 1500 || $largeFailures !== []) {
            $failures[] = 'Le WebP pleine taille ne respecte pas le plafond de 2500 px et le ratio source.';
        }
    }

    $customDimensions = static function () {
        return [0, 0, 10, 20, 25, 25, 50, 50];
    };
    add_filter('image_resize_dimensions', $customDimensions);
    if (wp_webp_resize_dimensions(100, 100, 25, 25, true) !== [0, 0, 10, 20, 25, 25, 50, 50]) {
        $failures[] = 'Le filtre WordPress de cadrage personnalisé est ignoré.';
    }
    remove_filter('image_resize_dimensions', $customDimensions);

    $collisionFailures = [];
    $image->setImageFormat('jpeg');
    if (
        wp_webp_make_webp_from_source(
            $image,
            0,
            0,
            false,
            $directory . '/collision.jpg',
            wp_webp_get_profile(),
            false,
            $collisionFailures
        ) !== 0
        || $collisionFailures === []
    ) {
        $failures[] = 'Une collision JPEG/PNG n’est pas bloquée.';
    }
    if (wp_webp_unique_filename('collision.png', '.png', $directory, null, [], '') !== 'collision-1.png') {
        $failures[] = 'Le nom d’upload ne prévient pas la collision WebP.';
    }
    if (wp_webp_unique_filename('collision.webp', '.webp', $directory, null, [], '') !== 'collision-1.webp') {
        $failures[] = 'Un WebP natif peut encore entrer en collision avec une source JPEG/PNG.';
    }

    file_put_contents($directory . '/tiny.jpg', 'x');
    $weightFailures = [];
    if (
        wp_webp_make_webp_from_source(
            $image,
            0,
            0,
            false,
            $directory . '/tiny.jpg',
            wp_webp_get_profile(),
            false,
            $weightFailures
        ) !== 1
        || !is_file($directory . '/tiny.webp')
        || filesize($directory . '/tiny.webp') <= filesize($directory . '/tiny.jpg')
        || $weightFailures !== []
    ) {
        $failures[] = 'Le meilleur WebP n’est pas publié lorsque tous les essais sont plus lourds.';
    }

    $retrySource = new Imagick();
    $retrySource->newPseudoImage(256, 256, 'plasma:fractal');
    $retryProfile = wp_webp_get_profile();
    $retryProfile['quality'] = 10;
    $retryProfile['quality_floor'] = 1;
    $retryProfile['near_lossless'] = 100;
    $retryProfile['sigma'] = 0;

    $nearCandidate = clone $retrySource;
    $nearCandidate->stripImage();
    wp_webp_apply_webp_options($nearCandidate, $retryProfile, true);
    $nearTemporary = wp_webp_write_temp_image($nearCandidate, $directory . '/near-candidate.webp');
    $nearSize = filesize($nearTemporary);
    @unlink($nearTemporary);
    $nearCandidate->clear();
    $nearCandidate->destroy();

    $lossyCandidate = clone $retrySource;
    $lossyCandidate->stripImage();
    wp_webp_apply_webp_options($lossyCandidate, $retryProfile, false);
    $lossyCandidate->setImageCompressionQuality(10);
    $lossyTemporary = wp_webp_write_temp_image($lossyCandidate, $directory . '/lossy-candidate.webp');
    $lossySize = filesize($lossyTemporary);
    @unlink($lossyTemporary);
    $lossyCandidate->clear();
    $lossyCandidate->destroy();

    if ($nearSize <= $lossySize) {
        $failures[] = 'Le fixture ne permet pas de tester le repli near-lossless vers lossy.';
    } else {
        $referenceSize = (int) floor(($nearSize + $lossySize) / 2);
        file_put_contents($directory . '/retry.jpg', str_repeat('x', $referenceSize));
        $retryFailures = [];
        $retryGenerated = wp_webp_make_webp_from_source(
            $retrySource,
            0,
            0,
            false,
            $directory . '/retry.jpg',
            $retryProfile,
            true,
            $retryFailures
        );
        if (
            $retryGenerated !== 1
            || !is_file($directory . '/retry.webp')
            || filesize($directory . '/retry.webp') >= $referenceSize
            || $retryFailures !== []
        ) {
            $failures[] = 'Le repli near-lossless vers lossy n’a pas produit un WebP plus léger.';
        }
    }
    $retrySource->clear();
    $retrySource->destroy();

    $groupedSource = new Imagick();
    $groupedSource->newPseudoImage(400, 300, 'gradient:#16324f-#d9eafd');
    $groupedSource->setImageFormat('jpeg');
    $groupedSource->setImageCompressionQuality(90);
    $groupedSource->writeImage($directory . '/grouped.jpg');
    $groupedThumbnail = clone $groupedSource;
    $groupedThumbnail->cropThumbnailImage(150, 150);
    $groupedThumbnail->writeImage($directory . '/grouped-150x150.jpg');
    $groupedThumbnail->clear();
    $groupedThumbnail->destroy();
    $groupedSource->clear();
    $groupedSource->destroy();

    $groupedAttachmentId = wp_insert_attachment([
        'post_mime_type' => 'image/jpeg',
        'post_title'     => 'WP WebP grouped contract',
        'post_status'    => 'inherit',
    ]);
    if (is_wp_error($groupedAttachmentId) || !$groupedAttachmentId) {
        $failures[] = 'Impossible de créer l’attachement du test groupé.';
    } else {
        update_post_meta($groupedAttachmentId, '_wp_attached_file', $directory . '/grouped.jpg');
        wp_update_attachment_metadata($groupedAttachmentId, [
            'file'   => 'grouped.jpg',
            'width'  => 400,
            'height' => 300,
            'sizes'  => [
                'thumbnail' => [
                    'file'      => 'grouped-150x150.jpg',
                    'width'     => 150,
                    'height'    => 150,
                    'mime-type' => 'image/jpeg',
                ],
            ],
        ]);

        $disabledSizes = get_option(WP_WEBP_DISABLED_SIZES_OPTION, null);
        update_option(WP_WEBP_DISABLED_SIZES_OPTION, []);
        $groupedFailures = [];
        $groupedResult = wp_webp_process_attachment_jobs($groupedAttachmentId, $groupedFailures);
        if ($disabledSizes === null) {
            delete_option(WP_WEBP_DISABLED_SIZES_OPTION);
        } else {
            update_option(WP_WEBP_DISABLED_SIZES_OPTION, $disabledSizes);
        }

        if (
            $groupedResult['processed'] < 2
            || $groupedResult['generated'] < 2
            || !is_file($directory . '/grouped.webp')
            || !is_file($directory . '/grouped-150x150.webp')
            || $groupedFailures !== []
        ) {
            $failures[] = 'Le traitement groupé ne génère pas toutes les déclinaisons de l’attachement.';
        }

        $deleteGroupedFailures = [];
        $deleteGroupedCount = wp_webp_delete_attachment_sizes(
            $groupedAttachmentId,
            [WP_WEBP_ORIGINAL_SIZE, 'thumbnail'],
            $deleteGroupedFailures
        );
        if (
            $deleteGroupedCount !== 2
            || is_file($directory . '/grouped.webp')
            || is_file($directory . '/grouped-150x150.webp')
            || $deleteGroupedFailures !== []
        ) {
            $failures[] = 'La suppression groupée recharge ou traite mal les formats.';
        }

        wp_delete_attachment($groupedAttachmentId, true);
    }

    $failedCompletion = wp_webp_generation_completion(WP_WEBP_ORIGINAL_SIZE, 1);
    if ($failedCompletion['sizes'] !== [] || $failedCompletion['timestamp'] !== 0) {
        $failures[] = 'Une génération en erreur est marquée comme terminée.';
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

    $runIdA = 'cccccccccccccccccccc';
    $runIdB = 'dddddddddddddddddddd';
    $firstRunAcquired = wp_webp_acquire_operation_lock($runIdA);
    $secondRunBlocked = !wp_webp_acquire_operation_lock($runIdB);
    if (
        !$firstRunAcquired
        || !$secondRunBlocked
        || (wp_webp_get_operation_lock()['run_id'] ?? '') !== $runIdA
        || !wp_webp_refresh_operation_lock($runIdA)
    ) {
        $failures[] = 'Le verrou global n’isole pas les générations concurrentes.';
    }
    wp_webp_release_operation_lock($runIdA);

    update_option($generationLockOption, [
        'run_id' => 'aaaaaaaaaaaaaaaaaaaa',
        'user_id' => 0,
        'expires' => time() - 1,
    ], false);
    $replacementRunId = 'bbbbbbbbbbbbbbbbbbbb';
    if (
        !wp_webp_acquire_operation_lock($replacementRunId)
        || (wp_webp_get_operation_lock()['run_id'] ?? '') !== $replacementRunId
    ) {
        $failures[] = 'Un verrou expiré empêche encore une nouvelle génération.';
    }
    wp_webp_release_operation_lock($replacementRunId);

    foreach ([
        'wp_webp_count_attachments',
        'wp_webp_get_attachment_ids',
        'wp_webp_count_total_jobs',
        'wp_webp_generate_for_attachment',
        'wp_webp_ajax_cleanup_size',
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
    if ($disabledSizesExisted) {
        update_option(WP_WEBP_DISABLED_SIZES_OPTION, $disabledSizesBefore);
    } else {
        delete_option(WP_WEBP_DISABLED_SIZES_OPTION);
    }
    if ($showOriginalExisted) {
        update_option(WP_WEBP_SHOW_ORIGINAL_OPTION, $showOriginalBefore);
    } else {
        delete_option(WP_WEBP_SHOW_ORIGINAL_OPTION);
    }
    if ($generationLockExisted) {
        update_option($generationLockOption, $generationLockBefore, false);
    } else {
        delete_option($generationLockOption);
    }
    remove_filter('wp_webp_operation_lock_option_name', $generationLockOptionFilter);

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
