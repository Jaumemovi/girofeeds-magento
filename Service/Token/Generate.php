<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Girofeeds\ProductApi\Service\Token;

use Girofeeds\ProductApi\Api\Config\RepositoryInterface as ConfigProvider;

class Generate
{
    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @param ConfigProvider $configProvider
     */
    public function __construct(
        ConfigProvider $configProvider
    ) {
        $this->configProvider = $configProvider;
    }

    /**
     * @param bool $force
     * @return void
     */
    public function execute(bool $force = false): void
    {
        if ($force || !$this->configProvider->getApiKey()) {
            $this->configProvider->setApiKey(
                $this->getRandomString()
            );
        }
    }

    /**
     * @return string
     */
    private function getRandomString(): string
    {
        return bin2hex(random_bytes(16));
    }
}