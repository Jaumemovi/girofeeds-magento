<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Update;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class StockUpdater
{

    private StockRegistryInterface $stockRegistry;

    public function __construct(StockRegistryInterface $stockRegistry)
    {
        $this->stockRegistry = $stockRegistry;
    }

    public function updateStock(ProductInterface $product, array $resolvedData): array
    {
        $warnings = [];
        $stockFields = ['qty', 'is_in_stock', 'manage_stock', 'min_sale_qty', 'max_sale_qty', 'backorders'];
        $hasStockUpdate = false;

        foreach ($stockFields as $field) {
            if (array_key_exists($field, $resolvedData)) {
                $hasStockUpdate = true;
                break;
            }
        }

        if (!$hasStockUpdate) {
            return $warnings;
        }

        $stockItem = $this->stockRegistry->getStockItem($product->getId());

        if (array_key_exists('qty', $resolvedData)) {
            $qty = (int) $resolvedData['qty'];
            $stockItem->setQty($qty);

            if (!array_key_exists('is_in_stock', $resolvedData)) {
                $stockItem->setIsInStock($qty > 0);
            }
        }

        if (array_key_exists('is_in_stock', $resolvedData)) {
            $stockItem->setIsInStock(
                in_array($resolvedData['is_in_stock'], [true, 1, '1', 'true'], true)
            );
        }

        if (array_key_exists('manage_stock', $resolvedData)) {
            $stockItem->setManageStock(
                in_array($resolvedData['manage_stock'], [true, 1, '1', 'true'], true)
            );
        }

        if (array_key_exists('min_sale_qty', $resolvedData)) {
            $stockItem->setMinSaleQty((int) $resolvedData['min_sale_qty']);
        }

        if (array_key_exists('max_sale_qty', $resolvedData)) {
            $stockItem->setMaxSaleQty((int) $resolvedData['max_sale_qty']);
        }

        if (array_key_exists('backorders', $resolvedData)) {
            $stockItem->setBackorders((int) $resolvedData['backorders']);
        }

        try {
            $this->stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);
        } catch (\Exception $e) {
            $warnings[] = "Stock update failed: " . $e->getMessage();
        }

        return $warnings;
    }
}
