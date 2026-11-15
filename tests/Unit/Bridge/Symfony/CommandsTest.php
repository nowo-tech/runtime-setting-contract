<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Bridge\Symfony;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\ChangeOutput;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\CommandIo;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\GetRuntimeSettingCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\ListRuntimeSettingsCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\RotateRuntimeSettingCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\SetRuntimeSettingCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\UnsetRuntimeSettingCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\UnconfiguredRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use Nowo\RuntimeSettingContract\InMemory\InMemoryRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Tests\Fixtures\DemoLimitValidator;
use Nowo\RuntimeSettingContract\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Tester\CommandTester;

use function fopen;
use function fwrite;
use function rewind;
use function str_repeat;
use function strlen;

final class CommandsTest extends TestCase
{
    private const SECRET = 'REDACTED-TEST-SECRET-VALUE';

    private MutableClock $clock;

    private InMemoryRuntimeSettingStore $store;

    private RuntimeSettingRegistry $registry;

    private RuntimeSettingResolver $resolver;

    private RuntimeValueFingerprinter $fingerprinter;

    protected function setUp(): void
    {
        $this->clock         = new MutableClock();
        $this->store         = new InMemoryRuntimeSettingStore($this->clock);
        $this->fingerprinter = new RuntimeValueFingerprinter(str_repeat('k', 32));
        $this->registry      = new RuntimeSettingRegistry([
            new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::DbThenEnv, validator: new DemoLimitValidator(), configured: 30),
            new RuntimeSettingDefinition('chat.mode', RuntimeSettingType::String, configured: 'normal'),
            new RuntimeSettingDefinition('chat.unset', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded()),
            new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new Unbounded()),
        ]);
        $this->resolver = new RuntimeSettingResolver($this->store, null, $this->clock, 0);
    }

    #[Test]
    public function setWritesAValueFromTheArgument(): void
    {
        $tester = $this->tester($this->setCommand());

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'chat.days', 'value' => '45']));
        self::assertStringContainsString('chat.days set (fingerprint none ->', $tester->getDisplay());
        self::assertSame(45, $this->resolver->get($this->registry->get('chat.days')));
        self::assertStringStartsWith('cli:', $this->store->auditLog()[0]['actor']);
    }

    #[Test]
    public function setRefusesAnInvalidValueAndWritesNothing(): void
    {
        $tester = $this->tester($this->setCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'chat.days', 'value' => '0']));
        self::assertStringContainsString('must be at least 1', $tester->getDisplay());
        self::assertSame([], $this->store->auditLog());
    }

    #[Test]
    public function setRefusesUnknownAndEnvSettings(): void
    {
        $tester = $this->tester($this->setCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'chat.nope', 'value' => '1']));
        self::assertStringContainsString('Unknown runtime setting', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'chat.mode', 'value' => 'x']));
        self::assertStringContainsString('source: env', $tester->getDisplay());
        self::assertSame([], $this->store->auditLog());
    }

    #[Test]
    public function setRefusesASecretAsArgumentWithoutEchoingIt(): void
    {
        $tester = $this->tester($this->setCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'API_TOKEN', 'value' => self::SECRET]));
        self::assertStringContainsString('only accepted through --stdin', $tester->getDisplay());
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
        self::assertSame([], $this->store->auditLog());
    }

    #[Test]
    public function setReadsASecretFromStdinAndNeverEchoesIt(): void
    {
        $tester = $this->tester($this->setCommand());
        $tester->setInputs([self::SECRET]);

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'API_TOKEN', '--stdin' => true]));
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
        self::assertSame(self::SECRET, $this->store->read(RuntimeSettingId::fromString('API_TOKEN'))?->current, 'the trailing newline is dropped');
    }

    #[Test]
    public function setNeedsExactlyOneValueSource(): void
    {
        $tester = $this->tester($this->setCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'chat.days']));
        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'chat.days', 'value' => '4', '--stdin' => true]));
        self::assertStringContainsString('either as an argument or with --stdin', $tester->getDisplay());
    }

    #[Test]
    public function setDryRunWritesNothing(): void
    {
        $tester = $this->tester($this->setCommand());

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'chat.days', 'value' => '45', '--dry-run' => true]));
        self::assertStringContainsString('Dry run: chat.days would be set (new fingerprint', $tester->getDisplay());
        self::assertSame([], $this->store->auditLog());
    }

    #[Test]
    public function unsetFallsBackAndSupportsDryRun(): void
    {
        $this->updater()->set('chat.days', '45', 'cli:test');
        $tester = $this->tester(new UnsetRuntimeSettingCommand($this->updater()));

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'chat.days', '--dry-run' => true]));
        self::assertStringContainsString('would be unset. Nothing was written.', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'chat.days']));
        self::assertStringContainsString('chat.days unset', $tester->getDisplay());
        self::assertSame(30, $this->resolver->get($this->registry->get('chat.days')));

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'chat.mode']));
    }

    #[Test]
    public function rotateKeepsThePreviousValue(): void
    {
        $this->updater()->set('API_TOKEN', 'REDACTED-TEST-OLD', 'cli:test');
        $tester = $this->tester(new RotateRuntimeSettingCommand($this->updater()));
        $tester->setInputs(['REDACTED-TEST-NEW']);

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'API_TOKEN', '--stdin' => true, '--keep-previous' => 'PT30M']));
        self::assertStringContainsString('API_TOKEN rotated', $tester->getDisplay());
        self::assertStringNotContainsString('REDACTED-TEST', $tester->getDisplay());
        self::assertSame(['REDACTED-TEST-NEW', 'REDACTED-TEST-OLD'], $this->resolver->candidates($this->registry->get('API_TOKEN')));
        $stored = $this->store->read(RuntimeSettingId::fromString('API_TOKEN'));
        self::assertEquals($this->clock->now()->modify('+30 minutes'), $stored?->previousExpiresAt);
    }

    #[Test]
    public function rotateRequiresStdinAndAValidWindow(): void
    {
        $tester = $this->tester(new RotateRuntimeSettingCommand($this->updater()));

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'API_TOKEN']));
        self::assertStringContainsString('add --stdin', $tester->getDisplay());

        foreach (['1h', 'P', 'PT', 'P99999D', 'P5DT', 'PT1H ', 'P400D'] as $window) {
            $tester->setInputs(['REDACTED-TEST-NEW']);
            $code = $tester->execute(['id' => 'API_TOKEN', '--stdin' => true, '--keep-previous' => $window]);
            self::assertSame(Command::FAILURE, $code, $window);
        }

        $tester->setInputs(['REDACTED-TEST-NEW']);
        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'chat.mode', '--stdin' => true]));
        $tester->setInputs(['REDACTED-TEST-NEW']);
        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'API_TOKEN', '--stdin' => true, '--dry-run' => true]));
        self::assertSame([], $this->store->auditLog());
    }

    #[Test]
    public function getMasksSecrets(): void
    {
        $this->updater()->set('API_TOKEN', self::SECRET, 'cli:test');
        $tester = $this->tester(new GetRuntimeSettingCommand($this->registry, $this->resolver));

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'API_TOKEN']));
        self::assertStringContainsString('API_TOKEN: ' . CommandIo::MASK . ' (secret)', $tester->getDisplay());
        self::assertStringNotContainsString($this->fingerprinter->fingerprint(RuntimeSettingId::fromString('API_TOKEN'), self::SECRET), $tester->getDisplay(), 'no fingerprint of a secret (QS-M-1)');
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'chat.days']));
        self::assertStringContainsString('chat.days: 30', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => 'chat.unset']));
        self::assertStringContainsString('chat.unset: (not set)', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'chat.nope']));
    }

    #[Test]
    public function listShowsSettingsWithSecretsMasked(): void
    {
        $this->updater()->set('API_TOKEN', self::SECRET, 'cli:test');
        $failing = new RuntimeSettingRegistry([new RuntimeSettingDefinition('down.x', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded())]);
        $tester  = $this->tester(new ListRuntimeSettingsCommand($this->registry, $this->resolver));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('API_TOKEN', $display);
        self::assertStringContainsString(CommandIo::MASK, $display);
        self::assertStringNotContainsString(self::SECRET, $display);
        self::assertStringContainsString('db_then_env', $display);
        self::assertStringContainsString('(not set)', $display);

        self::assertSame(Command::SUCCESS, $tester->execute(['--namespace' => 'nothing']));
        self::assertStringContainsString('No runtime settings registered.', $tester->getDisplay());

        $down = $this->tester(new ListRuntimeSettingsCommand($failing, new RuntimeSettingResolver(new UnconfiguredRuntimeSettingStore())));
        self::assertSame(Command::SUCCESS, $down->execute([]));
        self::assertStringContainsString('(unavailable)', $down->getDisplay());
    }

    #[Test]
    public function commandIoSanitizesTheActorAndBoundsStdin(): void
    {
        self::assertSame('cli:unknown', CommandIo::actorFor(null));
        self::assertSame('cli:unknown', CommandIo::actorFor(''));
        self::assertSame('cli:a_b_c', CommandIo::actorFor("a b\nc"));
        self::assertSame(4 + 128, strlen(CommandIo::actorFor(str_repeat('x', 300))));
        self::assertMatchesRegularExpression(RuntimeSettingWriteContext::ACTOR_PATTERN, CommandIo::actor());

        self::assertSame('a', CommandIo::readValue($this->input("a\r\n")));
        self::assertSame("a\n", CommandIo::readValue($this->input("a\n\n")));
        self::assertSame('a', CommandIo::readValue($this->input('a')));

        $this->expectException(InvalidRuntimeSettingValue::class);
        CommandIo::readValue($this->input(str_repeat('x', RuntimeSettingType::MAX_LENGTH + 1)));
    }

    #[Test]
    public function commandIoFallsBackToPhpStdin(): void
    {
        $input = $this->createStub(InputInterface::class);

        self::assertSame('', CommandIo::readValue($input, 'php://memory'));

        $this->expectException(InvalidRuntimeSettingValue::class);
        @CommandIo::readValue($input, '/nonexistent/stdin');
    }

    #[Test]
    public function changeOutputNeverShowsValues(): void
    {
        $id = RuntimeSettingId::fromString('a.b');

        self::assertSame('Dry run: a.b would be unset. Nothing was written.', ChangeOutput::describe(new RuntimeSettingChange($id, RuntimeSettingAction::Unset, null, null, null, true)));
        self::assertStringStartsWith('a.b rotated (fingerprint x -> y)', ChangeOutput::describe(new RuntimeSettingChange($id, RuntimeSettingAction::Rotate, 'x', 'y', 'v')));
    }

    #[Test]
    public function theUnconfiguredStoreRefusesEverything(): void
    {
        $store      = new UnconfiguredRuntimeSettingStore();
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, RuntimeSettingSource::Db, RuntimeSettingErrorPolicy::Fallback, validator: new Unbounded(), configured: 'env');
        $context    = new RuntimeSettingWriteContext('cli:test', $this->fingerprinter);

        self::assertSame('env', (new RuntimeSettingResolver($store))->get($definition));
        foreach ([
            static fn (): ?\Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue => $store->read($definition->id),
            static fn (): RuntimeSettingChange => $store->set($definition, 'x', $context),
            static fn (): RuntimeSettingChange => $store->unset($definition, $context),
            static fn (): RuntimeSettingChange => $store->rotate($definition, 'x', new DateTimeImmutable(), $context),
        ] as $i => $call) {
            try {
                $call();
                self::fail('Expected a refusal.');
            } catch (RuntimeSettingSourceFailure|RuntimeSettingNotWritable $e) {
                self::assertSame($i === 0 ? RuntimeSettingSourceFailure::class : RuntimeSettingNotWritable::class, $e::class);
            }
        }
    }

    private function setCommand(): SetRuntimeSettingCommand
    {
        return new SetRuntimeSettingCommand($this->updater());
    }

    private function updater(): RuntimeSettingUpdater
    {
        return new RuntimeSettingUpdater($this->registry, $this->store, $this->resolver, $this->fingerprinter, null, $this->clock);
    }

    private function tester(Command $command): CommandTester
    {
        return new CommandTester($command);
    }

    private function input(string $content): ArrayInput
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);
        $input = new ArrayInput([]);
        $input->setStream($stream);

        return $input;
    }
}
