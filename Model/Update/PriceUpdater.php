<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Update;

use Magento\Catalog\Api\Data\ProductInterface;

class PriceUpdater
{

    /**
     * Update prices
     *
     * @param ProductInterface $product
     * @param array $resolvedData
     * @param bool $allowZeroPrice createproduct only: a price of 0 is set without warning
     *                             (products created disabled, ValidationService::allowsZeroPrice)
     * @return array
     */
    public function updatePrices(ProductInterface $product, array $resolvedData, bool $allowZeroPrice = false): array
    {
        $warnings = [];

        if (array_key_exists('price', $resolvedData)) {
            $price = (float) $resolvedData['price'];
            if ($allowZeroPrice && $price == 0) {
                $product->setPrice(0.0);
            } elseif ($price <= 0) {
                $warnings[] = "Field 'price' must be a positive number, received: {$price}";
            } else {
                $product->setPrice($price);
            }
        }

        if (array_key_exists('special_price', $resolvedData)) {
            $specialPrice = $resolvedData['special_price'];
            $product->setSpecialPrice($specialPrice);

            if ($specialPrice !== null && !$product->getSpecialFromDate()) {
                $product->setSpecialFromDate(date('Y-m-d H:i:s'));
            }
        }

        if (array_key_exists('cost', $resolvedData)) {
            $product->setData('cost', (float) $resolvedData['cost']);
        }

        return $warnings;
    }
}
