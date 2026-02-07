<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magmodules\Channable\Plugin;

use Magento\InventorySalesApi\Api\Data\ProductSalableResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Class AfterGetBackorders
 *
 */
class AfterGetBackorders
{

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * AfterGetBackorders constructor.
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        CheckoutSession $checkoutSession
    ) {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Force allow backorders for adding products to order.
     *
     * Out-of-stock products: depending on configuration setting
     * LVB Orders: these orders are shipped from external warehouse
     *
     * @param $subject
     * @param int $result
     * @return int $result
     */
    public function afterGetBackorders(
        $subject,
        $result
    ) {
        if ($this->checkoutSession->getChannableSkipQtyCheck()
            && interface_exists(ProductSalableResultInterface::class)
        ) {
            return 1;
        }

        return $result;
    }
}
