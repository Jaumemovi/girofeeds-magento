<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Create;

/**
 * Turns the createproduct request images ([{filename, mimeType, content(base64)}]) into the
 * data URIs that Model\Update\ImageUpdater::updateImages() understands.
 */
class CreationImages
{
    const MAX_IMAGES = 10;
    const MAX_DECODED_SIZE = 10485760;
    const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Build the image fields for ImageUpdater
     *
     * The first valid image becomes 'image_link' (image/small_image/thumbnail), the rest go to
     * 'additional_images' in order. Invalid entries are skipped with a warning.
     *
     * @param array $images [{filename, mimeType, content}]
     * @return array ['image_link' => string|null, 'additional_images' => string[], 'warnings' => string[]]
     */
    public function toResolvedFields(array $images)
    {
        $warnings = [];
        $dataUris = [];

        $images = array_values($images);
        if (count($images) > self::MAX_IMAGES) {
            $warnings[] = 'images: only the first ' . self::MAX_IMAGES . ' are imported';
            $images = array_slice($images, 0, self::MAX_IMAGES);
        }

        foreach ($images as $index => $image) {
            $position = $index + 1;

            if (!is_array($image)) {
                $warnings[] = 'image #' . $position . ': invalid entry';
                continue;
            }

            $mimeType = isset($image['mimeType']) && is_string($image['mimeType'])
                ? strtolower(trim($image['mimeType']))
                : '';
            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                $warnings[] = 'image #' . $position . ': unsupported mimeType';
                continue;
            }

            if (!isset($image['content']) || !is_string($image['content'])) {
                $warnings[] = 'image #' . $position . ': invalid base64 content';
                continue;
            }

            // ImageUpdater matches the data URI on a single line, so whitespace is dropped
            $content = preg_replace('/\s+/', '', $image['content']);
            $decoded = $content === '' ? false : base64_decode($content, true);
            if ($decoded === false || $decoded === '') {
                $warnings[] = 'image #' . $position . ': invalid base64 content';
                continue;
            }

            if (strlen($decoded) > self::MAX_DECODED_SIZE) {
                $warnings[] = 'image #' . $position . ': exceeds 10 MB';
                continue;
            }

            $dataUris[] = 'data:' . $mimeType . ';base64,' . $content;
        }

        return [
            'image_link' => $dataUris ? array_shift($dataUris) : null,
            'additional_images' => $dataUris,
            'warnings' => $warnings,
        ];
    }
}
