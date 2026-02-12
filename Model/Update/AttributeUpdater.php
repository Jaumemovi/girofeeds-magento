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
     * @return string|null
     */
    public function updateSelectAttribute(
        ProductInterface $product,
        $attributeCode,
        $textValue
    ) {
        $attribute = $this->eavConfig->getAttribute('catalog_product', $attributeCode);

        if (!$attribute || !$attribute->getId()) {
            return "Attribute '{$attributeCode}' does not exist";
        }

        if ($attribute->getFrontendInput() !== 'select') {
            $product->setData($attributeCode, $textValue);
            return null;
        }

        $options = $attribute->getSource()->getAllOptions(false);
        $optionId = null;

        foreach ($options as $option) {
            if (strtolower(trim((string) $option['label'])) === strtolower(trim($textValue))) {
                $optionId = $option['value'];
                break;
            }
        }

        if ($optionId === null) {
            $availableLabels = array_map(fn($o) => $o['label'], $options);
            return "Value '{$textValue}' is not a valid option for attribute '{$attributeCode}'. "
                . "Available options: " . implode(', ', $availableLabels);
        }

        $product->setData($attributeCode, $optionId);
        return null;
    }
}
