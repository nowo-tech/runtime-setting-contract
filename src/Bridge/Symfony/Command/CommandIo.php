<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\Command;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;

use function fclose;
use function fopen;
use function function_exists;
use function getenv;
use function is_resource;
use function is_string;
use function posix_geteuid;
use function posix_getpwuid;
use function preg_replace;
use function str_ends_with;
use function stream_get_contents;
use function strlen;
use function substr;

/**
 * Shared console helpers: the audit actor and reading a value from STDIN.
 *
 * @internal
 */
final class CommandIo
{
    public const MASK = '********';

    /**
     * `cli:<os user>`, with the user name reduced to the actor alphabet.
     */
    public static function actor(): string
    {
        return self::actorFor(self::osUser());
    }

    public static function actorFor(?string $user): string
    {
        $name = substr((string) preg_replace('/[^A-Za-z0-9._@+-]/', '_', (string) $user), 0, 128);

        return 'cli:' . ($name === '' ? 'unknown' : $name);
    }

    /**
     * Reads the value from the command's input stream (STDIN unless a test set one). One trailing newline is
     * dropped, so `echo value | bin/console …` works. Bounded: more than the maximum length is refused.
     *
     * @throws InvalidRuntimeSettingValue
     */
    public static function readValue(InputInterface $input, string $fallback = 'php://stdin'): string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $opened = null;
        if (!is_resource($stream)) {
            $stream = $opened = fopen($fallback, 'r');
        }

        // One byte more than the longest acceptable input (value plus "\r\n"), so overflow is detected, never cut.
        $raw = is_resource($stream) ? stream_get_contents($stream, RuntimeSettingType::MAX_LENGTH + 3) : false;
        if (is_resource($opened)) {
            fclose($opened);
        }

        if (!is_string($raw)) {
            throw InvalidRuntimeSettingValue::because('could not read the value from STDIN');
        }
        if (strlen($raw) > RuntimeSettingType::MAX_LENGTH + 2) {
            throw InvalidRuntimeSettingValue::because('the value read from STDIN is too long');
        }
        if (str_ends_with($raw, "\r\n")) {
            $raw = substr($raw, 0, -2);
        } elseif (str_ends_with($raw, "\n")) {
            $raw = substr($raw, 0, -1);
        }
        if (strlen($raw) > RuntimeSettingType::MAX_LENGTH) {
            throw InvalidRuntimeSettingValue::because('the value read from STDIN is too long');
        }

        return $raw;
    }

    public static function argument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : null;
    }

    private static function osUser(): ?string
    {
        $entry = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $name  = $entry !== false ? $entry['name'] : (getenv('USER') ?: getenv('USERNAME'));

        return is_string($name) ? $name : null;
    }
}
