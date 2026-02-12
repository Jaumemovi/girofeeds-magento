<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Girofeeds\ProductApi\Api\FeedInterface;
use Girofeeds\ProductApi\Helper\Feed as FeedHelper;
use Girofeeds\ProductApi\Model\Generate as GenerateModel;
use Girofeeds\ProductApi\Model\ApiKeyValidator;
use Girofeeds\ProductApi\Model\RateLimiter;
use Girofeeds\ProductApi\Model\ApiLogger;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;

class Feed implements FeedInterface
{
    /**
     * @var GenerateModel
     */
    private $generateModel;

    /**
     * @var FeedHelper
     */
    private $feedHelper;

    /**
     * @var ApiKeyValidator
     */
    private $apiKeyValidator;

    /**
     * @var RateLimiter
     */
    private $rateLimiter;

    /**
     * @var ApiLogger
     */
    private $apiLogger;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * Constructor
     *
     * @param GenerateModel $generateModel
     * @param FeedHelper $feedHelper
     * @param ApiKeyValidator $apiKeyValidator
     * @param RateLimiter $rateLimiter
     * @param ApiLogger $apiLogger
     * @param RemoteAddress $remoteAddress
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        GenerateModel $generateModel,
        FeedHelper $feedHelper,
        ApiKeyValidator $apiKeyValidator,
        RateLimiter $rateLimiter,
        ApiLogger $apiLogger,
        RemoteAddress $remoteAddress,
        StoreManagerInterface $storeManager
    ) {
        $this->generateModel = $generateModel;
        $this->feedHelper = $feedHelper;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->rateLimiter = $rateLimiter;
        $this->apiLogger = $apiLogger;
        $this->remoteAddress = $remoteAddress;
        $this->storeManager = $storeManager;
    }

    /**
     * Get product feed
     *
     * @param string $key
     * @param string $store
     * @param int $limit
     * @param int $offset
     * @param string|null $pid
     * @param string|null $currency
     * @return array
     */
    public function getFeed(
        string $key,
        string $store,
        int $limit = 50,
        int $offset = 0,
        ?string $pid = null,
        ?string $currency = null
    ): array {
        $startTime = microtime(true);
        $ip = $this->remoteAddress->getRemoteAddress();
        $keyHash = ApiLogger::hashKey($key);

        try {
            $storeObj = $this->storeManager->getStore($store);
            $storeId = (int) $storeObj->getId();
        } catch (\Exception $e) {
            $this->apiLogger->logRequest('feed', $keyHash, $ip, 400, $this->elapsed($startTime));
            return $this->buildErrorResponse('invalid_store', 'Invalid store code: ' . $store);
        }

        $validation = $this->apiKeyValidator->validate($key, $storeId);
        if (!$validation['valid']) {
            $this->apiLogger->logRequest('feed', $keyHash, $ip, $validation['code'], $this->elapsed($startTime));
            return $this->buildErrorResponse('auth_error', $validation['message']);
        }

        if (!$this->rateLimiter->isAllowed($key)) {
            $this->apiLogger->logRequest('feed', $keyHash, $ip, 429, $this->elapsed($startTime));
            return $this->buildErrorResponse('rate_limit', 'Too many requests. Please try again later.');
        }

        $productIds = $pid ? [$pid] : null;
        $page = (int) floor($offset / max($limit, 1)) + 1;

        $this->feedHelper->setLastFetched($storeId, $ip);

        try {
            $data = $this->generateModel->generateByStore($storeId, $page, $productIds, $currency);
            $this->apiLogger->logRequest('feed', $keyHash, $ip, 200, $this->elapsed($startTime));

            if (!is_array($data)) {
                return [];
            }

            if ($limit > 0 && isset($data['products'])) {
                $startIndex = $offset % max($limit, 1);
                $data['products'] = array_slice($data['products'], $startIndex, $limit);
            }

            return $data;
        } catch (\Exception $e) {
            $this->apiLogger->logError('feed', $e->getMessage());
            $this->apiLogger->logRequest('feed', $keyHash, $ip, 500, $this->elapsed($startTime));
            return $this->buildErrorResponse('generation_error', $e->getMessage());
        }
    }

    /**
     * Build error response array
     *
     * @param string $code
     * @param string $message
     * @return array
     */
    private function buildErrorResponse(string $code, string $message): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * Calculate elapsed time in milliseconds
     *
     * @param float $startTime
     * @return float
     */
    private function elapsed(float $startTime): float
    {
        return (microtime(true) - $startTime) * 1000;
    }
}
