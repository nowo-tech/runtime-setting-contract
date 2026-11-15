<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration\Ddd;

use DateInterval;
use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Tests\Fixtures\DemoLimitValidator;
use Nowo\RuntimeSettingContract\Tests\Integration\Ddd\Application\RuntimeRetentionPolicyProvider;
use Nowo\RuntimeSettingContract\Tests\Integration\Ddd\Domain\PurgePlanner;
use Nowo\RuntimeSettingContract\Tests\Support\MutableClock;
use Nowo\RuntimeSettingContract\Tests\Support\PdoRuntimeSettingStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function glob;
use function str_repeat;

/**
 * Spec §7 "DDD host": the host implements the source and writer over its own repository (here PDO), no Symfony
 * bridge and no InstanceSettings / RuntimeEnv; the domain only sees its typed port.
 */
final class DddHostTest extends TestCase
{
    #[Test]
    public function aHostWithItsOwnSourceDrivesTheDomainPort(): void
    {
        $clock      = new MutableClock('2026-11-16 00:00:00');
        $store      = new PdoRuntimeSettingStore('sqlite::memory:');
        $definition = new RuntimeSettingDefinition('CHAT_RETENTION_DAYS', RuntimeSettingType::Int, RuntimeSettingSource::DbThenEnv, RuntimeSettingErrorPolicy::FailClosed, validator: new DemoLimitValidator(), configured: '30');
        $resolver   = new RuntimeSettingResolver($store, null, $clock, 0);
        $updater    = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$definition]), $store, $resolver, new RuntimeValueFingerprinter(str_repeat('k', 32)), null, $clock);
        $planner    = new PurgePlanner(new RuntimeRetentionPolicyProvider($resolver, $definition));

        self::assertEquals(new DateTimeImmutable('2026-10-17 00:00:00'), $planner->cutoff($clock->now()), 'env value: 30 days');

        $updater->set('CHAT_RETENTION_DAYS', '7', 'user:42');
        $resolver->clearRuntimeCache();
        self::assertEquals(new DateTimeImmutable('2026-11-09 00:00:00'), $planner->cutoff($clock->now()));

        $updater->rotate('CHAT_RETENTION_DAYS', '14', new DateInterval('PT0S'), 'user:42');
        $resolver->clearRuntimeCache();
        self::assertEquals(new DateTimeImmutable('2026-11-02 00:00:00'), $planner->cutoff($clock->now()));
        self::assertSame(2, $store->auditCount());
    }

    #[Test]
    public function theDomainImportsNoFrameworkAndNoContractTypes(): void
    {
        $files = glob(__DIR__ . '/Domain/*.php') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            self::assertStringNotContainsString('Symfony\\', $code, $file);
            self::assertStringNotContainsString('Doctrine\\', $code, $file);
            self::assertStringNotContainsString('RuntimeSettingContract\\Contract', $code, $file);
        }
    }

    #[Test]
    public function theContractNamespaceImportsNoSymfonyOrDoctrine(): void
    {
        $src   = __DIR__ . '/../../../src/';
        $files = [...(glob($src . 'Contract/*.php') ?: []), ...(glob($src . 'Contract/Exception/*.php') ?: []), ...(glob($src . 'InMemory/*.php') ?: [])];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            self::assertStringNotContainsString('use Symfony\\', $code, $file);
            self::assertStringNotContainsString('use Doctrine\\', $code, $file);
        }
    }
}
