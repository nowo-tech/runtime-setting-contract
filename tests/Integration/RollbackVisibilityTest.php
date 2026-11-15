<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration;

use Nowo\RuntimeSettingContract\Bridge\Psr6\Psr6RuntimeValueCache;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Tests\Support\PdoRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function str_repeat;

/**
 * Spec §3.4 (audit lessons QL-M1 / QL-M2, IS R10): a rolled-back write never publishes a value.
 */
final class RollbackVisibilityTest extends TestCase
{
    #[Test]
    public function aValueReadInsideARolledBackTransactionIsNeverServedAgain(): void
    {
        $store      = new PdoRuntimeSettingStore('sqlite::memory:');
        $cache      = new Psr6RuntimeValueCache(new ArrayAdapter());
        $definition = new RuntimeSettingDefinition('demo.greeting', RuntimeSettingType::String, RuntimeSettingSource::Db);
        $resolver   = new RuntimeSettingResolver($store, $cache, versionTtl: 0);
        $dispatcher = new RecordingDispatcher();
        $updater    = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$definition]), $store, $resolver, new RuntimeValueFingerprinter(str_repeat('k', 32)), $dispatcher);
        $updater->set('demo.greeting', 'committed', 'cli:test');
        $dispatcher->clear();

        // Something on the same connection writes and bumps the version inside a transaction, then rolls back.
        $pdo = $store->connection();
        $pdo->beginTransaction();
        $pdo->exec("UPDATE rs_values SET cur = 'phantom' WHERE ns = 'demo' AND k = 'greeting'");
        $pdo->exec("UPDATE rs_versions SET counter = counter + 1, token = (counter + 1) || '-uncommitted' WHERE ns = 'demo'");
        $resolver->clearRuntimeCache();
        self::assertSame('phantom', $resolver->get($definition), 'the same connection sees its own uncommitted row');
        $pdo->rollBack();

        // Next request: the memo is gone and the cached entry is keyed by a token that will never be reused.
        $resolver->clearRuntimeCache();
        self::assertSame('committed', $resolver->get($definition));

        $updater->set('demo.greeting', 'next', 'cli:test');
        $resolver->clearRuntimeCache();
        self::assertSame('next', $resolver->get($definition));
        self::assertCount(1, $dispatcher->events);
    }

    #[Test]
    public function aWriteThatFailsBeforeCommitPublishesNothingAndLeavesNoMemo(): void
    {
        $store      = new PdoRuntimeSettingStore('sqlite::memory:');
        $definition = new RuntimeSettingDefinition('demo.greeting', RuntimeSettingType::String, RuntimeSettingSource::Db);
        $resolver   = new RuntimeSettingResolver($store, versionTtl: 300);
        $dispatcher = new RecordingDispatcher();
        $updater    = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$definition]), $store, $resolver, new RuntimeValueFingerprinter(str_repeat('k', 32)), $dispatcher);
        $updater->set('demo.greeting', 'committed', 'cli:test');
        $version = $store->namespaceVersion('demo');

        $store->failBeforeCommit = true;
        try {
            $updater->set('demo.greeting', 'rolled-back', 'cli:test');
        } catch (RuntimeException) {
        }

        self::assertSame($version, $store->namespaceVersion('demo'), 'the bump was rolled back with the value');
        self::assertSame('committed', $resolver->get($definition));
        self::assertCount(1, $dispatcher->events, 'only the committed write was published');
    }
}
