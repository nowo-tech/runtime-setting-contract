<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\Command;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingExceptionInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function is_string;

#[AsCommand(name: 'nowo:runtime-setting:list', description: 'List the registered runtime settings and their current values (secrets masked)')]
final class ListRuntimeSettingsCommand extends Command
{
    public function __construct(
        private readonly RuntimeSettingRegistry $registry,
        private readonly RuntimeSettingResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Only this namespace (use "env" for environment variable names)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $namespace = $input->getOption('namespace');
        // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
        $this->resolver->clearRuntimeCache();

        $rows = array_map(
            fn (RuntimeSettingDefinition $definition): array => [
                $definition->idString(),
                $definition->type->value,
                $definition->source->value,
                $definition->onError->value,
                $definition->secret ? 'yes' : 'no',
                $this->display($definition),
            ],
            $this->registry->all(is_string($namespace) ? $namespace : null),
        );

        $io = new SymfonyStyle($input, $output);
        if ($rows === []) {
            $io->writeln('No runtime settings registered.');

            return Command::SUCCESS;
        }
        $io->table(['Setting', 'Type', 'Source', 'On error', 'Secret', 'Value'], $rows);

        return Command::SUCCESS;
    }

    private function display(RuntimeSettingDefinition $definition): string
    {
        try {
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $value = $this->resolver->get($definition);
        } catch (RuntimeSettingExceptionInterface) {
            return '(unavailable)';
        }

        return match (true) {
            $value === null     => '(not set)',
            $definition->secret => CommandIo::MASK,
            default             => $definition->type->format($value),
        };
    }
}
