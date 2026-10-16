<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Update;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;

class AttributeUpdater
{
    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * Constructor
     *
     * @param EavConfig $eavConfig
     */
    public function __construct(EavConfig $eavConfig)
    {
        $this->eavConfig = $eavConfig;
    }

    /**
     * Update select attribute
     *
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param string $textValue
     * @param int $storeId write store id; labels are matched in admin (0) first, then in this store
     * @return string|null
     */
    public function updateSelectAttribute(
        ProductInterface $product,
        $attributeCode,
        $textValue,
        $storeId = 0
    ) {
        $attribute = $this->eavConfig->getAttribute('catalog_product', $attributeCode);

        if (!$attribute || !$attribute->getId()) {
            return "Attribute '{$attributeCode}' does not exist";
        }

        if ($attribute->getFrontendInput() !== 'select') {
            $product->setData($attributeCode, $textValue);
            return null;
        }

        $optionId = null;
        $availableLabels = [];
        $previousStoreId = $attribute->getStoreId();

        try {
            foreach (array_unique([0, (int) $storeId]) as $labelStoreId) {
                // The option source reads labels for the attribute's store id
                $attribute->setStoreId($labelStoreId);
                foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                    $label = (string) $option['label'];
                    if (strtolower(trim($label)) === strtolower(trim($textValue))) {
                        $optionId = $option['value'];
                        break 2;
                    }
                    $availableLabels[] = $label;
                }
            }
        } finally {
            $attribute->setStoreId($previousStoreId);
        }

        if ($optionId === null) {
            return "Value '{$textValue}' is not a valid option for attribute '{$attributeCode}'. "
                . "Available options: " . implode(', ', array_unique($availableLabels));
        }

        $product->setData($attributeCode, $optionId);
        return null;
    }
}
