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
use Magento\Catalog\Model\Indexer\Product\Category as CategoryProductIndexer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\CategoryLink;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Response as RestResponse;
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
     * @var RestResponse
     */
    private $restResponse;

    /**
     * @var ProductAction
     */
    private $productAction;

    /**
     * @var ProductResource
     */
    private $productResource;

    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @var CategoryLink
     */
    private $categoryLink;

    /**
     * @var IndexerRegistry
     */
    private $indexerRegistry;

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
     * @param RestResponse $restResponse
     * @param ProductAction $productAction
     * @param ProductResource $productResource
     * @param EavConfig $eavConfig
     * @param CategoryLink $categoryLink
     * @param IndexerRegistry $indexerRegistry
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
        RateLimiter $rateLimiter,
        RestResponse $restResponse,
        ProductAction $productAction,
        ProductResource $productResource,
        EavConfig $eavConfig,
        CategoryLink $categoryLink,
        IndexerRegistry $indexerRegistry
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
        $this->restResponse = $restResponse;
        $this->productAction = $productAction;
        $this->productResource = $productResource;
        $this->eavConfig = $eavConfig;
        $this->categoryLink = $categoryLink;
        $this->indexerRegistry = $indexerRegistry;
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
            return $this->buildResponse(
                'error',
                $validation['message'],
                [],
                [],
                [],
                null,
                [$validation['message']],
                (int)($validation['code'] ?? 401)
            );
        }

        if ($token && !$this->rateLimiter->isAllowed($token)) {
            return $this->buildResponse('error', 'Rate limit exceeded', [], [], [], null, ['Rate limit exceeded'], 429);
        }

        try {
            $body = $this->request->getContent();
            $data = json_decode($body, true);

            if (!is_array($data) || empty($data['id_product'])) {
                return $this->buildResponse(
                    'error',
                    'Missing required field: id_product',
                    [],
                    [],
                    [],
                    null,
                    ['id_product is required'],
                    400
                );
            }

            $productId = (int) $data['id_product'];

            // Write scope: `store` param / `store_id` payload, else the configured scope (default All Store Views)
            try {
                $storeId = $this->resolveWriteStoreId($data);
            } catch (NoSuchEntityException $e) {
                return $this->buildResponse(
                    'error',
                    $e->getMessage(),
                    [],
                    [],
                    [],
                    null,
                    [$e->getMessage()],
                    400
                );
            }
            unset($data['store_id']);

            try {
                $product = $this->productRepository->getById($productId, false, $storeId);
            } catch (NoSuchEntityException $e) {
                return $this->buildResponse(
                    'error',
                    "Product not found with ID {$productId}",
                    [],
                    [],
                    [],
                    null,
                    ["Product with entity_id {$productId} does not exist"],
                    404
                );
            }
            $product->setStoreId($storeId);

            $resolved = $this->fieldProcessor->resolveFields($data);

            $validationErrors = $this->validationService->validate($resolved);
            if (!empty($validationErrors)) {
                return $this->buildResponse('error', 'Validation failed', [], [], [], null, $validationErrors, 422);
            }

            $updatedFields = [];
            $warnings = [];
            $failedFields = [];
            // Attribute codes set on the product by the processors => request field name
            $touchedAttributes = [];

            $this->processTextAttributes($product, $resolved, $updatedFields, $touchedAttributes);
            $this->processNumericAttributes($product, $resolved, $updatedFields, $touchedAttributes);
            $this->processSelectAttributes(
                $product,
                $resolved,
                $updatedFields,
                $warnings,
                $failedFields,
                $touchedAttributes
            );
            $this->processIdentifierFields($product, $resolved, $updatedFields, $touchedAttributes);
            $this->processStatusAndVisibility($product, $resolved, $updatedFields, $touchedAttributes);
            $this->processCustomMappedAttributes($product, $resolved, $updatedFields, $touchedAttributes);

            $specialFromDate = $product->getData('special_from_date');
            $priceWarnings = $this->priceUpdater->updatePrices($product, $resolved);
            $warnings = array_merge($warnings, $priceWarnings);
            foreach (['price', 'special_price', 'cost'] as $pf) {
                if (array_key_exists($pf, $resolved) && !in_array($pf, $failedFields)) {
                    $updatedFields[] = $pf;
                    if ($pf !== 'price' || (float) $resolved['price'] > 0) {
                        $touchedAttributes[$pf] = $pf;
                    }
                }
            }
            if ($product->getData('special_from_date') !== $specialFromDate) {
                $touchedAttributes['special_from_date'] = 'special_price';
            }

            // Persist only the touched attributes at $storeId (no full product save)
            try {
                $this->saveAttributes(
                    $product,
                    $productId,
                    $storeId,
                    $touchedAttributes,
                    $updatedFields,
                    $warnings,
                    $failedFields
                );
            } catch (\Exception $e) {
                return $this->buildResponse(
                    'error',
                    "Failed to save product: " . $e->getMessage(),
                    [],
                    [],
                    [],
                    $productId,
                    [$e->getMessage()],
                    500
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
                $this->saveCategoryLinks($product, $warnings);
                $updatedFields[] = array_key_exists('category_ids', $resolved) ? 'category_ids' : 'category';
            }

            // Images need a product save (media gallery); only when the payload carries images
            if (array_key_exists('image_link', $resolved) || array_key_exists('additional_images', $resolved)) {
                $imageWarnings = $this->imageUpdater->updateImages($product, $resolved);
                $warnings = array_merge($warnings, $imageWarnings);
                try {
                    $product->setStoreId($storeId);
                    $this->productResource->save($product);
                } catch (\Exception $e) {
                    $warnings[] = "Image save failed: " . $e->getMessage();
                }
                if (array_key_exists('image_link', $resolved)) {
                    $updatedFields[] = 'image_link';
                }
                if (array_key_exists('additional_images', $resolved)) {
                    $updatedFields[] = 'additional_images';
                }
            }

            $updatedFields = array_values(array_unique($updatedFields));

            if (empty($warnings)) {
                return $this->buildResponse(
                    'success',
                    "Product {$productId} updated successfully",
                    $updatedFields,
                    [],
                    [],
                    $productId,
                    [],
                    200,
                    $storeId
                );
            }

            return $this->buildResponse(
                'success',
                "Product {$productId} updated with warnings",
                $updatedFields,
                $warnings,
                $failedFields,
                $productId,
                [],
                200,
                $storeId
            );
        } catch (\Exception $e) {
            $this->generalHelper->addTolog('UpdateProduct', $e->getMessage());
            return $this->buildResponse(
                'error',
                'Internal error: ' . $e->getMessage(),
                [],
                [],
                [],
                null,
                [$e->getMessage()],
                500
            );
        }
    }

    /**
     * Process text attributes
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @param array $touchedAttributes
     * @return void
     */
    private function processTextAttributes(
        $product,
        array $resolved,
        array &$updatedFields,
        array &$touchedAttributes
    ) {
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
                $touchedAttributes[$magentoAttr] = $field;
            }
        }
    }

    /**
     * Process numeric attributes
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @param array $touchedAttributes
     * @return void
     */
    private function processNumericAttributes(
        $product,
        array $resolved,
        array &$updatedFields,
        array &$touchedAttributes
    ) {
        $numericFields = ['weight', 'length', 'width', 'height'];

        foreach ($numericFields as $field) {
            if (array_key_exists($field, $resolved)) {
                $product->setData($field, (float) $resolved[$field]);
                $updatedFields[] = $field;
                $touchedAttributes[$field] = $field;
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
     * @param array $touchedAttributes
     * @return void
     */
    private function processSelectAttributes(
        $product,
        array $resolved,
        array &$updatedFields,
        array &$warnings,
        array &$failedFields,
        array &$touchedAttributes
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
                    $touchedAttributes[$magentoAttribute] = $requestField;
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
     * @param array $touchedAttributes
     * @return void
     */
    private function processIdentifierFields(
        $product,
        array $resolved,
        array &$updatedFields,
        array &$touchedAttributes
    ) {
        if (array_key_exists('sku', $resolved)) {
            $product->setSku($resolved['sku']);
            $updatedFields[] = 'sku';
            $touchedAttributes['sku'] = 'sku';
        }

        if (array_key_exists('ean', $resolved)) {
            $eanAttribute = $this->sourceHelper->getStoreValue(SourceHelper::XPATH_EAN_SOURCE);
            if ($eanAttribute) {
                $product->setData($eanAttribute, $resolved['ean']);
                $updatedFields[] = 'ean';
                $touchedAttributes[$eanAttribute] = 'ean';
            }
        }
    }

    /**
     * Process status and visibility fields
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @param array $touchedAttributes
     * @return void
     */
    private function processStatusAndVisibility(
        $product,
        array $resolved,
        array &$updatedFields,
        array &$touchedAttributes
    ) {
        if (array_key_exists('status', $resolved)) {
            $status = $resolved['status'];
            if (is_bool($status) || in_array($status, ['true', 'false', '0', '1', 0, 1], true)) {
                $status = in_array($status, [true, 1, '1', 'true'], true) ? 1 : 2;
            }
            $product->setStatus((int) $status);
            $updatedFields[] = 'status';
            $touchedAttributes['status'] = 'status';
        }

        if (array_key_exists('visibility', $resolved)) {
            $product->setVisibility((int) $resolved['visibility']);
            $updatedFields[] = 'visibility';
            $touchedAttributes['visibility'] = 'visibility';
        }
    }

    /**
     * Process custom mapped attributes
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $resolved
     * @param array $updatedFields
     * @param array $touchedAttributes
     * @return void
     */
    private function processCustomMappedAttributes(
        $product,
        array $resolved,
        array &$updatedFields,
        array &$touchedAttributes
    ) {
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
                $touchedAttributes[$magentoAttr] = $field;
            }
        }
    }

    /**
     * Resolve the store id updates are written to.
     *
     * The `store` request param or the `store_id` payload field win ('all', 'admin' or '0' => 0,
     * a store code or id => that store); otherwise the configured write scope ('all' => 0).
     * The store view of the REST route is never used.
     *
     * @param array $data
     * @return int
     * @throws NoSuchEntityException
     */
    private function resolveWriteStoreId(array $data): int
    {
        $requested = $this->request->getParam('store');
        if (($requested === null || $requested === '') && array_key_exists('store_id', $data)) {
            $requested = $data['store_id'];
        }

        if ($requested !== null && $requested !== '') {
            return $this->resolveStoreValue((string) $requested, 'Store');
        }

        return $this->resolveStoreValue($this->generalHelper->getWriteScope(), 'Configured write scope store');
    }

    /**
     * Map a scope value ('all', 'admin', '0', store code or id) to a store id
     *
     * @param string $value
     * @param string $label
     * @return int
     * @throws NoSuchEntityException
     */
    private function resolveStoreValue(string $value, string $label): int
    {
        $value = trim($value);
        if (in_array(strtolower($value), ['all', 'admin', '0'], true)) {
            return 0;
        }

        try {
            return (int) $this->storeManager->getStore($value)->getId();
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(__("%1 '%2' not found", $label, $value));
        }
    }

    /**
     * Write only the touched attributes at the given store id.
     *
     * EAV attributes go through Product\Action::updateAttributes (respects each attribute's
     * global/website/store scope and leaves the other attributes untouched). Static attributes
     * (sku) live in the entity table and are written there.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param int $productId
     * @param int $storeId
     * @param array $touchedAttributes
     * @param array $updatedFields
     * @param array $warnings
     * @param array $failedFields
     * @return void
     * @throws \Exception
     */
    private function saveAttributes(
        $product,
        int $productId,
        int $storeId,
        array $touchedAttributes,
        array &$updatedFields,
        array &$warnings,
        array &$failedFields
    ) {
        $attributeData = [];
        $staticData = [];

        foreach ($touchedAttributes as $code => $field) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            if (!$attribute || !$attribute->getId()) {
                $warnings[] = "Field '{$field}': attribute '{$code}' does not exist, value not saved";
                $failedFields[] = $field;
                $updatedFields = array_values(array_diff($updatedFields, [$field]));
                continue;
            }

            $value = $product->getData($code);
            if (is_array($value)) {
                $value = implode(',', $value);
            }

            if ($attribute->isStatic()) {
                $staticData[$code] = $value;
            } else {
                $attributeData[$code] = $value;
            }
        }

        // Checked before any write: a duplicate SKU fails the whole update, as the full save did
        if (array_key_exists('sku', $staticData)) {
            $existingId = $this->productResource->getIdBySku($staticData['sku']);
            if ($existingId && (int) $existingId !== $productId) {
                throw new LocalizedException(
                    __("SKU '%1' is already used by product %2", $staticData['sku'], $existingId)
                );
            }
        }

        if (!empty($staticData)) {
            $linkField = $this->productResource->getLinkField();
            $this->productResource->getConnection()->update(
                $this->productResource->getEntityTable(),
                $staticData,
                [$linkField . ' = ?' => $product->getData($linkField)]
            );
        }

        if (!empty($attributeData)) {
            $this->productAction->updateAttributes([$productId], $attributeData, $storeId);
        }
    }

    /**
     * Persist the product's category ids as category links (global, no product save)
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $warnings
     * @return void
     */
    private function saveCategoryLinks($product, array &$warnings)
    {
        try {
            $categoryIds = array_map('intval', (array) $product->getCategoryIds());

            $positions = [];
            foreach ($this->categoryLink->getCategoryLinks($product, $categoryIds) as $link) {
                $positions[(int) $link['category_id']] = (int) $link['position'];
            }

            $links = [];
            foreach ($categoryIds as $categoryId) {
                $links[] = [
                    'category_id' => $categoryId,
                    'position' => $positions[$categoryId] ?? 0,
                ];
            }

            $this->categoryLink->saveCategoryLinks($product, $links);
            // The links loaded with the product are stale now; a later product save (images)
            // must not merge them back in
            $extensionAttributes = $product->getExtensionAttributes();
            if ($extensionAttributes && method_exists($extensionAttributes, 'setCategoryLinks')) {
                $extensionAttributes->setCategoryLinks(null);
            }

            $indexer = $this->indexerRegistry->get(CategoryProductIndexer::INDEXER_ID);
            if (!$indexer->isScheduled()) {
                $indexer->reindexRow((int) $product->getId());
            }
        } catch (\Exception $e) {
            $warnings[] = "Category save failed: " . $e->getMessage();
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
     * @param int $httpCode
     * @param int|null $storeId effective write store id (success responses)
     * @return string
     */
    private function buildResponse(
        $status,
        $message,
        array $updatedFields = [],
        array $warnings = [],
        array $failedFields = [],
        $productId = null,
        array $errors = [],
        int $httpCode = 200,
        $storeId = null
    ) {
        $this->restResponse->setHttpResponseCode($httpCode);

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
            if ($storeId !== null) {
                $response['store_id'] = (int) $storeId;
                $response['scope'] = (int) $storeId === 0 ? 'all' : 'store';
            }
        } else {
            $response['errors'] = $errors;
        }

        return json_encode($response);
    }
}
