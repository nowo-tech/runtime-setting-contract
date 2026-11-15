<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

/**
 * Dispatched after a write committed (never inside the writer's transaction, never after a rollback).
 *
 * Ids only: no value, no fingerprint, no actor. A listener that needs the value reads it through the resolver.
 */
final readonly class SettingChangedEvent
{
    public function __construct(
        public string $namespace,
        public string $key,
        public RuntimeSettingAction $action,
    ) {
    }

    public static function fromChange(RuntimeSettingChange $change): self
    {
        return new self($change->id->namespace, $change->id->key, $change->action);
    }

    public function id(): RuntimeSettingId
    {
        return RuntimeSettingId::of($this->namespace, $this->key);
    }
}
