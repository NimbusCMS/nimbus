<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Database\Connection;
use Nimbus\Support\Events;
use PHPUnit\Framework\TestCase;

/** Base for tests that hit the real (test) database; truncates between cases. */
abstract class IntegrationTestCase extends TestCase
{
    protected Connection $db;

    protected function setUp(): void
    {
        // Listeners are static, so one registered in a previous test class would
        // otherwise fire for every case that follows it.
        Events::reset();

        $this->db = new Connection(NB_TEST_DB);
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['nb_relations', 'nb_revisions', 'nb_entries', 'nb_fields', 'nb_collections', 'nb_media', 'nb_activity', 'nb_api_tokens', 'nb_users', 'nb_settings', 'nb_login_throttle'] as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
