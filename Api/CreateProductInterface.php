<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Api;

interface CreateProductInterface
{
    /**
     * Execute product creation
     *
     * @return string
     */
    public function execute(): string;
}
