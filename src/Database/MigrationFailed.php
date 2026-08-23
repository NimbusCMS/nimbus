<?php

declare(strict_types=1);

namespace Nimbus\Database;

/**
 * A **core** migration failed — a first-party, platform-critical error. It halts
 * the run (later core migrations, and every plugin migration, may depend on the
 * schema this one was building), so it is thrown rather than collected: a plugin
 * failure is isolated into a {@see MigrationReport}, but a broken core schema must
 * fail closed. `bin/nimbus`'s top-level handler turns it into a stderr line + a
 * non-zero exit, so `install` never seeds against a half-migrated schema.
 */
final class MigrationFailed extends \RuntimeException
{
}
