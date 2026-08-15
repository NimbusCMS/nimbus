<?php

declare(strict_types=1);

/**
 * Navigation menus.
 *
 * Each key is a menu name a theme can render; the starter theme reads `main`
 * for its header. Each item is a label and a URL — point them at anything: the
 * home page, a collection index (/posts), a single entry (/pages/about), or an
 * external site.
 *
 *   return [
 *       'main' => [
 *           ['label' => 'Home', 'url' => '/'],
 *           ['label' => 'Blog', 'url' => '/posts'],
 *       ],
 *   ];
 */

return [
    'main' => [
        ['label' => 'Home', 'url' => '/'],
    ],
];
