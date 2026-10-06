<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Enums\DatabaseType;
use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Support\Formatters;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Neo4j Enterprise Edition, backed up online with `neo4j-admin database backup`.
 *
 * The backup runs against the server's backup listener (default port 6362),
 * which does not authenticate: it is secured by where it listens and, on
 * Enterprise, by an SSL policy. Bolt (the server's main port) and the
 * credentials are only used by `cypher-shell` to test the connection and to
 * list databases.
 *
 * Restore has to write into the target server's own store, which a remote
 * client cannot do, so the type is backup-only (see
 * {@see DatabaseType::supportsRestore()}).
 */
class Neo4jDatabase implements DatabaseInterface
{
    public const int DEFAULT_BACKUP_PORT = 6362;

    /**
     * Each cypher-shell call starts a JVM, which alone can take a few seconds,
     * so interactive lookups get at least this long however short the form's
     * connect timeout is.
     */
    private const int MIN_CLIENT_TIMEOUT_SECONDS = 15;

    private const int DEFAULT_CLIENT_TIMEOUT_SECONDS = 30;

    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * A full backup into an empty directory leaves exactly one
     * `<database>-<timestamp>.backup` artifact, which is moved onto the output
     * path so the snapshot is a single self-contained file.
     */
    public function dump(string $outputPath): DatabaseOperationResult
    {
        $stagingDir = escapeshellarg($outputPath.'.d');

        $parts = [
            $this->shellBinary('neo4j-admin'),
            'database backup',
            '--from='.escapeshellarg($this->backupAddress()),
            '--type=full',
            '--to-path='.$stagingDir,
        ];

        if (! empty($this->config['dump_flags'])) {
            $parts[] = DatabaseOperationResult::escapeFlags($this->config['dump_flags'], DatabaseType::NEO4J);
        }

        $parts[] = DatabaseOperationResult::escapeDatabaseName((string) ($this->config['database'] ?? ''));

        return new DatabaseOperationResult(command: implode(' && ', [
            'mkdir -p '.$stagingDir,
            implode(' ', $parts),
            'mv '.$stagingDir.'/*.backup '.escapeshellarg($outputPath),
            'rm -rf '.$stagingDir,
        ]));
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        throw new UnsupportedDatabaseTypeException('neo4j');
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        throw new UnsupportedDatabaseTypeException('neo4j');
    }

    /**
     * Standard databases only: `system` is not user data and composite
     * databases hold none of their own to back up. A cluster reports each
     * database once per member, hence DISTINCT.
     */
    public function listDatabases(): array
    {
        $result = $this->runCypher(
            "SHOW DATABASES YIELD name, type WHERE type = 'standard' RETURN DISTINCT name ORDER BY name",
            $this->clientTimeout(self::DEFAULT_CLIENT_TIMEOUT_SECONDS),
        );

        if ($result->failed()) {
            throw new \RuntimeException('Failed to list Neo4j databases: '.trim($result->errorOutput() ?: $result->output()));
        }

        return array_map(fn (array $row) => $row[0], $this->parseRows($result->output()));
    }

    public function testConnection(): array
    {
        $startTime = microtime(true);

        try {
            $result = $this->runCypher(
                'CALL dbms.components() YIELD name, versions, edition RETURN versions[0], edition',
                $this->clientTimeout(self::MIN_CLIENT_TIMEOUT_SECONDS),
            );
        } catch (ProcessTimedOutException) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'success' => false,
                'message' => 'Connection timed out after '.Formatters::humanDuration($durationMs).'. Please check the host and port are correct and accessible.',
                'details' => [],
            ];
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($result->failed()) {
            $errorOutput = trim($result->errorOutput() ?: $result->output());

            return [
                'success' => false,
                'message' => $errorOutput ?: 'Connection failed with exit code '.$result->exitCode(),
                'details' => [],
            ];
        }

        [$version, $edition] = array_pad($this->parseRows($result->output())[0] ?? [], 2, 'unknown');

        if (strtolower($edition) !== 'enterprise') {
            return [
                'success' => false,
                'message' => "Neo4j {$version} reports the {$edition} edition. Online backups need Neo4j Enterprise Edition.",
                'details' => [],
            ];
        }

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => [
                'ping_ms' => $durationMs,
                'output' => json_encode([
                    'dbms' => "Neo4j {$version}",
                    'edition' => $edition,
                    'backup_address' => $this->backupAddress(),
                ], JSON_PRETTY_PRINT),
            ],
        ];
    }

    /**
     * Run a query on the `system` database through cypher-shell.
     *
     * Credentials go through the environment rather than the argument list,
     * so they never show up in the process table.
     */
    private function runCypher(string $query, int $timeout): ProcessResult
    {
        $host = (string) ($this->config['host'] ?? '');
        $port = (int) ($this->config['port'] ?? DatabaseType::NEO4J->defaultPort());

        return Process::timeout($timeout)
            ->env([
                'NEO4J_USERNAME' => (string) ($this->config['user'] ?? ''),
                'NEO4J_PASSWORD' => (string) ($this->config['pass'] ?? ''),
            ])
            ->run([
                $this->binary('cypher-shell'),
                '--address', 'bolt://'.self::formatAddress($host, $port),
                '--database', 'system',
                '--format', 'plain',
                '--non-interactive',
                $query,
            ]);
    }

    /**
     * Rows of cypher-shell's plain output, header dropped and quotes removed.
     *
     * @return list<list<string>>
     */
    private function parseRows(string $output): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            fn (string $line) => $line !== '',
        ));

        return array_map(
            fn (string $line) => array_map(
                fn (?string $value) => trim((string) $value),
                str_getcsv($line, ',', '"', ''),
            ),
            array_slice($lines, 1),
        );
    }

    private function clientTimeout(int $default): int
    {
        $configured = $this->config['connect_timeout'] ?? null;

        if (! is_numeric($configured)) {
            return $default;
        }

        return max((int) $configured, self::MIN_CLIENT_TIMEOUT_SECONDS);
    }

    private function backupAddress(): string
    {
        return self::formatAddress(
            (string) ($this->config['host'] ?? ''),
            (int) ($this->config['backup_port'] ?? self::DEFAULT_BACKUP_PORT),
        );
    }

    /**
     * `host:port`, with an IPv6 literal bracketed so its colons are not read
     * as the port separator.
     */
    private static function formatAddress(string $host, int $port): string
    {
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        return $host.':'.$port;
    }

    /**
     * A client from the configured Neo4j `bin` directory, or the one on PATH.
     */
    private function binary(string $name): string
    {
        $directory = config('backup.neo4j_bin_dir');

        if (! is_string($directory) || trim($directory) === '') {
            return $name;
        }

        return rtrim($directory, '/').'/'.$name;
    }

    /**
     * {@see binary()}, quoted for a shell command line when it is a path.
     */
    private function shellBinary(string $name): string
    {
        $binary = $this->binary($name);

        return $binary === $name ? $binary : escapeshellarg($binary);
    }
}
