<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Update;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class CategoryUpdater
{
    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * Constructor
     *
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CategoryCollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        CategoryRepositoryInterface $categoryRepository,
        CategoryCollectionFactory $categoryCollectionFactory
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
    }

    /**
     * Update product categories
     *
     * @param ProductInterface $product
     * @param array $resolvedData
     * @return array
     */
    public function updateCategories(ProductInterface $product, array $resolvedData)
    {
        $warnings = [];

        if (array_key_exists('category_ids', $resolvedData) && is_array($resolvedData['category_ids'])) {
            $validIds = [];
            foreach ($resolvedData['category_ids'] as $catId) {
                try {
                    $this->categoryRepository->get($catId);
                    $validIds[] = (int) $catId;
                } catch (NoSuchEntityException $e) {
                    $warnings[] = "Category ID {$catId} does not exist, skipping";
                }
            }
            $product->setCategoryIds($validIds);
            return $warnings;
        }

        if (array_key_exists('category', $resolvedData) && is_string($resolvedData['category'])) {
            $categoryPath = $resolvedData['category'];
            $categoryId = $this->findCategoryByPath($categoryPath);

            if ($categoryId) {
                $existingIds = $product->getCategoryIds();
                if (!in_array($categoryId, $existingIds)) {
                    $existingIds[] = $categoryId;
                    $product->setCategoryIds($existingIds);
                }
            } else {
                $warnings[] = "Category path '{$categoryPath}' not found in store. Categories not updated.";
            }
        }

        return $warnings;
    }

    /**
     * Find category by path
     *
     * @param string $path
     * @return int|null
     */
    private function findCategoryByPath($path)
    {
        $parts = array_map('trim', explode(' > ', $path));
        $lastCategoryName = end($parts);

        $collection = $this->categoryCollectionFactory->create()
            ->addAttributeToFilter('name', $lastCategoryName)
            ->setPageSize(1);

        if ($collection->getSize() > 0) {
            return (int) $collection->getFirstItem()->getId();
        }

        return null;
    }
}
