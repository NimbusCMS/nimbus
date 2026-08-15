<?php
/**
 * The HTML shell every starter-theme page renders inside.
 *
 * A template is handed a prepared, data-only view-model and an escaping helper —
 * never a service, a repository, or the database. Presentation only.
 *
 * @var string $appName    the site name
 * @var string $__content  the rendered page, injected by View::render()
 * @var string $title      the page title (optional)
 */
use Nimbus\View\View;

$e     = static fn (?string $v): string => View::e($v);
$title = isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?></title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.6; max-width: 42rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
        header a { text-decoration: none; font-weight: 600; }
        h1 { letter-spacing: -.02em; }
        time { color: #6b7280; font-size: .9rem; }
        .entry-list { list-style: none; padding: 0; }
        .entry-list li { margin: 0 0 1rem; }
        img { max-width: 100%; height: auto; }
        nav.pager { display: flex; gap: 1rem; margin-top: 2rem; }
    </style>
</head>
<body>
<header>
    <a href="/"><?= $e($appName) ?></a>
</header>
<main>
    <?= $__content ?>
</main>
</body>
</html>
