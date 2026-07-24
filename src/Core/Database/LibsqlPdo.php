<?php
declare(strict_types=1);

namespace TypeDock\Core\Database;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO subset adapter backed by Hrana over HTTP.
 *
 * This is a protocol adapter, not a native PDO driver. It keeps TypeDock's
 * existing PDO service contract while allowing remote-only libSQL services
 * such as Turso and Bunny Database to run without FFI.
 */
final class LibsqlPdo extends PDO
{
    private int $defaultFetchMode = PDO::FETCH_ASSOC;
    private ?string $lastErrorCode = null;
    /** @var array{0:string,1:int|null,2:string|null} */
    private array $lastErrorInfo = ['00000', null, null];
    private readonly HranaHttpClient $client;
    private bool $transactionActive = false;
    /** @var list<array{sql:string,params:array<int|string,mixed>}> */
    private array $transactionStatements = [];

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        string $url,
        #[\SensitiveParameter] string $authToken,
        array $options = [],
        ?HranaHttpClient $client = null,
    ) {
        $this->defaultFetchMode = (int) ($options[PDO::ATTR_DEFAULT_FETCH_MODE] ?? PDO::FETCH_ASSOC);

        try {
            $this->client = $client ?? new HranaHttpClient($url, $authToken, [
                'timeout' => (int) ($options['timeout'] ?? 15),
                'connect_timeout' => (int) ($options['connect_timeout'] ?? 5),
                'allow_insecure' => (bool) ($options['allow_insecure'] ?? false),
            ]);
        } catch (\Throwable $e) {
            $this->rememberError($e);
            throw new PDOException('Failed to configure remote libSQL: ' . $e->getMessage(), 0, $e);
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $fetchMode = (int) ($options[PDO::ATTR_DEFAULT_FETCH_MODE] ?? $this->defaultFetchMode);

        return new LibsqlPdoStatement(
            $query,
            fn(string $sql, array $params): array => $this->executePrepared($sql, $params),
            $fetchMode,
        );
    }

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs,
    ): PDOStatement|false {
        $statement = $this->prepare($query);
        if ($statement === false) {
            return false;
        }
        if ($fetchMode !== null) {
            $statement->setFetchMode($fetchMode, ...$fetchModeArgs);
        }
        $statement->execute();

        return $statement;
    }

    public function exec(string $statement): int|false
    {
        return $this->executePrepared($statement, [])['affected'];
    }

    public function beginTransaction(): bool
    {
        if ($this->transactionActive) {
            throw new PDOException('A remote libSQL transaction is already active.');
        }

        $this->transactionActive = true;
        $this->transactionStatements = [];
        return true;
    }

    public function commit(): bool
    {
        if (!$this->transactionActive) {
            throw new PDOException('No active remote libSQL transaction.');
        }

        try {
            $this->client->executeAtomicBatch($this->transactionStatements);
            $this->transactionActive = false;
            $this->transactionStatements = [];
            return true;
        } catch (\Throwable $e) {
            $this->rememberError($e);
            throw new PDOException('Failed to commit remote libSQL transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    public function rollBack(): bool
    {
        if (!$this->transactionActive) {
            throw new PDOException('No active remote libSQL transaction.');
        }

        $this->transactionActive = false;
        $this->transactionStatements = [];
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->client->lastInsertId();
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        if ($type === PDO::PARAM_NULL) {
            return 'NULL';
        }
        return "'" . str_replace("'", "''", $string) . "'";
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            // Report SQLite intentionally: migrations, plugin migrations, and
            // SQL dump code should all select their SQLite-compatible branch.
            PDO::ATTR_DRIVER_NAME => 'sqlite',
            PDO::ATTR_DEFAULT_FETCH_MODE => $this->defaultFetchMode,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_SERVER_VERSION => 'libsql-hrana-http',
            default => false,
        };
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        if ($attribute === PDO::ATTR_DEFAULT_FETCH_MODE) {
            $this->defaultFetchMode = (int) $value;
            return true;
        }

        if ($attribute === PDO::ATTR_ERRMODE) {
            if ((int) $value !== PDO::ERRMODE_EXCEPTION) {
                throw new PDOException('The remote libSQL driver supports PDO::ERRMODE_EXCEPTION only.');
            }
            return true;
        }

        if ($attribute === PDO::ATTR_EMULATE_PREPARES) {
            return (bool) $value === false;
        }

        return false;
    }

    public function errorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function errorInfo(): array
    {
        return $this->lastErrorInfo;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array{rows:list<array<string,mixed>>,affected:int,columns:int}
     */
    private function executePrepared(string $sql, array $params): array
    {
        try {
            $params = self::normalizeParams($params);
            if ($this->transactionActive) {
                if (self::cannotBufferInTransaction($sql)) {
                    throw new PDOException(
                        'Remote libSQL transactions support write-only statements. '
                        . 'Move reads before beginTransaction().'
                    );
                }

                $this->transactionStatements[] = ['sql' => $sql, 'params' => $params];
                return ['rows' => [], 'affected' => 0, 'columns' => 0];
            }

            $result = $this->client->execute($sql, $params);
            return [
                'rows' => $result['rows'],
                'affected' => $result['affected'],
                'columns' => $result['columns'],
            ];
        } catch (\Throwable $e) {
            $this->rememberError($e);
            throw new PDOException('Remote libSQL query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function cannotBufferInTransaction(string $sql): bool
    {
        $sql = preg_replace(
            '/\A(?:\s+|--[^\r\n]*(?:\r?\n|$)|\/\*.*?\*\/)*/s',
            '',
            $sql,
        ) ?? $sql;

        return preg_match(
            '/\A(?:SELECT|PRAGMA|EXPLAIN|VALUES|WITH|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE)\b/i',
            $sql,
        ) === 1
            || preg_match('/\bRETURNING\b/i', $sql) === 1;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int|string,mixed>
     */
    private static function normalizeParams(array $params): array
    {
        if ($params !== [] && array_filter(array_keys($params), 'is_string') === []) {
            return array_values($params);
        }
        return $params;
    }

    private function rememberError(\Throwable $error): void
    {
        $this->lastErrorCode = 'HY000';
        $this->lastErrorInfo = ['HY000', (int) $error->getCode(), $error->getMessage()];
    }
}
