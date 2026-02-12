<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Girofeeds\ProductApi\Helper\General as GeneralHelper;

class Feed extends AbstractHelper
{

    const XPATH_ENABLE = 'girofeeds_productapi/general/enable';
    const XPATH_LAST_FETCHED = 'girofeeds_productapi/results/last_fetched';

    private $generalHelper;
    private $storeManager;
    private $datetime;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        DateTime $datetime,
        GeneralHelper $generalHelper
    ) {
        $this->generalHelper = $generalHelper;
        $this->datetime = $datetime;
        $this->storeManager = $storeManager;
        parent::__construct($context);
    }

    public function getConfigData()
    {
        $feedData = [];
        $stores = $this->storeManager->getStores();
        foreach ($stores as $store) {
            $storeId = $store->getStoreId();
            $feedData[$storeId] = [
                'store_id'     => $storeId,
                'code'         => $store->getCode(),
                'name'         => $store->getName(),
                'is_active'    => $store->getIsActive(),
                'status'       => $this->generalHelper->getStoreValue(self::XPATH_ENABLE, $storeId),
                'json_url'     => $this->getJsonUrl($storeId),
                'last_fetched' => $this->getLastFetched($storeId)
            ];
        }
        return $feedData;
    }

    public function getJsonUrl($storeId)
    {
        $url = $this->storeManager->getStore($storeId)->getBaseUrl();
        $storeCode = $this->storeManager->getStore($storeId)->getCode();
        $apiKey = $this->generalHelper->getApiKey();
        return $url . sprintf('girofeeds/feed/json?store=%s&key=%s', $storeCode, $apiKey);
    }

    public function getLastFetched($storeId)
    {
        return $this->generalHelper->getUncachedStoreValue(self::XPATH_LAST_FETCHED, $storeId);
    }

    public function getFeedSummary($timeStart, $count, $limit, $productQty, $page = 1, $pages = 1)
    {
        $summary = [];
        $summary['system'] = 'Magento 2';
        $summary['extension'] = 'Girofeeds_ProductApi';
        $summary['version'] = $this->generalHelper->getExtensionVersion();
        $summary['magento_version'] = $this->generalHelper->getMagentoVersion();
        $summary['magento_edition'] = $this->generalHelper->getMagentoEdition();
        $summary['products_total'] = $count;
        $summary['products_limit'] = $limit;
        $summary['products_output'] = $productQty;
        $summary['products_pages'] = $pages;
        $summary['current_page'] = ($page) ? $page : 1;
        if ($pages > $summary['current_page']) {
            $summary['next_page'] = 'true';
        } else {
            $summary['next_page'] = 'false';
        }
        $summary['time'] = number_format((microtime(true) - $timeStart), 2) . ' sec';
        $summary['date'] = $this->datetime->gmtDate();
        return $summary;
    }

    public function setLastFetched($storeId, $ip)
    {
        $date = $this->generalHelper->getLocalDateTime();
        $msg = sprintf('%s (IP: %s)', $date, $ip);
        $this->generalHelper->setConfigData($msg, self::XPATH_LAST_FETCHED, $storeId);
    }
}
