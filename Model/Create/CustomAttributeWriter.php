<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Create;

use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Eav\Model\Config as EavConfig;

/**
 * Writes, on create, every request key that is a product attribute and that the 1.26.0
 * createproduct flow does not already handle (spec 111 §4.1).
 *
 * Receives the raw request data (before FieldProcessor::resolveFields, which turns
 * `manufacturer` into `brand`). Values follow AttributeValueNormalizer; an invalid value or an
 * exception only produces a warning for that field and never stops the creation. Select and
 * multiselect values are matched against the admin (store 0) labels, then the option ids;
 * options are never created.
 *
 * write() returns:
 * ['warnings' => string[], 'failed_fields' => string[], 'written' => string[]]
 * - warnings: messages for the response `warnings`
 * - failed_fields: codes not (fully) written because of an invalid value or an exception
 *   (same criterion as CreateProduct::processSelectAttributes: the field was meant to be
 *   written and was not); keys that are not attributes, not in the set or of an unsupported
 *   type only warn
 * - written: codes set on the product, in request order
 *
 * `color`, `size`, `material` and `gender` are reserved only while their Data > X field has a
 * source attribute configured; without one they are written as their own attribute code.
 *
 * Codes already handled by CreateProduct::processSelectAttributes in the request (the configured
 * source attribute of brand/color/size/material/gender) or by processIdentifierFields (the
 * Data > EAN attribute when `ean` arrives) are skipped, so the 1.26.0 select step
 * keeps precedence and each attribute has a single writer: with a warning when the request also
 * carried that select field explicitly, silently when the key only acted as its alias
 * (`manufacturer` alone becoming `brand`).
 */
class CustomAttributeWriter
{
    const ENTITY_TYPE = 'catalog_product';

    /** Attribute codes: a letter, then letters, digits or underscores (no numeric ids) */
    const CODE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    /**
     * Keys the createproduct flow already reads (directly, as an alias or through a priority
     * rule) or that are request metadata / system data. They are never written as added
     * attributes. `manufacturer` is deliberately NOT here: it stays the `brand` alias of
     * FieldProcessor and is also written as an attribute when it exists.
     */
    const RESERVED_KEYS = [
        // Request metadata (CreateProduct::execute, FieldProcessor::resolveFields; `key` is the API key)
        'store', 'store_id', 'id_product', 'key',
        // Identity and aliases (FieldProcessor priority rules, CreateProduct::buildProduct,
        // findExistingProductId, processIdentifierFields)
        'sku', 'reference', 'name', 'ean', 'ean13', 'gtin',
        // Status and visibility (FieldProcessor aliases active/visible, ValidationService)
        'status', 'active', 'visibility', 'visible',
        // Attribute set, websites and tax class (resolveAttributeSetId, resolveWebsiteIds, resolveTaxClassId)
        'attribute_set', 'website_ids', 'tax_class_id',
        // Content (buildProduct, url_key normalization and ValidationService)
        'description', 'short_description', 'url_key', 'weight',
        // Prices (PriceUpdater, FieldProcessor alias specific_price)
        'price', 'special_price', 'specific_price', 'cost',
        // Select attributes through the configured source attributes (processSelectAttributes)
        'brand', 'color', 'size', 'material', 'gender',
        // Images (CreationImages, ImageUpdater, FieldProcessor alias image)
        'images', 'image', 'image_link', 'additional_images',
        // Categories (normalizeCategoryIds, CategoryUpdater)
        'category', 'category_ids',
        // Stock (StockUpdater, FieldProcessor alias stock, ValidationService)
        'qty', 'stock', 'is_in_stock', 'manage_stock', 'use_config_manage_stock',
        'min_sale_qty', 'max_sale_qty', 'backorders',
        // System data of the product model (`id` is the model's alias of entity_id)
        'id', 'entity_id', 'attribute_set_id', 'type_id', 'created_at', 'updated_at', 'has_options',
        'required_options', 'media_gallery', 'gallery', 'tier_price', 'quantity_and_stock_status',
    ];

    /**
     * Attributes the generic writer never writes, even when they exist and are in the set:
     * layout XML and design settings (per-product layout updates are a code execution vector
     * in Magento 2.3) and page layout / options container. A non-blank value only warns.
     */
    const BLOCKED_ATTRIBUTES = [
        'custom_layout_update', 'custom_layout_update_file', 'custom_layout', 'custom_design',
        'custom_design_from', 'custom_design_to', 'page_layout', 'options_container',
    ];

    /**
     * Reserved select fields that become ordinary attribute codes when their Data > X field has
     * no source attribute configured (the 1.26.0 select step does nothing with them then).
     * `brand` is deliberately not here: it keeps its 1.26.0 behaviour in every configuration.
     */
    const RELEASABLE_SELECT_FIELDS = ['color', 'size', 'material', 'gender'];

    /**
     * Raw request keys that carry a handled field explicitly (FieldProcessor priority rules);
     * any other field is carried only by its own name
     */
    const FIELD_REQUEST_KEYS = [
        'ean' => ['ean', 'ean13', 'gtin'],
    ];

    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @var ProductAttributeManagementInterface
     */
    private $attributeManagement;

    /**
     * @var AttributeValueNormalizer
     */
    private $normalizer;

    /**
     * Attribute codes per attribute set id
     *
     * @var array
     */
    private $setAttributeCodes = [];

    /**
     * Constructor
     *
     * @param EavConfig $eavConfig
     * @param ProductAttributeManagementInterface $attributeManagement
     * @param AttributeValueNormalizer $normalizer
     */
    public function __construct(
        EavConfig $eavConfig,
        ProductAttributeManagementInterface $attributeManagement,
        AttributeValueNormalizer $normalizer
    ) {
        $this->eavConfig = $eavConfig;
        $this->attributeManagement = $attributeManagement;
        $this->normalizer = $normalizer;
    }

    /**
     * Set the added attributes of the request on the product (not saved)
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $data raw request data
     * @param int $attributeSetId attribute set of the new product
     * @param array $handledCodes attribute code => request field already handled by the select step
     *                            or the identifier step (Data > EAN attribute => 'ean')
     * @param array $selectSources select field => configured source attribute (Data > X; empty = none).
     *                             Without an entry a field keeps its reservation.
     * @return array see the class docblock
     */
    public function write(
        $product,
        array $data,
        int $attributeSetId,
        array $handledCodes = [],
        array $selectSources = []
    ): array {
        $warnings = [];
        $failedFields = [];
        $written = [];

        // Reserved, blocked and handled codes are matched case-insensitively (Magento resolves
        // attribute codes case-insensitively on lookup and save)
        $handled = [];
        foreach ($handledCodes as $handledCode => $selectField) {
            $handled[strtolower((string) $handledCode)] = (string) $selectField;
        }

        $released = [];
        foreach (self::RELEASABLE_SELECT_FIELDS as $selectField) {
            if (array_key_exists($selectField, $selectSources) && !$selectSources[$selectField]) {
                $released[] = $selectField;
            }
        }

        foreach ($data as $key => $value) {
            $code = (string) $key;
            $lowerCode = strtolower($code);
            $reserved = in_array($lowerCode, self::RESERVED_KEYS, true) && !in_array($lowerCode, $released, true);
            if ($reserved || $this->normalizer->isBlank($value)) {
                continue;
            }

            try {
                if (in_array($lowerCode, self::BLOCKED_ATTRIBUTES, true)) {
                    $warnings[] = "Field '{$code}' ignored: attribute not allowed";
                    continue;
                }

                if (array_key_exists($lowerCode, $handled)) {
                    $selectField = $handled[$lowerCode];
                    if ($selectField !== $lowerCode && $this->carriesField($data, $selectField, $lowerCode)) {
                        $warnings[] = "Field '{$code}' ignored: the attribute is handled by '{$selectField}'";
                    }
                    continue;
                }

                if (!preg_match(self::CODE_PATTERN, $code)) {
                    $warnings[] = "Field '{$code}' ignored: not a product attribute";
                    continue;
                }

                $attribute = $this->eavConfig->getAttribute(self::ENTITY_TYPE, $code);
                if (!$attribute || !$attribute->getId()) {
                    $warnings[] = "Field '{$code}' ignored: not a product attribute";
                    continue;
                }

                if (!in_array($code, $this->getSetAttributeCodes($attributeSetId), true)) {
                    $warnings[] = "Field '{$code}' ignored: not in the attribute set of the product";
                    continue;
                }

                $frontendInput = (string) $attribute->getFrontendInput();
                $backendType = (string) $attribute->getBackendType();
                $kind = $this->normalizer->resolveKind($frontendInput, $backendType);
                if ($kind === AttributeValueNormalizer::KIND_UNSUPPORTED) {
                    $warnings[] = $backendType === 'static'
                        ? "Field '{$code}' ignored: static attribute"
                        : "Field '{$code}' ignored: unsupported input type '{$frontendInput}'";
                    continue;
                }

                $options = [];
                if ($kind === AttributeValueNormalizer::KIND_SELECT
                    || $kind === AttributeValueNormalizer::KIND_MULTISELECT
                ) {
                    $options = $this->getAdminOptions($attribute);
                }

                $result = $this->normalizer->normalize($code, $kind, $value, $options, $backendType);
                foreach ($result['warnings'] as $warning) {
                    $warnings[] = $warning;
                }
                if ($result['failed']) {
                    $failedFields[] = $code;
                }
                if ($result['write']) {
                    $product->setData($code, $result['value']);
                    $written[] = $code;
                }
            } catch (\Throwable $e) {
                $warnings[] = "Field '{$code}' not written: " . $e->getMessage();
                $failedFields[] = $code;
            }
        }

        return [
            'warnings' => $warnings,
            'failed_fields' => array_values(array_unique($failedFields)),
            'written' => $written,
        ];
    }

    /**
     * The raw request carries a handled field explicitly (by its own name or, for `ean`, one of
     * its priority keys), other than the skipped code itself
     *
     * @param array $data
     * @param string $field
     * @param string $lowerCode
     * @return bool
     */
    private function carriesField(array $data, string $field, string $lowerCode): bool
    {
        $fieldRequestKeys = self::FIELD_REQUEST_KEYS;
        $requestKeys = array_key_exists($field, $fieldRequestKeys) ? $fieldRequestKeys[$field] : [$field];
        foreach ($requestKeys as $requestKey) {
            if ($requestKey !== $lowerCode && array_key_exists($requestKey, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Codes of the attributes assigned to an attribute set (cached per set)
     *
     * @param int $attributeSetId
     * @return string[]
     */
    private function getSetAttributeCodes(int $attributeSetId): array
    {
        if (!isset($this->setAttributeCodes[$attributeSetId])) {
            $codes = [];
            foreach ($this->attributeManagement->getAttributes((string) $attributeSetId) as $setAttribute) {
                $codes[] = (string) $setAttribute->getAttributeCode();
            }
            $this->setAttributeCodes[$attributeSetId] = $codes;
        }

        return $this->setAttributeCodes[$attributeSetId];
    }

    /**
     * Options of a select/multiselect attribute with the admin (store 0) labels
     *
     * @param \Magento\Eav\Model\Entity\Attribute\AbstractAttribute $attribute
     * @return array [['value' => ..., 'label' => ...]]
     */
    private function getAdminOptions($attribute): array
    {
        $previousStoreId = $attribute->getStoreId();
        try {
            // The option source reads labels for the attribute's store id
            $attribute->setStoreId(0);
            $options = $attribute->getSource()->getAllOptions(false);
        } finally {
            $attribute->setStoreId($previousStoreId);
        }

        return is_array($options) ? $options : [];
    }
}
