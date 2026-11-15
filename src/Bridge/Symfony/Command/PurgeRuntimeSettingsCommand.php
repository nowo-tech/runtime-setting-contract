<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\Command;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingExceptionInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(name: 'nowo:runtime-setting:purge', description: 'Delete the previous values of rotations whose window has expired (run it from a scheduler)')]
final class PurgeRuntimeSettingsCommand extends Command
{
    public function __construct(
        private readonly RuntimeSettingUpdater $updater,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $purged = $this->updater->purgeExpired();
        } catch (RuntimeSettingExceptionInterface $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Purged %d expired previous value(s).', $purged));

        return Command::SUCCESS;
    }
}
