<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Controller\Feed;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Girofeeds\ProductApi\Helper\Feed as FeedHelper;
use Girofeeds\ProductApi\Model\Generate as GenerateModel;
use Girofeeds\ProductApi\Model\ApiKeyValidator;
use Girofeeds\ProductApi\Model\RateLimiter;
use Girofeeds\ProductApi\Model\ApiLogger;
use Magento\Framework\App\Response\Http as HttpResponse;

class Json extends Action
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
     * @param GenerateModel $generateModel
     * @param FeedHelper $feedHelper
     * @param JsonFactory $resultJsonFactory
     * @param RemoteAddress $remoteAddress
     * @param ApiKeyValidator $apiKeyValidator
     * @param RateLimiter $rateLimiter
     * @param ApiLogger $apiLogger
     */
    public function __construct(
        Context $context,
        GenerateModel $generateModel,
        FeedHelper $feedHelper,
        JsonFactory $resultJsonFactory,
        RemoteAddress $remoteAddress,
        ApiKeyValidator $apiKeyValidator,
        RateLimiter $rateLimiter,
        ApiLogger $apiLogger
    ) {
        parent::__construct($context);
        $this->generateModel = $generateModel;
        $this->feedHelper = $feedHelper;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->remoteAddress = $remoteAddress;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->rateLimiter = $rateLimiter;
        $this->apiLogger = $apiLogger;
    }

    /**
     * Execute feed generation
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $startTime = microtime(true);
        $storeId = (int) $this->getRequest()->getParam('id');
        $page = (int) $this->getRequest()->getParam('page', 1);
        $currency = $this->getRequest()->getParam('currency');
        $token = $this->getRequest()->getParam('token');
        $ip = $this->remoteAddress->getRemoteAddress();
        $keyHash = ApiLogger::hashKey($token);

        $result = $this->resultJsonFactory->create();
        $this->setNoCacheHeaders();

        if (empty($storeId) || empty($token)) {
            $this->apiLogger->logRequest('feed', $keyHash, $ip, 400, $this->elapsed($startTime));
            return $result->setData(
                $this->buildErrorResponse('missing_params', 'Store ID and token are required.')
            );
        }

        $validation = $this->apiKeyValidator->validate($token, $storeId);
        if (!$validation['valid']) {
            $this->apiLogger->logRequest('feed', $keyHash, $ip, $validation['code'], $this->elapsed($startTime));
            return $result->setData(
                $this->buildErrorResponse('auth_error', $validation['message'])
            );
        }

        if (!$this->rateLimiter->isAllowed($token)) {
            $this->apiLogger->logRequest('feed', $keyHash, $ip, 429, $this->elapsed($startTime));
            return $result->setData(
                $this->buildErrorResponse('rate_limit', 'Too many requests. Please try again later.')
            );
        }

        $productId = $this->getRequest()->getParam('pid');
        $productIds = $productId ? [$productId] : null;

        $this->feedHelper->setLastFetched($storeId, $ip);

        try {
            $data = $this->generateModel->generateByStore($storeId, $page, $productIds, $currency);
            $this->apiLogger->logRequest('feed', $keyHash, $ip, 200, $this->elapsed($startTime));
            return $result->setData($data ?: []);
        } catch (\Exception $e) {
            $this->apiLogger->logError('feed', $e->getMessage());
            $this->apiLogger->logRequest('feed', $keyHash, $ip, 500, $this->elapsed($startTime));
            return $result->setData(
                $this->buildErrorResponse('generation_error', $e->getMessage())
            );
        }
    }

    /**
     * Build error response array
     *
     * @param string $code
     * @param string $message
     * @return array
     */
    private function buildErrorResponse($code, $message)
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
