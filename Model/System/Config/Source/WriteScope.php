<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\System\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Write scope source class: All Store Views (store_id 0) or a single store view code
 */
class WriteScope implements OptionSourceInterface
{

    const ALL = 'all';

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * Options array
     *
     * @var array
     */
    public $options = null;

    /**
     * WriteScope constructor.
     *
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(StoreManagerInterface $storeManager)
    {
        $this->storeManager = $storeManager;
    }

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        if (!$this->options) {
            $this->options = [
                ['value' => self::ALL, 'label' => __('All Store Views (store_id 0) — recommended')],
            ];
            foreach ($this->storeManager->getStores(false) as $store) {
                $this->options[] = [
                    'value' => $store->getCode(),
                    'label' => __('Store view: %1 (%2)', $store->getName(), $store->getCode()),
                ];
            }
        }
        return $this->options;
    }
}
