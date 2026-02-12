<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Api;

interface UpdateProductInterface
{
    /**
     * Execute product update
     *
     * @return string
     */
    public function execute(): string;
}
