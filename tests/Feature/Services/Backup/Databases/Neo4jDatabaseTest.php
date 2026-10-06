<?php

use App\Exceptions\Backup\DatabaseDumpException;
use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Services\Backup\Databases\Neo4jDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->db = new Neo4jDatabase;
    $this->db->setConfig([
        'host' => 'neo4j.example.com',
        'port' => 7687,
        'backup_port' => 6362,
        'user' => 'neo4j',
        'pass' => 'secret',
        'database' => 'movies',
    ]);
});

test('dump runs a full neo4j-admin backup and moves the artifact onto the output path', function () {
    $command = $this->db->dump('/tmp/job/dump.backup')->command;

    expect($command)->toBe(
        "mkdir -p '/tmp/job/dump.backup.d'"
        ." && neo4j-admin database backup --from='neo4j.example.com:6362' --type=full --to-path='/tmp/job/dump.backup.d' 'movies'"
        ." && mv '/tmp/job/dump.backup.d'/*.backup '/tmp/job/dump.backup'"
        ." && rm -rf '/tmp/job/dump.backup.d'"
    )->not->toContain('secret');
});

test('dump uses the configured Neo4j bin directory, brackets IPv6 hosts and appends dump flags', function () {
    config(['backup.neo4j_bin_dir' => '/opt/neo4j/bin/']);

    $db = new Neo4jDatabase;
    $db->setConfig(['host' => '2001:db8::1', 'backup_port' => 7000, 'database' => 'movies', 'dump_flags' => '--compress=false']);

    expect($db->dump('/tmp/out.backup')->command)
        ->toContain("'/opt/neo4j/bin/neo4j-admin' database backup --from='[2001:db8::1]:7000'")
        ->toContain("'--compress=false' 'movies'");
});

test('dump refuses flags that redirect the backup', function () {
    $db = new Neo4jDatabase;
    $db->setConfig(['host' => 'h', 'database' => 'movies', 'dump_flags' => '--to-path=/etc']);

    expect(fn () => $db->dump('/tmp/out.backup'))->toThrow(DatabaseDumpException::class);
});

test('restore is not supported', function () {
    expect(fn () => $this->db->restore('/tmp/x.backup'))->toThrow(UnsupportedDatabaseTypeException::class)
        ->and(fn () => $this->db->prepareForRestore('movies', Mockery::mock(\App\Contracts\BackupLogger::class)))
        ->toThrow(UnsupportedDatabaseTypeException::class);
});

test('listDatabases parses cypher-shell output and passes credentials through the environment', function () {
    Process::fake(['*' => Process::result(output: "name\n\"movies\"\n\"neo4j\"\n")]);

    expect($this->db->listDatabases())->toBe(['movies', 'neo4j']);

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'cypher-shell'
        && in_array('bolt://neo4j.example.com:7687', $process->command, true)
        && ! in_array('secret', $process->command, true)
        && $process->environment['NEO4J_PASSWORD'] === 'secret');
});

test('listDatabases throws when cypher-shell fails', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'The client is unauthorized')]);

    expect(fn () => $this->db->listDatabases())->toThrow(RuntimeException::class, 'The client is unauthorized');
});

test('testConnection succeeds against Enterprise Edition', function () {
    Process::fake(['*' => Process::result(output: "versions[0], edition\n\"5.26.12\", \"enterprise\"\n")]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['details']['output'])->toContain('Neo4j 5.26.12')
        ->toContain('neo4j.example.com:6362');
});

test('testConnection refuses Community Edition', function () {
    Process::fake(['*' => Process::result(output: "versions[0], edition\n\"5.26.12\", \"community\"\n")]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Neo4j Enterprise Edition');
});

test('testConnection reports a cypher-shell failure', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'Unable to connect to neo4j.example.com:7687')]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Unable to connect');
});
