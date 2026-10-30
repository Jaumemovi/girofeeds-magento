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
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * Constructor
     *
     * @param Filesystem $filesystem
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Filesystem $filesystem,
        StoreManagerInterface $storeManager
    ) {
        $this->filesystem = $filesystem;
        $this->storeManager = $storeManager;
    }

    /**
     * Update product images
     *
     * Images already in the gallery (same source) are skipped, so the caller only needs to save
     * the product when 'added' > 0.
     *
     * @param ProductInterface $product
     * @param array $resolvedData
     * @return array ['warnings' => string[], 'added' => int]
     */
    public function updateImages(ProductInterface $product, array $resolvedData)
    {
        $warnings = [];
        $added = 0;
        $existingFiles = $this->getExistingGalleryFiles($product);

        if (array_key_exists('image_link', $resolvedData)) {
            $imageUrl = $resolvedData['image_link'];
            $result = $this->processImage($product, (string) $imageUrl, true, $existingFiles);
            if ($result === true) {
                $added++;
            } elseif (is_string($result)) {
                $warnings[] = $result;
            }
        }

        if (array_key_exists('additional_images', $resolvedData) && is_array($resolvedData['additional_images'])) {
            foreach ($resolvedData['additional_images'] as $imageUrl) {
                $result = $this->processImage($product, (string) $imageUrl, false, $existingFiles);
                if ($result === true) {
                    $added++;
                } elseif (is_string($result)) {
                    $warnings[] = $result;
                }
            }
        }

        return ['warnings' => $warnings, 'added' => $added];
    }

    /**
     * Base file names (without extension) of the images already in the product gallery
     *
     * @param ProductInterface $product
     * @return array
     */
    private function getExistingGalleryFiles(ProductInterface $product)
    {
        $files = [];
        $gallery = $product->getData('media_gallery');
        if (!is_array($gallery) || empty($gallery['images']) || !is_array($gallery['images'])) {
            return $files;
        }

        foreach ($gallery['images'] as $image) {
            if (empty($image['file']) || !empty($image['removed'])) {
                continue;
            }
            $files[] = strtolower(pathinfo((string) $image['file'], PATHINFO_FILENAME));
        }

        return $files;
    }

    /**
     * Whether an image with this base name (or a Magento "_N" copy of it) is in the gallery
     *
     * @param string $baseName
     * @param array $existingFiles
     * @return bool
     */
    private function isInGallery($baseName, array $existingFiles)
    {
        $baseName = strtolower($baseName);
        foreach ($existingFiles as $file) {
            if ($file === $baseName || preg_match('/^' . preg_quote($baseName, '/') . '_\d+$/', $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process single image
     *
     * The file is named after a hash of its source (girofeeds_{md5}), so re-sending the same
     * URL or data finds it in the gallery and is skipped.
     *
     * @param ProductInterface $product
     * @param string $imageSource
     * @param bool $isMain
     * @param array $existingFiles gallery base names; images added here are appended
     * @return bool|string true when added, false when skipped (already present), warning otherwise
     */
    private function processImage(ProductInterface $product, $imageSource, $isMain, array &$existingFiles)
    {
        try {
            $storeMediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            if (strpos($imageSource, $storeMediaUrl) === 0) {
                return false;
            }

            $sourceBaseName = strtolower(pathinfo((string) parse_url($imageSource, PHP_URL_PATH), PATHINFO_FILENAME));
            if (strpos($imageSource, 'data:') !== 0 && $sourceBaseName !== ''
                && $this->isInGallery($sourceBaseName, $existingFiles)
            ) {
                return false;
            }

            $baseName = 'girofeeds_' . md5($imageSource);
            if ($this->isInGallery($baseName, $existingFiles)) {
                return false;
            }

            $tmpFilePath = null;

            if (strpos($imageSource, 'data:') === 0) {
                $tmpFilePath = $this->decodeBase64Image($imageSource, $baseName);
            } elseif (filter_var($imageSource, FILTER_VALIDATE_URL)) {
                $tmpFilePath = $this->downloadImage($imageSource, $baseName);
            } else {
                return "Invalid image source: not a valid URL or base64 data";
            }

            if (!$tmpFilePath) {
                return "Failed to process image: {$imageSource}";
            }

            $product->addImageToMediaGallery(
                $tmpFilePath,
                $isMain ? ['image', 'small_image', 'thumbnail'] : null,
                true,
                false
            );
            $existingFiles[] = $baseName;

            return true;
        } catch (\Exception $e) {
            return "Image error: " . $e->getMessage();
        }
    }

    /**
     * Temporary file path under pub/media (Gallery\Processor::addImage resolves files there)
     *
     * @param string $baseName
     * @param string $extension
     * @return string
     */
    private function getTmpFilePath($baseName, $extension)
    {
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $mediaDirectory->create('tmp/girofeeds');

        return $mediaDirectory->getAbsolutePath('tmp/girofeeds/' . $baseName . '.' . $extension);
    }

    /**
     * Decode base64 image
     *
     * @param string $dataUrl
     * @param string $baseName
     * @return string|null
     */
    private function decodeBase64Image($dataUrl, $baseName)
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

        $tmpFile = $this->getTmpFilePath($baseName, $extension);
        file_put_contents($tmpFile, $imageData);

        return $tmpFile;
    }

    /**
     * Download image from URL
     *
     * @param string $url
     * @param string $baseName
     * @return string|null
     */
    private function downloadImage($url, $baseName)
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

            $tmpFile = $this->getTmpFilePath($baseName, $extension);
            file_put_contents($tmpFile, $imageData);

            return $tmpFile;
        } catch (\Exception $e) {
            return null;
        }
    }
}
