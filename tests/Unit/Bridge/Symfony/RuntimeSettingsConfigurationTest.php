<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Bridge\Symfony;

use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\DefaultPortsPass;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RuntimeSettingsConfigurationTest extends TestCase
{
    #[Test]
    public function appendsARuntimeNodeWithDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame(
            [
                'days'   => ['source' => 'env', 'key' => 'chat.days', 'on_error' => 'fail_closed'],
                'secret' => ['source' => 'db', 'key' => 'CHAT_SECRET', 'on_error' => 'fallback'],
            ],
            $config['runtime'],
        );
    }

    #[Test]
    public function hostOverridesSourceKeyAndOnError(): void
    {
        $config = $this->process(['runtime' => ['days' => ['source' => 'db_then_env', 'key' => 'CHAT_RETENTION_DAYS', 'on_error' => 'fallback']]]);

        self::assertSame(['source' => 'db_then_env', 'key' => 'CHAT_RETENTION_DAYS', 'on_error' => 'fallback'], $config['runtime']['days']);
    }

    #[Test]
    public function refusesAnInvalidKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['runtime' => ['days' => ['key' => 'Chat.Days']]]);
    }

    #[Test]
    public function refusesANonStringKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['runtime' => ['days' => ['key' => 12]]]);
    }

    #[Test]
    public function refusesAnUnknownSource(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['runtime' => ['days' => ['source' => 'redis']]]);
    }

    #[Test]
    public function registersATaggedDefinitionService(): void
    {
        $container = new ContainerBuilder();
        $container->register('validator.days', stdClass::class);

        RuntimeSettingsConfiguration::registerDefinition(
            $container,
            'chat.runtime.days',
            ['source' => 'db_then_env', 'key' => 'chat.days', 'on_error' => 'fallback'],
            RuntimeSettingType::Int,
            30,
            validatorServiceId: 'validator.days',
            default: 90,
            required: true,
            maxLength: 10,
        );

        $definition = $container->getDefinition('chat.runtime.days');
        self::assertSame(RuntimeSettingDefinition::class, $definition->getClass());
        self::assertTrue($definition->hasTag(RuntimeSettingsConfiguration::DEFINITION_TAG));
        self::assertSame(RuntimeSettingSource::DbThenEnv, $definition->getArgument('$source'));
        self::assertSame(RuntimeSettingErrorPolicy::Fallback, $definition->getArgument('$onError'));
        self::assertEquals(new Reference('validator.days'), $definition->getArgument('$validator'));

        RuntimeSettingsConfiguration::registerDefinition($container, 'chat.runtime.mode', ['source' => 'env', 'key' => 'chat.mode', 'on_error' => 'fail_closed'], RuntimeSettingType::String, 'x');
        self::assertNull($container->getDefinition('chat.runtime.mode')->getArgument('$validator'));
    }

    #[Test]
    public function defaultPortsPassKeepsExistingBindings(): void
    {
        $container = new ContainerBuilder();
        $container->register('host.source', stdClass::class);
        $container->setAlias(RuntimeValueSourceInterface::class, 'host.source');

        (new DefaultPortsPass())->process($container);

        self::assertSame('host.source', (string) $container->getAlias(RuntimeValueSourceInterface::class));
        self::assertSame(DefaultPortsPass::UNCONFIGURED_ID, (string) $container->getAlias(RuntimeSettingWriterInterface::class));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{runtime: array<string, array{source: string, key: string, on_error: string}>}
     */
    private function process(array $input): array
    {
        $tree = new TreeBuilder('chat');
        RuntimeSettingsConfiguration::appendTo($tree->getRootNode(), [
            'days'   => ['key' => 'chat.days'],
            'secret' => ['key' => 'CHAT_SECRET', 'source' => 'db', 'on_error' => 'fallback'],
        ]);

        /** @var array{runtime: array<string, array{source: string, key: string, on_error: string}>} $config */
        $config = (new Processor())->process($tree->buildTree(), [$input]);

        return $config;
    }
}
