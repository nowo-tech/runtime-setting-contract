<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration\Symfony;

use Nowo\RuntimeSettingContract\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function microtime;
use function spl_object_id;

/**
 * Spec §7 "Live change": process A (this PHPUnit process, a kept-alive worker kernel) reads the value; process B (a
 * separate PHP process with its own kernel) changes it through the console command; A sees the new value on a
 * later request within version_ttl, with no restart.
 */
final class LiveChangeAcrossProcessesTest extends TestCase
{
    use KernelTestTrait;

    #[Test]
    public function aWorkerSeesACommandWrittenByAnotherProcessWithinTheVersionTtl(): void
    {
        $dsn    = $this->newDatabase();
        $kernel = $this->kernel($dsn, versionTtl: 1);
        $id     = spl_object_id($kernel->getContainer());

        self::assertSame('hello|stable', $this->request($kernel));

        $written = microtime(true);
        $this->console($dsn, ['nowo:runtime-setting:set', 'demo.greeting', 'from-process-b']);

        // Keep serving requests (no sleep: each request is real work) until the change shows up or 3 s passed.
        $seenAfter = null;
        while ($seenAfter === null && microtime(true) - $written < 3.0) {
            if ($this->request($kernel) === 'from-process-b|stable') {
                $seenAfter = microtime(true) - $written;
            }
        }

        self::assertNotNull($seenAfter, 'the change never reached the running worker');
        self::assertLessThan(1.0 + 1.0, $seenAfter, 'seen within version_ttl (1 s) plus scheduling slack');
        self::assertSame($id, spl_object_id($kernel->getContainer()), 'same container: no restart, no reboot');
    }

    #[Test]
    public function withVersionTtlZeroTheNextRequestSeesIt(): void
    {
        $dsn    = $this->newDatabase();
        $kernel = $this->kernel($dsn, versionTtl: 0);
        self::assertSame('hello|stable', $this->request($kernel));

        $this->console($dsn, ['nowo:runtime-setting:set', 'demo.greeting', 'immediate']);
        self::assertSame('immediate|stable', $this->request($kernel));

        $this->console($dsn, ['nowo:runtime-setting:unset', 'demo.greeting']);
        self::assertSame('hello|stable', $this->request($kernel), 'unset falls back to the configured value');
    }

    #[Test]
    public function aSecretRotatedThroughStdinInProcessBIsNeverPrinted(): void
    {
        $dsn     = $this->newDatabase();
        $secret  = 'REDACTED-TEST-ROTATION';
        $process = $this->console($dsn, ['nowo:runtime-setting:set', 'DEMO_TOKEN', '--stdin'], $secret . "\n");
        self::assertStringNotContainsString($secret, $process->getOutput() . $process->getErrorOutput());

        $refused = $this->console($dsn, ['nowo:runtime-setting:set', 'DEMO_TOKEN', $secret], null, 1);
        self::assertStringNotContainsString($secret, $refused->getOutput() . $refused->getErrorOutput());
    }

    /**
     * @param list<string> $arguments
     */
    private function console(string $dsn, array $arguments, ?string $stdin = null, int $expectedExit = 0): Process
    {
        $process = new Process(['php', TestKernel::fixturesDir() . '/bin/console.php', ...$arguments, '--no-interaction'], null, ['RS_TEST_DSN' => $dsn], $stdin, 120);
        $process->run();
        self::assertSame($expectedExit, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        return $process;
    }
}
