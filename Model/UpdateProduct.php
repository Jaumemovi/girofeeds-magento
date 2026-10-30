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
use Magento\Catalog\Model\Indexer\Product\Eav\Processor as EavIndexerProcessor;
use Magento\Catalog\Model\Indexer\Product\Flat\Processor as FlatIndexerProcessor;
use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexerProcessor;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor as FulltextIndexerProcessor;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\RequestInterface;
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
     * @var ProductUrl
     */
    private $productUrl;

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
     * @param ProductUrl $productUrl
     * @param PriceIndexerProcessor $priceIndexerProcessor
     * @param EavIndexerProcessor $eavIndexerProcessor
     * @param FlatIndexerProcessor $flatIndexerProcessor
     * @param FulltextIndexerProcessor $fulltextIndexerProcessor
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
        ProductUrl $productUrl,
        PriceIndexerProcessor $priceIndexerProcessor,
        EavIndexerProcessor $eavIndexerProcessor,
        FlatIndexerProcessor $flatIndexerProcessor,
        FulltextIndexerProcessor $fulltextIndexerProcessor
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
        $this->productUrl = $productUrl;
        $this->priceIndexerProcessor = $priceIndexerProcessor;
        $this->eavIndexerProcessor = $eavIndexerProcessor;
        $this->flatIndexerProcessor = $flatIndexerProcessor;
        $this->fulltextIndexerProcessor = $fulltextIndexerProcessor;
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
            if (array_key_exists('url_key', $resolved)) {
                $resolved['url_key'] = $this->productUrl->formatUrlKey((string) $resolved['url_key']);
            }

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

            $hasCategories = array_key_exists('category_ids', $resolved) || array_key_exists('category', $resolved);
            if ($hasCategories) {
                $categoryWarnings = $this->categoryUpdater->updateCategories($product, $resolved, $storeId);
                $warnings = array_merge($warnings, $categoryWarnings);
                $updatedFields[] = array_key_exists('category_ids', $resolved) ? 'category_ids' : 'category';
            }

            $imagesAdded = 0;
            if (array_key_exists('image_link', $resolved) || array_key_exists('additional_images', $resolved)) {
                $imageResult = $this->imageUpdater->updateImages($product, $resolved);
                $warnings = array_merge($warnings, $imageResult['warnings']);
                $imagesAdded = (int) $imageResult['added'];
                if (array_key_exists('image_link', $resolved)) {
                    $updatedFields[] = 'image_link';
                }
                if (array_key_exists('additional_images', $resolved)) {
                    $updatedFields[] = 'additional_images';
                }
            }

            // Full save only when Magento's save observers are needed: url_key (format, uniqueness,
            // URL rewrites), categories (links + category URL rewrites) or newly added images
            $savedFull = array_key_exists('url_key', $resolved) || $hasCategories || $imagesAdded > 0;

            try {
                $attributeData = $this->collectAttributeData(
                    $product,
                    $productId,
                    $touchedAttributes,
                    $updatedFields,
                    $warnings,
                    $failedFields
                );

                if ($savedFull) {
                    $this->saveFullProduct($product, $storeId, $hasCategories);
                } else {
                    $this->saveAttributes($product, $productId, $storeId, $attributeData);
                }
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

            // The full save reindexes by itself; the attribute-only path does not
            if (!$savedFull && (!empty($attributeData['eav']) || !empty($attributeData['static']))) {
                $this->reindexProduct($productId, $warnings);
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
                    $storeId,
                    $savedFull
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
                $storeId,
                $savedFull
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
                    (string) $resolved[$requestField],
                    (int) $product->getStoreId()
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
     * Split the touched attributes into EAV and static (entity table) values.
     *
     * Unknown attribute codes are reported as failed fields. A duplicate SKU throws before anything
     * is written, as the full save did.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param int $productId
     * @param array $touchedAttributes
     * @param array $updatedFields
     * @param array $warnings
     * @param array $failedFields
     * @return array ['eav' => code => value, 'static' => code => value]
     * @throws LocalizedException
     */
    private function collectAttributeData(
        $product,
        int $productId,
        array $touchedAttributes,
        array &$updatedFields,
        array &$warnings,
        array &$failedFields
    ): array {
        $attributeData = ['eav' => [], 'static' => []];

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

            $attributeData[$attribute->isStatic() ? 'static' : 'eav'][$code] = $value;
        }

        if (array_key_exists('sku', $attributeData['static'])) {
            $sku = $attributeData['static']['sku'];
            $existingId = $this->productResource->getIdBySku($sku);
            if ($existingId && (int) $existingId !== $productId) {
                throw new LocalizedException(__("SKU '%1' is already used by product %2", $sku, $existingId));
            }
        }

        return $attributeData;
    }

    /**
     * Write only the touched attributes at the given store id (no product save).
     *
     * EAV attributes go through Product\Action::updateAttributes (respects each attribute's
     * global/website/store scope and leaves the other attributes untouched). Static attributes
     * (sku) live in the entity table and are written there.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param int $productId
     * @param int $storeId
     * @param array $attributeData from collectAttributeData()
     * @return void
     * @throws \Exception
     */
    private function saveAttributes($product, int $productId, int $storeId, array $attributeData)
    {
        if (!empty($attributeData['static'])) {
            $linkField = $this->productResource->getLinkField();
            $this->productResource->getConnection()->update(
                $this->productResource->getEntityTable(),
                $attributeData['static'],
                [$linkField . ' = ?' => $product->getData($linkField)]
            );
        }

        if (!empty($attributeData['eav'])) {
            $this->productAction->updateAttributes([$productId], $attributeData['eav'], $storeId);
        }
    }

    /**
     * Full product save with the resource model at the resolved store id.
     *
     * The resource model keeps the product's store id (ProductRepository::save would force the
     * request's store view) and runs the save observers: url_key formatting/uniqueness, URL
     * rewrites, category links and media gallery. A duplicate url_key throws.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param int $storeId
     * @param bool $hasCategories
     * @return void
     * @throws \Exception
     */
    private function saveFullProduct($product, int $storeId, bool $hasCategories)
    {
        if ($hasCategories) {
            // The links loaded with the product are stale; the save handler must use category_ids
            $extensionAttributes = $product->getExtensionAttributes();
            if ($extensionAttributes && method_exists($extensionAttributes, 'setCategoryLinks')) {
                $extensionAttributes->setCategoryLinks(null);
            }
        }

        $product->setStoreId($storeId);
        $this->productResource->save($product);
    }

    /**
     * Reindex one product after an attribute-only write (price, EAV, flat, fulltext), skipping
     * indexers in "Update by Schedule" mode. Failures become warnings.
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
     * @param bool|null $savedFull whether the product was saved as a whole (success responses)
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
        $storeId = null,
        $savedFull = null
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
            if ($savedFull !== null) {
                $response['saved_full'] = (bool) $savedFull;
            }
        } else {
            $response['errors'] = $errors;
        }

        return json_encode($response);
    }
}
