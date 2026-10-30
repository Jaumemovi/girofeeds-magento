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
use Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException;

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

        $sku = null;
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
                    $this->getExistingSku($existingId, $sku),
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

            // Build and save. A url_key collision detected by the URL rewrite storage on save (e.g. in
            // a store view or with a category/CMS path) is retried once with the SKU suffix, on a
            // freshly built product (the rolled-back one may hold a stale id and gallery state).
            $baseWarnings = $warnings;
            $baseFailedFields = $failedFields;
            $urlKeyCandidates = $this->resolveUrlKeyCandidates($sku, $resolved);
            $urlKey = $urlKeyCandidates['key'];
            $attempt = 0;
            while (true) {
                $warnings = $baseWarnings;
                $failedFields = $baseFailedFields;
                if ($attempt > 0) {
                    $warnings[] = 'url_key: collision, suffixed';
                }

                $build = $this->buildProduct($resolved, $images, $attributeSetId, $urlKey, $warnings, $failedFields);
                /** @var \Magento\Catalog\Model\Product $product */
                $product = $build['product'];
                $imagesAdded = $build['images_added'];
                $imagesFailed = $build['images_failed'];

                try {
                    $product->setStoreId(self::ADMIN_STORE_ID);
                    $this->productResource->save($product);
                    break;
                } catch (UrlAlreadyExistsException $e) {
                    if ($attempt === 0 && $urlKeyCandidates['retry'] !== null) {
                        $this->generalHelper->addTolog(
                            'CreateProduct',
                            "url_key '{$urlKey}' collision on save, retrying suffixed: " . $e->getMessage()
                        );
                        $urlKey = $urlKeyCandidates['retry'];
                        $attempt++;
                        continue;
                    }
                    return $this->buildSaveFailedResponse($e, $sku);
                } catch (\Exception $e) {
                    return $this->buildSaveFailedResponse($e, $sku);
                }
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
                $sku,
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
     * New simple product at store 0 with every requested field, images and categories (not saved)
     *
     * @param array $resolved
     * @param mixed $images raw `images` from the request, or null
     * @param int $attributeSetId
     * @param string $urlKey
     * @param array $warnings
     * @param array $failedFields
     * @return array ['product' => Product, 'images_added' => int, 'images_failed' => int]
     */
    private function buildProduct(
        array $resolved,
        $images,
        int $attributeSetId,
        string $urlKey,
        array &$warnings,
        array &$failedFields
    ): array {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->productFactory->create();
        $product->setStoreId(self::ADMIN_STORE_ID);
        $product->setTypeId('simple');
        $product->setAttributeSetId($attributeSetId);
        $product->setSku($resolved['sku']);
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
        $product->setUrlKey($urlKey);

        $this->processIdentifierFields($product, $resolved);
        $this->processSelectAttributes($product, $resolved, $warnings, $failedFields);

        $warnings = array_merge($warnings, $this->priceUpdater->updatePrices($product, $resolved));

        // Images: base64 entries become data URIs for ImageUpdater (before the save)
        $creationFailed = 0;
        if ($images !== null) {
            // A single image object (has `content`) instead of a list
            $imageList = is_array($images) && !array_key_exists('content', $images) ? $images : [$images];
            $imageFields = $this->creationImages->toResolvedFields($imageList);
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

        // Categories: `category_ids` (normalized to ints) replace, then a `category` path appends.
        // CategoryUpdater handles only one of the two per call, so each goes in its own call.
        if (array_key_exists('category_ids', $resolved)) {
            $categoryIds = $this->normalizeCategoryIds($resolved['category_ids'], $warnings);
            $warnings = array_merge(
                $warnings,
                $this->categoryUpdater->updateCategories(
                    $product,
                    ['category_ids' => $categoryIds],
                    self::ADMIN_STORE_ID
                )
            );
        }
        if (array_key_exists('category', $resolved)) {
            $warnings = array_merge(
                $warnings,
                $this->categoryUpdater->updateCategories(
                    $product,
                    ['category' => $resolved['category']],
                    self::ADMIN_STORE_ID
                )
            );
        }

        return [
            'product' => $product,
            'images_added' => $imagesAdded,
            'images_failed' => $imagesFailed,
        ];
    }

    /**
     * Normalize `category_ids` to a list of ints
     *
     * Accepts a list, a single scalar (12) or a comma-separated string ("12,15"). Empty entries
     * are dropped; non-numeric ones are skipped with a warning.
     *
     * @param mixed $raw
     * @param array $warnings
     * @return int[]
     */
    private function normalizeCategoryIds($raw, array &$warnings): array
    {
        if (is_array($raw)) {
            $entries = $raw;
        } elseif (is_string($raw)) {
            $entries = explode(',', $raw);
        } elseif (is_scalar($raw)) {
            $entries = [$raw];
        } else {
            $entries = [];
        }

        $categoryIds = [];
        foreach ($entries as $entry) {
            if (!is_scalar($entry) || is_bool($entry)) {
                $warnings[] = 'category_ids: invalid entry';
                continue;
            }
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if (!ctype_digit($entry) || (int) $entry <= 0) {
                $warnings[] = "category_ids: invalid id '{$entry}'";
                continue;
            }
            $categoryIds[] = (int) $entry;
        }

        return array_values(array_unique($categoryIds));
    }

    /**
     * Error response for a failed save (logged)
     *
     * @param \Exception $e
     * @param string $sku
     * @return string
     */
    private function buildSaveFailedResponse(\Exception $e, string $sku): string
    {
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
     * Actual SKU of an existing product (a match by EAN may have a different SKU)
     *
     * @param int $productId
     * @param string $fallback
     * @return string
     */
    private function getExistingSku(int $productId, string $fallback): string
    {
        try {
            $value = $this->productResource->getAttributeRawValue($productId, 'sku', self::ADMIN_STORE_ID);
        } catch (\Exception $e) {
            return $fallback;
        }
        if (is_array($value)) {
            $value = isset($value['sku']) ? $value['sku'] : null;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $fallback;
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
     * Website ids from the request or the configured default, keeping only existing websites;
     * when none remain, the default store view's website (then the current website)
     *
     * @param array $resolved
     * @param array $warnings
     * @return int[]
     */
    private function resolveWebsiteIds(array $resolved, array &$warnings): array
    {
        $knownIds = $this->getKnownWebsiteIds();

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
            $websiteIds = $this->filterKnownWebsiteIds($websiteIds, $knownIds, $warnings);
        }

        if (empty($websiteIds)) {
            $websiteIds = $this->filterKnownWebsiteIds(
                $this->generalHelper->getCreateWebsiteIds(),
                $knownIds,
                $warnings
            );
        }

        if (empty($websiteIds)) {
            try {
                $defaultStore = $this->storeManager->getDefaultStoreView();
                $websiteId = $defaultStore !== null
                    ? (int) $defaultStore->getWebsiteId()
                    : (int) $this->storeManager->getWebsite()->getId();
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
     * Ids of the existing websites (admin excluded), or null if they cannot be read
     *
     * @return int[]|null
     */
    private function getKnownWebsiteIds()
    {
        try {
            $ids = [];
            foreach ($this->storeManager->getWebsites() as $website) {
                $ids[] = (int) $website->getId();
            }

            return $ids;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Drop website ids that do not exist, with a warning for each one
     *
     * @param int[] $websiteIds
     * @param int[]|null $knownIds null = no check
     * @param array $warnings
     * @return int[]
     */
    private function filterKnownWebsiteIds(array $websiteIds, $knownIds, array &$warnings): array
    {
        if ($knownIds === null) {
            return $websiteIds;
        }

        $valid = [];
        foreach ($websiteIds as $websiteId) {
            if (in_array((int) $websiteId, $knownIds, true)) {
                $valid[] = (int) $websiteId;
            } else {
                $warnings[] = 'website_ids: unknown id ' . (int) $websiteId;
            }
        }

        return $valid;
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
     * url_key from the request or the name; if already used at store 0, suffixed with the SKU.
     * `retry` is the suffixed key for a collision found only on save (null when the key is
     * already suffixed or there is no SKU key).
     *
     * @param string $sku
     * @param array $resolved
     * @return array ['key' => string, 'retry' => string|null]
     */
    private function resolveUrlKeyCandidates(string $sku, array $resolved): array
    {
        $source = array_key_exists('url_key', $resolved) ? (string) $resolved['url_key'] : (string) $resolved['name'];
        $key = (string) $this->productUrl->formatUrlKey($source);
        $skuKey = (string) $this->productUrl->formatUrlKey($sku);

        if ($key === '') {
            return ['key' => $skuKey, 'retry' => null];
        }

        $suffixed = $skuKey !== '' ? $key . '-' . $skuKey : null;

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId(self::ADMIN_STORE_ID);
        $collection->addAttributeToFilter('url_key', $key);
        $collection->setPageSize(1);

        if ($collection->getSize() > 0 && $suffixed !== null) {
            return ['key' => $suffixed, 'retry' => null];
        }

        return ['key' => $key, 'retry' => $suffixed];
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
