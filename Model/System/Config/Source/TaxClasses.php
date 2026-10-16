<?php
/**
 * Copyright © Girofeeds. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\System\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Tax\Model\ClassModel;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as TaxClassCollectionFactory;

/**
 * Product tax classes source class (id => name), including "None" (0)
 */
class TaxClasses implements OptionSourceInterface
{

    /**
     * @var TaxClassCollectionFactory
     */
    private $taxClassCollectionFactory;

    /**
     * Options array
     *
     * @var array
     */
    public $options = null;

    /**
     * TaxClasses constructor.
     *
     * @param TaxClassCollectionFactory $taxClassCollectionFactory
     */
    public function __construct(TaxClassCollectionFactory $taxClassCollectionFactory)
    {
        $this->taxClassCollectionFactory = $taxClassCollectionFactory;
    }

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        if (!$this->options) {
            $this->options = [
                ['value' => '0', 'label' => __('None')],
            ];
            $collection = $this->taxClassCollectionFactory->create()
                ->setClassTypeFilter(ClassModel::TAX_CLASS_TYPE_PRODUCT)
                ->setOrder('class_name', 'ASC');
            foreach ($collection as $taxClass) {
                $this->options[] = [
                    'value' => (string) $taxClass->getClassId(),
                    'label' => $taxClass->getClassName(),
                ];
            }
        }
        return $this->options;
    }
}
