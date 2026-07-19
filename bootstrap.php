<?php

declare(strict_types=1);

/**
 * Bootstraps autoloading. Uses Composer's autoloader when installed; otherwise
 * registers a PSR-4 fallback so the app runs with zero `composer install` in
 * development (runtime has no third-party dependencies).
 */
$composer = __DIR__ . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Nimbus\\';
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
