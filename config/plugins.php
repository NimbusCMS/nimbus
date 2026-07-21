<?php

declare(strict_types=1);

/**
 * Plugin enable/disable switches, by plugin id.
 *
 * Installed plugins are enabled by default — installing one is already a
 * deliberate act. List an id as false to disable it without uninstalling,
 * which is how you recover from a plugin that breaks the admin.
 *
 * Entries whose data uses a disabled plugin's field type stay intact: the
 * admin shows them read-only and refuses saves until the plugin is back.
 *
 *   return [
 *       'nimbuscms.markdown' => false,
 *   ];
 */

return [];
