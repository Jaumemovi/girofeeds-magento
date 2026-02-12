<?php
/**
 * Copyright © 2019 Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Girofeeds\ProductApi\Model\System\Config\Source\Configurable;

use Magento\Framework\Option\ArrayInterface;

/**
 * Class Link
 *
 * @package Girofeeds\ProductApi\Model\System\Config\Source\Configurable
 */
class Link implements ArrayInterface
{

    /**
     * Options array
     *
     * @var array
     */
    public $options = null;

    /**
     * @return array
     */
    public function toOptionArray()
    {
        if (!$this->options) {
            $this->options = [
                ['value' => '0', 'label' => __('No')],
                ['value' => '1', 'label' => __('Yes')],
                ['value' => '2', 'label' => __('Yes, with Auto-Link (Recommended)')],
            ];
        }
        return $this->options;
    }
}
