<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Girofeeds\ProductApi\Api\CreateProductInterface;
use Girofeeds\ProductApi\Helper\General as GeneralHelper;
use Girofeeds\ProductApi\Helper\Source as SourceHelper;
use Girofeeds\ProductApi\Model\Create\CreationImages;
use Girofeeds\ProductApi\Model\Update\AttributeUpdater;
use Girofeeds\ProductApi\Model\Update\CategoryUpdater;
use Girofeeds\ProductApi\Model\Update\FieldProcessor;
use Girofeeds\ProductApi\Model\Update\ImageUpdater;
use Girofeeds\ProductApi\Model\Update\PriceUpdater;
use Girofeeds\ProductApi\Model\Update\StockUpdater;
use Girofeeds\ProductApi\Model\Update\ValidationService;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Model\Indexer\Product\Eav\Processor as EavIndexerProcessor;
use Magento\Catalog\Model\Indexer\Product\Flat\Processor as FlatIndexerProcessor;
use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexerProcessor;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor as FulltextIndexerProcessor;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Webapi\Rest\Response as RestResponse;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Creates one simple product per request, always at store 0 (All Store Views).
 *
 * Reuses the updateproduct services (aliases, validation, select attributes, prices, categories,
 * images, stock). Idempotent by SKU and, when an EAN attribute is configured, by EAN.
 */
class CreateProduct implements CreateProductInterface
{
    const ADMIN_STORE_ID = 0;
    const WARNING_MAX_DATA_URI_LENGTH = 80;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ProductInterfaceFactory
     */
    private $productFactory;

    /**
     * @var ProductResource
     */
    private $productResource;

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
     * @var CreationImages
     */
    private $creationImages;

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
     * @var ProductUrl
     */
    private $productUrl;

    /**
     * @var AttributeSetCollectionFactory
     */
    private $attributeSetCollectionFactory;

    /**
     * @var PriceIndexerProcessor
     */
    private $priceIndexerProcessor;

    /**
     * @var EavIndexerProcessor
     */
    private $eavIndexerProcessor;

    /**
     * @var FlatIndexerProcessor
     */
    private $flatIndexerProcessor;

    /**
     * @var FulltextIndexerProcessor
     */
    private $fulltextIndexerProcessor;

    /**
     * @var ProductCollectionFactory
     */
    private $productCollectionFactory;

    /**
     * Constructor
     *
     * @param RequestInterface $request
     * @param ProductInterfaceFactory $productFactory
     * @param ProductResource $productResource
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
     * @param CreationImages $creationImages
     * @param ApiKeyValidator $apiKeyValidator
     * @param RateLimiter $rateLimiter
     * @param RestResponse $restResponse
     * @param ProductUrl $productUrl
     * @param AttributeSetCollectionFactory $attributeSetCollectionFactory
     * @param PriceIndexerProcessor $priceIndexerProcessor
     * @param EavIndexerProcessor $eavIndexerProcessor
     * @param FlatIndexerProcessor $flatIndexerProcessor
     * @param FulltextIndexerProcessor $fulltextIndexerProcessor
     * @param ProductCollectionFactory $productCollectionFactory
     */
    public function __construct(
        RequestInterface $request,
        ProductInterfaceFactory $productFactory,
        ProductResource $productResource,
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
        CreationImages $creationImages,
        ApiKeyValidator $apiKeyValidator,
        RateLimiter $rateLimiter,
        RestResponse $restResponse,
        ProductUrl $productUrl,
        AttributeSetCollectionFactory $attributeSetCollectionFactory,
        PriceIndexerProcessor $priceIndexerProcessor,
        EavIndexerProcessor $eavIndexerProcessor,
        FlatIndexerProcessor $flatIndexerProcessor,
        FulltextIndexerProcessor $fulltextIndexerProcessor,
        ProductCollectionFactory $productCollectionFactory
    ) {
        $this->request = $request;
        $this->productFactory = $productFactory;
        $this->productResource = $productResource;
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
        $this->creationImages = $creationImages;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->rateLimiter = $rateLimiter;
        $this->restResponse = $restResponse;
        $this->productUrl = $productUrl;
        $this->attributeSetCollectionFactory = $attributeSetCollectionFactory;
        $this->priceIndexerProcessor = $priceIndexerProcessor;
        $this->eavIndexerProcessor = $eavIndexerProcessor;
        $this->flatIndexerProcessor = $flatIndexerProcessor;
        $this->fulltextIndexerProcessor = $fulltextIndexerProcessor;
        $this->productCollectionFactory = $productCollectionFactory;
    }

    /**
     * Execute product creation
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
                null,
                null,
                null,
                [],
                [],
                [$validation['message']],
                null,
                (int) ($validation['code'] ?? 401)
            );
        }

        if ($token && !$this->rateLimiter->isAllowed($token)) {
            return $this->buildResponse(
                'error',
                'Rate limit exceeded',
                null,
                null,
                null,
                [],
                [],
                ['Rate limit exceeded'],
                null,
                429
            );
        }

        try {
            $data = json_decode((string) $this->request->getContent(), true);
            if (!is_array($data)) {
                return $this->buildResponse('error', 'Invalid JSON', null, null, null, [], [], ['Invalid JSON'], null, 400);
            }

            $warnings = [];
            $failedFields = [];

            // Creation always happens at store 0; a requested store is only reported
            $requestedStore = $this->request->getParam('store');
            if (($requestedStore !== null && $requestedStore !== '')
                || array_key_exists('store', $data)
                || array_key_exists('store_id', $data)
            ) {
                $warnings[] = 'store: ignored on create (products are created at store 0)';
            }
            unset($data['store'], $data['store_id']);

            $images = null;
            if (array_key_exists('images', $data)) {
                $images = $data['images'];
                unset($data['images']);
            }

            $resolved = $this->fieldProcessor->resolveFields($data);

            $missing = [];
            foreach (['sku', 'name'] as $requiredField) {
                if (!isset($resolved[$requiredField]) || !is_scalar($resolved[$requiredField])
                    || trim((string) $resolved[$requiredField]) === ''
                ) {
                    $missing[] = 'missing: ' . $requiredField;
                }
            }
            if (!array_key_exists('price', $resolved) || $resolved['price'] === null || $resolved['price'] === '') {
                $missing[] = 'missing: price';
            }
            if (!empty($missing)) {
                return $this->buildResponse('error', 'Validation failed', null, null, null, [], [], $missing, null, 422);
            }

            $resolved['sku'] = trim((string) $resolved['sku']);
            $resolved['name'] = trim((string) $resolved['name']);
            $sku = $resolved['sku'];

            if (array_key_exists('url_key', $resolved)) {
                $formattedUrlKey = is_scalar($resolved['url_key'])
                    ? (string) $this->productUrl->formatUrlKey((string) $resolved['url_key'])
                    : '';
                if ($formattedUrlKey === '') {
                    unset($resolved['url_key']);
                } else {
                    $resolved['url_key'] = $formattedUrlKey;
                }
            }

            $validationErrors = $this->validationService->validate($resolved);
            if (!empty($validationErrors)) {
                return $this->buildResponse('error', 'Validation failed', null, $sku, null, [], [], $validationErrors, null, 422);
            }

            // Idempotency, before any write
            $existingId = $this->findExistingProductId($resolved, $warnings);
            if ($existingId !== null) {
                return $this->buildResponse(
                    'exists',
                    "Product already exists with ID {$existingId}",
                    $existingId,
                    $sku,
                    $this->getExistingUrlKey($existingId),
                    $this->sanitizeWarnings($warnings),
                    [],
                    [],
                    null,
                    200
                );
            }

            $attributeSetId = $this->resolveAttributeSetId($resolved);
            if ($attributeSetId === null) {
                return $this->buildResponse(
                    'error',
                    'attribute set not found',
                    null,
                    $sku,
                    null,
                    [],
                    [],
                    ['attribute set not found'],
                    null,
                    422
                );
            }

            /** @var \Magento\Catalog\Model\Product $product */
            $product = $this->productFactory->create();
            $product->setStoreId(self::ADMIN_STORE_ID);
            $product->setTypeId('simple');
            $product->setAttributeSetId($attributeSetId);
            $product->setSku($sku);
            $product->setName($resolved['name']);
            $product->setPrice((float) $resolved['price']);
            $product->setStatus($this->normalizeStatus(array_key_exists('status', $resolved) ? $resolved['status'] : 1));
            $product->setVisibility(
                array_key_exists('visibility', $resolved)
                    ? (int) $resolved['visibility']
                    : $this->generalHelper->getCreateVisibility()
            );
            $product->setWebsiteIds($this->resolveWebsiteIds($resolved, $warnings));
            $product->setTaxClassId($this->resolveTaxClassId($resolved, $warnings));
            if (array_key_exists('weight', $resolved)) {
                $product->setWeight((float) $resolved['weight']);
            }
            if (array_key_exists('description', $resolved)) {
                $product->setDescription($resolved['description']);
            }
            if (array_key_exists('short_description', $resolved)) {
                $product->setShortDescription($resolved['short_description']);
            }
            $urlKey = $this->resolveUrlKey($product, $resolved);
            $product->setUrlKey($urlKey);

            $this->processIdentifierFields($product, $resolved);
            $this->processSelectAttributes($product, $resolved, $warnings, $failedFields);

            $warnings = array_merge($warnings, $this->priceUpdater->updatePrices($product, $resolved));

            // Images: base64 entries become data URIs for ImageUpdater (before the save)
            $creationFailed = 0;
            if ($images !== null) {
                $imageFields = $this->creationImages->toResolvedFields(is_array($images) ? $images : [$images]);
                foreach ($imageFields['warnings'] as $imageWarning) {
                    $warnings[] = $imageWarning;
                    if (strpos($imageWarning, 'image #') === 0) {
                        $creationFailed++;
                    }
                }
                if ($imageFields['image_link'] !== null) {
                    $resolved['image_link'] = $imageFields['image_link'];
                } else {
                    unset($resolved['image_link']);
                }
                if (!empty($imageFields['additional_images'])) {
                    $resolved['additional_images'] = $imageFields['additional_images'];
                } else {
                    unset($resolved['additional_images']);
                }
            }
            if (array_key_exists('image_link', $resolved)
                && (!is_scalar($resolved['image_link']) || trim((string) $resolved['image_link']) === '')
            ) {
                unset($resolved['image_link']);
            }

            $imagesAdded = 0;
            $imagesFailed = $creationFailed;
            if (array_key_exists('image_link', $resolved) || array_key_exists('additional_images', $resolved)) {
                $imageResult = $this->imageUpdater->updateImages($product, $resolved);
                $imagesAdded = (int) $imageResult['added'];
                $imagesFailed += count($imageResult['warnings']);
                $warnings = array_merge($warnings, $imageResult['warnings']);
            }

            if (array_key_exists('category_ids', $resolved) || array_key_exists('category', $resolved)) {
                $warnings = array_merge(
                    $warnings,
                    $this->categoryUpdater->updateCategories($product, $resolved, self::ADMIN_STORE_ID)
                );
            }

            try {
                $product->setStoreId(self::ADMIN_STORE_ID);
                $this->productResource->save($product);
            } catch (\Exception $e) {
                $this->generalHelper->addTolog('CreateProduct', $e->getMessage());
                return $this->buildResponse(
                    'error',
                    'Failed to save product: ' . $e->getMessage(),
                    null,
                    $sku,
                    null,
                    [],
                    [],
                    [$e->getMessage()],
                    null,
                    500
                );
            }

            $productId = (int) $product->getId();

            // Stock after the save (the stock item needs the product id)
            if (!array_key_exists('qty', $resolved)) {
                $resolved['qty'] = 0;
            }
            if (!array_key_exists('manage_stock', $resolved)) {
                $resolved['manage_stock'] = 1;
            }
            $warnings = array_merge($warnings, $this->stockUpdater->updateStock($product, $resolved));

            $this->reindexProduct($productId, $warnings);

            $warnings = $this->sanitizeWarnings($warnings);

            return $this->buildResponse(
                'created',
                empty($warnings)
                    ? "Product {$productId} created successfully"
                    : "Product {$productId} created with warnings",
                $productId,
                $sku,
                (string) ($product->getUrlKey() ?: $urlKey),
                $warnings,
                array_values(array_unique($failedFields)),
                [],
                ['added' => $imagesAdded, 'failed' => $imagesFailed],
                200
            );
        } catch (\Exception $e) {
            $this->generalHelper->addTolog('CreateProduct', $e->getMessage());
            return $this->buildResponse(
                'error',
                'Internal error: ' . $e->getMessage(),
                null,
                null,
                null,
                [],
                [],
                [$e->getMessage()],
                null,
                500
            );
        }
    }

    /**
     * Existing product id for the request SKU or, when an EAN attribute is configured and the
     * request has an EAN, for that EAN
     *
     * @param array $resolved
     * @param array $warnings
     * @return int|null
     */
    private function findExistingProductId(array $resolved, array &$warnings)
    {
        $existingId = $this->productResource->getIdBySku($resolved['sku']);
        if ($existingId) {
            return (int) $existingId;
        }

        if (!array_key_exists('ean', $resolved) || !is_scalar($resolved['ean'])
            || trim((string) $resolved['ean']) === ''
        ) {
            return null;
        }

        $eanAttribute = $this->sourceHelper->getStoreValue(SourceHelper::XPATH_EAN_SOURCE);
        if (!$eanAttribute) {
            return null;
        }

        try {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId(self::ADMIN_STORE_ID);
            $collection->addAttributeToFilter($eanAttribute, trim((string) $resolved['ean']));
            $collection->setPageSize(1);
            $item = $collection->getFirstItem();
            if ($item && $item->getId()) {
                return (int) $item->getId();
            }
        } catch (\Exception $e) {
            $warnings[] = "ean: lookup by attribute '{$eanAttribute}' failed: " . $e->getMessage();
        }

        return null;
    }

    /**
     * url_key of an existing product at store 0
     *
     * @param int $productId
     * @return string|null
     */
    private function getExistingUrlKey(int $productId)
    {
        try {
            $value = $this->productResource->getAttributeRawValue($productId, 'url_key', self::ADMIN_STORE_ID);
        } catch (\Exception $e) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Attribute set id from the request (`attribute_set` name or id) or the configured default.
     * Returns null when the set does not exist for catalog_product.
     *
     * @param array $resolved
     * @return int|null
     */
    private function resolveAttributeSetId(array $resolved)
    {
        $requested = array_key_exists('attribute_set', $resolved) ? $resolved['attribute_set'] : null;
        if ($requested === null || (is_string($requested) && trim($requested) === '')) {
            $requested = $this->generalHelper->getCreateAttributeSetId();
        }
        if (!is_scalar($requested)) {
            return null;
        }

        $collection = $this->attributeSetCollectionFactory->create();
        $collection->setEntityTypeFilter((int) $this->productResource->getTypeId());

        $requested = trim((string) $requested);
        if (ctype_digit($requested)) {
            $collection->addFieldToFilter('attribute_set_id', (int) $requested);
        } else {
            $collection->addFieldToFilter('attribute_set_name', $requested);
        }
        $collection->setPageSize(1);

        $set = $collection->getFirstItem();
        if (!$set || !$set->getId()) {
            return null;
        }

        return (int) $set->getId();
    }

    /**
     * Website ids from the request or the configured default (then the current website)
     *
     * @param array $resolved
     * @param array $warnings
     * @return int[]
     */
    private function resolveWebsiteIds(array $resolved, array &$warnings): array
    {
        $websiteIds = [];
        if (array_key_exists('website_ids', $resolved)) {
            $raw = $resolved['website_ids'];
            if (is_string($raw)) {
                $raw = explode(',', $raw);
            } elseif (!is_array($raw)) {
                $raw = [$raw];
            }
            foreach ($raw as $value) {
                if (is_scalar($value) && (int) $value > 0 && !in_array((int) $value, $websiteIds, true)) {
                    $websiteIds[] = (int) $value;
                }
            }
        }

        if (empty($websiteIds)) {
            $websiteIds = $this->generalHelper->getCreateWebsiteIds();
        }

        if (empty($websiteIds)) {
            try {
                $websiteId = (int) $this->storeManager->getWebsite()->getId();
                if ($websiteId > 0) {
                    $websiteIds = [$websiteId];
                }
            } catch (\Exception $e) {
                $websiteIds = [];
            }
        }

        if (empty($websiteIds)) {
            $warnings[] = 'website_ids: none resolved';
        }

        return $websiteIds;
    }

    /**
     * Tax class id from the request or the configured default. The id is not looked up: an
     * unusable value falls back to the default with a warning.
     *
     * @param array $resolved
     * @param array $warnings
     * @return int
     */
    private function resolveTaxClassId(array $resolved, array &$warnings): int
    {
        if (!array_key_exists('tax_class_id', $resolved)) {
            return $this->generalHelper->getCreateTaxClassId();
        }

        $value = $resolved['tax_class_id'];
        if (is_scalar($value) && is_numeric($value) && (int) $value >= 0) {
            return (int) $value;
        }

        $default = $this->generalHelper->getCreateTaxClassId();
        $warnings[] = "tax_class_id: invalid value, default {$default} used";

        return $default;
    }

    /**
     * Status 1 (enabled) / 2 (disabled) from the accepted request values
     *
     * @param mixed $status
     * @return int
     */
    private function normalizeStatus($status): int
    {
        if (in_array($status, [true, 1, '1', 'true'], true)) {
            return 1;
        }
        if (in_array($status, [false, 2, '2', 'false', '0', 0], true)) {
            return 2;
        }

        return (int) $status === 2 ? 2 : 1;
    }

    /**
     * url_key from the request or the name; if already used at store 0, suffixed with the SKU
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $resolved
     * @return string
     */
    private function resolveUrlKey($product, array $resolved): string
    {
        $source = array_key_exists('url_key', $resolved) ? (string) $resolved['url_key'] : (string) $resolved['name'];
        $key = (string) $this->productUrl->formatUrlKey($source);
        $skuKey = (string) $this->productUrl->formatUrlKey((string) $product->getSku());

        if ($key === '') {
            return $skuKey;
        }

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId(self::ADMIN_STORE_ID);
        $collection->addAttributeToFilter('url_key', $key);
        $collection->setPageSize(1);

        if ($collection->getSize() > 0 && $skuKey !== '') {
            return $key . '-' . $skuKey;
        }

        return $key;
    }

    /**
     * EAN to the configured EAN attribute (same as UpdateProduct)
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $resolved
     * @return void
     */
    private function processIdentifierFields($product, array $resolved)
    {
        if (array_key_exists('ean', $resolved)) {
            $eanAttribute = $this->sourceHelper->getStoreValue(SourceHelper::XPATH_EAN_SOURCE);
            if ($eanAttribute) {
                $product->setData($eanAttribute, $resolved['ean']);
            }
        }
    }

    /**
     * Select attributes (brand/color/size/material/gender); options are never created
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $resolved
     * @param array $warnings
     * @param array $failedFields
     * @return void
     */
    private function processSelectAttributes($product, array $resolved, array &$warnings, array &$failedFields)
    {
        $selectFields = [
            'color'    => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_COLOR_SOURCE),
            'size'     => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_SIZE_SOURCE),
            'material' => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_MATERIAL_SOURCE),
            'gender'   => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_GENDER_SOURCE),
            'brand'    => $this->sourceHelper->getStoreValue(SourceHelper::XPATH_BRAND_SOURCE),
        ];

        foreach ($selectFields as $requestField => $magentoAttribute) {
            if (!array_key_exists($requestField, $resolved) || !$magentoAttribute) {
                continue;
            }
            $value = $resolved[$requestField];
            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $warning = $this->attributeUpdater->updateSelectAttribute(
                $product,
                $magentoAttribute,
                (string) $value,
                self::ADMIN_STORE_ID
            );
            if ($warning) {
                $warnings[] = "Field '{$requestField}': {$warning}";
                $failedFields[] = $requestField;
            }
        }
    }

    /**
     * Reindex the new product (price, EAV, flat, fulltext), skipping indexers in
     * "Update by Schedule" mode. Failures become warnings.
     *
     * @param int $productId
     * @param array $warnings
     * @return void
     */
    private function reindexProduct(int $productId, array &$warnings)
    {
        $processors = [
            'price' => $this->priceIndexerProcessor,
            'eav' => $this->eavIndexerProcessor,
            'flat' => $this->flatIndexerProcessor,
            'fulltext' => $this->fulltextIndexerProcessor,
        ];

        foreach ($processors as $name => $processor) {
            try {
                if (!$processor->isIndexerScheduled()) {
                    $processor->reindexRow($productId);
                }
            } catch (\Exception $e) {
                $warnings[] = "Reindex ({$name}) failed: " . $e->getMessage();
            }
        }
    }

    /**
     * Shorten warnings that embed a data URI (ImageUpdater echoes the image source)
     *
     * @param array $warnings
     * @return array
     */
    private function sanitizeWarnings(array $warnings): array
    {
        $sanitized = [];
        foreach ($warnings as $warning) {
            $warning = (string) $warning;
            if (strpos($warning, 'data:image') !== false) {
                $short = function_exists('mb_substr')
                    ? mb_substr($warning, 0, self::WARNING_MAX_DATA_URI_LENGTH)
                    : substr($warning, 0, self::WARNING_MAX_DATA_URI_LENGTH);
                $warning = $short . '…';
            }
            $sanitized[] = $warning;
        }

        return $sanitized;
    }

    /**
     * Build response JSON (spec §14.4)
     *
     * @param string $status created|exists|error
     * @param string $message
     * @param int|null $productId
     * @param string|null $sku
     * @param string|null $urlKey
     * @param array $warnings
     * @param array $failedFields
     * @param array $errors
     * @param array|null $images ['added' => int, 'failed' => int]
     * @param int $httpCode
     * @return string
     */
    private function buildResponse(
        $status,
        $message,
        $productId = null,
        $sku = null,
        $urlKey = null,
        array $warnings = [],
        array $failedFields = [],
        array $errors = [],
        $images = null,
        int $httpCode = 200
    ) {
        $this->restResponse->setHttpResponseCode($httpCode);

        $response = [
            'status' => $status,
            'message' => $message,
        ];

        if ($status === 'created' || $status === 'exists') {
            $response['product_id'] = $productId !== null ? (int) $productId : null;
            $response['sku'] = $sku;
            $response['url_key'] = $urlKey;
            $response['store_id'] = self::ADMIN_STORE_ID;
            $response['warnings'] = $warnings;
            if (!empty($failedFields)) {
                $response['failed_fields'] = $failedFields;
            }
            $response['images'] = is_array($images) ? $images : ['added' => 0, 'failed' => 0];
        } else {
            if ($sku !== null) {
                $response['sku'] = $sku;
            }
            $response['errors'] = $errors;
        }

        return (string) json_encode($response);
    }
}
