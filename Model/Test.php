<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Girofeeds\ProductApi\Api\TestInterface;
use Girofeeds\ProductApi\Helper\General as GeneralHelper;
use Girofeeds\ProductApi\Helper\Source as SourceHelper;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

class Test implements TestInterface
{
    /**
     * @var GeneralHelper
     */
    private $generalHelper;

    /**
     * @var SourceHelper
     */
    private $sourceHelper;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Generate
     */
    private $generateModel;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ApiKeyValidator
     */
    private $apiKeyValidator;

    /**
     * @var RateLimiter
     */
    private $rateLimiter;

    /**
     * Constructor
     *
     * @param GeneralHelper $generalHelper
     * @param SourceHelper $sourceHelper
     * @param ProductMetadataInterface $productMetadata
     * @param StoreManagerInterface $storeManager
     * @param Generate $generateModel
     * @param RequestInterface $request
     * @param ApiKeyValidator $apiKeyValidator
     * @param RateLimiter $rateLimiter
     */
    public function __construct(
        GeneralHelper $generalHelper,
        SourceHelper $sourceHelper,
        ProductMetadataInterface $productMetadata,
        StoreManagerInterface $storeManager,
        Generate $generateModel,
        RequestInterface $request,
        ApiKeyValidator $apiKeyValidator,
        RateLimiter $rateLimiter
    ) {
        $this->generalHelper = $generalHelper;
        $this->sourceHelper = $sourceHelper;
        $this->productMetadata = $productMetadata;
        $this->storeManager = $storeManager;
        $this->generateModel = $generateModel;
        $this->request = $request;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Execute test endpoint logic
     *
     * @return string
     */
    public function test(): string
    {
        $token = $this->request->getParam('key');

        $validation = $this->apiKeyValidator->validate($token);
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
            $storeId = (int) $this->storeManager->getStore()->getId();
            $storeCode = $this->storeManager->getStore()->getCode();
            $productCount = $this->generateModel->getSize($storeId);

            $extraFields = $this->sourceHelper->getExtraFields();
            $extraMappedFields = [];
            foreach ($extraFields as $fieldConfig) {
                if (isset($fieldConfig['label'])) {
                    $extraMappedFields[] = $fieldConfig['label'];
                }
            }

            $response = [
                'status' => 'success',
                'version' => $this->generalHelper->getExtensionVersion() ?: '2.0.0',
                'magento_version' => $this->productMetadata->getVersion(),
                'store' => $storeCode,
                'product_count' => (int) $productCount,
                'module_name' => 'Girofeeds_ProductApi',
                'supported_fields' => $this->getSupportedFields(),
                'extra_mapped_fields' => $extraMappedFields,
                'write_scope' => $this->generalHelper->getWriteScope(),
                'write_scope_capability' => true,
            ];

            return json_encode($response);
        } catch (\Exception $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get list of supported fields
     *
     * @return array
     */
    private function getSupportedFields()
    {
        return [
            'name',
            'description',
            'short_description',
            'price',
            'special_price',
            'qty',
            'status',
            'visibility',
            'brand',
            'sku',
            'ean',
            'gtin',
            'meta_title',
            'meta_description',
            'meta_keyword',
            'url_key',
            'color',
            'size',
            'material',
            'gender',
            'weight',
            'image',
            'image_link',
            'additional_images',
            'category_ids',
            'category',
            'active',
            'stock',
            'reference',
            'ean13',
            'specific_price',
            'visible',
            'cost',
            'is_in_stock',
            'manage_stock',
            'min_sale_qty',
            'max_sale_qty',
            'backorders',
            'condition',
            'country_of_origin',
            'length',
            'width',
            'height',
        ];
    }
}
