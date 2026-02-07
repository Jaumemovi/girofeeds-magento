<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Girofeeds\ProductApi\Api\CountInterface;
use Girofeeds\ProductApi\Model\Generate;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Count implements CountInterface
{

    private Generate $generateModel;
    private RequestInterface $request;
    private StoreManagerInterface $storeManager;
    private ApiKeyValidator $apiKeyValidator;
    private RateLimiter $rateLimiter;

    public function __construct(
        Generate $generateModel,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        ApiKeyValidator $apiKeyValidator,
        RateLimiter $rateLimiter
    ) {
        $this->generateModel = $generateModel;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->rateLimiter = $rateLimiter;
    }

    public function getCount(): string
    {
        $token = $this->request->getParam('key');
        $storeId = $this->resolveStoreId();

        $validation = $this->apiKeyValidator->validate($token, $storeId);
        if (!$validation['valid']) {
            return json_encode([
                'status' => 'error',
                'message' => $validation['message'],
            ]);
        }

        if ($token && !$this->rateLimiter->isAllowed($token)) {
            return json_encode([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
            ]);
        }

        try {
            $count = $this->generateModel->getSize($storeId);
            return json_encode(['count' => (int) $count]);
        } catch (\Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveStoreId(): int
    {
        $storeCode = $this->request->getParam('store');

        if ($storeCode) {
            try {
                $store = $this->storeManager->getStore($storeCode);
                return (int) $store->getId();
            } catch (NoSuchEntityException $e) {
                throw new \InvalidArgumentException("Store '{$storeCode}' not found");
            }
        }

        return (int) $this->storeManager->getStore()->getId();
    }
}
