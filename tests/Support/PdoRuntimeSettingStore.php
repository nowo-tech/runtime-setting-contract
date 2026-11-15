<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Support;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use PDO;
use RuntimeException;
use Throwable;

use function is_string;

/**
 * A host's own source and writer over PDO (SQLite in the tests): what a DDD host writes over its repository.
 * Real transactions, so the rollback rules of the contract can be exercised. Not shipped.
 */
final class PdoRuntimeSettingStore implements RuntimeValueSourceInterface, RuntimeSettingWriterInterface
{
    public const INITIAL_VERSION = '0';

    private ?PDO $pdo = null;

    /** Test hook: throw after the value and the version were written, before the commit. */
    public bool $failBeforeCommit = false;

    public function __construct(private readonly string $dsn)
    {
    }

    public function connection(): PDO
    {
        if (!$this->pdo instanceof PDO) {
            $this->pdo = new PDO($this->dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->pdo->exec('PRAGMA synchronous = OFF');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS rs_values (ns TEXT NOT NULL, k TEXT NOT NULL, cur TEXT NOT NULL, prev TEXT NULL, prev_expires INTEGER NULL, PRIMARY KEY (ns, k))');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS rs_versions (ns TEXT NOT NULL PRIMARY KEY, counter INTEGER NOT NULL, token TEXT NOT NULL)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS rs_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, actor TEXT NOT NULL, setting TEXT NOT NULL, action TEXT NOT NULL, old_fp TEXT NULL, new_fp TEXT NULL)');
        }

        return $this->pdo;
    }

    public function namespaceVersion(string $namespace): string
    {
        $statement = $this->connection()->prepare('SELECT token FROM rs_versions WHERE ns = ?');
        $statement->execute([$namespace]);
        $token = $statement->fetchColumn();

        return is_string($token) ? $token : self::INITIAL_VERSION;
    }

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        $statement = $this->connection()->prepare('SELECT ns, k, cur, prev, prev_expires FROM rs_values WHERE ns = ? AND k = ?');
        $statement->execute([$id->namespace, $id->key]);
        /** @var array{ns: string, k: string, cur: string, prev: ?string, prev_expires: int|string|null}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        // Exact re-check in PHP (REQ-SEC-006).
        if ($row === false || $row['ns'] !== $id->namespace || $row['k'] !== $id->key) {
            return null;
        }

        return $row['prev'] === null || $row['prev_expires'] === null
            ? new StoredRuntimeValue($row['cur'])
            : new StoredRuntimeValue($row['cur'], $row['prev'], new DateTimeImmutable('@' . $row['prev_expires']));
    }

    public function set(RuntimeSettingDefinition $definition, string $value, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        return $this->write($definition->id, RuntimeSettingAction::Set, $value, null, $context);
    }

    public function unset(RuntimeSettingDefinition $definition, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        return $this->write($definition->id, RuntimeSettingAction::Unset, null, null, $context);
    }

    public function rotate(RuntimeSettingDefinition $definition, string $value, DateTimeImmutable $previousExpiresAt, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        return $this->write($definition->id, RuntimeSettingAction::Rotate, $value, $previousExpiresAt, $context);
    }

    public function auditCount(): int
    {
        $statement = $this->connection()->query('SELECT COUNT(*) FROM rs_audit');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function write(RuntimeSettingId $id, RuntimeSettingAction $action, ?string $value, ?DateTimeImmutable $keepUntil, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        $pdo = $this->connection();
        if ($pdo->inTransaction()) {
            throw RuntimeSettingNotWritable::insideTransaction();
        }

        $pdo->beginTransaction();
        try {
            $old   = $this->read($id);
            $oldFp = $old instanceof StoredRuntimeValue ? $context->fingerprinter->fingerprint($id, $old->current) : null;
            $newFp = $value === null ? null : $context->fingerprinter->fingerprint($id, $value);

            $pdo->prepare('DELETE FROM rs_values WHERE ns = ? AND k = ?')->execute([$id->namespace, $id->key]);
            if ($value !== null) {
                $previous = $keepUntil instanceof DateTimeImmutable && $old instanceof StoredRuntimeValue ? $old->current : null;
                $pdo->prepare('INSERT INTO rs_values (ns, k, cur, prev, prev_expires) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id->namespace, $id->key, $value, $previous, $previous === null ? null : $keepUntil->getTimestamp()]);
            }

            $max     = $pdo->query('SELECT COALESCE(MAX(counter), 0) FROM rs_versions');
            $counter = ($max === false ? 0 : (int) $max->fetchColumn()) + 1;
            $token   = $counter . '-' . bin2hex(random_bytes(8));
            $pdo->prepare('INSERT OR REPLACE INTO rs_versions (ns, counter, token) VALUES (?, ?, ?)')->execute([$id->namespace, $counter, $token]);
            $pdo->prepare('INSERT INTO rs_audit (actor, setting, action, old_fp, new_fp) VALUES (?, ?, ?, ?, ?)')
                ->execute([$context->actor, $id->toString(), $action->value, $oldFp, $newFp]);

            if ($this->failBeforeCommit) {
                throw new RuntimeException('Simulated failure before commit.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return new RuntimeSettingChange($id, $action, $oldFp, $newFp, $token);
    }
}
