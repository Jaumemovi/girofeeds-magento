<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Controller\Count;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Girofeeds\ProductApi\Api\CountInterface;
use Girofeeds\ProductApi\Model\ApiKeyValidator;
use Girofeeds\ProductApi\Model\RateLimiter;
use Girofeeds\ProductApi\Model\ApiLogger;
use Magento\Framework\App\Response\Http as HttpResponse;

class Index extends Action
{

    /**
     * @var CountInterface
     */
    private $countModel;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

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
     * Constructor
     *
     * @param Context $context
     * @param CountInterface $countModel
     * @param JsonFactory $resultJsonFactory
     * @param RemoteAddress $remoteAddress
     * @param ApiKeyValidator $apiKeyValidator
     * @param RateLimiter $rateLimiter
     * @param ApiLogger $apiLogger
     */
    public function __construct(
        Context $context,
        CountInterface $countModel,
        JsonFactory $resultJsonFactory,
        RemoteAddress $remoteAddress,
        ApiKeyValidator $apiKeyValidator,
        RateLimiter $rateLimiter,
        ApiLogger $apiLogger
    ) {
        parent::__construct($context);
        $this->countModel = $countModel;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->remoteAddress = $remoteAddress;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->rateLimiter = $rateLimiter;
        $this->apiLogger = $apiLogger;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $startTime = microtime(true);
        $result = $this->resultJsonFactory->create();
        $this->setNoCacheHeaders();

        $token = $this->getRequest()->getParam('key');
        $ip = $this->remoteAddress->getRemoteAddress();
        $keyHash = ApiLogger::hashKey($token);

        $validation = $this->apiKeyValidator->validate($token);
        if (!$validation['valid']) {
            $this->apiLogger->logRequest('count', $keyHash, $ip, $validation['code'], $this->elapsed($startTime));
            return $result->setData([
                'status' => 'error',
                'message' => $validation['message'],
            ]);
        }

        if (!$this->rateLimiter->isAllowed($token)) {
            $this->apiLogger->logRequest('count', $keyHash, $ip, 429, $this->elapsed($startTime));
            return $result->setData([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
            ]);
        }

        try {
            $responseJson = $this->countModel->getCount();
            $responseData = json_decode($responseJson, true);
            $this->apiLogger->logRequest('count', $keyHash, $ip, 200, $this->elapsed($startTime));
            return $result->setData($responseData);
        } catch (\Exception $e) {
            $this->apiLogger->logError('count', $e->getMessage());
            $this->apiLogger->logRequest('count', $keyHash, $ip, 500, $this->elapsed($startTime));
            return $result->setData([
                'status' => 'error',
                'message' => 'Internal error',
            ]);
        }
    }

    /**
     * Set no-cache headers
     *
     * @return void
     */
    private function setNoCacheHeaders(): void
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $response->setHeader('Pragma', 'no-cache', true);
        $response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
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
