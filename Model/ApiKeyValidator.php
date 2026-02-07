<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ApiKeyValidator
{

    private const XML_PATH_ENABLE = 'girofeeds_productapi/general/enable';
    private const XML_PATH_API_KEY = 'girofeeds_productapi/general/token';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function validate(?string $key, ?int $storeId = null): array
    {
        if (!$this->isModuleEnabled($storeId)) {
            return [
                'valid' => false,
                'code' => 403,
                'message' => 'Module is not enabled',
            ];
        }

        if (empty($key)) {
            return [
                'valid' => false,
                'code' => 401,
                'message' => 'Missing API key',
            ];
        }

        $storedKey = $this->getStoredApiKey($storeId);

        if (empty($storedKey) || !hash_equals($storedKey, $key)) {
            return [
                'valid' => false,
                'code' => 401,
                'message' => 'Invalid API key',
            ];
        }

        return ['valid' => true, 'code' => 200, 'message' => ''];
    }

    private function isModuleEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function getStoredApiKey(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value ? (string) $value : null;
    }
}
