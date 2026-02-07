<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Update;

use Magento\Catalog\Api\Data\ProductInterface;

class PriceUpdater
{

    public function updatePrices(ProductInterface $product, array $resolvedData): array
    {
        $warnings = [];

        if (array_key_exists('price', $resolvedData)) {
            $price = (float) $resolvedData['price'];
            if ($price <= 0) {
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
