<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Block\Adminhtml\Design;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Header extends Field
{

    const MODULE_SUPPORT_LINK = 'https://support.girofeeds.com/hc/en-us';

    protected $_template = 'Girofeeds_ProductApi::system/config/fieldset/header.phtml';

    public function render(AbstractElement $element): string
    {
        $element->addClass('girofeeds');
        return $this->toHtml();
    }

    public function getSupportLink(): string
    {
        return self::MODULE_SUPPORT_LINK;
    }
}
