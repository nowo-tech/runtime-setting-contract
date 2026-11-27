<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration\Symfony;

use Nowo\RuntimeSettingContract\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;

use function bin2hex;
use function is_file;
use function random_bytes;
use function unlink;

trait KernelTestTrait
{
    /** @var list<string> */
    private array $databases = [];

    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        foreach ($this->databases as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function newDatabase(): string
    {
        $file = TestKernel::fixturesDir() . '/var/db-' . bin2hex(random_bytes(6)) . '.sqlite';
        @mkdir(TestKernel::fixturesDir() . '/var', 0o777, true);
        $this->databases[] = $file;

        return 'sqlite:' . $file;
    }

    /**
     * @param array<string, mixed> $demoConfig
     * @param array<string, mixed> $nowoConfig
     */
    private function kernel(string $dsn, int $versionTtl = 0, bool $commandEnabled = true, bool $mutableClock = false, array $demoConfig = [], ?string $cachePool = null, array $nowoConfig = []): TestKernel
    {
        $kernel = new TestKernel($dsn, $versionTtl, $commandEnabled, $mutableClock, $demoConfig, $cachePool, $nowoConfig);
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    /**
     * One worker request: handle + terminate on the kept-alive kernel.
     */
    private function request(TestKernel $kernel): string
    {
        $request  = Request::create('/value');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return (string) $response->getContent();
    }
}
