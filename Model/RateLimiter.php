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

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Constructor
     *
     * @param CacheInterface $cache
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        CacheInterface $cache,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->cache = $cache;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Check if API key is allowed based on rate limiting
     *
     * @param string $apiKey
     * @return bool
     */
    public function isAllowed($apiKey)
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

    /**
     * Get maximum number of requests allowed
     *
     * @return int
     */
    public function getMaxRequests()
    {
        $configured = (int) $this->scopeConfig->getValue(self::XML_PATH_RATE_LIMIT);
        return $configured > 0 ? $configured : self::DEFAULT_MAX_REQUESTS;
    }

    /**
     * Get cache key for API key
     *
     * @param string $apiKey
     * @return string
     */
    private function getCacheKey($apiKey)
    {
        return self::CACHE_PREFIX . substr(hash('sha256', $apiKey), 0, 8);
    }
}
