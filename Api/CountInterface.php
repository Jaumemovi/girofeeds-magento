<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Api;

interface CountInterface
{
    /**
     * Get product count
     *
     * @return string
     */
    public function getCount(): string;
}
