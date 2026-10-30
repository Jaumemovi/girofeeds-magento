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
    const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    /** Mime types detected from the decoded bytes that Magento 2.3 accepts (jpg/jpeg/png/gif) */
    const ALLOWED_DETECTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif'];

    /**
     * Build the image fields for ImageUpdater
     *
     * The first valid image becomes 'image_link' (image/small_image/thumbnail), the rest go to
     * 'additional_images' in order. Invalid entries are skipped with a warning. The decoded bytes
     * must be a real JPEG, PNG or GIF image; WebP is rejected because Magento 2.3 does not accept
     * the extension (the Girofeeds server converts WebP to JPEG before sending).
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
            if ($mimeType === 'image/webp') {
                $warnings[] = 'image #' . $position . ': webp not supported by Magento 2.3';
                continue;
            }
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
            if ($decoded === false) {
                $warnings[] = 'image #' . $position . ': invalid base64 content';
                continue;
            }

            if (strlen($decoded) > self::MAX_DECODED_SIZE) {
                $warnings[] = 'image #' . $position . ': exceeds 10 MB';
                continue;
            }

            // '@': getimagesizefromstring() emits a notice on non-image data, which Magento turns
            // into an exception in developer mode
            $imageInfo = @getimagesizefromstring($decoded);
            $detectedMimeType = is_array($imageInfo) && isset($imageInfo['mime'])
                ? strtolower((string) $imageInfo['mime'])
                : '';
            if ($detectedMimeType === 'image/webp') {
                $warnings[] = 'image #' . $position . ': webp not supported by Magento 2.3';
                continue;
            }
            if (!in_array($detectedMimeType, self::ALLOWED_DETECTED_MIME_TYPES, true)) {
                $warnings[] = 'image #' . $position . ': not an image';
                continue;
            }

            // The detected mime type wins over the declared one so the file extension matches
            $dataUris[] = 'data:' . $detectedMimeType . ';base64,' . $content;
        }

        return [
            'image_link' => $dataUris ? array_shift($dataUris) : null,
            'additional_images' => $dataUris,
            'warnings' => $warnings,
        ];
    }
}
