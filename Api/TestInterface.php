<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Api;

interface TestInterface
{
    /**
     * Test API connection
     *
     * @return string
     */
    public function test(): string;
}
