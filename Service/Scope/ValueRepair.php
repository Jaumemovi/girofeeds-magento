<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Service\Scope;

use Magento\Framework\App\ResourceConnection;

/**
 * Store view value repair for product EAV attributes (girofeeds:scope:repair / :restore).
 *
 * Works directly on the five product EAV value tables through the DB adapter, never through
 * product saves. Every write runs inside a transaction and every row that is deleted or
 * overwritten is copied first into a girofeeds_scope_backup_<backend type>_<suffix> table.
 */
class ValueRepair
{
    const BACKEND_TYPES = ['varchar', 'text', 'int', 'decimal', 'datetime'];

    const BACKUP_PREFIX = 'girofeeds_scope_backup_';

    const KIND_STORE_ROW = 'store_row';
    const KIND_GLOBAL_BEFORE = 'global_before';
    const KIND_GLOBAL_CREATED = 'global_created';

    const SAMPLE_SIZE = 5;

    const MAX_TABLE_NAME_LENGTH = 64;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface|null
     */
    private $connection;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Abort on Adobe Commerce with staging (row_id link) or on an unexpected value table layout.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function assertOpenSourceSchema()
    {
        foreach (self::BACKEND_TYPES as $type) {
            $table = $this->getValueTable($type);
            $columns = $this->getConnection()->describeTable($table);
            if (isset($columns['row_id'])) {
                throw new \RuntimeException(sprintf(
                    'La tabla %s usa row_id (Adobe Commerce con staging). Este comando solo admite '
                    . 'Magento Open Source (entity_id); no se ha hecho nada.',
                    $table
                ));
            }
            foreach (['value_id', 'attribute_id', 'store_id', 'entity_id', 'value'] as $column) {
                if (!isset($columns[$column])) {
                    throw new \RuntimeException(sprintf(
                        'La tabla %s no tiene la columna %s; estructura no reconocida, no se ha hecho nada.',
                        $table,
                        $column
                    ));
                }
            }
        }
    }

    /**
     * Product attributes indexed by code.
     *
     * @return array code => ['id' => int, 'code' => string, 'backend_type' => string, 'is_global' => int|null]
     */
    public function getProductAttributes(): array
    {
        $connection = $this->getConnection();
        $sql = sprintf(
            'SELECT ea.attribute_id, ea.attribute_code, ea.backend_type, cea.is_global'
            . ' FROM %s AS ea'
            . ' INNER JOIN %s AS et ON et.entity_type_id = ea.entity_type_id'
            . ' LEFT JOIN %s AS cea ON cea.attribute_id = ea.attribute_id'
            . ' WHERE et.entity_type_code = ?',
            $this->quoteTable('eav_attribute'),
            $this->quoteTable('eav_entity_type'),
            $this->quoteTable('catalog_eav_attribute')
        );

        $attributes = [];
        foreach ($connection->fetchAll($sql, ['catalog_product']) as $row) {
            $attributes[(string) $row['attribute_code']] = [
                'id' => (int) $row['attribute_id'],
                'code' => (string) $row['attribute_code'],
                'backend_type' => (string) $row['backend_type'],
                'is_global' => $row['is_global'] === null ? null : (int) $row['is_global'],
            ];
        }

        return $attributes;
    }

    /**
     * Rows of the store view per attribute, how many differ from the global value and samples.
     *
     * A missing global row counts as differing. Values are compared byte by byte (BINARY), so
     * case or trailing space changes are differences.
     *
     * @param int $storeId
     * @param array $attributes code => attribute (EAV backend types only)
     * @return array list of ['attribute' => array, 'table' => string, 'rows' => int, 'differing' => int, 'samples' => array]
     */
    public function analyze(int $storeId, array $attributes): array
    {
        $connection = $this->getConnection();
        $result = [];

        foreach (self::BACKEND_TYPES as $type) {
            $byId = [];
            foreach ($attributes as $attribute) {
                if ($attribute['backend_type'] === $type) {
                    $byId[$attribute['id']] = $attribute;
                }
            }
            if (!$byId) {
                continue;
            }

            $table = $this->quoteTable('catalog_product_entity_' . $type);
            $ids = array_keys($byId);
            $in = $this->placeholders(count($ids));

            $rowCounts = $connection->fetchPairs(
                sprintf(
                    'SELECT attribute_id, COUNT(*) FROM %s WHERE store_id = ? AND attribute_id IN (%s)'
                    . ' GROUP BY attribute_id',
                    $table,
                    $in
                ),
                array_merge([$storeId], $ids)
            );
            if (!$rowCounts) {
                continue;
            }

            $differing = $connection->fetchPairs(
                sprintf(
                    'SELECT s.attribute_id, COUNT(*) FROM %1$s AS s'
                    . ' LEFT JOIN %1$s AS g ON g.entity_id = s.entity_id AND g.attribute_id = s.attribute_id'
                    . ' AND g.store_id = 0'
                    . ' WHERE s.store_id = ? AND s.attribute_id IN (%2$s)'
                    . ' AND (g.value_id IS NULL OR NOT (BINARY s.value <=> BINARY g.value))'
                    . ' GROUP BY s.attribute_id',
                    $table,
                    $in
                ),
                array_merge([$storeId], $ids)
            );

            foreach ($rowCounts as $attributeId => $count) {
                $attributeId = (int) $attributeId;
                $diffCount = isset($differing[$attributeId]) ? (int) $differing[$attributeId] : 0;
                $samples = [];
                if ($diffCount > 0) {
                    $samples = $connection->fetchAll(
                        sprintf(
                            'SELECT s.entity_id, s.value AS store_value, g.value AS global_value,'
                            . ' (g.value_id IS NULL) AS global_missing'
                            . ' FROM %1$s AS s'
                            . ' LEFT JOIN %1$s AS g ON g.entity_id = s.entity_id'
                            . ' AND g.attribute_id = s.attribute_id AND g.store_id = 0'
                            . ' WHERE s.store_id = ? AND s.attribute_id = ?'
                            . ' AND (g.value_id IS NULL OR NOT (BINARY s.value <=> BINARY g.value))'
                            . ' ORDER BY s.entity_id LIMIT %2$d',
                            $table,
                            self::SAMPLE_SIZE
                        ),
                        [$storeId, $attributeId]
                    );
                }
                $result[] = [
                    'attribute' => $byId[$attributeId],
                    'table' => $type,
                    'rows' => (int) $count,
                    'differing' => $diffCount,
                    'samples' => $samples,
                ];
            }
        }

        usort($result, function ($a, $b) {
            return strcmp($a['attribute']['code'], $b['attribute']['code']);
        });

        return $result;
    }

    /**
     * Number of distinct products with at least one own value in the store view for the attributes.
     *
     * @param int $storeId
     * @param array $attributes code => attribute
     * @return int
     */
    public function countProducts(int $storeId, array $attributes): int
    {
        $parts = [];
        $bind = [];
        foreach (self::BACKEND_TYPES as $type) {
            $ids = [];
            foreach ($attributes as $attribute) {
                if ($attribute['backend_type'] === $type) {
                    $ids[] = $attribute['id'];
                }
            }
            if (!$ids) {
                continue;
            }
            $parts[] = sprintf(
                'SELECT entity_id FROM %s WHERE store_id = ? AND attribute_id IN (%s)',
                $this->quoteTable('catalog_product_entity_' . $type),
                $this->placeholders(count($ids))
            );
            $bind = array_merge($bind, [$storeId], $ids);
        }
        if (!$parts) {
            return 0;
        }

        return (int) $this->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM (' . implode(' UNION ', $parts) . ') AS products',
            $bind
        );
    }

    /**
     * Backup table name for a backend type and suffix (with the installation table prefix).
     *
     * @param string $type
     * @param string $suffix
     * @return string
     */
    public function getBackupTable(string $type, string $suffix): string
    {
        return $this->resource->getTableName(self::BACKUP_PREFIX . $type . '_' . $suffix);
    }

    /**
     * Create the (empty) backup tables of a run. DDL runs here, never inside a transaction.
     *
     * The value column copies the type, charset and collation of the source value column.
     *
     * @param array $types backend types
     * @param string $suffix
     * @return array type => backup table name
     * @throws \RuntimeException
     */
    public function createBackupTables(array $types, string $suffix): array
    {
        $connection = $this->getConnection();
        $tables = [];

        foreach ($types as $type) {
            $backupTable = $this->getBackupTable($type, $suffix);
            if (strlen($backupTable) > self::MAX_TABLE_NAME_LENGTH) {
                throw new \RuntimeException(sprintf(
                    'El nombre de la tabla de respaldo %s supera %d caracteres; no se ha hecho nada.',
                    $backupTable,
                    self::MAX_TABLE_NAME_LENGTH
                ));
            }
            if ($connection->isTableExists($backupTable)) {
                throw new \RuntimeException(sprintf(
                    'La tabla de respaldo %s ya existe; espera un segundo y vuelve a lanzar el comando.',
                    $backupTable
                ));
            }
            $tables[$type] = $backupTable;
        }

        foreach ($tables as $type => $backupTable) {
            $valueTable = $this->getValueTable($type);
            $column = $connection->fetchRow(
                'SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$valueTable, 'value']
            );
            if (!$column || !preg_match('/^[a-z]+(\(\d+(,\d+)?\))?( unsigned)?$/', (string) $column['COLUMN_TYPE'])) {
                throw new \RuntimeException(sprintf(
                    'No se ha podido leer el tipo de la columna value de %s; no se ha hecho nada.',
                    $valueTable
                ));
            }
            $valueDefinition = (string) $column['COLUMN_TYPE'];
            if ($column['CHARACTER_SET_NAME'] !== null && $column['COLLATION_NAME'] !== null) {
                if (!preg_match('/^[a-z0-9_]+$/', (string) $column['CHARACTER_SET_NAME'])
                    || !preg_match('/^[a-z0-9_]+$/', (string) $column['COLLATION_NAME'])
                ) {
                    throw new \RuntimeException(sprintf(
                        'Juego de caracteres no reconocido en %s.value; no se ha hecho nada.',
                        $valueTable
                    ));
                }
                $valueDefinition .= sprintf(
                    ' CHARACTER SET %s COLLATE %s',
                    $column['CHARACTER_SET_NAME'],
                    $column['COLLATION_NAME']
                );
            }

            $connection->query(sprintf(
                'CREATE TABLE %s ('
                . ' backup_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' value_id INT UNSIGNED NULL,'
                . ' attribute_id INT UNSIGNED NOT NULL,'
                . ' store_id INT UNSIGNED NOT NULL,'
                . ' entity_id INT UNSIGNED NOT NULL,'
                . ' value %s NULL,'
                . " backup_kind ENUM('%s','%s','%s') NOT NULL,"
                . ' created_at DATETIME NOT NULL,'
                . ' PRIMARY KEY (backup_id),'
                . ' KEY idx_key (entity_id, attribute_id, store_id)'
                . ') ENGINE=InnoDB',
                $connection->quoteIdentifier($backupTable),
                $valueDefinition,
                self::KIND_STORE_ROW,
                self::KIND_GLOBAL_BEFORE,
                self::KIND_GLOBAL_CREATED
            ));
        }

        return $tables;
    }

    /**
     * Next batch of entity ids with an own value of the attribute in the store view.
     *
     * @param array $attribute
     * @param int $storeId
     * @param int $afterEntityId
     * @param int $limit
     * @return int[]
     */
    public function getEntityIdBatch(array $attribute, int $storeId, int $afterEntityId, int $limit): array
    {
        $ids = $this->getConnection()->fetchCol(
            sprintf(
                'SELECT entity_id FROM %s WHERE attribute_id = ? AND store_id = ? AND entity_id > ?'
                . ' ORDER BY entity_id LIMIT %d',
                $this->quoteTable('catalog_product_entity_' . $attribute['backend_type']),
                $limit
            ),
            [$attribute['id'], $storeId, $afterEntityId]
        );

        return array_map('intval', $ids);
    }

    /**
     * Repair one batch of one attribute in a single transaction.
     *
     * 1. back up the store rows; 2. unless $promote is false, back up the global rows that will be
     * overwritten and record the global rows that will be created, then write the store value into
     * store_id 0; 3. delete the store rows. Row counts are checked against the backups; any
     * mismatch rolls the batch back.
     *
     * @param array $attribute
     * @param int $storeId
     * @param int[] $entityIds
     * @param bool $promote
     * @param string $backupTable
     * @param string $createdAt
     * @return array ['deleted' => int, 'updated' => int, 'created' => int]
     * @throws \Exception
     */
    public function repairBatch(
        array $attribute,
        int $storeId,
        array $entityIds,
        bool $promote,
        string $backupTable,
        string $createdAt
    ): array {
        $connection = $this->getConnection();
        $table = $this->quoteTable('catalog_product_entity_' . $attribute['backend_type']);
        $backup = $connection->quoteIdentifier($backupTable);
        $in = $this->placeholders(count($entityIds));
        $keyBind = array_merge([$attribute['id'], $storeId], $entityIds);
        $backupColumns = '(value_id, attribute_id, store_id, entity_id, value, backup_kind, created_at)';
        $globalJoin = sprintf(
            '%s AS g ON g.entity_id = s.entity_id AND g.attribute_id = s.attribute_id AND g.store_id = 0',
            $table
        );
        $storeWhere = sprintf('s.attribute_id = ? AND s.store_id = ? AND s.entity_id IN (%s)', $in);
        $stats = ['deleted' => 0, 'updated' => 0, 'created' => 0];

        $connection->beginTransaction();
        try {
            $connection->fetchCol(
                sprintf(
                    'SELECT value_id FROM %s WHERE attribute_id = ? AND store_id IN (0, ?) AND entity_id IN (%s)'
                    . ' FOR UPDATE',
                    $table,
                    $in
                ),
                $keyBind
            );

            $storeRows = $connection->query(
                sprintf(
                    'INSERT INTO %s %s SELECT s.value_id, s.attribute_id, s.store_id, s.entity_id, s.value, ?, ?'
                    . ' FROM %s AS s WHERE %s',
                    $backup,
                    $backupColumns,
                    $table,
                    $storeWhere
                ),
                array_merge([self::KIND_STORE_ROW, $createdAt], $keyBind)
            )->rowCount();

            if ($promote) {
                $globalBefore = $connection->query(
                    sprintf(
                        'INSERT INTO %s %s SELECT g.value_id, g.attribute_id, g.store_id, g.entity_id, g.value, ?, ?'
                        . ' FROM %s AS s INNER JOIN %s WHERE %s AND NOT (BINARY s.value <=> BINARY g.value)',
                        $backup,
                        $backupColumns,
                        $table,
                        $globalJoin,
                        $storeWhere
                    ),
                    array_merge([self::KIND_GLOBAL_BEFORE, $createdAt], $keyBind)
                )->rowCount();

                $globalCreated = $connection->query(
                    sprintf(
                        'INSERT INTO %s %s SELECT NULL, s.attribute_id, 0, s.entity_id, s.value, ?, ?'
                        . ' FROM %s AS s LEFT JOIN %s WHERE %s AND g.value_id IS NULL',
                        $backup,
                        $backupColumns,
                        $table,
                        $globalJoin,
                        $storeWhere
                    ),
                    array_merge([self::KIND_GLOBAL_CREATED, $createdAt], $keyBind)
                )->rowCount();

                $updated = $connection->query(
                    sprintf(
                        'UPDATE %1$s AS g INNER JOIN %1$s AS s ON s.entity_id = g.entity_id'
                        . ' AND s.attribute_id = g.attribute_id AND s.store_id = ?'
                        . ' SET g.value = s.value'
                        . ' WHERE g.attribute_id = ? AND g.store_id = 0 AND g.entity_id IN (%2$s)'
                        . ' AND NOT (BINARY s.value <=> BINARY g.value)',
                        $table,
                        $in
                    ),
                    array_merge([$storeId, $attribute['id']], $entityIds)
                )->rowCount();
                if ($updated !== $globalBefore) {
                    throw new \RuntimeException(sprintf(
                        'Valores globales actualizados (%d) distintos de los respaldados (%d).',
                        $updated,
                        $globalBefore
                    ));
                }

                $created = $connection->query(
                    sprintf(
                        'INSERT INTO %s (attribute_id, store_id, entity_id, value)'
                        . ' SELECT s.attribute_id, 0, s.entity_id, s.value'
                        . ' FROM %s AS s LEFT JOIN %s WHERE %s AND g.value_id IS NULL',
                        $table,
                        $table,
                        $globalJoin,
                        $storeWhere
                    ),
                    $keyBind
                )->rowCount();
                if ($created !== $globalCreated) {
                    throw new \RuntimeException(sprintf(
                        'Filas globales creadas (%d) distintas de las registradas (%d).',
                        $created,
                        $globalCreated
                    ));
                }

                $stats['updated'] = $updated;
                $stats['created'] = $created;
            }

            $deleted = $connection->query(
                sprintf(
                    'DELETE FROM %s WHERE attribute_id = ? AND store_id = ? AND entity_id IN (%s)',
                    $table,
                    $in
                ),
                $keyBind
            )->rowCount();
            if ($deleted !== $storeRows) {
                throw new \RuntimeException(sprintf(
                    'Filas de la vista eliminadas (%d) distintas de las respaldadas (%d).',
                    $deleted,
                    $storeRows
                ));
            }
            $stats['deleted'] = $deleted;

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * Existing backup tables of a suffix.
     *
     * @param string $suffix
     * @return array type => backup table name
     * @throws \RuntimeException when a table exists but is not a girofeeds scope backup
     */
    public function findBackupTables(string $suffix): array
    {
        $connection = $this->getConnection();
        $tables = [];
        foreach (self::BACKEND_TYPES as $type) {
            $backupTable = $this->getBackupTable($type, $suffix);
            if (!$connection->isTableExists($backupTable)) {
                continue;
            }
            $columns = $connection->describeTable($backupTable);
            foreach (['backup_id', 'backup_kind', 'attribute_id', 'store_id', 'entity_id', 'value'] as $column) {
                if (!isset($columns[$column])) {
                    throw new \RuntimeException(sprintf(
                        'La tabla %s no tiene la estructura de un respaldo de girofeeds:scope:repair.',
                        $backupTable
                    ));
                }
            }
            $tables[$type] = $backupTable;
        }

        return $tables;
    }

    /**
     * Rows per backup kind, store ids involved and backup_id range of a backup table.
     *
     * @param string $backupTable
     * @return array ['kinds' => kind => int, 'stores' => int[], 'min' => int, 'max' => int]
     */
    public function describeBackup(string $backupTable): array
    {
        $connection = $this->getConnection();
        $backup = $connection->quoteIdentifier($backupTable);
        $kinds = $connection->fetchPairs(
            sprintf('SELECT backup_kind, COUNT(*) FROM %s GROUP BY backup_kind', $backup)
        );
        $stores = $connection->fetchCol(
            sprintf('SELECT DISTINCT store_id FROM %s WHERE backup_kind = ?', $backup),
            [self::KIND_STORE_ROW]
        );
        $range = $connection->fetchRow(
            sprintf('SELECT MIN(backup_id) AS min_id, MAX(backup_id) AS max_id FROM %s', $backup)
        );

        return [
            'kinds' => array_map('intval', $kinds),
            'stores' => array_map('intval', $stores),
            'min' => (int) $range['min_id'],
            'max' => (int) $range['max_id'],
        ];
    }

    /**
     * Backup rows that cannot be restored because the product, attribute or store no longer exists.
     *
     * @param string $type
     * @param string $backupTable
     * @return int
     */
    public function countOrphanBackupRows(string $type, string $backupTable): int
    {
        return (int) $this->getConnection()->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM %s AS b'
                . ' LEFT JOIN %s AS e ON e.entity_id = b.entity_id'
                . ' LEFT JOIN %s AS a ON a.attribute_id = b.attribute_id'
                . ' LEFT JOIN %s AS st ON st.store_id = b.store_id'
                . ' WHERE b.backup_kind IN (?, ?)'
                . ' AND (e.entity_id IS NULL OR a.attribute_id IS NULL OR st.store_id IS NULL)',
                $this->getConnection()->quoteIdentifier($backupTable),
                $this->quoteTable('catalog_product_entity'),
                $this->quoteTable('eav_attribute'),
                $this->quoteTable('store')
            ),
            [self::KIND_STORE_ROW, self::KIND_GLOBAL_BEFORE]
        );
    }

    /**
     * Restore the backup rows with backup_id in [$fromId, $toId] in a single transaction.
     *
     * 1. delete the global rows the repair created; 2. put back the saved values (store rows and
     * overwritten global rows) where the row exists and differs; 3. re-insert the saved rows that
     * are missing (skipping products, attributes or stores that no longer exist). Running it
     * again changes nothing.
     *
     * @param string $type
     * @param string $backupTable
     * @param int $fromId
     * @param int $toId
     * @return array ['removed' => int, 'updated' => int, 'inserted' => int]
     * @throws \Exception
     */
    public function restoreChunk(string $type, string $backupTable, int $fromId, int $toId): array
    {
        $connection = $this->getConnection();
        $table = $this->quoteTable('catalog_product_entity_' . $type);
        $backup = $connection->quoteIdentifier($backupTable);
        $keyJoin = 't.entity_id = b.entity_id AND t.attribute_id = b.attribute_id AND t.store_id = b.store_id';
        $savedBind = [self::KIND_STORE_ROW, self::KIND_GLOBAL_BEFORE, $fromId, $toId];

        $connection->beginTransaction();
        try {
            $removed = $connection->query(
                sprintf(
                    'DELETE t FROM %s AS t INNER JOIN %s AS b ON %s'
                    . ' WHERE b.backup_kind = ? AND b.backup_id BETWEEN ? AND ?',
                    $table,
                    $backup,
                    $keyJoin
                ),
                [self::KIND_GLOBAL_CREATED, $fromId, $toId]
            )->rowCount();

            $updated = $connection->query(
                sprintf(
                    'UPDATE %s AS t INNER JOIN %s AS b ON %s SET t.value = b.value'
                    . ' WHERE b.backup_kind IN (?, ?) AND b.backup_id BETWEEN ? AND ?'
                    . ' AND NOT (BINARY t.value <=> BINARY b.value)',
                    $table,
                    $backup,
                    $keyJoin
                ),
                $savedBind
            )->rowCount();

            $inserted = $connection->query(
                sprintf(
                    'INSERT INTO %1$s (attribute_id, store_id, entity_id, value)'
                    . ' SELECT b.attribute_id, b.store_id, b.entity_id, b.value FROM %2$s AS b'
                    . ' INNER JOIN %3$s AS e ON e.entity_id = b.entity_id'
                    . ' INNER JOIN %4$s AS a ON a.attribute_id = b.attribute_id'
                    . ' INNER JOIN %5$s AS st ON st.store_id = b.store_id'
                    . ' LEFT JOIN %1$s AS t ON %6$s'
                    . ' WHERE b.backup_kind IN (?, ?) AND b.backup_id BETWEEN ? AND ? AND t.value_id IS NULL',
                    $table,
                    $backup,
                    $this->quoteTable('catalog_product_entity'),
                    $this->quoteTable('eav_attribute'),
                    $this->quoteTable('store'),
                    $keyJoin
                ),
                $savedBind
            )->rowCount();

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        return ['removed' => $removed, 'updated' => $updated, 'inserted' => $inserted];
    }

    /**
     * @param string $type
     * @return string
     */
    public function getValueTable(string $type): string
    {
        return $this->resource->getTableName('catalog_product_entity_' . $type);
    }

    /**
     * @param string $name
     * @return string
     */
    private function quoteTable(string $name): string
    {
        return $this->getConnection()->quoteIdentifier($this->resource->getTableName($name));
    }

    /**
     * @param int $count
     * @return string
     */
    private function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '?'));
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function getConnection()
    {
        if ($this->connection === null) {
            $this->connection = $this->resource->getConnection();
        }
        return $this->connection;
    }
}
