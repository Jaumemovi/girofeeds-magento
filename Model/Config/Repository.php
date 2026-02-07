<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Config;

use Girofeeds\ProductApi\Api\Config\RepositoryInterface;

class Repository extends System\FeedRepository implements RepositoryInterface
{

    public function getExtensionVersion(): string
    {
        return $this->getStoreValue(self::XML_PATH_EXTENSION_VERSION);
    }

    public function getStoreCurrencyCode(): string
    {
        try {
            return $this->getStore()->getCurrentCurrency()->getCode();
        } catch (\Exception $e) {
            return 'EUR';
        }
    }

    public function getMagentoVersion(): string
    {
        return $this->metadata->getVersion();
    }

    public function getMagentoEdition(): string
    {
        return $this->metadata->getEdition();
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE);
    }
}
