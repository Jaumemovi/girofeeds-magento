<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Api\Config;

interface RepositoryInterface
{

    public const EXTENSION_CODE = 'Girofeeds_ProductApi';

    public const XML_PATH_EXTENSION_VERSION = 'girofeeds_productapi/general/version';
    public const XML_PATH_ENABLE = 'girofeeds_productapi/general/enable';

    /**
     * Get extension version
     *
     * @return string
     */
    public function getExtensionVersion(): string;

    /**
     * Get Magento version
     *
     * @return string
     */
    public function getMagentoVersion(): string;

    /**
     * Get Magento edition
     *
     * @return string
     */
    public function getMagentoEdition(): string;

    /**
     * Check if module is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Get API key
     *
     * @return string|null
     */
    public function getApiKey(): ?string;

    /**
     * Set API key
     *
     * @param string|null $apiKey
     * @return void
     */
    public function setApiKey(?string $apiKey);

    /**
     * Get store currency code
     *
     * @return string
     */
    public function getStoreCurrencyCode(): string;

    /**
     * Check if bundle stock calculation is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isBundleStockCalculationEnabled(?int $storeId = null): bool;
}
