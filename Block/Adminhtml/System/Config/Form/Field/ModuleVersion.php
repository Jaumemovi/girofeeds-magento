<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Backend\Block\Template\Context;

class ModuleVersion extends Field
{

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    public function __construct(
        Context $context,
        ModuleListInterface $moduleList,
        array $data = []
    ) {
        $this->moduleList = $moduleList;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $moduleInfo = $this->moduleList->getOne('Girofeeds_ProductApi');
        $version = $moduleInfo['setup_version'] ?? '1.24.0';

        $element->setData('value', $version);
        $element->setReadonly(true);
        $element->addClass('girofeeds-readonly-field');

        return $element->getElementHtml();
    }
}
