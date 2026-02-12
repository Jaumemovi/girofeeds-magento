<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Backend\Block\Template\Context;

class StoreViews extends Field
{

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $stores = $this->storeManager->getStores();
        $storeList = [];

        foreach ($stores as $store) {
            if ($store->getIsActive()) {
                $storeList[] = sprintf('%s (%s)', $store->getCode(), $store->getName());
            }
        }

        $value = implode(', ', $storeList);
        if (empty($value)) {
            $value = 'default';
        }

        $element->setData('value', $value);
        $element->setReadonly(true);
        $element->addClass('girofeeds-readonly-field');

        return $element->getElementHtml();
    }
}
