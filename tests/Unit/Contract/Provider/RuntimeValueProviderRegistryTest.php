<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Contract\Provider;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderBinding;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderRegistry;
use Nowo\RuntimeSettingContract\Tests\Support\ArrayValueProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

final class RuntimeValueProviderRegistryTest extends TestCase
{
    #[Test]
    public function aBindingKeepsNameProviderAndTtl(): void
    {
        $provider = new ArrayValueProvider();
        $binding  = new RuntimeValueProviderBinding('tenant_settings', $provider, 60);

        self::assertSame('tenant_settings', $binding->name);
        self::assertSame($provider, $binding->provider);
        self::assertSame(60, $binding->cacheTtl);
        self::assertSame(0, (new RuntimeValueProviderBinding('a', $provider))->cacheTtl);
        self::assertSame(3600, (new RuntimeValueProviderBinding('a', $provider, 3600))->cacheTtl);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Tenant'];
        yield 'leading digit' => ['1tenant'];
        yield 'dash' => ['tenant-settings'];
        yield 'dot' => ['tenant.settings'];
        yield 'newline' => ["tenant\n"];
        yield 'too long' => ['a' . str_repeat('b', 64)];
        yield 'not a string' => [12];
        yield 'null' => [null];
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function refusesAnInvalidName(mixed $name): void
    {
        self::assertFalse(RuntimeValueProviderBinding::isValidName($name));
    }

    #[Test]
    public function acceptsTheLongestName(): void
    {
        self::assertTrue(RuntimeValueProviderBinding::isValidName('a' . str_repeat('b', 63)));
    }

    #[Test]
    public function theConstructorRefusesAnInvalidName(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('Invalid runtime value provider name');

        new RuntimeValueProviderBinding('Bad-Name', new ArrayValueProvider());
    }

    #[Test]
    public function refusesANegativeTtl(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('between 0 and 3600');

        new RuntimeValueProviderBinding('tenant', new ArrayValueProvider(), -1);
    }

    #[Test]
    public function refusesATooLongTtl(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);

        RuntimeValueProviderBinding::assertValid('tenant', 3601);
    }

    #[Test]
    public function theCacheScopeIsHashedIntoTheTokenPrefix(): void
    {
        $provider = new ArrayValueProvider();

        self::assertSame('tenant:60', (new RuntimeValueProviderBinding('tenant', $provider, 60))->cacheTokenPrefix());
        $scoped = new RuntimeValueProviderBinding('tenant', $provider, 60, 'conn:tenants|REDACTED-TEST-TENANT');
        self::assertSame('conn:tenants|REDACTED-TEST-TENANT', $scoped->cacheScope);
        self::assertMatchesRegularExpression('/^tenant:60:[0-9a-f]{16}$/D', $scoped->cacheTokenPrefix());
        self::assertStringNotContainsString('REDACTED', $scoped->cacheTokenPrefix());
        self::assertNotSame($scoped->cacheTokenPrefix(), (new RuntimeValueProviderBinding('tenant', $provider, 60, 'conn:other'))->cacheTokenPrefix());
        self::assertSame(4096, strlen((new RuntimeValueProviderBinding('tenant', $provider, 60, str_repeat('s', 4096)))->cacheScope));
    }

    #[Test]
    public function refusesATooLongScope(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('longer than 4096 bytes');

        new RuntimeValueProviderBinding('tenant', new ArrayValueProvider(), 60, str_repeat('s', 4097));
    }

    #[Test]
    public function theRegistryFindsProvidersByName(): void
    {
        $a        = new RuntimeValueProviderBinding('zeta', new ArrayValueProvider());
        $b        = new RuntimeValueProviderBinding('alpha', new ArrayValueProvider());
        $registry = new RuntimeValueProviderRegistry([$a, $b]);

        self::assertTrue($registry->has('alpha'));
        self::assertFalse($registry->has('beta'));
        self::assertSame($a, $registry->get('zeta'));
        self::assertSame(['alpha', 'zeta'], $registry->names());
        self::assertSame([], (new RuntimeValueProviderRegistry())->names());
    }

    #[Test]
    public function theRegistryRefusesTwoProvidersWithOneName(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('"tenant" is registered twice');

        new RuntimeValueProviderRegistry([
            new RuntimeValueProviderBinding('tenant', new ArrayValueProvider()),
            new RuntimeValueProviderBinding('tenant', new ArrayValueProvider()),
        ]);
    }

    #[Test]
    public function anUnknownNameIsEchoedOnlyWhenWellFormed(): void
    {
        $registry = new RuntimeValueProviderRegistry();

        try {
            $registry->get('missing');
            self::fail('Expected an exception.');
        } catch (RuntimeSettingConfigurationError $e) {
            self::assertSame('No runtime value provider is registered under "missing".', $e->getMessage());
        }

        try {
            $registry->get("<script>\n");
            self::fail('Expected an exception.');
        } catch (RuntimeSettingConfigurationError $e) {
            self::assertSame('No runtime value provider is registered under that name.', $e->getMessage());
        }
    }
}
