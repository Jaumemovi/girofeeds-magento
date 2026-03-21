<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Service\Product;

use Magento\Framework\App\ResourceConnection;

class OrderCountData
{

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var array
     */
    private $orderCounts = [];

    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection = $resourceConnection;
    }

    public function load(array $productIds, array $config): void
    {
        $this->orderCounts = [];

        if (empty($productIds)
            || empty($config['orders_count']['enable'])
            || empty($config['orders_count']['statuses'])
        ) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $date1d = (clone $now)->modify('-1 day')->format('Y-m-d H:i:s');
        $date7d = (clone $now)->modify('-7 days')->format('Y-m-d H:i:s');
        $date30d = (clone $now)->modify('-30 days')->format('Y-m-d H:i:s');
        $date90d = (clone $now)->modify('-90 days')->format('Y-m-d H:i:s');
        $date365d = (clone $now)->modify('-365 days')->format('Y-m-d H:i:s');

        $statuses = $config['orders_count']['statuses'];
        $storeId = (int) $config['store_id'];

        $select = $connection->select()
            ->from(
                ['soi' => $orderItemTable],
                [
                    'product_id' => 'soi.product_id',
                    'orders_1d' => new \Zend_Db_Expr(
                        'SUM(CASE WHEN so.created_at >= ' . $connection->quote($date1d)
                        . ' THEN FLOOR(soi.qty_ordered) ELSE 0 END)'
                    ),
                    'orders_7d' => new \Zend_Db_Expr(
                        'SUM(CASE WHEN so.created_at >= ' . $connection->quote($date7d)
                        . ' THEN FLOOR(soi.qty_ordered) ELSE 0 END)'
                    ),
                    'orders_30d' => new \Zend_Db_Expr(
                        'SUM(CASE WHEN so.created_at >= ' . $connection->quote($date30d)
                        . ' THEN FLOOR(soi.qty_ordered) ELSE 0 END)'
                    ),
                    'orders_90d' => new \Zend_Db_Expr(
                        'SUM(CASE WHEN so.created_at >= ' . $connection->quote($date90d)
                        . ' THEN FLOOR(soi.qty_ordered) ELSE 0 END)'
                    ),
                    'orders_365d' => new \Zend_Db_Expr(
                        'SUM(CASE WHEN so.created_at >= ' . $connection->quote($date365d)
                        . ' THEN FLOOR(soi.qty_ordered) ELSE 0 END)'
                    ),
                ]
            )
            ->join(
                ['so' => $orderTable],
                'soi.order_id = so.entity_id',
                []
            )
            ->where('soi.product_id IN (?)', $productIds)
            ->where('so.status IN (?)', $statuses)
            ->where('so.store_id = ?', $storeId)
            ->group('soi.product_id');

        $results = $connection->fetchAll($select);

        foreach ($results as $row) {
            $this->orderCounts[(int) $row['product_id']] = [
                'orders_1d' => (int) $row['orders_1d'],
                'orders_7d' => (int) $row['orders_7d'],
                'orders_30d' => (int) $row['orders_30d'],
                'orders_90d' => (int) $row['orders_90d'],
                'orders_365d' => (int) $row['orders_365d'],
            ];
        }
    }

    public function getOrderCounts(int $productId): array
    {
        if (isset($this->orderCounts[$productId])) {
            return $this->orderCounts[$productId];
        }

        return [
            'orders_1d' => 0,
            'orders_7d' => 0,
            'orders_30d' => 0,
            'orders_90d' => 0,
            'orders_365d' => 0,
        ];
    }
}
