<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class RateLimiter
{

    private const CACHE_PREFIX = 'girofeeds_rate_';
    private const TTL_SECONDS = 60;
    private const XML_PATH_RATE_LIMIT = 'girofeeds_productapi/general/rate_limit';
    private const DEFAULT_MAX_REQUESTS = 60;

    private CacheInterface $cache;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        CacheInterface $cache,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->cache = $cache;
        $this->scopeConfig = $scopeConfig;
    }

    public function isAllowed(string $apiKey): bool
    {
        $cacheKey = $this->getCacheKey($apiKey);
        $current = (int) $this->cache->load($cacheKey);
        $maxRequests = $this->getMaxRequests();

        if ($current >= $maxRequests) {
            return false;
        }

        $this->cache->save((string) ($current + 1), $cacheKey, [], self::TTL_SECONDS);
        return true;
    }

    public function getMaxRequests(): int
    {
        $configured = (int) $this->scopeConfig->getValue(self::XML_PATH_RATE_LIMIT);
        return $configured > 0 ? $configured : self::DEFAULT_MAX_REQUESTS;
    }

    private function getCacheKey(string $apiKey): string
    {
        return self::CACHE_PREFIX . substr(hash('sha256', $apiKey), 0, 8);
    }
}
