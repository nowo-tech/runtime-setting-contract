<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

use const JSON_THROW_ON_ERROR;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function text(): string
    {
        return json_encode($this->records, JSON_THROW_ON_ERROR);
    }
}
