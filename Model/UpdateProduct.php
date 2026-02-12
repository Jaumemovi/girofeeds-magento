<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Girofeeds\ProductApi\Api\UpdateProductInterface;
use Girofeeds\ProductApi\Helper\General as GeneralHelper;
use Girofeeds\ProductApi\Helper\Source as SourceHelper;
use Girofeeds\ProductApi\Model\Update\AttributeUpdater;
use Girofeeds\ProductApi\Model\Update\CategoryUpdater;
use Girofeeds\ProductApi\Model\Update\FieldProcessor;
use Girofeeds\ProductApi\Model\Update\ImageUpdater;
use Girofeeds\ProductApi\Model\Update\PriceUpdater;
use Girofeeds\ProductApi\Model\Update\StockUpdater;
use Girofeeds\ProductApi\Model\Update\ValidationService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

class UpdateProduct implements UpdateProductInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var GeneralHelper
     */
    private $generalHelper;

    /**
     * @var SourceHelper
     */
    private $sourceHelper;

    /**
     * @var FieldProcessor
     */
    private $fieldProcessor;

    /**
     * @var ValidationService
     */
    private $validationService;

    /**
     * @var AttributeUpdater
     */
    private $attributeUpdater;

    /**
     * @var PriceUpdater
     */
    private $priceUpdater;

    /**
     * @var StockUpdater
     */
    private $stockUpdater;

    /**
     * @var ImageUpdater
     */
    private $imageUpdater;

    /**
     * @var CategoryUpdater
     */
    private $categoryUpdater;

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
     * @param RequestInterface $request
     * @param ProductRepositoryInterface $productRepository
     * @param StoreManagerInterface $storeManager
     * @param GeneralHelper $generalHelper
     * @param SourceHelper $sourceHelper
     * @param FieldProcessor $fieldProcessor
     * @param ValidationService $validationService
     * @param AttributeUpdater $attributeUpdater
     * @param PriceUpdater $priceUpdater
     * @param StockUpdater $stockUpdater
     * @param ImageUpdater $imageUpdater
     * @param CategoryUpdater $categoryUpdater
     * @param ApiKeyValidator $apiKeyValidator
     * @param RateLimiter $rateLimiter
     */
    public function __construct(
        RequestInterface $request,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        GeneralHelper $generalHelper,
        SourceHelper $sourceHelper,
        FieldProcessor $fieldProcessor,
        ValidationService $validationService,
        AttributeUpdater $attributeUpdater,
        PriceUpdater $priceUpdater,
        StockUpdater $stockUpdater,
        ImageUpdater $imageUpdater,
        CategoryUpdater $categoryUpdater,
        ApiKeyValidator $apiKeyValidator,
        RateLimiter $rateLimiter
    ) {
        $this->request = $request;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->generalHelper = $generalHelper;
        $this->sourceHelper = $sourceHelper;
        $this->fieldProcessor = $fieldProcessor;
        $this->validationService = $validationService;
        $this->attributeUpdater = $attributeUpdater;
        $this->priceUpdater = $priceUpdater;
        $this->stockUpdater = $stockUpdater;
        $this->imageUpdater = $imageUpdater;
        $this->categoryUpdater = $categoryUpdater;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Execute product update
     *
     * @return string
     */
    public function execute(): string
    {
        $token = $this->request->getParam('key');

        $validation = $this->apiKeyValidator->validate($token);
        if (!$validation['valid']) {
            return $this->buildResponse('error', $validation['message'], [], [], [], null, [$validation['message']]);
        }

        if ($token && !$this->rateLimiter->isAllowed($token)) {
            return $this->buildResponse('error', 'Rate limit exceeded', [], [], [], null, ['Rate limit exceeded']);
        }

        try {
            $body = $this->request->getContent();
            $data = json_decode($body, true);

            if (!is_array($data) || empty($data['id_product'])) {
                return $this->buildResponse('error', 'Missing required field: id_product', [], [], [], null, ['id_product is required']);
            }

            $productId = (int) $data['id_product'];

            try {
                $product = $this->productRepository->getById($productId);
            } catch (NoSuchEntityException $e) {
                return $this->buildResponse(
                    'error',
                    "Product not found with ID {$productId}",
                    [],
                    [],
                    [],
                    null,
                    ["Product with entity_id {$productId} does not exist"]
                );
            }

            $storeCode = $this->request->getParam('store');
            if ($storeCode) {
                try {
                    $store = $this->storeManager->getStore($storeCode);
                    $product->setStoreId($store->getId());
                } catch (NoSuchEntityException $e) {
                    return $this->buildResponse(
                        'error',
                        "Store '{$storeCode}' not found",
                        [],
                        [],
                        [],
                        null,
                        ["Store with code '{$storeCode}' does not exist"]
                    );
                }
            }

            $resolved = $this->fieldProcessor->resolveFields($data);

            $validationErrors = $this->validationService->validate($resolved);
            if (!empty($validationErrors)) {
                return $this->buildResponse('error', 'Validation failed', [], [], [], null, $validationErrors);
            }

            $updatedFields = [];
            $warnings = [];
            $failedFields = [];

            $this->processTextAttributes($product, $resolved, $updatedFields);
            $this->processNumericAttributes($product, $resolved, $updatedFields);
            $this->processSelectAttributes($product, $resolved, $updatedFields, $warnings, $failedFields);
            $this->processIdentifierFields($product, $resolved, $updatedFields);
            $this->processStatusAndVisibility($product, $resolved, $updatedFields);
            $this->processCustomMappedAttributes($product, $resolved, $updatedFields);

            $priceWarnings = $this->priceUpdater->updatePrices($product, $resolved);
            $warnings = array_merge($warnings, $priceWarnings);
            foreach (['price', 'special_price', 'cost'] as $pf) {
                if (array_key_exists($pf, $resolved) && !in_array($pf, $failedFields)) {
                    $updatedFields[] = $pf;
                }
            }

            try {
                $this->productRepository->save($product);
            } catch (\Exception $e) {
                return $this->buildResponse(
                    'error',
                    "Failed to save product: " . $e->getMessage(),
                    [],
                    [],
                    [],
                    $productId,
                    [$e->getMessage()]
                );
            }

            $stockWarnings = $this->stockUpdater->updateStock($product, $resolved);
            $warnings = array_merge($warnings, $stockWarnings);
            foreach (['qty', 'is_in_stock', 'manage_stock', 'min_sale_qty', 'max_sale_qty', 'backorders'] as $sf) {
                if (array_key_exists($sf, $resolved)) {
                    $updatedFields[] = $sf;
                }
            }

            $categoryWarnings = $this->categoryUpdater->updateCategories($product, $resolved);
            $warnings = array_merge($warnings, $categoryWarnings);
            if (array_key_exists('category_ids', $resolved) || array_key_exists('category', $resolved)) {
                try {
                    $this->productRepository->save($product);
                } catch (\Exception $e) {
                    $warnings[] = "Category save failed: " . $e->getMessage();
                }
                $updatedFields[] = array_key_exists('category_ids', $resolved) ? 'category_ids' : 'category';
            }

            $imageWarnings = $this->imageUpdater->updateImages($product, $resolved);
            $warnings = array_merge($warnings, $imageWarnings);
            if (array_key_exists('image_link', $resolved)) {
                $updatedFields[] = 'image_link';
            }
            if (array_key_exists('additional_images', $resolved)) {
                $updatedFields[] = 'additional_images';
            }

            $updatedFields = array_values(array_unique($updatedFields));

            if (empty($warnings)) {
                return $this->buildResponse(
                    'success',
                    "Product {$productId} updated successfully",
                    $updatedFields,
                    [],
                    [],
                    $productId
                );
            }

            return $this->buildResponse(
                'success',
                "Product {$productId} updated with warnings",
                $updatedFields,
                $warnings,
                $failedFields,
                $productId
            );
        } catch (\Exception $e) {
            $this->generalHelper->addTolog('UpdateProduct', $e->getMessage());
            return $this->buildResponse('error', 'Internal error: ' . $e->getMessage(), [], [], [], null, [$e->getMessage()]);
        }
    }

    /**
     * Process text attributes
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @return void
     */
    private function processTextAttributes($product, array $resolved, array &$updatedFields)
    {
        $textFields = [
            'name', 'description', 'short_description',
            'meta_title', 'meta_description', 'meta_keyword',
            'url_key', 'condition', 'country_of_origin',
        ];

        foreach ($textFields as $field) {
            if (array_key_exists($field, $resolved)) {
                $magentoAttr = $field === 'country_of_origin' ? 'country_of_manufacture' : $field;
                $product->setData($magentoAttr, $resolved[$field]);
                $updatedFields[] = $field;
            }
        }
    }

    /**
     * Process numeric attributes
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @return void
     */
    private function processNumericAttributes($product, array $resolved, array &$updatedFields)
    {
        $numericFields = ['weight', 'length', 'width', 'height'];

        foreach ($numericFields as $field) {
            if (array_key_exists($field, $resolved)) {
                $product->setData($field, (float) $resolved[$field]);
                $updatedFields[] = $field;
            }
        }
    }

    /**
     * Process select attributes
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @param array $warnings
     * @param array $failedFields
     * @return void
     */
    private function processSelectAttributes(
        $product,
        array $resolved,
        array &$updatedFields,
        array &$warnings,
        array &$failedFields
    ) {
        $selectFields = [
            'color'    => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_COLOR_SOURCE),
            'size'     => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_SIZE_SOURCE),
            'material' => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_MATERIAL_SOURCE),
            'gender'   => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_GENDER_SOURCE),
            'brand'    => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_BRAND_SOURCE),
        ];

        foreach ($selectFields as $requestField => $magentoAttribute) {
            if (array_key_exists($requestField, $resolved) && $magentoAttribute) {
                $warning = $this->attributeUpdater->updateSelectAttribute(
                    $product,
                    $magentoAttribute,
                    (string) $resolved[$requestField]
                );
                if ($warning) {
                    $warnings[] = "Field '{$requestField}': {$warning}";
                    $failedFields[] = $requestField;
                } else {
                    $updatedFields[] = $requestField;
                }
            }
        }
    }

    /**
     * Process identifier fields
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @return void
     */
    private function processIdentifierFields($product, array $resolved, array &$updatedFields)
    {
        if (array_key_exists('sku', $resolved)) {
            $product->setSku($resolved['sku']);
            $updatedFields[] = 'sku';
        }

        if (array_key_exists('ean', $resolved)) {
            $eanAttribute = $this->sourceHelper->getStoreValue(SourceHelper::XPATH_EAN_SOURCE);
            if ($eanAttribute) {
                $product->setData($eanAttribute, $resolved['ean']);
                $updatedFields[] = 'ean';
            }
        }
    }

    /**
     * Process status and visibility fields
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @return void
     */
    private function processStatusAndVisibility($product, array $resolved, array &$updatedFields)
    {
        if (array_key_exists('status', $resolved)) {
            $status = $resolved['status'];
            if (is_bool($status) || in_array($status, ['true', 'false', '0', '1', 0, 1], true)) {
                $status = in_array($status, [true, 1, '1', 'true'], true) ? 1 : 2;
            }
            $product->setStatus((int) $status);
            $updatedFields[] = 'status';
        }

        if (array_key_exists('visibility', $resolved)) {
            $product->setVisibility((int) $resolved['visibility']);
            $updatedFields[] = 'visibility';
        }
    }

    /**
     * Process custom mapped attributes
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @return void
     */
    private function processCustomMappedAttributes($product, array $resolved, array &$updatedFields)
    {
        $extraFields = $this->sourceHelper->getExtraFields();
        if (empty($extraFields)) {
            return;
        }

        $reverseMappings = [];
        foreach ($extraFields as $key => $fieldConfig) {
            if (isset($fieldConfig['source'])) {
                $reverseMappings[$fieldConfig['label']] = $fieldConfig['source'];
            }
        }

        foreach ($resolved as $field => $value) {
            if (isset($reverseMappings[$field])) {
                $magentoAttr = $reverseMappings[$field];
                $product->setData($magentoAttr, $value);
                $updatedFields[] = $field;
            }
        }
    }

    /**
     * Build response array
     *
     * @param string $status
     * @param string $message
     * @param array $updatedFields
     * @param array $warnings
     * @param array $failedFields
     * @param int|null $productId
     * @param array $errors
     * @return string
     */
    private function buildResponse(
        $status,
        $message,
        array $updatedFields = [],
        array $warnings = [],
        array $failedFields = [],
        $productId = null,
        array $errors = []
    ) {
        $response = [
            'status' => $status,
            'message' => $message,
        ];

        if ($status === 'success') {
            $response['updated_fields'] = $updatedFields;
            $response['warnings'] = $warnings;
            if (!empty($failedFields)) {
                $response['failed_fields'] = $failedFields;
            }
            $response['product_id'] = $productId;
        } else {
            $response['errors'] = $errors;
        }

        return json_encode($response);
    }
}
