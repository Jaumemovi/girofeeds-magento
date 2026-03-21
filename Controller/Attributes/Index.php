<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Controller\Attributes;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Girofeeds\ProductApi\Api\AttributesInterface;
use Girofeeds\ProductApi\Model\ApiKeyValidator;
use Girofeeds\ProductApi\Model\RateLimiter;
use Girofeeds\ProductApi\Model\ApiLogger;
use Magento\Framework\App\Response\Http as HttpResponse;

class Index extends Action
{
    /**
     * @var AttributesInterface
     */
    private $attributesModel;

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
     * @param AttributesInterface $attributesModel
     * @param JsonFactory $resultJsonFactory
     * @param RemoteAddress $remoteAddress
     * @param ApiKeyValidator $apiKeyValidator
     * @param RateLimiter $rateLimiter
     * @param ApiLogger $apiLogger
     */
    public function __construct(
        Context $context,
        AttributesInterface $attributesModel,
        JsonFactory $resultJsonFactory,
        RemoteAddress $remoteAddress,
        ApiKeyValidator $apiKeyValidator,
        RateLimiter $rateLimiter,
        ApiLogger $apiLogger
    ) {
        parent::__construct($context);
        $this->attributesModel = $attributesModel;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->remoteAddress = $remoteAddress;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->rateLimiter = $rateLimiter;
        $this->apiLogger = $apiLogger;
    }

    /**
     * Execute attributes endpoint
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
            $this->apiLogger->logRequest('attributes', $keyHash, $ip, $validation['code'], $this->elapsed($startTime));
            return $result->setData([
                'status' => 'error',
                'message' => $validation['message'],
            ]);
        }

        if (!$this->rateLimiter->isAllowed($token)) {
            $this->apiLogger->logRequest('attributes', $keyHash, $ip, 429, $this->elapsed($startTime));
            return $result->setData([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
            ]);
        }

        try {
            $responseJson = $this->attributesModel->getAttributes();
            $responseData = json_decode($responseJson, true);
            $this->apiLogger->logRequest('attributes', $keyHash, $ip, 200, $this->elapsed($startTime));
            return $result->setData($responseData);
        } catch (\Exception $e) {
            $this->apiLogger->logError('attributes', $e->getMessage());
            $this->apiLogger->logRequest('attributes', $keyHash, $ip, 500, $this->elapsed($startTime));
            return $result->setData([
                'status' => 'error',
                'message' => 'Internal error',
            ]);
        }
    }

    /**
     * Set no-cache headers on the response
     *
     * @return void
     */
    private function setNoCacheHeaders()
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
    private function elapsed($startTime)
    {
        return (microtime(true) - $startTime) * 1000;
    }
}
