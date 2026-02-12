<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Backend\Block\Template\Context;

class StoreDomain extends Field
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
        $storeId = (int) $element->getScopeId();
        try {
            if ($storeId) {
                $baseUrl = $this->storeManager->getStore($storeId)->getBaseUrl();
            } else {
                $baseUrl = $this->storeManager->getDefaultStoreView()->getBaseUrl();
            }
        } catch (\Exception $e) {
            $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        }

        $baseUrl = rtrim($baseUrl, '/');

        $element->setData('value', $baseUrl);
        $element->setReadonly(true);
        $element->addClass('girofeeds-readonly-field');

        return $element->getElementHtml();
    }
}
