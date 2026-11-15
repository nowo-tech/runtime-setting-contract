<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Closure;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
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
     *
     * @throws RuntimeSettingConfigurationError when the configured value or the default does not fit the type
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

    public function isWritable(): bool
    {
        return $this->source !== RuntimeSettingSource::Env;
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
            throw $e;
        } catch (Throwable $e) {
            throw InvalidRuntimeSettingValue::because('refused by the validator', $e);
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
