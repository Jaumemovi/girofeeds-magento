<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Girofeeds\ProductApi\Console\Command;

use Magento\Framework\Console\Cli;
use Girofeeds\ProductApi\Api\Config\RepositoryInterface as ConfigProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Girofeeds\ProductApi\Service\Token\Generate;

class GenerateToken extends Command
{

    /**
     * Error message is token is already set
     */
    const API_KEY_ALREADY_SET = 'API key is already set, use --force=1 to regenerate.';

    const API_KEY_SET = 'New API key generated, please re-authenticate connection.';

    /**
     * Command call name
     */
    const COMMAND_NAME = 'girofeeds:token:generate';

    /**
     * @var Generate
     */
    private $generate;
    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @param Generate $generate
     * @param ConfigProvider $configProvider
     */
    public function __construct(
        Generate $generate,
        ConfigProvider $configProvider
    ) {
        $this->generate = $generate;
        $this->configProvider = $configProvider;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    public function configure()
    {
        $this->setName(self::COMMAND_NAME);
        $this->setDescription('Generate API key for Girofeeds connection');
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_OPTIONAL,
            'Use force=1 to regenerate API key'
        );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool)$input->getOption('force');
        if (!$force && $this->configProvider->getApiKey()) {
            $output->writeln(sprintf('<error>%s</error>', self::API_KEY_ALREADY_SET));
            return Cli::RETURN_FAILURE;
        }

        $this->generate->execute(true);
        $output->writeln(sprintf('<info>%s</info>', self::API_KEY_SET));
        return Cli::RETURN_SUCCESS;
    }
}
