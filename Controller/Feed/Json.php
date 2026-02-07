<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Controller\Feed;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Girofeeds\ProductApi\Helper\Feed as FeedHelper;
use Girofeeds\ProductApi\Helper\General as GeneralHelper;
use Girofeeds\ProductApi\Model\Generate as GenerateModel;
use Magento\Framework\App\Response\Http as HttpResponse;

class Json extends Action
{

    private $generateModel;
    private $generalHelper;
    private $feedHelper;
    private $resultJsonFactory;
    private $remoteAddress;

    public function __construct(
        Context $context,
        GeneralHelper $generalHelper,
        GenerateModel $generateModel,
        FeedHelper $feedHelper,
        JsonFactory $resultJsonFactory,
        RemoteAddress $remoteAddress
    ) {
        parent::__construct($context);
        $this->generateModel = $generateModel;
        $this->generalHelper = $generalHelper;
        $this->feedHelper = $feedHelper;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->remoteAddress = $remoteAddress;
    }

    public function execute()
    {
        $storeId = (int)$this->getRequest()->getParam('id');
        $page = (int)$this->getRequest()->getParam('page', 1);
        $currency = $this->getRequest()->getParam('currency');
        $token = $this->getRequest()->getParam('token');

        $result = $this->resultJsonFactory->create();
        $this->setNoCacheHeaders();

        if (empty($storeId) || empty($token)) {
            return $result->setData(
                $this->buildErrorResponse('missing_params', 'Store ID and token are required.')
            );
        }

        if (!$this->generalHelper->getEnabled($storeId)) {
            return $result->setData(
                $this->buildErrorResponse('module_disabled', 'Module is not enabled for this store.')
            );
        }

        if ($token !== $this->generalHelper->getToken()) {
            return $result->setData(
                $this->buildErrorResponse('invalid_token', 'The provided token is invalid.')
            );
        }

        $productId = $this->getRequest()->getParam('pid');
        $productIds = $productId ? [$productId] : null;

        $ip = $this->remoteAddress->getRemoteAddress();
        $this->feedHelper->setLastFetched($storeId, $ip);

        try {
            $data = $this->generateModel->generateByStore($storeId, $page, $productIds, $currency);
            return $result->setData($data ?: []);
        } catch (\Exception $e) {
            $this->generalHelper->addTolog('Generate', $e->getMessage());
            return $result->setData(
                $this->buildErrorResponse('generation_error', $e->getMessage())
            );
        }
    }

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

    private function setNoCacheHeaders(): void
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $response->setHeader('Pragma', 'no-cache', true);
        $response->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT', true);
    }
}
