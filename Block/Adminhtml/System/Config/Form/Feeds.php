<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Block\Adminhtml\System\Config\Form;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Girofeeds\ProductApi\Helper\Feed as FeedHelper;

class Feeds extends Field
{

    protected $_template = 'Girofeeds_ProductApi::system/config/fieldset/feeds.phtml';

    private $feedHelper;

    public function __construct(
        Context $context,
        FeedHelper $feedHelper
    ) {
        $this->feedHelper = $feedHelper;
        parent::__construct($context);
    }

    public function getCacheLifetime()
    {
        return null;
    }

    public function render(AbstractElement $element)
    {
        $element->addClass('girofeeds');
        return $this->toHtml();
    }

    public function getFeedData()
    {
        return $this->feedHelper->getConfigData();
    }
}
