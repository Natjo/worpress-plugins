<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$_SERVER['HTTP_HOST'] = 'starterkit-lonsdale-2027.code';

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$temporary = sys_get_temp_dir() . '/wp-webp-lifecycle-' . bin2hex(random_bytes(4)) . '.jpg';
$attachmentId = 0;
$postId = 0;
$failures = [];
$sourcePaths = [];
$webpPaths = [];

try {
    $source = new Imagick();
    $source->newPseudoImage(640, 480, 'plasma:fractal');
    $source->setImageFormat('jpeg');
    $source->setImageCompressionQuality(90);
    $source->writeImage($temporary);
    $source->clear();
    $source->destroy();

    $attachment = media_handle_sideload([
        'name'     => 'wp-webp-lifecycle-' . bin2hex(random_bytes(3)) . '.jpg',
        'type'     => 'image/jpeg',
        'tmp_name' => $temporary,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($temporary),
    ], 0, 'WP WebP lifecycle contract');

    if (is_wp_error($attachment)) {
        throw new RuntimeException($attachment->get_error_message());
    }

    $attachmentId = (int) $attachment;
    $original = (string) get_attached_file($attachmentId);
    $metadata = wp_get_attachment_metadata($attachmentId);

    if ($original === '' || !is_file($original) || !is_array($metadata)) {
        $failures[] = 'L’upload WordPress n’a pas créé l’attachement et ses métadonnées.';
    } else {
        $sourcePaths[] = $original;
        $webpPaths[] = wp_webp_target_path($original);
        $directory = trailingslashit(dirname($original));

        foreach (($metadata['sizes'] ?? []) as $sizeName => $size) {
            if (empty($size['file'])) {
                continue;
            }

            $sourcePaths[] = $directory . basename((string) $size['file']);
            if (wp_webp_size_enabled((string) $sizeName)) {
                $webpPaths[] = wp_webp_target_path($directory . basename((string) $size['file']));
            }
        }

        foreach ($webpPaths as $webpPath) {
            if ($webpPath === '' || !is_file($webpPath)) {
                $failures[] = 'Un WebP attendu manque après l’upload : ' . basename((string) $webpPath);
            }
        }
    }

    if (!function_exists('acf_add_local_field_group') || !function_exists('update_field')) {
        $failures[] = 'ACF n’est pas disponible.';
    } else {
        acf_add_local_field_group([
            'key'    => 'group_wp_webp_lifecycle',
            'title'  => 'WP WebP lifecycle contract',
            'fields' => [[
                'key'           => 'field_wp_webp_lifecycle_image',
                'label'         => 'Image',
                'name'          => 'wp_webp_lifecycle_image',
                'type'          => 'image',
                'return_format' => 'id',
            ]],
            'location' => [[[
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => 'post',
            ]]],
        ]);

        $postId = (int) wp_insert_post([
            'post_type'   => 'post',
            'post_status' => 'draft',
            'post_title'  => 'WP WebP lifecycle contract',
        ]);

        if (
            $postId <= 0
            || !update_field('field_wp_webp_lifecycle_image', $attachmentId, $postId)
            || (int) get_field('field_wp_webp_lifecycle_image', $postId) !== $attachmentId
        ) {
            $failures[] = 'L’ajout de l’attachement dans un champ image ACF a échoué.';
        }

        update_field('field_wp_webp_lifecycle_image', 0, $postId);
        if ((int) get_field('field_wp_webp_lifecycle_image', $postId) !== 0) {
            $failures[] = 'Le retrait de l’image du champ ACF a échoué.';
        }
        foreach (array_merge($sourcePaths, $webpPaths) as $path) {
            if ($path !== '' && !is_file($path)) {
                $failures[] = 'Retirer une image d’ACF a supprimé un fichier média.';
                break;
            }
        }
    }

    if ($attachmentId > 0) {
        wp_delete_attachment($attachmentId, true);
        $attachmentId = 0;

        foreach (array_merge($sourcePaths, $webpPaths) as $path) {
            if ($path !== '' && is_file($path)) {
                $failures[] = 'La suppression du média a laissé un fichier : ' . basename($path);
            }
        }
    }
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
} finally {
    if ($attachmentId > 0) {
        wp_delete_attachment($attachmentId, true);
    }
    if ($postId > 0) {
        wp_delete_post($postId, true);
    }
    if (is_file($temporary)) {
        @unlink($temporary);
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "WP WebP lifecycle: OK\n";
