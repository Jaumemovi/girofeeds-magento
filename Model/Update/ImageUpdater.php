<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Update;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;

class ImageUpdater
{

    private const MAX_FILE_SIZE = 10485760;
    private const DOWNLOAD_TIMEOUT = 15;
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    private Filesystem $filesystem;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Filesystem $filesystem,
        StoreManagerInterface $storeManager
    ) {
        $this->filesystem = $filesystem;
        $this->storeManager = $storeManager;
    }

    public function updateImages(ProductInterface $product, array $resolvedData): array
    {
        $warnings = [];

        if (array_key_exists('image_link', $resolvedData)) {
            $imageUrl = $resolvedData['image_link'];
            $warning = $this->processImage($product, (string) $imageUrl, true);
            if ($warning) {
                $warnings[] = $warning;
            }
        }

        if (array_key_exists('additional_images', $resolvedData) && is_array($resolvedData['additional_images'])) {
            foreach ($resolvedData['additional_images'] as $imageUrl) {
                $warning = $this->processImage($product, (string) $imageUrl, false);
                if ($warning) {
                    $warnings[] = $warning;
                }
            }
        }

        return $warnings;
    }

    private function processImage(ProductInterface $product, string $imageSource, bool $isMain): ?string
    {
        try {
            $storeMediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            if (strpos($imageSource, $storeMediaUrl) === 0) {
                return null;
            }

            $tmpFilePath = null;

            if (strpos($imageSource, 'data:') === 0) {
                $tmpFilePath = $this->decodeBase64Image($imageSource);
            } elseif (filter_var($imageSource, FILTER_VALIDATE_URL)) {
                $tmpFilePath = $this->downloadImage($imageSource);
            } else {
                return "Invalid image source: not a valid URL or base64 data";
            }

            if (!$tmpFilePath) {
                return "Failed to process image: {$imageSource}";
            }

            $product->addImageToMediaGallery(
                $tmpFilePath,
                $isMain ? ['image', 'small_image', 'thumbnail'] : null,
                false,
                false
            );

            return null;
        } catch (\Exception $e) {
            return "Image error: " . $e->getMessage();
        }
    }

    private function decodeBase64Image(string $dataUrl): ?string
    {
        if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $dataUrl, $matches)) {
            return null;
        }

        $extension = $matches[1];
        $base64Data = $matches[2];

        if (!in_array('image/' . $extension, array_keys(self::ALLOWED_MIME_TYPES))) {
            return null;
        }

        $imageData = base64_decode($base64Data, true);

        if (!$imageData || strlen($imageData) > self::MAX_FILE_SIZE) {
            return null;
        }

        $tmpDir = $this->filesystem->getDirectoryRead(DirectoryList::TMP)->getAbsolutePath();
        $tmpFile = $tmpDir . 'girofeeds_' . uniqid() . '.' . $extension;
        file_put_contents($tmpFile, $imageData);

        return $tmpFile;
    }

    private function downloadImage(string $url): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::DOWNLOAD_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'Girofeeds-ImageImport/1.0',
            ]);

            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            curl_close($ch);

            if ($httpCode !== 200 || !$imageData) {
                return null;
            }

            if ($downloadSize > self::MAX_FILE_SIZE) {
                return null;
            }

            $contentType = strtolower(trim(explode(';', (string) $contentType)[0]));
            $extension = self::ALLOWED_MIME_TYPES[$contentType] ?? 'jpg';

            $tmpDir = $this->filesystem->getDirectoryRead(DirectoryList::TMP)->getAbsolutePath();
            $tmpFile = $tmpDir . 'girofeeds_' . uniqid() . '.' . $extension;
            file_put_contents($tmpFile, $imageData);

            return $tmpFile;
        } catch (\Exception $e) {
            return null;
        }
    }
}
