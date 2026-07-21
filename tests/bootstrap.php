<?php

declare(strict_types=1);

/**
 * Test bootstrap: autoload + provision an isolated test database.
 *
 * Runs inside the app container (host "db"), against nimbus_test as root so it
 * can create the schema. Integration tests truncate between cases.
 */
require __DIR__ . '/../vendor/autoload.php';

// Sessions in CLI: there is nowhere to send a cookie, and attempting to would
// warn once PHPUnit has printed anything. The HTTP-functional tests still get
// real session storage, real ids, and real session_regenerate_id().
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');

use Nimbus\Database\Connection;
use Nimbus\Database\Migrator;

// Host defaults to the docker service name "db"; CI overrides via TEST_DB_HOST.
define('NB_TEST_DB', [
    'host' => getenv('TEST_DB_HOST') ?: 'db',
    'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
    'name' => getenv('TEST_DB_NAME') ?: 'nimbus_test',
    'user' => getenv('TEST_DB_USER') ?: 'root',
    'pass' => getenv('TEST_DB_PASS') !== false ? getenv('TEST_DB_PASS') : 'root',
]);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', NB_TEST_DB['host'], NB_TEST_DB['port']),
    NB_TEST_DB['user'],
    NB_TEST_DB['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('CREATE DATABASE IF NOT EXISTS nimbus_test CHARACTER SET utf8mb4');

(new Migrator(new Connection(NB_TEST_DB), __DIR__ . '/../src/Database/migrations'))->migrate();
