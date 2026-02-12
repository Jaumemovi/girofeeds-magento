<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Api;

interface FeedInterface
{
    /**
     * Get product feed
     *
     * @param string $key
     * @param string $store
     * @param int $limit
     * @param int $offset
     * @param string|null $pid
     * @param string|null $currency
     * @return array
     */
    public function getFeed(
        string $key,
        string $store,
        int $limit = 50,
        int $offset = 0,
        ?string $pid = null,
        ?string $currency = null
    ): array;
}
