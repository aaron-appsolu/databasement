<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | The encryption key used when BACKUP_COMPRESSION=encrypted.
    | Defaults to APP_KEY. Used with 7-Zip AES-256 encryption.
    |
    | WARNING: If you change this key, you will not be able to restore
    | backups that were encrypted with the previous key.
    |
    */

    'encryption_key' => env('BACKUP_ENCRYPTION_KEY', env('APP_KEY')),

    /*
    |--------------------------------------------------------------------------
    | Neo4j Client Directory
    |--------------------------------------------------------------------------
    |
    | Neo4j Enterprise backups run `neo4j-admin`, and connection tests run
    | `cypher-shell`. Neither ships in the image: both need a JRE and the
    | former is part of the Enterprise distribution. Mount a Neo4j
    | distribution matching your servers' version and point this at its
    | `bin` directory, and set JAVA_HOME to a Java 21 runtime built for the
    | image's libc (Alpine/musl). Left empty, both are looked up on PATH.
    |
    */

    'neo4j_bin_dir' => env('NEO4J_BIN_DIR'),
];
