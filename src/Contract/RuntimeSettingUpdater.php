<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use DateInterval;
use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\Exception\UnknownRuntimeSetting;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function strlen;

/**
 * The one place that changes runtime settings: the console commands and an admin UI both go through it, so they
 * follow the same rules.
 *
 * 1. Only registered settings ({@see RuntimeSettingRegistry}); unknown ids are refused.
 * 2. `source: env` is refused: an environment value cannot change at runtime.
 * 3. The new value is bounded, parsed by type and run through the bundle's validator before anything is written;
 *    the validated (possibly clamped) value is what gets stored.
 * 4. A dry run stops here and writes nothing.
 * 5. The writer commits its own unit of work (value, version bump, audit). Whatever happens, this process then
 *    forgets what it remembered about the namespace (memo and version), so a failed or rolled-back write never
 *    leaves a value behind (lesson IS R10).
 * 6. Fingerprints of secrets are never returned, by a dry run or by a write (audit QS-M-1).
 * 7. {@see SettingChangedEvent} is dispatched only after the writer returned, i.e. after commit, never after a
 *    failure (lessons QL-M1 / QL-M2). A failing listener is logged, not rethrown: the change is already committed.
 */
final readonly class RuntimeSettingUpdater
{
    /** Longest rotation window accepted. */
    public const MAX_KEEP_PREVIOUS_SECONDS = 2_592_000;

    private ClockInterface $clock;

    private LoggerInterface $logger;

    public function __construct(
        private RuntimeSettingRegistry $registry,
        private RuntimeSettingWriterInterface $writer,
        private RuntimeSettingResolver $resolver,
        private RuntimeValueFingerprinter $fingerprinter,
        private ?EventDispatcherInterface $dispatcher = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock  = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The definition of a setting that can be written, or why not.
     *
     * @throws InvalidRuntimeSettingId
     * @throws UnknownRuntimeSetting
     * @throws RuntimeSettingNotWritable
     */
    public function writableDefinition(string $id): RuntimeSettingDefinition
    {
        $definition = $this->registry->get($id);
        if (!$definition->isWritable()) {
            throw RuntimeSettingNotWritable::envSource($definition->idString());
        }

        return $definition;
    }

    /**
     * Validates a value as {@see set()} would, without writing. Returns the string that would be stored.
     *
     * @throws InvalidRuntimeSettingId
     * @throws UnknownRuntimeSetting
     * @throws RuntimeSettingNotWritable
     * @throws InvalidRuntimeSettingValue
     */
    public function prepare(string $id, string $value): string
    {
        $definition = $this->writableDefinition($id);

        return $this->prepareFor($definition, $value);
    }

    /**
     * @throws InvalidRuntimeSettingId
     * @throws UnknownRuntimeSetting
     * @throws RuntimeSettingNotWritable
     * @throws InvalidRuntimeSettingValue
     * @throws RuntimeSettingConfigurationError when the actor is malformed
     */
    public function set(string $id, string $value, string $actor, bool $dryRun = false): RuntimeSettingChange
    {
        $definition = $this->writableDefinition($id);
        $stored     = $this->prepareFor($definition, $value);
        $context    = new RuntimeSettingWriteContext($actor, $this->fingerprinter);

        if ($dryRun) {
            return $this->plan($definition, RuntimeSettingAction::Set, $stored);
        }

        // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
        return $this->publish($definition, fn (): RuntimeSettingChange => $this->writer->set($definition, $stored, $context));
    }

    /**
     * @throws InvalidRuntimeSettingId
     * @throws UnknownRuntimeSetting
     * @throws RuntimeSettingNotWritable
     * @throws RuntimeSettingConfigurationError when the actor is malformed
     */
    public function unset(string $id, string $actor, bool $dryRun = false): RuntimeSettingChange
    {
        $definition = $this->writableDefinition($id);
        $context    = new RuntimeSettingWriteContext($actor, $this->fingerprinter);

        if ($dryRun) {
            return $this->plan($definition, RuntimeSettingAction::Unset, null);
        }

        // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
        return $this->publish($definition, fn (): RuntimeSettingChange => $this->writer->unset($definition, $context));
    }

    /**
     * Stores a new current value and keeps the old one as `previous` for `$keepPrevious` (0 to 30 days).
     *
     * @throws InvalidRuntimeSettingId
     * @throws UnknownRuntimeSetting
     * @throws RuntimeSettingNotWritable
     * @throws InvalidRuntimeSettingValue
     * @throws RuntimeSettingConfigurationError when the window or the actor is invalid
     */
    public function rotate(string $id, string $value, DateInterval $keepPrevious, string $actor, bool $dryRun = false): RuntimeSettingChange
    {
        $definition = $this->writableDefinition($id);
        $stored     = $this->prepareFor($definition, $value);
        $context    = new RuntimeSettingWriteContext($actor, $this->fingerprinter);
        $now        = $this->clock->now();
        $expiresAt  = $this->windowEnd($now, $keepPrevious);

        if ($dryRun) {
            return $this->plan($definition, RuntimeSettingAction::Rotate, $stored);
        }

        // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
        return $this->publish($definition, fn (): RuntimeSettingChange => $this->writer->rotate($definition, $stored, $expiresAt, $context));
    }

    private function prepareFor(RuntimeSettingDefinition $definition, string $value): string
    {
        // Bounded before any parsing (fail closed, QP-H-1 / QP-L-3).
        if (strlen($value) > $definition->maxLength) {
            throw InvalidRuntimeSettingValue::forSetting($definition->idString(), InvalidRuntimeSettingValue::because('longer than ' . $definition->maxLength . ' bytes'));
        }

        try {
            return $definition->type->format($definition->parseAndValidate($value));
        } catch (InvalidRuntimeSettingValue $e) {
            throw InvalidRuntimeSettingValue::forSetting($definition->idString(), $e);
        }
    }

    private function windowEnd(DateTimeImmutable $now, DateInterval $keepPrevious): DateTimeImmutable
    {
        $end     = $now->add($keepPrevious);
        $seconds = $end->getTimestamp() - $now->getTimestamp();
        if ($keepPrevious->invert === 1 || $seconds < 0 || $seconds > self::MAX_KEEP_PREVIOUS_SECONDS) {
            throw RuntimeSettingConfigurationError::because('The rotation window must be between 0 seconds and 30 days.');
        }

        return $end;
    }

    /**
     * Deletes every expired previous value at rest (the resolver already ignores them), then forgets everything this
     * process remembered. Run it from the `nowo:runtime-setting:purge` command, a scheduled task or a message.
     *
     * @throws RuntimeSettingNotWritable
     *
     * @return int how many previous values were deleted
     */
    public function purgeExpired(): int
    {
        try {
            // @igor-ignore - Calls a port; no request data kept in this service.
            return $this->writer->purgeExpired($this->clock->now());
        } finally {
            // @igor-ignore - Calls the resolver's own boundary/invalidation API; no request data kept in this service.
            $this->resolver->reset();
        }
    }

    private function plan(RuntimeSettingDefinition $definition, RuntimeSettingAction $action, ?string $stored): RuntimeSettingChange
    {
        // Never a fingerprint of a secret, not even of a candidate (audit QS-M-1: dry runs were a guess oracle).
        $fingerprint = $definition->secret || $stored === null ? null : $this->fingerprinter->fingerprint($definition->id, $stored);

        return new RuntimeSettingChange($definition->id, $action, null, $fingerprint, null, true, $definition->secret);
    }

    /**
     * @param callable(): RuntimeSettingChange $write
     */
    private function publish(RuntimeSettingDefinition $definition, callable $write): RuntimeSettingChange
    {
        try {
            $change = $write();
        } finally {
            // Committed, failed or rolled back: never keep a value or a version read around the write.
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $this->resolver->forgetNamespace($definition->namespace());
        }

        // Defense in depth: whatever the writer returned, no fingerprint of a secret leaves the updater (QS-M-1).
        if ($definition->secret) {
            $change = $change->withoutFingerprints();
        }

        try {
            $this->dispatcher?->dispatch(SettingChangedEvent::fromChange($change));
        } catch (Throwable $e) {
            $this->logger->error('Runtime setting {id} changed, but a SettingChangedEvent listener failed.', [
                'id'        => $definition->idString(),
                'exception' => $e::class,
            ]);
        }

        return $change;
    }
}
