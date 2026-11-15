<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Audit;

use DateInterval;
use DateTimeImmutable;
use LogicException;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\ChangeOutput;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\CommandIo;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\PurgeRuntimeSettingsCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Bridge\Symfony\UnconfiguredRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\Validator\IntRange;
use Nowo\RuntimeSettingContract\Contract\Validator\SingleLineString;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use Nowo\RuntimeSettingContract\InMemory\InMemoryRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Tests\Support\MutableClock;
use Nowo\RuntimeSettingContract\Tests\Support\PdoRuntimeSettingStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function fopen;
use function fwrite;
use function hash_hmac;
use function json_encode;
use function rewind;
use function str_repeat;
use function strlen;
use function substr;

use const PHP_INT_MIN;

/**
 * Audit QS (runtime-setting-contract PR #1): the auditor's PoCs, inverted, plus the behaviour of each fix.
 */
final class AuditQsRegressionTest extends TestCase
{
    private const KEY = 'test-fingerprint-key-0123456789abcdef';

    // --- QS-M-1: no fingerprint of a secret ---------------------------------------------------------------------

    #[Test]
    public function dryRunIsNoLongerAFingerprintOracleForSecrets(): void
    {
        $store = new InMemoryRuntimeSettingStore();
        $def   = new RuntimeSettingDefinition('chat.webhook_secret', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new SingleLineString());
        $fp    = new RuntimeValueFingerprinter(self::KEY);
        $res   = new RuntimeSettingResolver($store, versionTtl: 0);
        $upd   = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$def]), $store, $res, $fp);

        $written = $upd->set('chat.webhook_secret', 'summer2026', 'user:1');

        foreach (['winter2026', 'summer2026'] as $guess) {
            $set    = $upd->set('chat.webhook_secret', $guess, 'user:2', dryRun: true);
            $rotate = $upd->rotate('chat.webhook_secret', $guess, new DateInterval('PT1H'), 'user:2', dryRun: true);
            foreach ([$set, $rotate] as $plan) {
                self::assertNull($plan->oldFingerprint);
                self::assertNull($plan->newFingerprint);
                self::assertTrue($plan->secret);
                self::assertTrue($plan->changed());
            }
        }

        self::assertNull($written->oldFingerprint);
        self::assertNull($written->newFingerprint);
        self::assertTrue($written->changed());
        $audit = $store->auditLog();
        self::assertCount(1, $audit);
        self::assertTrue($audit[0]['secret']);
        self::assertNull($audit[0]['old']);
        self::assertNull($audit[0]['new']);
    }

    #[Test]
    public function theUpdaterStripsSecretFingerprintsEvenFromANonCompliantWriter(): void
    {
        $def    = new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new Unbounded());
        $writer = new class implements RuntimeSettingWriterInterface {
            public function set(RuntimeSettingDefinition $definition, string $value, RuntimeSettingWriteContext $context): RuntimeSettingChange
            {
                return new RuntimeSettingChange($definition->id, RuntimeSettingAction::Set, 'leak-old', 'leak-new', 'v');
            }

            public function unset(RuntimeSettingDefinition $definition, RuntimeSettingWriteContext $context): RuntimeSettingChange
            {
                return new RuntimeSettingChange($definition->id, RuntimeSettingAction::Unset, 'leak-old', null, 'v');
            }

            public function rotate(RuntimeSettingDefinition $definition, string $value, DateTimeImmutable $previousExpiresAt, RuntimeSettingWriteContext $context): RuntimeSettingChange
            {
                return new RuntimeSettingChange($definition->id, RuntimeSettingAction::Rotate, 'leak-old', 'leak-new', 'v');
            }

            public function purgeExpired(DateTimeImmutable $now): int
            {
                return 0;
            }
        };
        $upd = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$def]), $writer, new RuntimeSettingResolver(new InMemoryRuntimeSettingStore()), new RuntimeValueFingerprinter(self::KEY));

        foreach ([
            $upd->set('API_TOKEN', 'REDACTED-TEST', 'user:1'),
            $upd->unset('API_TOKEN', 'user:1'),
            $upd->rotate('API_TOKEN', 'REDACTED-TEST', new DateInterval('PT1H'), 'user:1'),
        ] as $change) {
            self::assertNull($change->oldFingerprint);
            self::assertNull($change->newFingerprint);
            self::assertSame('v', $change->version);
        }
    }

    #[Test]
    public function theWriteContextNeverFingerprintsASecret(): void
    {
        $context = new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter(self::KEY));
        $secret  = new RuntimeSettingDefinition('A_B', RuntimeSettingType::String, secret: true);
        $plain   = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String);

        self::assertNull($context->fingerprint($secret, 'x'));
        self::assertNull($context->fingerprint($plain, null));
        self::assertSame((new RuntimeValueFingerprinter(self::KEY))->fingerprint($plain->id, 'x'), $context->fingerprint($plain, 'x'));
    }

    #[Test]
    public function commandOutputForASecretCarriesNoFingerprint(): void
    {
        $id = RuntimeSettingId::fromString('API_TOKEN');

        self::assertSame('Dry run: API_TOKEN would be rotated (secret: no fingerprint). Nothing was written.', ChangeOutput::describe(new RuntimeSettingChange($id, RuntimeSettingAction::Rotate, null, null, null, true, true)));
        self::assertStringStartsWith('API_TOKEN set (secret: changed, no fingerprint).', ChangeOutput::describe(new RuntimeSettingChange($id, RuntimeSettingAction::Set, null, null, 'v', false, true)));
    }

    // --- QS-M-2: bounds are a decision ----------------------------------------------------------------------------

    #[Test]
    public function aWritableSettingWithoutValidatorIsRefused(): void
    {
        foreach ([RuntimeSettingSource::Db, RuntimeSettingSource::DbThenEnv] as $source) {
            try {
                new RuntimeSettingDefinition('chat.retention_days', RuntimeSettingType::Int, $source, configured: '30');
                self::fail('Expected RuntimeSettingConfigurationError.');
            } catch (RuntimeSettingConfigurationError $e) {
                self::assertStringContainsString('has no validator', $e->getMessage());
            }
        }

        self::assertSame(30, (new RuntimeSettingDefinition('chat.retention_days', RuntimeSettingType::Int, configured: '30'))->configuredValue(), 'source env needs no validator');
    }

    #[Test]
    public function registeringAWritableSettingWithoutValidatorFailsTheContainerBuild(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('"chat.retention_days" (x) uses "source: db_then_env" but its bundle registers no validator');

        RuntimeSettingsConfiguration::registerDefinition(
            new ContainerBuilder(),
            'x',
            ['source' => 'db_then_env', 'key' => 'chat.retention_days', 'on_error' => 'fail_closed'],
            RuntimeSettingType::Int,
            '30',
        );
    }

    #[Test]
    public function intRangeRefusesTheAuditorsValues(): void
    {
        $store = new InMemoryRuntimeSettingStore();
        $def   = new RuntimeSettingDefinition('chat.retention_days', RuntimeSettingType::Int, RuntimeSettingSource::DbThenEnv, validator: new IntRange(1, 36500), configured: '30');
        $upd   = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$def]), $store, new RuntimeSettingResolver($store), new RuntimeValueFingerprinter(self::KEY));

        foreach ([(string) PHP_INT_MIN, '0', '-1', '36501'] as $value) {
            try {
                $upd->set('chat.retention_days', $value, 'cli:root');
                self::fail('Expected InvalidRuntimeSettingValue for ' . $value);
            } catch (InvalidRuntimeSettingValue $e) {
                self::assertStringContainsString('must be between 1 and 36500', $e->getMessage());
            }
        }
        self::assertSame([], $store->auditLog());
    }

    #[Test]
    public function intRangeCanClampAndChecksItsBounds(): void
    {
        $clamp = new IntRange(1, 10, clamp: true);
        self::assertSame(10, $clamp->validate(99));
        self::assertSame(1, $clamp->validate(-5));
        self::assertSame(5, $clamp->validate(5));

        try {
            $clamp->validate('5');
            self::fail('Expected InvalidRuntimeSettingValue.');
        } catch (InvalidRuntimeSettingValue $e) {
            self::assertSame('expected an integer', $e->getMessage());
        }

        $this->expectException(RuntimeSettingConfigurationError::class);
        new IntRange(2, 1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function multiLine(): iterable
    {
        yield 'LF' => ["smtp://host\nBcc: x"];
        yield 'CR' => ["a\rb"];
        yield 'NUL' => ["a\0b"];
        yield 'TAB' => ["a\tb"];
        yield 'DEL' => ["a\x7Fb"];
        yield 'C1 NEL' => ["a\u{0085}b"];
    }

    #[Test]
    #[DataProvider('multiLine')]
    public function singleLineStringRefusesControlCharacters(string $value): void
    {
        $this->expectException(InvalidRuntimeSettingValue::class);
        $this->expectExceptionMessage('single line');

        (new SingleLineString())->validate($value);
    }

    #[Test]
    public function singleLineStringBoundsLengthAndEmptiness(): void
    {
        self::assertSame('héllo wörld', (new SingleLineString(32))->validate('héllo wörld'));
        self::assertSame('', (new SingleLineString(allowEmpty: true))->validate(''));

        foreach ([[new SingleLineString(3), 'abcd', 'longer than 3 bytes'], [new SingleLineString(), '', 'must not be empty'], [new SingleLineString(), 5, 'expected a string']] as [$validator, $value, $reason]) {
            try {
                $validator->validate($value);
                self::fail('Expected InvalidRuntimeSettingValue.');
            } catch (InvalidRuntimeSettingValue $e) {
                self::assertSame($reason, $e->getMessage());
            }
        }

        $this->expectException(RuntimeSettingConfigurationError::class);
        new SingleLineString(0);
    }

    #[Test]
    public function unboundedIsAnExplicitPassThrough(): void
    {
        self::assertSame(PHP_INT_MIN, (new Unbounded())->validate(PHP_INT_MIN));
    }

    // --- QS-L-2: no value through the exception chain --------------------------------------------------------------

    #[Test]
    public function validatorExceptionChainNoLongerCarriesTheValue(): void
    {
        $quoting = static function (string|int|float|bool $v): never {
            throw new RuntimeException('Malformed DSN "' . $v . '"');
        };

        foreach ([true, false] as $secret) {
            $def = new RuntimeSettingDefinition('mail.dsn', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: $secret, validator: $quoting);
            try {
                $def->parseAndValidate('smtp://user:hunter2@mail');
                self::fail('Expected a refusal.');
            } catch (InvalidRuntimeSettingValue $e) {
                self::assertNull($e->getPrevious());
                self::assertStringNotContainsString('hunter2', (string) $e);
                self::assertSame($secret ? 'refused by the validator' : 'refused by the validator (RuntimeException)', $e->getMessage());
            }
        }
    }

    #[Test]
    public function aSecretsValidatorReasonIsReplacedByAGenericOne(): void
    {
        $quoting = static function (string|int|float|bool $v): never {
            throw InvalidRuntimeSettingValue::because('bad token ' . $v);
        };
        $secret = new RuntimeSettingDefinition('A_B', RuntimeSettingType::String, secret: true, validator: $quoting);
        $plain  = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, validator: static function (): never {
            throw new LogicException('x');
        });

        try {
            $secret->validate('REDACTED-TEST');
            self::fail('Expected a refusal.');
        } catch (InvalidRuntimeSettingValue $e) {
            self::assertSame('refused by the validator', $e->getMessage());
            self::assertNull($e->getPrevious());
        }

        $this->expectExceptionMessage('refused by the validator (LogicException)');
        $plain->validate('x');
    }

    // --- QS-L-3: domain-separated fingerprint key ------------------------------------------------------------------

    #[Test]
    public function theFingerprintKeyIsDerivedWithADomainLabel(): void
    {
        $id  = RuntimeSettingId::fromString('a.b');
        $raw = substr(hash_hmac('sha256', 'a.b' . "\0" . 'v', self::KEY), 0, 16);
        $sub = hash_hmac('sha256', RuntimeValueFingerprinter::DOMAIN, self::KEY, true);

        self::assertNotSame($raw, (new RuntimeValueFingerprinter(self::KEY))->fingerprint($id, 'v'));
        self::assertSame(substr(hash_hmac('sha256', 'a.b' . "\0" . 'v', $sub), 0, 16), (new RuntimeValueFingerprinter(self::KEY))->fingerprint($id, 'v'));
    }

    // --- QS-L-4: purge at rest ------------------------------------------------------------------------------------

    #[Test]
    public function expiredPreviousValuesArePurgedOnDemand(): void
    {
        $clock = new MutableClock();
        $store = new InMemoryRuntimeSettingStore($clock);
        $a     = new RuntimeSettingDefinition('ns.a', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new Unbounded());
        $b     = new RuntimeSettingDefinition('other.b', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded());
        $res   = new RuntimeSettingResolver($store, null, $clock, 300);
        $upd   = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$a, $b]), $store, $res, new RuntimeValueFingerprinter(self::KEY), null, $clock);
        $upd->set('ns.a', 'REDACTED-TEST-OLD', 'cli:test');
        $upd->rotate('ns.a', 'REDACTED-TEST-NEW', new DateInterval('PT1H'), 'cli:test');
        $upd->set('other.b', 'x', 'cli:test');
        $version = $store->namespaceVersion('ns');

        self::assertSame(0, $upd->purgeExpired(), 'inside the window nothing is purged');
        self::assertSame('REDACTED-TEST-OLD', $store->read($a->id)?->previous);

        $clock->advance('+1 hour');
        self::assertSame(1, $upd->purgeExpired());
        self::assertNull($store->read($a->id)?->previous);
        self::assertNotSame($version, $store->namespaceVersion('ns'), 'a purge bumps the version of the namespace it changed');
        self::assertSame(['REDACTED-TEST-NEW'], $res->candidates($a));
        self::assertSame(0, $upd->purgeExpired());
    }

    #[Test]
    public function thePdoReferenceStorePurgesInItsOwnTransaction(): void
    {
        $clock = new MutableClock('2030-01-01 00:00:00');
        $store = new PdoRuntimeSettingStore('sqlite::memory:');
        $def   = new RuntimeSettingDefinition('ns.a', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded());
        $upd   = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$def]), $store, new RuntimeSettingResolver($store), new RuntimeValueFingerprinter(self::KEY), null, $clock);
        $upd->set('ns.a', 'old', 'cli:test');
        $upd->rotate('ns.a', 'new', new DateInterval('PT1H'), 'cli:test');

        self::assertSame(1, $store->purgeExpired(new DateTimeImmutable('2030-01-01 01:00:00')));
        self::assertNull($store->read($def->id)?->previous);

        $store->connection()->beginTransaction();
        $this->expectException(RuntimeSettingNotWritable::class);
        $store->purgeExpired(new DateTimeImmutable());
    }

    #[Test]
    public function thePurgeCommandReportsAndRefusesWithoutAWriter(): void
    {
        $store = new InMemoryRuntimeSettingStore();
        $upd   = new RuntimeSettingUpdater(new RuntimeSettingRegistry([]), $store, new RuntimeSettingResolver($store), new RuntimeValueFingerprinter(self::KEY));
        $ok    = new CommandTester(new PurgeRuntimeSettingsCommand($upd));
        self::assertSame(Command::SUCCESS, $ok->execute([]));
        self::assertStringContainsString('Purged 0 expired previous value(s).', $ok->getDisplay());

        $none    = new UnconfiguredRuntimeSettingStore();
        $refused = new CommandTester(new PurgeRuntimeSettingsCommand(new RuntimeSettingUpdater(new RuntimeSettingRegistry([]), $none, new RuntimeSettingResolver($none), new RuntimeValueFingerprinter(self::KEY))));
        self::assertSame(Command::FAILURE, $refused->execute([]));
        self::assertStringContainsString('No runtime setting writer is configured.', $refused->getDisplay());
    }

    // --- QS-I-3: one spelling per id -------------------------------------------------------------------------------

    #[Test]
    public function envDottedAliasIsRefused(): void
    {
        try {
            RuntimeSettingId::fromString('env.CHAT_RETENTION_DAYS');
            self::fail('Expected InvalidRuntimeSettingId.');
        } catch (InvalidRuntimeSettingId) {
        }

        $tree = new TreeBuilder('chat');
        RuntimeSettingsConfiguration::appendTo($tree->getRootNode(), ['days' => ['key' => 'CHAT_RETENTION_DAYS']]);
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->process($tree->buildTree(), [['runtime' => ['days' => ['key' => 'env.CHAT_RETENTION_DAYS']]]]);
    }

    // --- QS-I-4: STDIN never truncates silently --------------------------------------------------------------------

    #[Test]
    public function stdinReaderRefusesInsteadOfTruncating(): void
    {
        self::assertSame(RuntimeSettingType::MAX_LENGTH, strlen(CommandIo::readValue($this->input(str_repeat('a', RuntimeSettingType::MAX_LENGTH) . "\r\n"))));

        $this->expectException(InvalidRuntimeSettingValue::class);
        $this->expectExceptionMessage('too long');
        CommandIo::readValue($this->input(str_repeat('a', RuntimeSettingType::MAX_LENGTH) . "\r\nTAIL-THAT-WAS-DROPPED"));
    }

    #[Test]
    public function auditEntriesNeverContainTheSecret(): void
    {
        $store = new InMemoryRuntimeSettingStore();
        $def   = new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new Unbounded());
        $upd   = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$def]), $store, new RuntimeSettingResolver($store), new RuntimeValueFingerprinter(self::KEY));
        $upd->set('API_TOKEN', 'REDACTED-TEST-A', 'cli:test');
        $upd->rotate('API_TOKEN', 'REDACTED-TEST-B', new DateInterval('PT1H'), 'cli:test');
        $upd->unset('API_TOKEN', 'cli:test');

        self::assertStringNotContainsString('REDACTED', (string) json_encode($store->auditLog()));
        foreach ($store->auditLog() as $entry) {
            self::assertNull($entry['old']);
            self::assertNull($entry['new']);
        }
    }

    private function input(string $content): ArrayInput
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);
        $input = new ArrayInput([], new InputDefinition());
        $input->setStream($stream);

        return $input;
    }
}
