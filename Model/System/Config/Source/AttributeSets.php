<?php
/**
 * Copyright © Girofeeds. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\System\Config\Source;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Product attribute sets source class (id => name)
 */
class AttributeSets implements OptionSourceInterface
{

    /**
     * @var AttributeSetCollectionFactory
     */
    private $attributeSetCollectionFactory;

    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * Options array
     *
     * @var array
     */
    public $options = null;

    /**
     * AttributeSets constructor.
     *
     * @param AttributeSetCollectionFactory $attributeSetCollectionFactory
     * @param EavConfig                     $eavConfig
     */
    public function __construct(
        AttributeSetCollectionFactory $attributeSetCollectionFactory,
        EavConfig $eavConfig
    ) {
        $this->attributeSetCollectionFactory = $attributeSetCollectionFactory;
        $this->eavConfig = $eavConfig;
    }

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        if (!$this->options) {
            $this->options = [];
            $entityTypeId = (int) $this->eavConfig->getEntityType(Product::ENTITY)->getEntityTypeId();
            $collection = $this->attributeSetCollectionFactory->create()
                ->setEntityTypeFilter($entityTypeId)
                ->setOrder('attribute_set_name', 'ASC');
            foreach ($collection as $attributeSet) {
                $this->options[] = [
                    'value' => (string) $attributeSet->getAttributeSetId(),
                    'label' => $attributeSet->getAttributeSetName(),
                ];
            }
        }
        return $this->options;
    }
}
