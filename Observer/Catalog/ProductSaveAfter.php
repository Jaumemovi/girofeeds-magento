<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Observer\Catalog;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Girofeeds\ProductApi\Logger\DebugLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ProductSaveAfter implements ObserverInterface
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
            $product = $observer->getData('product');
            if ($product && $product->getId()) {
                $this->debugLogger->addLog(
                    'ProductSaveAfter',
                    sprintf('Product ID %s (%s) saved', $product->getId(), $product->getSku())
                );
            }
        } catch (\Exception $e) {
            $this->debugLogger->addLog('ProductSaveAfter', 'Error: ' . $e->getMessage());
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
