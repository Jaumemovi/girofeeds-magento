<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ApiKeyValidator
{

    private const XML_PATH_ENABLE = 'girofeeds_productapi/general/enable';
    private const XML_PATH_API_KEY = 'girofeeds_productapi/general/token';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Validate API key
     *
     * @param string|null $key
     * @param int|null $storeId
     * @return array
     */
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

    /**
     * Check if module is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    private function isModuleEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get stored API key
     *
     * @param int|null $storeId
     * @return string|null
     */
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
