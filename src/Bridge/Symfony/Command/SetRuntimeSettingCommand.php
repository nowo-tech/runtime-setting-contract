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

use function is_string;

#[AsCommand(name: 'nowo:runtime-setting:set', description: 'Change a runtime setting; the running application picks it up without a restart')]
final class SetRuntimeSettingCommand extends Command
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
            ->addArgument('value', InputArgument::OPTIONAL, 'New value (not for secrets: use --stdin)')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the value from STDIN (required for secrets)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and show what would change; write nothing')
            ->setHelp(<<<'HELP'
                Only settings that a bundle registered can be written, and never one with "source: env".
                The bundle's validator runs first: an invalid value is refused and nothing is written.

                Secrets are never accepted as an argument (shell history, ps). Pipe them in:

                  <info>printf '%s' "$NEW_TOKEN" | php %command.full_name% chat.webhook_secret --stdin</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $id       = (string) CommandIo::argument($input, 'id');
        $argument = CommandIo::argument($input, 'value');
        $stdin    = (bool) $input->getOption('stdin');
        $dryRun   = (bool) $input->getOption('dry-run');

        try {
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $definition = $this->updater->writableDefinition($id);
            if (is_string($argument) && $definition->secret) {
                $io->error('Secrets are only accepted through --stdin, never as an argument. Nothing was written.');

                return Command::FAILURE;
            }
            if (is_string($argument) === $stdin) {
                $io->error('Give the value either as an argument or with --stdin.');

                return Command::FAILURE;
            }

            $value = $stdin || $argument === null ? CommandIo::readValue($input) : $argument;
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $change = $this->updater->set($id, $value, CommandIo::actor(), $dryRun);
        } catch (RuntimeSettingExceptionInterface $e) {
            $io->error($e->getMessage() . ' Nothing was written.');

            return Command::FAILURE;
        }

        $io->success(ChangeOutput::describe($change));

        return Command::SUCCESS;
    }
}
