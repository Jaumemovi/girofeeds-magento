<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magmodules\Channable\Plugin;

use Magento\InventorySalesApi\Api\Data\ProductSalableResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Class AroundIsSalableWithReservationsCondition
 *
 * This class has also a hidden dependency not listed in the constuctor:
 * - \Magento\InventorySalesApi\Api\Data\ProductSalableResultInterface
 *
 * This class is only loaded when MSI is enabled, but when setup:di:compile runs it will still fail on this class
 * in Magento 2.2 because it doesn't exist. That's why they are using the object manager.
 */
class AroundIsSalableWithReservationsCondition
{

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * AroundIsSalableWithReservationsCondition constructor.
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        CheckoutSession $checkoutSession
    ) {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Skip MSI Salable With Reservations check on submitting quote
     *
     * Out-of-stock products: depending on configuration setting
     * LVB Orders: these orders are shipped from external warehouse
     *
     * @param $subject
     * @param \Closure $proceed
     * @param string $sku
     * @param int $stockId
     * @param float $requestedQty
     * @return mixed
     */
    public function aroundExecute(
        $subject,
        $proceed,
        string $sku,
        int $stockId,
        float $requestedQty
    ) {
        if ($this->checkoutSession->getChannableSkipReservation()
            && interface_exists(ProductSalableResultInterface::class)
        ) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

            return $objectManager->getInstance()->create(
                ProductSalableResultInterface::class,
                ['errors' => []]
            );
        }
        return $proceed($sku, $stockId, $requestedQty);
    }
}
