<?php

// Standalone local fixture provisioning. This script never loads the shop application.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--initialize-fixture') {
    fwrite(STDERR, "Usage: php tests/mysql-fixture-bootstrap.php --initialize-fixture\n");
    exit(2);
}

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=13316', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server = $pdo->query('SELECT @@port AS port, @@bind_address AS address, @@datadir AS data_path')->fetch(PDO::FETCH_ASSOC);
    $expected = strtolower(str_replace('\\', '/', __DIR__ . '/.runtime/mysql-fixture-data/'));
    $actual = strtolower(str_replace('\\', '/', $server['data_path']));
    if ((int) $server['port'] !== 13316 || $server['address'] !== '127.0.0.1' || $actual !== $expected) {
        throw new RuntimeException('Server does not match the dedicated local fixture instance.');
    }
    $database = 'dujiaoka_concurrency_fixture';
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $exists->execute([$database]);
    if ($exists->fetchColumn()) {
        $marker = $pdo->query('SELECT value FROM dujiaoka_concurrency_fixture.fixture_marker')->fetchColumn();
        if ($marker !== 'dujiaoka-isolated-concurrency-v1') {
            throw new RuntimeException('Existing database is not the isolated fixture.');
        }
        echo "Verified existing isolated fixture.\n";
        exit(0);
    }
    $pdo->exec('CREATE DATABASE dujiaoka_concurrency_fixture CHARACTER SET utf8mb4');
    $pdo->exec('CREATE TABLE dujiaoka_concurrency_fixture.fixture_marker (value VARCHAR(64) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO dujiaoka_concurrency_fixture.fixture_marker VALUES ('dujiaoka-isolated-concurrency-v1')");
    $pdo->exec("CREATE USER 'dujiaoka_fixture'@'127.0.0.1' IDENTIFIED BY ''");
    $pdo->exec("GRANT ALL ON dujiaoka_concurrency_fixture.* TO 'dujiaoka_fixture'@'127.0.0.1'");
    echo "Created isolated fixture database and limited loopback user.\n";
} catch (Throwable $exception) {
    // Keep connection errors and connection credentials out of routine reports.
    $code = $exception instanceof PDOException ? ($exception->errorInfo[1] ?? $exception->getCode()) : '';
    fwrite(STDERR, 'Fixture provisioning failed: ' . get_class($exception) . ' ' . $code . "\n");
    exit(1);
}
