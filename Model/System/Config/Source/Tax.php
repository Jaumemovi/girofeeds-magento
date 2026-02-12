<?php
/**
 * Copyright © 2019 Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Girofeeds\ProductApi\Model\System\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Class Tax
 *
 * @package Girofeeds\ProductApi\Model\System\Config\Source
 */
class Tax implements ArrayInterface
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
                ['value' => '', 'label' => __('Default')],
                ['value' => 'true', 'label' => __('Force Add Tax')],
            ];
        }
        return $this->options;
    }
}
