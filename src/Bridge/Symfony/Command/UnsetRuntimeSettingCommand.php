<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\Command;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingExceptionInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'nowo:runtime-setting:unset', description: 'Remove a stored runtime setting: it falls back to the configured (env) value')]
final class UnsetRuntimeSettingCommand extends Command
{
    public function __construct(
        private readonly RuntimeSettingUpdater $updater,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Setting id: namespace.key or an environment variable name')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change; write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $change = $this->updater->unset((string) CommandIo::argument($input, 'id'), CommandIo::actor(), (bool) $input->getOption('dry-run'));
        } catch (RuntimeSettingExceptionInterface $e) {
            $io->error($e->getMessage() . ' Nothing was written.');

            return Command::FAILURE;
        }

        $io->success(ChangeOutput::describe($change));

        return Command::SUCCESS;
    }
}
