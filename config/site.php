<?php

declare(strict_types=1);

/**
 * Site-wide settings.
 *
 * 'home' names the collection rendered at the site root (`/`):
 *   - a 'single'-kind collection (e.g. a Homepage) renders its one live entry;
 *   - a regular collection renders its live entry index (e.g. a blog at `/`).
 *
 * Leave it null and `/` shows a placeholder until you choose a home. This
 * mirrors config/theme.php and config/plugins.php: configuration in one place,
 * in one form.
 *
 *   return ['home' => 'homepage'];
 */

return [
    'home' => null,

    // A one-line description used as the default meta and Open Graph description
    // for pages that don't supply their own. Leave null to omit it.
    'description' => null,
];
