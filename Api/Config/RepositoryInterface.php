<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Api\Config;

interface RepositoryInterface
{

    public const EXTENSION_CODE = 'Girofeeds_ProductApi';

    public const XML_PATH_EXTENSION_VERSION = 'girofeeds_productapi/general/version';
    public const XML_PATH_ENABLE = 'girofeeds_productapi/general/enable';

    public function getExtensionVersion(): string;

    public function getMagentoVersion(): string;

    public function getMagentoEdition(): string;

    public function isEnabled(): bool;

    public function getToken(): ?string;

    public function setToken(?string $token);

    public function getStoreCurrencyCode(): string;

    public function isBundleStockCalculationEnabled(?int $storeId = null): bool;
}
