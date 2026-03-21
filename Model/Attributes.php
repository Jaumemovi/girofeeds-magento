<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Girofeeds\ProductApi\Api\AttributesInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Catalog\Model\Product\Attribute\Repository as AttributeRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory as AttributeGroupCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class Attributes implements AttributesInterface
{
    protected static $numericFields = [
        'price', 'special_price', 'cost', 'msrp', 'weight',
        'qty', 'min_sale_qty', 'max_sale_qty', 'tax_class_id',
        'length', 'width', 'height'
    ];

    protected static $booleanFields = [
        'status', 'is_in_stock', 'manage_stock'
    ];

    protected static $htmlFields = ['description', 'short_description'];

    protected static $dateFields = [
        'special_from_date', 'special_to_date',
        'news_from_date', 'news_to_date',
        'created_at', 'updated_at'
    ];

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @var AttributeRepository
     */
    private $attributeRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var AttributeSetCollectionFactory
     */
    private $attributeSetCollectionFactory;

    /**
     * @var AttributeGroupCollectionFactory
     */
    private $attributeGroupCollectionFactory;

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
     * @param ProductMetadataInterface $productMetadata
     * @param StoreManagerInterface $storeManager
     * @param EavConfig $eavConfig
     * @param AttributeRepository $attributeRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param AttributeSetCollectionFactory $attributeSetCollectionFactory
     * @param AttributeGroupCollectionFactory $attributeGroupCollectionFactory
     * @param ApiKeyValidator $apiKeyValidator
     * @param RateLimiter $rateLimiter
     */
    public function __construct(
        RequestInterface $request,
        ProductMetadataInterface $productMetadata,
        StoreManagerInterface $storeManager,
        EavConfig $eavConfig,
        AttributeRepository $attributeRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CategoryCollectionFactory $categoryCollectionFactory,
        AttributeSetCollectionFactory $attributeSetCollectionFactory,
        AttributeGroupCollectionFactory $attributeGroupCollectionFactory,
        ApiKeyValidator $apiKeyValidator,
        RateLimiter $rateLimiter
    ) {
        $this->request = $request;
        $this->productMetadata = $productMetadata;
        $this->storeManager = $storeManager;
        $this->eavConfig = $eavConfig;
        $this->attributeRepository = $attributeRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->attributeSetCollectionFactory = $attributeSetCollectionFactory;
        $this->attributeGroupCollectionFactory = $attributeGroupCollectionFactory;
        $this->apiKeyValidator = $apiKeyValidator;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Get product attribute definitions
     *
     * @return string
     */
    public function getAttributes(): string
    {
        $token = $this->request->getParam('key');
        $storeId = $this->resolveStoreId();

        $validation = $this->apiKeyValidator->validate($token, $storeId);
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
            $storeCode = $this->storeManager->getStore($storeId)->getCode();

            $response = [
                'status' => 'success',
                'fetchedAt' => date('c'),
                'storeInfo' => [
                    'defaultStoreId' => $storeId,
                    'storeCode' => $storeCode,
                    'magentoVersion' => $this->productMetadata->getVersion()
                ],
                'standardFields' => $this->getStandardFields(),
                'customAttributes' => $this->getCustomAttributes(),
                'attributeSets' => $this->getAttributeSets(),
                'categories' => $this->getCategories($storeId),
                'manufacturers' => $this->getManufacturers()
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
     * Resolve store ID from request
     *
     * @return int
     */
    private function resolveStoreId(): int
    {
        $storeCode = $this->request->getParam('store');

        if ($storeCode) {
            try {
                $store = $this->storeManager->getStore($storeCode);
                return (int) $store->getId();
            } catch (NoSuchEntityException $e) {
                throw new \InvalidArgumentException("Store '{$storeCode}' not found");
            }
        }

        return (int) $this->storeManager->getStore()->getId();
    }

    /**
     * Get field type based on field name
     *
     * @param string $fieldName
     * @return string
     */
    private function getFieldType(string $fieldName): string
    {
        if (in_array($fieldName, self::$numericFields)) {
            return 'number';
        }
        if (in_array($fieldName, self::$booleanFields)) {
            return 'boolean';
        }
        if (in_array($fieldName, self::$dateFields)) {
            return 'date';
        }
        if (in_array($fieldName, self::$htmlFields)) {
            return 'html';
        }
        return 'string';
    }

    /**
     * Build a field definition array
     *
     * @param string $key
     * @param array $overrides
     * @return array
     */
    private function buildField(string $key, array $overrides = []): array
    {
        $defaults = [
            'key' => $key,
            'type' => $this->getFieldType($key),
            'table' => 'catalog_product_entity',
            'isMultilingual' => false,
            'category' => 'basic',
            'description' => '',
            'possibleValues' => null
        ];
        return array_merge($defaults, $overrides);
    }

    /**
     * Get standard product fields organized by category
     *
     * @return array
     */
    private function getStandardFields(): array
    {
        $fields = [];

        // --- Basic ---
        $fields[] = $this->buildField('name', [
            'category' => 'basic',
            'isMultilingual' => true,
            'description' => 'Product name'
        ]);
        $fields[] = $this->buildField('sku', [
            'category' => 'basic',
            'description' => 'Stock Keeping Unit'
        ]);
        $fields[] = $this->buildField('type_id', [
            'category' => 'basic',
            'description' => 'Product type',
            'possibleValues' => [
                ['value' => 'simple', 'label' => 'Simple'],
                ['value' => 'configurable', 'label' => 'Configurable'],
                ['value' => 'grouped', 'label' => 'Grouped'],
                ['value' => 'bundle', 'label' => 'Bundle'],
                ['value' => 'virtual', 'label' => 'Virtual'],
                ['value' => 'downloadable', 'label' => 'Downloadable']
            ]
        ]);
        $fields[] = $this->buildField('attribute_set_id', [
            'type' => 'number',
            'category' => 'basic',
            'description' => 'Attribute set ID'
        ]);
        $fields[] = $this->buildField('status', [
            'type' => 'boolean',
            'category' => 'basic',
            'description' => 'Product status',
            'possibleValues' => [
                ['value' => '1', 'label' => 'Enabled'],
                ['value' => '2', 'label' => 'Disabled']
            ]
        ]);
        $fields[] = $this->buildField('visibility', [
            'type' => 'number',
            'category' => 'basic',
            'description' => 'Product visibility',
            'possibleValues' => [
                ['value' => '1', 'label' => 'Not Visible Individually'],
                ['value' => '2', 'label' => 'Catalog'],
                ['value' => '3', 'label' => 'Search'],
                ['value' => '4', 'label' => 'Catalog, Search']
            ]
        ]);

        // --- Identifiers ---
        $fields[] = $this->buildField('ean', [
            'category' => 'identifiers',
            'description' => 'European Article Number'
        ]);
        $fields[] = $this->buildField('gtin', [
            'category' => 'identifiers',
            'description' => 'Global Trade Item Number'
        ]);
        $fields[] = $this->buildField('mpn', [
            'category' => 'identifiers',
            'description' => 'Manufacturer Part Number'
        ]);
        $fields[] = $this->buildField('upc', [
            'category' => 'identifiers',
            'description' => 'Universal Product Code'
        ]);
        $fields[] = $this->buildField('isbn', [
            'category' => 'identifiers',
            'description' => 'International Standard Book Number'
        ]);

        // --- Pricing ---
        $fields[] = $this->buildField('price', [
            'type' => 'number',
            'category' => 'pricing',
            'description' => 'Product price'
        ]);
        $fields[] = $this->buildField('special_price', [
            'type' => 'number',
            'category' => 'pricing',
            'description' => 'Special/sale price'
        ]);
        $fields[] = $this->buildField('cost', [
            'type' => 'number',
            'category' => 'pricing',
            'description' => 'Product cost'
        ]);
        $fields[] = $this->buildField('msrp', [
            'type' => 'number',
            'category' => 'pricing',
            'description' => 'Manufacturer Suggested Retail Price'
        ]);
        $fields[] = $this->buildField('tax_class_id', [
            'type' => 'number',
            'category' => 'pricing',
            'description' => 'Tax class ID'
        ]);
        $fields[] = $this->buildField('special_from_date', [
            'type' => 'date',
            'category' => 'pricing',
            'description' => 'Special price start date'
        ]);
        $fields[] = $this->buildField('special_to_date', [
            'type' => 'date',
            'category' => 'pricing',
            'description' => 'Special price end date'
        ]);

        // --- Inventory ---
        $fields[] = $this->buildField('qty', [
            'type' => 'number',
            'category' => 'inventory',
            'description' => 'Available stock quantity'
        ]);
        $fields[] = $this->buildField('is_in_stock', [
            'type' => 'boolean',
            'category' => 'inventory',
            'description' => 'Whether the product is in stock'
        ]);
        $fields[] = $this->buildField('manage_stock', [
            'type' => 'boolean',
            'category' => 'inventory',
            'description' => 'Whether stock is managed'
        ]);
        $fields[] = $this->buildField('min_sale_qty', [
            'type' => 'number',
            'category' => 'inventory',
            'description' => 'Minimum sale quantity'
        ]);
        $fields[] = $this->buildField('max_sale_qty', [
            'type' => 'number',
            'category' => 'inventory',
            'description' => 'Maximum sale quantity'
        ]);
        $fields[] = $this->buildField('backorders', [
            'type' => 'number',
            'category' => 'inventory',
            'description' => 'Backorder policy',
            'possibleValues' => [
                ['value' => '0', 'label' => 'No Backorders'],
                ['value' => '1', 'label' => 'Allow Qty Below 0'],
                ['value' => '2', 'label' => 'Allow Qty Below 0 and Notify Customer']
            ]
        ]);

        // --- Content (multilingual) ---
        $fields[] = $this->buildField('description', [
            'type' => 'html',
            'category' => 'content',
            'isMultilingual' => true,
            'description' => 'Product description'
        ]);
        $fields[] = $this->buildField('short_description', [
            'type' => 'html',
            'category' => 'content',
            'isMultilingual' => true,
            'description' => 'Short product description'
        ]);

        // --- SEO (multilingual) ---
        $fields[] = $this->buildField('meta_title', [
            'category' => 'seo',
            'isMultilingual' => true,
            'description' => 'Meta title for SEO'
        ]);
        $fields[] = $this->buildField('meta_description', [
            'category' => 'seo',
            'isMultilingual' => true,
            'description' => 'Meta description for SEO'
        ]);
        $fields[] = $this->buildField('meta_keyword', [
            'category' => 'seo',
            'isMultilingual' => true,
            'description' => 'Meta keywords for SEO'
        ]);
        $fields[] = $this->buildField('url_key', [
            'category' => 'seo',
            'isMultilingual' => true,
            'description' => 'URL key for product page'
        ]);

        // --- Shipping ---
        $fields[] = $this->buildField('weight', [
            'type' => 'number',
            'category' => 'shipping',
            'description' => 'Product weight'
        ]);
        $fields[] = $this->buildField('length', [
            'type' => 'number',
            'category' => 'shipping',
            'description' => 'Product length'
        ]);
        $fields[] = $this->buildField('width', [
            'type' => 'number',
            'category' => 'shipping',
            'description' => 'Product width'
        ]);
        $fields[] = $this->buildField('height', [
            'type' => 'number',
            'category' => 'shipping',
            'description' => 'Product height'
        ]);
        $fields[] = $this->buildField('country_of_origin', [
            'category' => 'shipping',
            'description' => 'Country of manufacture'
        ]);

        // --- Media ---
        $fields[] = $this->buildField('image', [
            'category' => 'media',
            'description' => 'Main product image'
        ]);
        $fields[] = $this->buildField('small_image', [
            'category' => 'media',
            'description' => 'Small product image'
        ]);
        $fields[] = $this->buildField('thumbnail', [
            'category' => 'media',
            'description' => 'Thumbnail image'
        ]);
        $fields[] = $this->buildField('media_gallery', [
            'type' => 'array',
            'category' => 'media',
            'description' => 'Additional product images'
        ]);

        // --- Relations ---
        $fields[] = $this->buildField('category_ids', [
            'type' => 'array',
            'category' => 'relations',
            'description' => 'Assigned category IDs'
        ]);

        // --- Advanced ---
        $fields[] = $this->buildField('condition', [
            'category' => 'advanced',
            'description' => 'Product condition',
            'possibleValues' => [
                ['value' => 'new', 'label' => 'New'],
                ['value' => 'used', 'label' => 'Used'],
                ['value' => 'refurbished', 'label' => 'Refurbished']
            ]
        ]);
        $fields[] = $this->buildField('created_at', [
            'type' => 'date',
            'category' => 'advanced',
            'description' => 'Product creation date'
        ]);
        $fields[] = $this->buildField('updated_at', [
            'type' => 'date',
            'category' => 'advanced',
            'description' => 'Product last update date'
        ]);

        return $fields;
    }

    /**
     * Get custom EAV attributes for catalog_product entity
     *
     * @return array
     */
    private function getCustomAttributes(): array
    {
        $result = [];
        $standardKeys = array_map(function ($f) {
            return $f['key'];
        }, $this->getStandardFields());

        $searchCriteria = $this->searchCriteriaBuilder->create();

        foreach ($this->attributeRepository->getList($searchCriteria)->getItems() as $attribute) {
            if (!$attribute->getIsVisible()) {
                continue;
            }

            $code = $attribute->getAttributeCode();
            if (in_array($code, $standardKeys)) {
                continue;
            }

            $possibleValues = null;
            $frontendInput = $attribute->getFrontendInput();

            if (in_array($frontendInput, ['select', 'multiselect'])) {
                $options = $attribute->getOptions();
                if ($options) {
                    $values = [];
                    foreach ($options as $option) {
                        if ($option->getValue() !== '' && $option->getValue() !== null) {
                            $values[] = [
                                'value' => (string) $option->getValue(),
                                'label' => (string) $option->getLabel()
                            ];
                        }
                    }
                    if (!empty($values)) {
                        $possibleValues = $values;
                    }
                }
            }

            $result[] = [
                'id' => (int) $attribute->getAttributeId(),
                'code' => $code,
                'label' => $attribute->getFrontendLabel() ?: $code,
                'frontendInput' => $frontendInput ?: 'text',
                'isRequired' => (bool) $attribute->getIsRequired(),
                'isUserDefined' => (bool) $attribute->getIsUserDefined(),
                'position' => (int) $attribute->getPosition(),
                'possibleValues' => $possibleValues
            ];
        }

        usort($result, function ($a, $b) {
            return strcmp((string) $a['label'], (string) $b['label']);
        });

        return $result;
    }

    /**
     * Get attribute sets with their groups
     *
     * @return array
     */
    private function getAttributeSets(): array
    {
        $entityTypeId = $this->eavConfig->getEntityType('catalog_product')->getId();

        $setCollection = $this->attributeSetCollectionFactory->create()
            ->setEntityTypeFilter($entityTypeId)
            ->setOrder('attribute_set_name', 'ASC');

        $result = [];
        foreach ($setCollection as $set) {
            $groups = [];
            $groupCollection = $this->attributeGroupCollectionFactory->create()
                ->setAttributeSetFilter($set->getId())
                ->setOrder('sort_order', 'ASC');

            foreach ($groupCollection as $group) {
                $groups[] = [
                    'id' => (int) $group->getId(),
                    'name' => $group->getAttributeGroupName(),
                    'sortOrder' => (int) $group->getSortOrder()
                ];
            }

            $result[] = [
                'id' => (int) $set->getId(),
                'name' => $set->getAttributeSetName(),
                'groups' => $groups
            ];
        }

        return $result;
    }

    /**
     * Get category tree for given store
     *
     * @param int $storeId
     * @return array
     */
    private function getCategories(int $storeId): array
    {
        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'level', 'path', 'is_active', 'position', 'url_key'])
            ->addFieldToFilter('is_active', ['eq' => 1])
            ->setOrder('level', 'ASC')
            ->setOrder('position', 'ASC');

        try {
            $rootCategoryId = $this->storeManager->getStore($storeId)->getRootCategoryId();
            if ($rootCategoryId) {
                $collection->addFieldToFilter('path', ['like' => '%/' . $rootCategoryId . '/%']);
            }
        } catch (\Exception $e) {
            // Use unfiltered collection if store root category is unavailable
        }

        $result = [];
        foreach ($collection as $category) {
            $result[] = [
                'id' => (int) $category->getId(),
                'parentId' => (int) $category->getParentId(),
                'depth' => (int) $category->getLevel(),
                'active' => true,
                'position' => (int) $category->getPosition(),
                'name' => (string) $category->getName(),
                'slug' => (string) ($category->getUrlKey() ?: '')
            ];
        }

        return $result;
    }

    /**
     * Get manufacturer/brand option values
     *
     * @return array
     */
    private function getManufacturers(): array
    {
        $attribute = $this->eavConfig->getAttribute('catalog_product', 'manufacturer');
        if (!$attribute || !$attribute->getId()) {
            return [];
        }

        $options = $attribute->getSource()->getAllOptions(false);
        $result = [];
        foreach ($options as $option) {
            if (!empty($option['value'])) {
                $result[] = [
                    'id' => (int) $option['value'],
                    'name' => (string) $option['label']
                ];
            }
        }

        return $result;
    }
}
