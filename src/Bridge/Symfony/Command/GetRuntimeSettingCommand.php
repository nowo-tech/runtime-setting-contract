<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\Command;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingExceptionInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(name: 'nowo:runtime-setting:get', description: 'Show the value a runtime setting resolves to now (secrets masked)')]
final class GetRuntimeSettingCommand extends Command
{
    public function __construct(
        private readonly RuntimeSettingRegistry $registry,
        private readonly RuntimeSettingResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Setting id: namespace.key or an environment variable name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $definition = $this->registry->get((string) CommandIo::argument($input, 'id'));
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $this->resolver->clearRuntimeCache();
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $value = $this->resolver->get($definition);
        } catch (RuntimeSettingExceptionInterface $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($value === null) {
            $io->writeln(sprintf('%s: (not set)', $definition->idString()));
        } elseif ($definition->secret) {
            // Masked, and no fingerprint: a fingerprint of a secret confirms guesses (audit QS-M-1).
            $io->writeln(sprintf('%s: %s (secret)', $definition->idString(), CommandIo::MASK));
        } else {
            $io->writeln(sprintf('%s: %s', $definition->idString(), $definition->type->format($value)));
        }

        return Command::SUCCESS;
    }
}
