<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration\Symfony;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * FrankenPHP worker mode with kernel reset false: one kernel, no services resetter, many requests.
 */
final class WorkerModeTest extends TestCase
{
    use KernelTestTrait;

    private const REQUESTS = 2000;

    #[Test]
    public function twoThousandRequestsAlternatingTwoValuesKeepMemoryFlatAndNeverServeAStaleValue(): void
    {
        $dsn    = $this->newDatabase();
        $kernel = $this->kernel($dsn, versionTtl: 1, mutableClock: true);
        // A second process (its own kernel) writes; this kernel only reads.
        $writer = $this->kernel($dsn, versionTtl: 1);
        /** @var RuntimeSettingUpdater $updater */
        $updater = $writer->getContainer()->get('test.updater');
        /** @var MutableClock $clock */
        $clock = $kernel->getContainer()->get('nowo_runtime_setting.clock');

        self::assertFalse($kernel->getContainer()->has('services_resetter'), 'worker kept alive without a services resetter');

        $baseline = 0;
        for ($i = 1; $i <= self::REQUESTS; ++$i) {
            $value = $i % 2 === 0 ? 'even' : 'odd';
            $updater->set('demo.greeting', $value, 'cli:worker-test');

            // The version token is at most version_ttl old: after the TTL the change is always seen.
            $clock->advance('+1001 milliseconds');
            self::assertSame($value . '|stable', $this->request($kernel), 'request ' . $i);

            if ($i === 200) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        $growth = memory_get_usage() - $baseline;
        self::assertLessThan(1024 * 1024, $growth, 'memory grew by ' . $growth . ' bytes over ' . (self::REQUESTS - 200) . ' requests');
    }

    #[Test]
    public function withASharedCacheAWorkerMayServeTheOldValueWithinTheTtlOnly(): void
    {
        $dsn    = $this->newDatabase();
        $kernel = $this->kernel($dsn, versionTtl: 5, mutableClock: true, cachePool: 'test.array_pool');
        $writer = $this->kernel($dsn);
        /** @var RuntimeSettingUpdater $updater */
        $updater = $writer->getContainer()->get('test.updater');
        /** @var MutableClock $clock */
        $clock = $kernel->getContainer()->get('nowo_runtime_setting.clock');

        self::assertSame('hello|stable', $this->request($kernel));
        $updater->set('demo.greeting', 'changed', 'cli:worker-test');

        $clock->advance('+4 seconds');
        self::assertSame('hello|stable', $this->request($kernel), 'token still fresh');
        $clock->advance('+1 second');
        self::assertSame('changed|stable', $this->request($kernel), 'visible once version_ttl elapsed');
    }
}
