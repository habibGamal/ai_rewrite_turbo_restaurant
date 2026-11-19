<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs one SELECT written by an AI client, only against the ai_* views.
 *
 * Layers, each enough on its own for the common cases:
 * 1. Validation: one statement, SELECT/WITH only, no comments, no write or
 *    session keywords, and no table, schema or system catalog other than
 *    the ai_* views.
 * 2. The connection: a separate database user (config database.ai_readonly)
 *    that should only hold SELECT on the views, when configured.
 * 3. The session: a read-only transaction that is always rolled back
 *    (MySQL/MariaDB/PostgreSQL) or PRAGMA query_only (SQLite), with a
 *    statement time limit where the database supports one.
 * 4. A row cap: the query is wrapped and limited to MAX_ROWS + 1 rows.
 */
final class ReadOnlyQuery
{
    public const MAX_ROWS = 500;

    public const TIMEOUT_SECONDS = 5;

    public const MAX_SQL_LENGTH = 4000;

    public const MAX_CELL_LENGTH = 300;

    public const CONNECTION = 'ai_readonly';

    /** Words that have no place in a read-only query. */
    private const FORBIDDEN = [
        'insert', 'update', 'delete', 'replace', 'merge', 'upsert', 'drop', 'alter', 'create', 'truncate', 'rename',
        'grant', 'revoke', 'attach', 'detach', 'pragma', 'vacuum', 'reindex', 'call', 'do', 'handler', 'load',
        'lock', 'unlock', 'set', 'into', 'outfile', 'dumpfile', 'load_file', 'sleep', 'pg_sleep', 'benchmark',
        'get_lock', 'release_lock', 'show', 'describe', 'explain', 'execute', 'exec', 'prepare', 'deallocate',
        'kill', 'shutdown', 'flush', 'purge', 'reset', 'commit', 'rollback', 'savepoint', 'begin', 'transaction',
        'recursive', 'randomblob', 'zeroblob', 'user', 'current_user', 'session_user', 'system_user', 'database',
        'schema', 'version', 'connection_id', 'sql_variant_property', 'load_extension', 'readfile', 'writefile',
        'edit', 'fts3_tokenizer', 'information_schema', 'performance_schema', 'mysql', 'sys', 'pg_catalog',
        'sqlite_master', 'sqlite_schema', 'sqlite_temp_master', 'sqlite_sequence', 'sqlite_stat1',
    ];

    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, row_count: int, truncated: bool}
     */
    public function run(string $sql): array
    {
        AiViews::ensure();
        $sql = $this->validate($sql);
        $connection = $this->connection();
        $wrapped = 'SELECT * FROM ('.$sql.') ai_query LIMIT '.(self::MAX_ROWS + 1);

        try {
            $rows = $this->readOnly($connection, fn () => $connection->select($wrapped));
        } catch (QueryException $e) {
            $reason = $e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? 'invalid query';

            throw new InvalidArgumentException('The database rejected the query: '.mb_substr((string) $reason, 0, 300));
        }

        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_map(fn ($row) => array_map($this->cell(...), (array) $row), array_slice($rows, 0, self::MAX_ROWS));

        return [
            'columns' => $rows === [] ? [] : array_keys($rows[0]),
            'rows' => $rows,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }

    /**
     * The query, trimmed, when it is allowed. Throws a message the model can act on.
     */
    public function validate(string $sql): string
    {
        $sql = trim($sql);
        $sql = rtrim($sql, "; \t\n\r");

        if ($sql === '') {
            throw new InvalidArgumentException('Write one SELECT query.');
        }
        if (mb_strlen($sql) > self::MAX_SQL_LENGTH) {
            throw new InvalidArgumentException('The query is too long (max '.self::MAX_SQL_LENGTH.' characters).');
        }
        // Literals cannot hide keywords or table names from the checks below.
        $code = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql) ?? '';
        if (str_contains(str_replace("''", '', $code), "'") || str_contains($code, ';')) {
            throw new InvalidArgumentException('Write exactly one SELECT statement, with balanced quotes.');
        }
        if (preg_match('/--|\/\*|\*\/|#/', $code) === 1) {
            throw new InvalidArgumentException('Remove comments from the query.');
        }
        if (preg_match('/^\(*\s*(select|with)\b/i', $code) !== 1) {
            throw new InvalidArgumentException('Only SELECT queries are allowed (a WITH ... SELECT is fine).');
        }

        $code = strtolower(str_replace(['`', '"', '[', ']'], ' ', $code));
        $words = array_unique(preg_match_all('/[a-z_][a-z0-9_$]*/', $code, $m) ? $m[0] : []);

        $bad = array_merge(
            array_values(array_intersect($words, self::FORBIDDEN)),
            array_values(array_filter($words, fn (string $word) => str_starts_with($word, 'pragma_') || str_starts_with($word, 'sqlite_'))),
        );
        if ($bad !== []) {
            throw new InvalidArgumentException('Not allowed in a read-only query: '.implode(', ', array_unique($bad)).'.');
        }

        $tables = array_values(array_intersect(array_unique($this->referencedNames($code)), $this->protectedTables()));
        if ($tables !== []) {
            throw new InvalidArgumentException('Read only the ai_* views (call describe_data_model), not: '.implode(', ', $tables).'. If that is a column alias, rename it (for example orders_count).');
        }

        return $sql;
    }

    /**
     * Names that may be used as tables: after FROM or JOIN, after a comma,
     * or as a qualifier (name.column). An alias such as "AS orders" is not
     * a table reference and stays allowed.
     *
     * @return list<string>
     */
    private function referencedNames(string $code): array
    {
        $names = [];
        $identifier = '[a-z_][a-z0-9_$]*';

        preg_match_all("/\\b(?:from|join)\\s+({$identifier})/", $code, $m);
        array_push($names, ...$m[1]);

        preg_match_all("/\\b({$identifier})\\s*\\.\\s*[a-z_*]/", $code, $m);
        array_push($names, ...$m[1]);

        // Comma joins (FROM a x, b y), including after a subquery. A column
        // alias right after a comma is caught too; the error asks to rename it.
        preg_match_all("/,\\s*({$identifier})/", $code, $m);
        array_push($names, ...$m[1]);

        return $names;
    }

    /** The separate read-only connection when configured, else the default one. */
    private function connection(): Connection
    {
        $credentials = (array) config('database.ai_readonly', []);
        if (empty($credentials['username'])) {
            return DB::connection();
        }

        $default = (string) config('database.default');
        config(['database.connections.'.self::CONNECTION => array_merge(
            (array) config("database.connections.{$default}"),
            array_filter(['username' => $credentials['username'], 'password' => $credentials['password'] ?? null], fn ($v) => $v !== null),
        )]);

        return DB::connection(self::CONNECTION);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $query
     * @return T
     */
    private function readOnly(Connection $connection, callable $query): mixed
    {
        $driver = $connection->getDriverName();

        if ($driver !== 'sqlite' && $connection->transactionLevel() > 0) {
            // Starting a read-only transaction would commit the open one.
            throw new RuntimeException('The read-only query cannot run inside another transaction.');
        }

        if ($driver === 'sqlite') {
            $connection->statement('PRAGMA query_only = ON');
            try {
                return $query();
            } finally {
                $connection->statement('PRAGMA query_only = OFF');
            }
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->limitTime($connection);
            $connection->unprepared('START TRANSACTION READ ONLY');
        } elseif ($driver === 'pgsql') {
            $connection->unprepared('BEGIN READ ONLY');
            $connection->unprepared('SET LOCAL statement_timeout = '.(self::TIMEOUT_SECONDS * 1000));
        } else {
            $connection->beginTransaction();
        }

        try {
            return $query();
        } finally {
            if ($driver === 'pgsql' || in_array($driver, ['mysql', 'mariadb'], true)) {
                $connection->unprepared('ROLLBACK');
            } else {
                $connection->rollBack();
            }
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $this->limitTime($connection, 0);
            }
        }
    }

    /** Statement time limit for this session; 0 removes it. */
    private function limitTime(Connection $connection, int $seconds = self::TIMEOUT_SECONDS): void
    {
        try {
            $connection->unprepared('SET SESSION MAX_EXECUTION_TIME = '.($seconds * 1000));
        } catch (Throwable) {
            // MariaDB names it differently, in seconds.
            $connection->unprepared('SET SESSION max_statement_time = '.$seconds);
        }
    }

    /** @return list<string> every real table or view that is not an ai_* view */
    private function protectedTables(): array
    {
        static $tables = null;

        return $tables ??= array_values(array_filter(
            array_map('strtolower', array_merge(
                array_column(Schema::getTables(), 'name'),
                array_column(Schema::getViews(), 'name'),
            )),
            fn (string $name) => ! str_starts_with($name, AiViews::PREFIX),
        ));
    }

    private function cell(mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value) > self::MAX_CELL_LENGTH) {
            return mb_substr($value, 0, self::MAX_CELL_LENGTH).'…';
        }

        return is_resource($value) ? null : $value;
    }
}
