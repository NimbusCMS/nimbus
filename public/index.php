<?php

declare(strict_types=1);

// Request start, for the admin's "summoned in N ms" whisper (a speed brag + a
// debugging aid). Captured first thing so the number reflects real work.
define('NB_START', microtime(true));

// Under the PHP built-in server, let existing static files (CSS, uploads, …)
// be served directly; route everything else through the app.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';

(new Nimbus\Application())->run();
