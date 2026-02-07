<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Controller\Count;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Girofeeds\ProductApi\Api\CountInterface;
use Girofeeds\ProductApi\Helper\General as GeneralHelper;
use Magento\Framework\App\Response\Http as HttpResponse;

class Index extends Action
{

    private CountInterface $countModel;
    private GeneralHelper $generalHelper;
    private JsonFactory $resultJsonFactory;

    public function __construct(
        Context $context,
        CountInterface $countModel,
        GeneralHelper $generalHelper,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
        $this->countModel = $countModel;
        $this->generalHelper = $generalHelper;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $this->setNoCacheHeaders();

        $token = $this->getRequest()->getParam('key');

        if (empty($token)) {
            return $result->setData([
                'status' => 'error',
                'message' => 'Missing API key',
                'errors' => ['The key parameter is required'],
            ]);
        }

        if (!$this->generalHelper->getEnabled()) {
            return $result->setData([
                'status' => 'error',
                'message' => 'Module is not enabled',
                'errors' => ['Girofeeds ProductApi module is disabled'],
            ]);
        }

        if ($token !== $this->generalHelper->getToken()) {
            return $result->setData([
                'status' => 'error',
                'message' => 'Invalid API key',
                'errors' => ['The provided API key is invalid'],
            ]);
        }

        try {
            $responseJson = $this->countModel->getCount();
            $responseData = json_decode($responseJson, true);
            return $result->setData($responseData);
        } catch (\Exception $e) {
            $this->generalHelper->addTolog('Count', $e->getMessage());
            return $result->setData([
                'status' => 'error',
                'message' => 'Internal error',
                'errors' => [$e->getMessage()],
            ]);
        }
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
