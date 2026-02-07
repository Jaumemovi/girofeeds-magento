<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Console\Command;

use Magento\Framework\Console\Cli;
use Girofeeds\ProductApi\Api\Config\RepositoryInterface as ConfigProvider;
use Girofeeds\ProductApi\Model\Generate;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Selftest extends Command
{

    private const COMMAND_NAME = 'girofeeds:selftest';

    private ConfigProvider $configProvider;
    private StoreManagerInterface $storeManager;
    private Generate $generateModel;

    public function __construct(
        ConfigProvider $configProvider,
        StoreManagerInterface $storeManager,
        Generate $generateModel
    ) {
        $this->configProvider = $configProvider;
        $this->storeManager = $storeManager;
        $this->generateModel = $generateModel;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME);
        $this->setDescription('Run Girofeeds ProductApi self-test');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hasErrors = false;

        $output->writeln('<info>Girofeeds ProductApi Self-test</info>');
        $output->writeln('');

        if ($this->configProvider->isEnabled()) {
            $output->writeln('<info>[PASS]</info> Module is enabled');
        } else {
            $output->writeln('<error>[FAIL]</error> Module is disabled');
            $hasErrors = true;
        }

        $token = $this->configProvider->getToken();
        if (!empty($token)) {
            $output->writeln('<info>[PASS]</info> API key is set');
        } else {
            $output->writeln('<error>[FAIL]</error> API key is not set. Run girofeeds:token:generate');
            $hasErrors = true;
        }

        try {
            $storeId = (int) $this->storeManager->getDefaultStoreView()->getId();
            $count = $this->generateModel->getSize($storeId);
            $output->writeln(sprintf('<info>[PASS]</info> Product collection built successfully (%d products)', $count));
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>[FAIL]</error> Cannot build product collection: %s', $e->getMessage()));
            $hasErrors = true;
        }

        $output->writeln('');
        $output->writeln(sprintf('Version: %s', $this->configProvider->getExtensionVersion() ?: '2.0.0'));
        $output->writeln(sprintf('Magento: %s', $this->configProvider->getMagentoVersion()));

        return $hasErrors ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
