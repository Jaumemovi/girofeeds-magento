<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Console\Command;

use Girofeeds\ProductApi\Service\Scope\ValueRepair;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Undo a girofeeds:scope:repair run from its backup tables. Idempotent.
 */
class ScopeRestore extends Command
{
    const COMMAND_NAME = 'girofeeds:scope:restore';

    const CHUNK_SIZE = 500;

    /**
     * @var ValueRepair
     */
    private $valueRepair;

    /**
     * @param ValueRepair $valueRepair
     */
    public function __construct(ValueRepair $valueRepair)
    {
        $this->valueRepair = $valueRepair;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_NAME);
        $this->setDescription('Restore the store view values saved by girofeeds:scope:repair');
        $this->addOption(
            'backup',
            null,
            InputOption::VALUE_REQUIRED,
            'Backup suffix printed by girofeeds:scope:repair (YYYYMMDDHHMMSS)'
        );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $suffix = trim((string) $input->getOption('backup'));
        if (!preg_match('/^\d{14}$/', $suffix)) {
            return $this->fail(
                $output,
                'Indica el sufijo del respaldo con --backup=AAAAMMDDHHMMSS (lo imprime girofeeds:scope:repair).'
            );
        }

        try {
            $this->valueRepair->assertOpenSourceSchema();
            $backupTables = $this->valueRepair->findBackupTables($suffix);
        } catch (\RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }
        if (!$backupTables) {
            return $this->fail($output, sprintf(
                'No hay tablas de respaldo con el sufijo %s (%s<tipo>_%s). No se ha hecho nada.',
                $suffix,
                ValueRepair::BACKUP_PREFIX,
                $suffix
            ));
        }

        $output->writeln(sprintf('<info>Restaurando el respaldo %s</info>', $suffix));
        $totals = ['removed' => 0, 'updated' => 0, 'inserted' => 0];

        foreach ($backupTables as $type => $backupTable) {
            $info = $this->valueRepair->describeBackup($backupTable);
            $stats = ['removed' => 0, 'updated' => 0, 'inserted' => 0];
            $orphans = $this->valueRepair->countOrphanBackupRows($type, $backupTable);

            try {
                for ($from = $info['min']; $info['max'] > 0 && $from <= $info['max']; $from += self::CHUNK_SIZE) {
                    $chunkStats = $this->valueRepair->restoreChunk(
                        $type,
                        $backupTable,
                        $from,
                        $from + self::CHUNK_SIZE - 1
                    );
                    foreach ($chunkStats as $key => $value) {
                        $stats[$key] += $value;
                    }
                }
            } catch (\Exception $e) {
                $output->writeln(sprintf(
                    '<error>Error restaurando %s (bloque desde backup_id %d): %s</error>',
                    $backupTable,
                    $from,
                    $e->getMessage()
                ));
                $output->writeln(
                    'El bloque fallido se ha revertido. La restauración es idempotente: corrige la causa y'
                    . ' vuelve a lanzar el mismo comando.'
                );
                return Cli::RETURN_FAILURE;
            }

            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }
            $output->writeln(sprintf(
                '  [%s] %s (vistas %s; %d filas de vista, %d globales previas, %d globales creadas):'
                . ' %d globales creadas eliminadas, %d valores repuestos, %d filas reinsertadas%s',
                $type,
                $backupTable,
                $info['stores'] ? implode(',', $info['stores']) : '-',
                isset($info['kinds'][ValueRepair::KIND_STORE_ROW]) ? $info['kinds'][ValueRepair::KIND_STORE_ROW] : 0,
                isset($info['kinds'][ValueRepair::KIND_GLOBAL_BEFORE])
                    ? $info['kinds'][ValueRepair::KIND_GLOBAL_BEFORE] : 0,
                isset($info['kinds'][ValueRepair::KIND_GLOBAL_CREATED])
                    ? $info['kinds'][ValueRepair::KIND_GLOBAL_CREATED] : 0,
                $stats['removed'],
                $stats['updated'],
                $stats['inserted'],
                $orphans ? sprintf(' (%d omitidas: el producto, atributo o vista ya no existe)', $orphans) : ''
            ));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Restaurado: %d globales creadas eliminadas, %d valores repuestos, %d filas reinsertadas.</info>',
            $totals['removed'],
            $totals['updated'],
            $totals['inserted']
        ));
        $output->writeln('Las tablas de respaldo se conservan; bórralas a mano cuando ya no hagan falta.');
        $output->writeln('');
        $output->writeln('Pasos manuales pendientes:');
        $output->writeln(
            '  bin/magento indexer:reindex catalog_product_attribute catalog_product_price catalogsearch_fulltext'
            . ' catalog_product_flat'
        );
        $output->writeln('    (catalog_product_flat solo si existe ese indexador en la instalación)');
        $output->writeln('  bin/magento cache:flush');

        return Cli::RETURN_SUCCESS;
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
