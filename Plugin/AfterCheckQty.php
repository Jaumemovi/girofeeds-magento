<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magmodules\Channable\Plugin;

use Magento\CatalogInventory\Model\StockState;
use Magento\Checkout\Model\Session as CheckoutSession;

class AfterCheckQty
{

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * AfterCheckQty constructor.
     *
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        CheckoutSession $checkoutSession
    ) {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Skip CheckQty for adding products to order.
     *
     * Out-of-stock products: depending on configuration setting
     * LVB Orders: these orders are shipped from external warehouse
     *
     * @param StockState $subject
     * @param $result
     *
     * @return mixed
     */
    public function afterCheckQty(StockState $subject, $result)
    {
        if ($this->checkoutSession->getChannableSkipQtyCheck()) {
            return true;
        }

        return $result;
    }
}
