<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\Command;

use DateInterval;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingExceptionInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function preg_match;

#[AsCommand(name: 'nowo:runtime-setting:rotate', description: 'Rotate a runtime setting: new value from STDIN, the old one stays accepted for a window')]
final class RotateRuntimeSettingCommand extends Command
{
    public const DEFAULT_KEEP_PREVIOUS = 'PT1H';

    public function __construct(
        private readonly RuntimeSettingUpdater $updater,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Setting id: namespace.key or an environment variable name')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the new value from STDIN (mandatory)')
            ->addOption('keep-previous', null, InputOption::VALUE_REQUIRED, 'ISO-8601 duration the previous value stays accepted (max P30D)', self::DEFAULT_KEEP_PREVIOUS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and show what would change; write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('stdin')) {
            $io->error('rotate reads the new value from STDIN only: add --stdin. Nothing was written.');

            return Command::FAILURE;
        }

        $keep = $this->interval($input->getOption('keep-previous'));
        if (!$keep instanceof DateInterval) {
            $io->error('--keep-previous must be an ISO-8601 duration such as PT1H or P1D. Nothing was written.');

            return Command::FAILURE;
        }

        try {
            $id = (string) CommandIo::argument($input, 'id');
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $this->updater->writableDefinition($id);
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $change = $this->updater->rotate($id, CommandIo::readValue($input), $keep, CommandIo::actor(), (bool) $input->getOption('dry-run'));
        } catch (RuntimeSettingExceptionInterface $e) {
            $io->error($e->getMessage() . ' Nothing was written.');

            return Command::FAILURE;
        }

        $io->success(ChangeOutput::describe($change));

        return Command::SUCCESS;
    }

    private function interval(mixed $option): ?DateInterval
    {
        if (!is_string($option) || preg_match('/^P(?!$)(?:\d{1,4}D)?(?:T(?=\d)(?:\d{1,6}H)?(?:\d{1,8}M)?(?:\d{1,9}S)?)?$/D', $option) !== 1) {
            return null;
        }

        // The pattern only lets through durations DateInterval accepts.
        return new DateInterval($option);
    }
}
