<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Api;

interface AttributesInterface
{
    /**
     * Get product attribute definitions
     *
     * @return string
     */
    public function getAttributes(): string;
}
