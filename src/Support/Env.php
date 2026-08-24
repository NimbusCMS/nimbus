<?php

declare(strict_types=1);

namespace Nimbus\Support;

/** Tiny .env loader. Real environment variables (e.g. from Docker) always win. */
final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            // Accept a shell-style `export KEY=…` line.
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                // Quoted: take the literal contents — a `#` inside is kept.
                $value = substr($value, 1, -1);
            } else {
                // Unquoted: strip an inline comment (a space-hash onward), like
                // mainstream dotenv parsers — otherwise `KEY=secret # note` stores
                // the comment as part of the (often secret) value (SUP-5).
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
