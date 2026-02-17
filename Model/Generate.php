<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Girofeeds\ProductApi\Helper\Feed as FeedHelper;
use Girofeeds\ProductApi\Helper\General as GeneralHelper;
use Girofeeds\ProductApi\Helper\Product as ProductHelper;
use Girofeeds\ProductApi\Helper\Source as SourceHelper;
use Girofeeds\ProductApi\Model\Collection\Products as ProductsModel;
use Girofeeds\ProductApi\Service\Category\CategoryData;

class Generate
{

    private $productModel;
    private $productHelper;
    private $sourceHelper;
    private $generalHelper;
    private $feedHelper;
    private $categoryData;
    private $appEmulation;

    public function __construct(
        ProductsModel $productModel,
        SourceHelper $sourceHelper,
        ProductHelper $productHelper,
        GeneralHelper $generalHelper,
        FeedHelper $feedHelper,
        CategoryData $categoryData,
        Emulation $appEmulation
    ) {
        $this->productModel = $productModel;
        $this->productHelper = $productHelper;
        $this->sourceHelper = $sourceHelper;
        $this->generalHelper = $generalHelper;
        $this->feedHelper = $feedHelper;
        $this->categoryData = $categoryData;
        $this->appEmulation = $appEmulation;
    }

    public function generateByStore(
        int $storeId,
        ?int $page,
        ?array $productIds = [],
        ?string $currency = null
    ): array {
        $feed = [];
        $pages = 1;

        $timeStart = microtime(true);
        $this->appEmulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

        $config = $this->sourceHelper->getConfig($storeId, 'feed', $currency);
        $productCollection = $this->productModel->getCollection($config, $page, $productIds);
        $size = $productCollection->getSize();

        if (($config['filters']['limit'] > 0) && empty($productIds)) {
            $productCollection->setPage($page, $config['filters']['limit']);
            $pages = ceil($size / $config['filters']['limit']);
        }

        if (($page <= $pages) || $pages == 0) {

            $products = $productCollection->load();
            $parentRelations = $this->productHelper->getParentsFromCollection($products, $config);
            $parents = $this->productModel->getParents($parentRelations, $config);

            $this->prefetchData($products, $parents, $config);
            $config['categories'] = $this->categoryData->load($products, $parents, $storeId);

            foreach ($products as $product) {
                /** @var Product $product */
                $parent = null;
                if (!empty($parentRelations[$product->getEntityId()])) {
                    foreach ($parentRelations[$product->getEntityId()] as $parentId) {
                        if ($foundParent = $parents->getItemById($parentId)) {
                            $parent = $foundParent;
                        }
                    }
                }
                if (!empty($productIds)) {
                    $product->unsetData('extension_attributes');
                    $feed['product_source'] = $product->getData();
                    if (!empty($parent)) {
                        $feed['parent_source'] = $parent->getData();
                    }
                }
                if ($dataRow = $this->getDataRow($product, $parent, $config)) {
                    $feed[] = $dataRow;
                }
            }

            $return = [];
            if (empty($productIds)) {
                $limit = $config['filters']['limit'];
                $return['config'] = $this->feedHelper
                    ->getFeedSummary($timeStart, $size, $limit, count($feed), $page, $pages);
                $return['products'] = $feed;
            } else {
                $return['products'] = array_filter([
                    'product' => !empty($feed['product_source']) ? $feed['product_source'] : null,
                    'parent' => !empty($feed['parent_source']) ? $feed['parent_source'] : null,
                    'feed' => !empty($feed[0]) ? $feed[0] : null,
                ]);
                $return['config'] = $this->feedHelper
                    ->getFeedSummary($timeStart, $size, count($productIds), count($feed), $page, $pages);
            }
            $feed = $return;
        }

        $this->appEmulation->stopEnvironmentEmulation();

        return $feed;
    }

    public function getSize(int $storeId): int
    {
        try {
            $this->appEmulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            $config = $this->sourceHelper->getConfig($storeId, 'size');
            $productCollection = $this->productModel->getCollection($config, 1, []);
            $size = $this->productModel->getCollectionCountWithFilters($productCollection);
            $this->appEmulation->stopEnvironmentEmulation();
        } catch (\Exception $e) {
            $this->generalHelper->addTolog('getSize', $e->getMessage());
            $size = -1;
        }

        return $size;
    }

    public function getDataRow($product, $parent, $config): ?array
    {
        if ($dataRow = $this->productHelper->getDataRow($product, $parent, $config)) {
            if ($row = $this->sourceHelper->reformatData($dataRow, $product, $parent, $config)) {
                return $this->appendAllProductAttributes($row, $product, $parent);
            }
        }

        return null;
    }

    private function appendAllProductAttributes(array $row, Product $product, ?Product $parent): array
    {
        $productData = $this->normalizeProductData($product->getData());
        $parentData = $parent ? $this->normalizeProductData($parent->getData()) : [];

        foreach ($productData as $code => $value) {
            if (!array_key_exists($code, $row)) {
                $row[$code] = $value;
            }
        }

        foreach ($parentData as $code => $value) {
            if (!array_key_exists($code, $row) && !array_key_exists($code, $productData)) {
                $row[$code] = $value;
            }
        }

        return $row;
    }

    private function normalizeProductData(array $data): array
    {
        unset($data['extension_attributes']);
        $normalized = [];

        foreach ($data as $code => $value) {
            if ($value === null || is_scalar($value)) {
                $normalized[$code] = $value;
                continue;
            }

            if (is_array($value)) {
                $normalized[$code] = $this->normalizeArrayValue($value);
            }
        }

        return $normalized;
    }

    private function normalizeArrayValue(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if ($item === null || is_scalar($item)) {
                $normalized[$key] = $item;
                continue;
            }

            if (is_array($item)) {
                $normalized[$key] = $this->normalizeArrayValue($item);
            }
        }

        return $normalized;
    }

    private function prefetchData(
        ProductCollection $products,
        ProductCollection $parents,
        array $config
    ) {
        $this->productHelper->getInventoryData()->load($products->getColumnValues('sku'), $config);
        $this->productHelper->getMediaData()->load($products, $parents);
    }
}
