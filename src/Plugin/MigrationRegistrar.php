<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Database\MigrationRegistry;

/**
 * The migrations capability, as a plugin sees it.
 *
 * A plugin declares migrations that create and evolve its *own* tables — never
 * core `nb_*` tables ([ADR 0005](../../../docs/adr/0005-plugin-owned-storage.md)).
 * The name is prefixed with the plugin id here (bound by the loader), so it is
 * globally unique in `nb_migrations` and a failed load rolls its migrations back
 * with the rest of its registrations. Mirrors the other registrars.
 *
 * **Table names are yours to keep unique.** ADR 0005 asks each plugin to keep its
 * tables in its own namespace: **prefix every table you create with your plugin
 * slug** (e.g. `analytics_hits`, not `hits`), so two plugins can't collide on a
 * generic name and wedge `nimbus migrate`. Nimbus namespaces the migration *name*
 * for you, but not the SQL — the table names are whatever your statements say.
 * (This is a convention, not a sandbox: nothing stops a determined plugin from
 * naming a table anything — see the contract-not-sandbox note in ADR 0005 — but
 * following it keeps honest plugins from colliding by accident.)
 */
final class MigrationRegistrar
{
    /**
     * Statement patterns that touch a core `nb_*` table — an **honest-accident
     * guard, not a sandbox** (FU-11). ADR 0001 makes an installed plugin trusted
     * in-process code with full app privilege; a plugin can still hit `nb_*` from
     * its runtime, dynamic/concatenated SQL, or a raw PDO — this only catches a
     * *typo'd or copy-pasted* migration statement before `nimbus migrate` hands
     * it to MySQL's irreversible auto-commit DDL. Each is verb-anchored on the
     * **target** identifier (so a legit `REFERENCES nb_users(id)` FK in the
     * plugin's own table is not flagged) and runs over a comment/literal-stripped
     * statement. See `docs/adr/0005-plugin-owned-storage.md`.
     *
     * @var list<string>
     */
    private const CORE_TABLE_DDL = [
        // The single target of a CREATE/ALTER/DROP/TRUNCATE TABLE.
        '/\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+(?:TEMPORARY\s+)?(?:TABLE\s+)?(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(?<![\w.])`?(?P<tbl>nb_\w+)/i',
        // Any table in a DROP TABLE list (`DROP TABLE a, nb_users`).
        '/\bDROP\s+TABLE\b[^;]*?(?<![\w.])`?(?P<tbl>nb_\w+)/i',
        // Either operand of a RENAME TABLE (`… TO nb_x` squats the namespace too).
        '/\bRENAME\s+TABLE\b[^;]*?(?<![\w.])`?(?P<tbl>nb_\w+)/i',
        '/\bRENAME\s+TO\s+(?<![\w.])`?(?P<tbl>nb_\w+)/i',      // the ALTER TABLE … RENAME TO form
        '/\b(?:CREATE|DROP)\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX\b[^;]*?\bON\s+(?<![\w.])`?(?P<tbl>nb_\w+)/i',
        // Target-keyed DML (a read via …SELECT FROM nb_* is a source, not matched).
        '/\b(?:INSERT|REPLACE)\s+INTO\s+(?<![\w.])`?(?P<tbl>nb_\w+)/i',
        '/\bUPDATE\s+(?<![\w.])`?(?P<tbl>nb_\w+)/i',
        '/\bDELETE\s+FROM\s+(?<![\w.])`?(?P<tbl>nb_\w+)/i',
    ];

    public function __construct(
        private MigrationRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * Declare a migration: a name local to the plugin (e.g. `001_create_hits`)
     * and the SQL statements that apply it, run once and recorded.
     *
     * **Make each statement individually idempotent** (`CREATE TABLE IF NOT
     * EXISTS`, `DROP … IF EXISTS`, a guarded `ADD COLUMN`). MySQL auto-commits DDL
     * and cannot roll it back, so if statement 2 fails, statement 1 stays applied
     * and the migration is **not** recorded — the runner isolates your plugin (it
     * won't wedge others or core) and will **retry** this whole migration on the
     * next `nimbus migrate`. A non-idempotent statement 1 then fails with "already
     * exists" and your plugin can never migrate.
     *
     * Consequently, **your runtime must not assume a table/column/constraint
     * exists until the migration is recorded** — a half-applied migration can
     * leave a table present but missing the UNIQUE/INDEX a later statement adds.
     *
     * @param list<string> $statements
     */
    public function register(string $name, array $statements): void
    {
        // The stored name is `pluginId:name` in nb_migrations.migration (VARCHAR
        // 191); the id is already bounded to ≤64 by the loader, so bound the name
        // too rather than let an over-long one 1406 → 500 at `nimbus migrate`.
        if ($name === '' || strlen($name) > 120) {
            throw new \InvalidArgumentException("A migration name must be 1–120 characters: \"{$name}\".");
        }
        foreach ($statements as $i => $statement) {
            $table = self::coreTableTouched((string) $statement);
            if ($table !== null) {
                // Rejected here → the loader records REGISTER_FAILED and skips the
                // plugin (its migrations roll back; core + other plugins are
                // unaffected). Teach, don't just refuse.
                throw new \InvalidArgumentException(
                    "Migration \"{$name}\" statement #" . ((int) $i + 1) . " touches the core table \"{$table}\". "
                    . '`nb_*` tables are reserved for Nimbus core; a plugin creates and alters only its own tables, '
                    . 'prefixed with its slug (e.g. "analytics_hits") — see ADR 0005. (This is an accident guard, '
                    . 'not a sandbox: an installed plugin is trusted in-process code.)',
                );
            }
        }
        $this->registry->add($this->pluginId . ':' . $name, $statements, $this->pluginId);
    }

    /**
     * The core `nb_*` table a statement mutates (create/alter/drop/rename/index/
     * DML), or null. Comments and string literals are stripped first so
     * DDL-looking text inside a value or a block comment never false-flags —
     * while the executable body of a MySQL versioned comment is kept. Fails
     * **closed**: a PCRE error (a pathological statement) is treated as a hit —
     * safe here, since the input is trusted-author migration SQL and the
     * rejection lands in the loader's containment path.
     */
    private static function coreTableTouched(string $sql): ?string
    {
        $normalized = self::stripNoise($sql);
        if ($normalized === null) {
            return 'nb_* (unparseable statement — rejected to be safe)';
        }
        foreach (self::CORE_TABLE_DDL as $pattern) {
            $matched = preg_match($pattern, $normalized, $m);
            if ($matched === false) {
                return 'nb_* (unmatchable statement — rejected to be safe)';
            }
            if ($matched === 1) {
                return $m['tbl'];
            }
        }
        return null;
    }

    /** Strip comments + string literals (keeping the body of a MySQL versioned
     *  comment, which executes) and collapse whitespace. Null on a PCRE error. */
    private static function stripNoise(string $sql): ?string
    {
        $steps = [
            ['#/\*!(?:\d+)?(.*?)\*/#s', ' $1 '],                 // versioned comment: keep the body
            ['#/\*.*?\*/#s', ' '],                              // ordinary block comment
            ['/(?:--|\#)[^\r\n]*/', ' '],                       // -- and # line comments
            ['/\'(?:[^\'\\\\]|\\\\.)*\'/s', ' '],               // single-quoted literals
            ['/"(?:[^"\\\\]|\\\\.)*"/s', ' '],                  // double-quoted literals
        ];
        foreach ($steps as [$pat, $repl]) {
            $sql = preg_replace($pat, $repl, $sql);
            if ($sql === null) {
                return null;
            }
        }
        $collapsed = preg_replace('/\s+/', ' ', $sql);
        return $collapsed ?? null;
    }
}
