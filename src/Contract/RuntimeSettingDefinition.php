<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Closure;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Validator\IntRange;
use Nowo\RuntimeSettingContract\Contract\Validator\SingleLineString;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use Throwable;

use function is_callable;
use function is_string;

/**
 * One setting a bundle registers: its id, type, where its value comes from, how failures are handled, and the
 * bundle's validator.
 *
 * Immutable and request-independent: one instance serves every request of a long-running worker. The configured
 * (env) value is captured when the definition is built; env does not change while a process runs.
 */
final readonly class RuntimeSettingDefinition
{
    public RuntimeSettingId $id;

    public int $maxLength;

    /** @var (Closure(bool|float|int|string): (bool|float|int|string))|null */
    private ?Closure $validator;

    private string|int|float|bool|null $configured;

    private string|int|float|bool|null $default;

    /**
     * @param RuntimeSettingId|string $id `namespace.key` or an environment variable name
     * @param mixed $configured the configured (env) value; null when none
     * @param mixed $default the bundle's documented default, used when nothing else gives a value
     * @param (callable(bool|float|int|string): (bool|float|int|string))|RuntimeSettingValidatorInterface|null $validator
     * @param string|null $provider 1.1: name of the read-only {@see RuntimeValueProviderInterface} that holds the value
     *                              (source db / db_then_env only); null: the default source
     *
     * @throws RuntimeSettingConfigurationError when the configured value or the default does not fit the type, or when a
     *                                          setting that can be written (source db / db_then_env) has no validator:
     *                                          bound it (e.g. {@see IntRange}, {@see SingleLineString}) or opt out
     *                                          explicitly with {@see Unbounded}; or when the provider name is
     *                                          malformed or combined with `source: env`
     */
    public function __construct(
        RuntimeSettingId|string $id,
        public RuntimeSettingType $type,
        public RuntimeSettingSource $source = RuntimeSettingSource::Env,
        public RuntimeSettingErrorPolicy $onError = RuntimeSettingErrorPolicy::FailClosed,
        public bool $secret = false,
        RuntimeSettingValidatorInterface|callable|null $validator = null,
        mixed $configured = null,
        mixed $default = null,
        public bool $required = false,
        ?int $maxLength = null,
        public ?string $provider = null,
    ) {
        $this->id = is_string($id) ? RuntimeSettingId::fromString($id) : $id;

        if ($maxLength !== null && ($maxLength < 1 || $maxLength > RuntimeSettingType::MAX_LENGTH)) {
            throw RuntimeSettingConfigurationError::because('maxLength must be between 1 and ' . RuntimeSettingType::MAX_LENGTH . '.');
        }
        $this->maxLength = $maxLength ?? RuntimeSettingType::MAX_LENGTH;

        $this->validator = match (true) {
            $validator instanceof RuntimeSettingValidatorInterface => $validator->validate(...),
            is_callable($validator)                                => Closure::fromCallable($validator),
            default                                                => null,
        };

        // Bounds are a decision, not an accident (audit QS-M-2, lesson QP-H-1): a value that can be written at runtime
        // needs a validator, or the explicit Unbounded opt-out.
        if (!$this->validator instanceof Closure && $this->source !== RuntimeSettingSource::Env) {
            throw RuntimeSettingConfigurationError::because('Runtime setting "' . $this->id->toString() . '" can be written at runtime (source: ' . $this->source->value . ') but has no validator. Bound it (IntRange, SingleLineString, your own validator) or declare it Unbounded explicitly.');
        }

        if ($provider !== null) {
            if (!RuntimeValueProviderBinding::isValidName($provider)) {
                throw RuntimeSettingConfigurationError::because('Runtime setting "' . $this->id->toString() . '" names an invalid provider: use lowercase letters, digits and underscores, starting with a letter (at most 64 bytes).');
            }
            if ($this->source === RuntimeSettingSource::Env) {
                throw RuntimeSettingConfigurationError::because('Runtime setting "' . $this->id->toString() . '" names provider "' . $provider . '" but uses "source: env", which reads no provider. Use "source: db" or "db_then_env".');
            }
        }

        $this->configured = $this->normalizeConfigured($configured, 'configured value');
        $this->default    = $this->normalizeConfigured($default, 'default');
    }

    public function namespace(): string
    {
        return $this->id->namespace;
    }

    public function key(): string
    {
        return $this->id->key;
    }

    public function idString(): string
    {
        return $this->id->toString();
    }

    /**
     * Whether the source kind can hold values (`db`, `db_then_env`). Unchanged since 1.0: a setting bound to a
     * read-only provider is still "writable" here, so that adapters which route writable settings through
     * {@see RuntimeSettingUpdater} keep doing so and get {@see Exception\RuntimeSettingNotWritable} (fail closed).
     * Use {@see isReadOnly()} to know whether a write can succeed.
     */
    public function isWritable(): bool
    {
        return $this->source !== RuntimeSettingSource::Env;
    }

    /**
     * 1.1: whether a write is refused whatever the writer: `source: env`, or a value read from a provider.
     */
    public function isReadOnly(): bool
    {
        return $this->source === RuntimeSettingSource::Env || $this->provider !== null;
    }

    /**
     * 1.1: whether the value comes from a named read-only provider instead of the default source.
     */
    public function usesProvider(): bool
    {
        return $this->provider !== null;
    }

    /**
     * The configured (env) value, typed but not yet validated; null when none was configured.
     */
    public function configuredValue(): string|int|float|bool|null
    {
        return $this->configured;
    }

    /**
     * The bundle's documented default, typed but not yet validated; null when there is none.
     */
    public function defaultValue(): string|int|float|bool|null
    {
        return $this->default;
    }

    /**
     * Parses a stored string and runs the validator on it.
     *
     * @throws InvalidRuntimeSettingValue
     */
    public function parseAndValidate(string $raw): string|int|float|bool
    {
        if ($this->secret && $raw === '') {
            throw InvalidRuntimeSettingValue::because('a secret cannot be empty');
        }

        return $this->validate($this->type->parse($raw, $this->maxLength));
    }

    /**
     * Runs the bundle's validator. A validator that throws anything else than {@see InvalidRuntimeSettingValue}, or
     * returns a value of another type, refuses the value (fail closed).
     *
     * @throws InvalidRuntimeSettingValue
     */
    public function validate(string|int|float|bool $value): string|int|float|bool
    {
        if (!$this->validator instanceof Closure) {
            return $value;
        }

        try {
            $validated = ($this->validator)($value);
        } catch (InvalidRuntimeSettingValue $e) {
            // A secret's validator may quote the value: keep only a generic reason, nothing chained (audit QS-L-2).
            throw $this->secret ? InvalidRuntimeSettingValue::because('refused by the validator') : $e;
        } catch (Throwable $e) {
            // Never chain a foreign exception: its message may quote the value (audit QS-L-2).
            throw InvalidRuntimeSettingValue::because($this->secret ? 'refused by the validator' : 'refused by the validator (' . $e::class . ')');
        }

        // Round-trip through the type: a validator cannot widen the type or return an unbounded value.
        return $this->type->parse($this->type->format($validated), $this->maxLength);
    }

    private function normalizeConfigured(mixed $value, string $what): string|int|float|bool|null
    {
        // An empty env variable means "not configured" for every type but string.
        if ($value === null || ($value === '' && $this->type !== RuntimeSettingType::String)) {
            return null;
        }

        try {
            return $this->type->normalize($value, $this->maxLength);
        } catch (InvalidRuntimeSettingValue $e) {
            throw RuntimeSettingConfigurationError::because('The ' . $what . ' of runtime setting "' . $this->id->toString() . '" is invalid: ' . $e->getMessage());
        }
    }
}
