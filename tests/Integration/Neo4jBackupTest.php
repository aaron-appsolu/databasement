<?php

/**
 * Opt-in integration test for Neo4j Enterprise backups.
 *
 * Needs a Neo4j Enterprise server whose backup listener is reachable, and the
 * Neo4j clients (NEO4J_BIN_DIR, JAVA_HOME) in the container. For example:
 *
 *   docker run -d --name neo4j-test --network databasement_default \
 *     -e NEO4J_ACCEPT_LICENSE_AGREEMENT=eval -e NEO4J_AUTH=neo4j/testpassword1 \
 *     -e NEO4J_server_backup_listen__address=0.0.0.0:6362 neo4j:5-enterprise
 *
 * then run with TEST_NEO4J_HOST=neo4j-test.
 */

use App\Services\Backup\Databases\Neo4jDatabase;
use App\Services\Backup\ShellProcessor;

beforeEach(function () {
    if (empty(config('testing.databases.neo4j.host'))) {
        $this->markTestSkipped('Set TEST_NEO4J_HOST to run the Neo4j Enterprise integration test.');
    }

    config(['backup.neo4j_bin_dir' => env('NEO4J_BIN_DIR')]);

    $this->db = new Neo4jDatabase;
    $this->db->setConfig([
        'host' => config('testing.databases.neo4j.host'),
        'port' => (int) config('testing.databases.neo4j.port'),
        'backup_port' => (int) config('testing.databases.neo4j.backup_port'),
        'user' => config('testing.databases.neo4j.username'),
        'pass' => config('testing.databases.neo4j.password'),
        'database' => config('testing.databases.neo4j.database'),
    ]);
});

test('connects, lists databases and writes a single backup artifact', function () {
    expect($this->db->testConnection()['success'])->toBeTrue()
        ->and($this->db->listDatabases())->toContain(config('testing.databases.neo4j.database'));

    $workingDirectory = sys_get_temp_dir().'/neo4j-backup-'.uniqid();
    mkdir($workingDirectory);
    $outputPath = $workingDirectory.'/dump.backup';

    (new ShellProcessor)->process($this->db->dump($outputPath)->command);

    expect(filesize($outputPath))->toBeGreaterThan(0)
        ->and(is_dir($outputPath.'.d'))->toBeFalse();

    unlink($outputPath);
    rmdir($workingDirectory);
});
