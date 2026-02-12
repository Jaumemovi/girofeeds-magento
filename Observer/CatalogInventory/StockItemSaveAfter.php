<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Observer\CatalogInventory;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Girofeeds\ProductApi\Logger\DebugLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class StockItemSaveAfter implements ObserverInterface
{

    private const XML_PATH_DEBUG = 'girofeeds_productapi/general/debug';

    /**
     * @var DebugLogger
     */
    private $debugLogger;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Constructor
     *
     * @param DebugLogger $debugLogger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        DebugLogger $debugLogger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->debugLogger = $debugLogger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        try {
            $stockItem = $observer->getEvent()->getItem();
            if ($stockItem && $stockItem->getProductId()) {
                $this->debugLogger->addLog(
                    'StockItemSaveAfter',
                    sprintf(
                        'Stock updated for product ID %s: qty=%s, is_in_stock=%s',
                        $stockItem->getProductId(),
                        $stockItem->getQty(),
                        $stockItem->getIsInStock() ? '1' : '0'
                    )
                );
            }
        } catch (\Exception $e) {
            $this->debugLogger->addLog('StockItemSaveAfter', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    private function isDebugEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_DEBUG,
            ScopeInterface::SCOPE_STORE
        );
    }
}
