<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Console\Command;

use Girofeeds\ProductApi\Service\Scope\ValueRepair;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Move the own values of a store view to All Store Views (store_id 0) and delete them from the view.
 *
 * Dry-run by default; --apply writes, with an automatic backup restorable by girofeeds:scope:restore.
 */
class ScopeRepair extends Command
{
    const COMMAND_NAME = 'girofeeds:scope:repair';

    const DEFAULT_BATCH_SIZE = 500;
    const MAX_BATCH_SIZE = 10000;

    const SAMPLE_VALUE_LENGTH = 60;

    /**
     * @var ValueRepair
     */
    private $valueRepair;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param ValueRepair $valueRepair
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ValueRepair $valueRepair,
        StoreManagerInterface $storeManager
    ) {
        $this->valueRepair = $valueRepair;
        $this->storeManager = $storeManager;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_NAME);
        $this->setDescription(
            'Promote the own values of a store view to All Store Views and remove them from the view'
        );
        $this->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view code or id (e.g. default)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only, write nothing (default)');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Execute the repair (with automatic backup)');
        $this->addOption(
            'exclude',
            null,
            InputOption::VALUE_REQUIRED,
            'Comma separated attribute codes that are not promoted (store rows are only removed;'
            . ' products without a global value are still promoted)'
        );
        $this->addOption(
            'promote-global',
            null,
            InputOption::VALUE_NONE,
            'Also promote attributes with Global scope (by default their store rows are only removed)'
        );
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma separated attribute codes to process');
        $this->addOption(
            'batch-size',
            null,
            InputOption::VALUE_REQUIRED,
            'Products per transaction',
            (string) self::DEFAULT_BATCH_SIZE
        );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        if ($apply && $input->getOption('dry-run')) {
            return $this->fail($output, '--dry-run y --apply son incompatibles; usa solo uno.');
        }

        $batchSize = (string) $input->getOption('batch-size');
        if (!ctype_digit($batchSize) || (int) $batchSize < 1 || (int) $batchSize > self::MAX_BATCH_SIZE) {
            return $this->fail($output, sprintf(
                '--batch-size debe ser un entero entre 1 y %d.',
                self::MAX_BATCH_SIZE
            ));
        }
        $batchSize = (int) $batchSize;

        try {
            $store = $this->resolveStore((string) $input->getOption('store'));
            $this->valueRepair->assertOpenSourceSchema();
            $productAttributes = $this->valueRepair->getProductAttributes();
            $only = $this->parseCodes($input->getOption('only'), $productAttributes, '--only');
            $exclude = $this->parseCodes($input->getOption('exclude'), $productAttributes, '--exclude');
        } catch (\RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }

        $attributes = [];
        foreach ($productAttributes as $code => $attribute) {
            if (!in_array($attribute['backend_type'], ValueRepair::BACKEND_TYPES, true)) {
                continue;
            }
            if ($only && !in_array($code, $only, true)) {
                continue;
            }
            $attributes[$code] = $attribute;
        }
        $promoteGlobal = (bool) $input->getOption('promote-global');

        $storeId = (int) $store->getId();
        $output->writeln(sprintf(
            '<info>Vista: %s (%s, store_id %d) · modo: %s</info>',
            $store->getName(),
            $store->getCode(),
            $storeId,
            $apply ? 'APLICAR' : 'simulación (dry-run)'
        ));

        $analysis = $this->valueRepair->analyze($storeId, $attributes);
        if (!$analysis) {
            $output->writeln('No hay valores propios en esta vista para los atributos indicados. Nada que hacer.');
            return Cli::RETURN_SUCCESS;
        }

        foreach ($analysis as $index => $item) {
            $analysis[$index]['mode'] = $this->resolveMode($item['attribute'], $exclude, $promoteGlobal);
        }

        $this->printReport($output, $analysis, $promoteGlobal);
        $analyzedAttributes = [];
        foreach ($analysis as $item) {
            $analyzedAttributes[$item['attribute']['code']] = $item['attribute'];
        }
        $output->writeln(sprintf(
            'Productos con algún valor propio en la vista: %d',
            $this->valueRepair->countProducts($storeId, $analyzedAttributes)
        ));
        $this->printWarnings($output, $analysis);
        if ($only) {
            $ignored = array_diff($exclude, $only);
            if ($ignored) {
                $output->writeln(sprintf(
                    '<comment>Aviso: %s está en --exclude pero no en --only; no se procesa.</comment>',
                    implode(', ', $ignored)
                ));
            }
        }

        if (!$apply) {
            $output->writeln('');
            $output->writeln('<comment>Simulación: no se ha escrito nada. Añade --apply para ejecutar.</comment>');
            return Cli::RETURN_SUCCESS;
        }

        return $this->apply($output, $analysis, $storeId, $batchSize);
    }

    /**
     * @param OutputInterface $output
     * @param array $analysis items with 'mode'
     * @param int $storeId
     * @param int $batchSize
     * @return int
     */
    private function apply(OutputInterface $output, array $analysis, int $storeId, int $batchSize): int
    {
        $suffix = date('YmdHis');
        $createdAt = date('Y-m-d H:i:s');
        $types = [];
        foreach ($analysis as $item) {
            $types[$item['table']] = true;
        }

        try {
            $backupTables = $this->valueRepair->createBackupTables(array_keys($types), $suffix);
        } catch (\Exception $e) {
            return $this->fail($output, $e->getMessage());
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Aplicando. Sufijo del respaldo: %s</info>', $suffix));
        $totals = ['deleted' => 0, 'updated' => 0, 'created' => 0];

        foreach ($analysis as $item) {
            $attribute = $item['attribute'];
            $stats = ['deleted' => 0, 'updated' => 0, 'created' => 0];
            $lastEntityId = 0;

            try {
                while (true) {
                    $entityIds = $this->valueRepair->getEntityIdBatch($attribute, $storeId, $lastEntityId, $batchSize);
                    if (!$entityIds) {
                        break;
                    }
                    $batchStats = $this->valueRepair->repairBatch(
                        $attribute,
                        $storeId,
                        $entityIds,
                        $item['mode'],
                        $backupTables[$item['table']],
                        $createdAt
                    );
                    foreach ($batchStats as $key => $value) {
                        $stats[$key] += $value;
                    }
                    $lastEntityId = (int) max($entityIds);
                }
            } catch (\Exception $e) {
                $output->writeln(sprintf(
                    '<error>Error en el atributo %s (lote desde entity_id > %d): %s</error>',
                    $attribute['code'],
                    $lastEntityId,
                    $e->getMessage()
                ));
                $output->writeln('El lote fallido se ha revertido y el comando se ha detenido.');
                $output->writeln(sprintf('Sufijo del respaldo (lotes ya aplicados): %s', $suffix));
                $output->writeln(sprintf(
                    'Para deshacer lo aplicado: bin/magento girofeeds:scope:restore --backup=%s',
                    $suffix
                ));
                $this->printPostSteps($output);
                return Cli::RETURN_FAILURE;
            }

            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }
            $output->writeln(sprintf(
                '  [%s] %s: %d filas de la vista eliminadas, %d valores globales actualizados, %d creados%s',
                $item['table'],
                $attribute['code'],
                $stats['deleted'],
                $stats['updated'],
                $stats['created'],
                $this->applyNote($item['mode'])
            ));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Terminado: %d filas de la vista eliminadas, %d valores globales actualizados, %d creados.</info>',
            $totals['deleted'],
            $totals['updated'],
            $totals['created']
        ));
        $output->writeln(sprintf('<info>Sufijo del respaldo: %s</info>', $suffix));
        $output->writeln(sprintf('Deshacer: bin/magento girofeeds:scope:restore --backup=%s', $suffix));
        $this->printPostSteps($output);

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param OutputInterface $output
     * @param array $analysis items with 'mode'
     * @param bool $promoteGlobal
     * @return void
     */
    private function printReport(OutputInterface $output, array $analysis, bool $promoteGlobal)
    {
        $table = new Table($output);
        $table->setHeaders(
            ['Atributo', 'Tabla', 'Ámbito', 'Filas en la vista', 'Global distinto', 'Sin global', 'Acción']
        );
        $totals = ['rows' => 0, 'different' => 0, 'missing' => 0];
        foreach ($analysis as $item) {
            $table->addRow([
                $item['attribute']['code'],
                $item['table'],
                $this->scopeLabel($item['attribute']['is_global']),
                (string) $item['rows'],
                (string) $item['different'],
                (string) $item['missing'],
                $this->actionLabel($item, $promoteGlobal),
            ]);
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $item[$key];
            }
        }
        $table->addRow([
            'TOTAL (' . count($analysis) . ' atributos)',
            '',
            '',
            (string) $totals['rows'],
            (string) $totals['different'],
            (string) $totals['missing'],
            '',
        ]);
        $table->render();

        $output->writeln('');
        $output->writeln('Ejemplos (primeros ' . ValueRepair::SAMPLE_SIZE . ' que difieren; id · vista · global):');
        foreach ($analysis as $item) {
            if (!$item['samples']) {
                continue;
            }
            $output->writeln(sprintf('  %s', $item['attribute']['code']));
            foreach ($item['samples'] as $sample) {
                $output->writeln(sprintf(
                    '    #%d · %s · %s',
                    (int) $sample['entity_id'],
                    $this->formatValue($sample['store_value']),
                    (int) $sample['global_missing'] ? '(sin valor global)' : $this->formatValue($sample['global_value'])
                ));
            }
        }
        $output->writeln('');
    }

    /**
     * @param OutputInterface $output
     * @param array $analysis items with 'mode'
     * @return void
     */
    private function printWarnings(OutputInterface $output, array $analysis)
    {
        $globalCleanup = [];
        $globalLost = 0;
        $excludedMissing = 0;
        foreach ($analysis as $item) {
            if ($item['mode'] === ValueRepair::MODE_CLEANUP) {
                $globalCleanup[] = $item['attribute']['code'];
                $globalLost += $item['missing'];
            } elseif ($item['mode'] === ValueRepair::MODE_MISSING_ONLY) {
                $excludedMissing += $item['missing'];
            }
        }
        if ($globalCleanup) {
            $output->writeln(sprintf(
                '<comment>Aviso: %s tienen ámbito Global. La tienda solo muestra su valor de All Store Views,'
                . ' así que sus filas de la vista no se ven: se eliminan sin promocionar (con respaldo).'
                . ' Usa --promote-global solo si quieres que el valor de la vista pase a ser el global.</comment>',
                implode(', ', $globalCleanup)
            ));
            if ($globalLost > 0) {
                $output->writeln(sprintf(
                    '<comment>Aviso: %d de esas filas no tienen valor global; al eliminarlas, el valor de la vista'
                    . ' (hoy no visible) solo queda en el respaldo.</comment>',
                    $globalLost
                ));
            }
        }
        if ($excludedMissing > 0) {
            $output->writeln(sprintf(
                '<comment>Aviso: %d productos de atributos en --exclude no tienen valor global; su valor de la'
                . ' vista se promociona igualmente para no perderlo en todas las vistas.</comment>',
                $excludedMissing
            ));
        }

        $storeCount = count($this->storeManager->getStores(false));
        if ($storeCount > 1) {
            $output->writeln(sprintf(
                '<comment>Aviso: hay %d vistas de tienda. Los valores promocionados a All Store Views se verán'
                . ' en todas las vistas que no tengan valor propio.</comment>',
                $storeCount
            ));
        }
    }

    /**
     * @param OutputInterface $output
     * @return void
     */
    private function printPostSteps(OutputInterface $output)
    {
        $output->writeln('');
        $output->writeln('Pasos manuales pendientes:');
        $output->writeln(
            '  bin/magento indexer:reindex catalog_product_attribute catalog_product_price catalogsearch_fulltext'
            . ' catalog_product_flat'
        );
        $output->writeln('    (catalog_product_flat solo si existe ese indexador en la instalación)');
        $output->writeln('  bin/magento cache:flush');
    }

    /**
     * @param string $value
     * @return \Magento\Store\Api\Data\StoreInterface
     * @throws \RuntimeException
     */
    private function resolveStore(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            throw new \RuntimeException('Falta --store (código o id de la vista, p. ej. --store=default).');
        }
        if (in_array(strtolower($value), ['0', 'admin', 'all'], true)) {
            throw new \RuntimeException(
                'La vista indicada es All Store Views (store_id 0): ahí no hay nada que reparar.'
            );
        }

        try {
            $store = $this->storeManager->getStore($value);
        } catch (NoSuchEntityException $e) {
            throw new \RuntimeException(sprintf('No existe la vista "%s".', $value));
        }
        if ((int) $store->getId() === 0) {
            throw new \RuntimeException(
                'La vista indicada es All Store Views (store_id 0): ahí no hay nada que reparar.'
            );
        }

        return $store;
    }

    /**
     * Parse a comma separated list of attribute codes; every code must be a product EAV attribute.
     *
     * @param mixed $value
     * @param array $productAttributes
     * @param string $option
     * @return string[]
     * @throws \RuntimeException
     */
    private function parseCodes($value, array $productAttributes, string $option): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen')));
        $unknown = [];
        foreach ($codes as $code) {
            if (!isset($productAttributes[$code])
                || !in_array($productAttributes[$code]['backend_type'], ValueRepair::BACKEND_TYPES, true)
            ) {
                $unknown[] = $code;
            }
        }
        if ($unknown) {
            throw new \RuntimeException(sprintf(
                '%s: atributos de producto desconocidos o sin valores por vista (estáticos): %s. No se ha hecho nada.',
                $option,
                implode(', ', $unknown)
            ));
        }

        return $codes;
    }

    /**
     * Global scope without --promote-global: cleanup only. Excluded: only the missing global rows
     * are created (a value is never lost in every view). Otherwise: full promotion.
     *
     * @param array $attribute
     * @param string[] $exclude
     * @param bool $promoteGlobal
     * @return string ValueRepair::MODE_*
     */
    private function resolveMode(array $attribute, array $exclude, bool $promoteGlobal): string
    {
        if ($attribute['is_global'] === ValueRepair::SCOPE_GLOBAL && !$promoteGlobal) {
            return ValueRepair::MODE_CLEANUP;
        }
        if (in_array($attribute['code'], $exclude, true)) {
            return ValueRepair::MODE_MISSING_ONLY;
        }
        return ValueRepair::MODE_FULL;
    }

    /**
     * @param array $item analysis item with 'mode'
     * @param bool $promoteGlobal
     * @return string
     */
    private function actionLabel(array $item, bool $promoteGlobal): string
    {
        if ($item['mode'] === ValueRepair::MODE_CLEANUP) {
            return 'ámbito Global: solo limpieza';
        }
        if ($item['mode'] === ValueRepair::MODE_MISSING_ONLY) {
            return $item['missing'] > 0
                ? sprintf('solo limpieza (--exclude); %d sin global se promocionan', $item['missing'])
                : 'solo limpieza (--exclude)';
        }
        if ($promoteGlobal && $item['attribute']['is_global'] === ValueRepair::SCOPE_GLOBAL) {
            return 'promocionar + limpiar (ámbito Global: se promociona por --promote-global)';
        }
        return 'promocionar + limpiar';
    }

    /**
     * @param string $mode
     * @return string
     */
    private function applyNote(string $mode): string
    {
        if ($mode === ValueRepair::MODE_CLEANUP) {
            return ' (ámbito Global: solo limpieza)';
        }
        if ($mode === ValueRepair::MODE_MISSING_ONLY) {
            return ' (excluido: solo limpieza; creados = productos sin global promocionados)';
        }
        return '';
    }

    /**
     * @param int|null $isGlobal
     * @return string
     */
    private function scopeLabel($isGlobal): string
    {
        if ($isGlobal === ValueRepair::SCOPE_GLOBAL) {
            return 'Global';
        }
        if ($isGlobal === 2) {
            return 'Website';
        }
        if ($isGlobal === 0) {
            return 'Store View';
        }
        return '-';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function formatValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        $value = preg_replace('/\s+/u', ' ', (string) $value);
        if ($value === null) {
            $value = '(valor no imprimible)';
        }
        if (function_exists('mb_strlen') && mb_strlen($value) > self::SAMPLE_VALUE_LENGTH) {
            $value = mb_substr($value, 0, self::SAMPLE_VALUE_LENGTH) . '…';
        } elseif (strlen($value) > self::SAMPLE_VALUE_LENGTH * 4) {
            $value = substr($value, 0, self::SAMPLE_VALUE_LENGTH) . '…';
        }
        return '"' . $value . '"';
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return int
     */
    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln(sprintf('<error>%s</error>', $message));
        return Cli::RETURN_FAILURE;
    }
}
