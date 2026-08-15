<?php
/**
 * The HTML shell every starter-theme page renders inside.
 *
 * A template is handed a prepared, data-only view-model and two helpers —
 * `$partial(name, data)` to include another template from this theme, and
 * `$e(value)` to escape output — never a service, a repository, or the database.
 *
 * @var string   $appName    the site name
 * @var string   $__content  the rendered page, injected by View::render()
 * @var string   $title      the page title (optional)
 * @var callable $partial     include another theme template
 * @var callable $e           escape a value for output
 * @var array<string,array<string,mixed>> $blocks reusable content blocks by slug
 */
$pageTitle = isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName;

// A reusable "announcement" block, if the site defines one — its body text,
// falling back to its title. Editors manage it in the Blocks collection.
$announcement = ($blocks ?? [])['announcement'] ?? null;
$announcementText = null;
if ($announcement !== null) {
    $body = $announcement['fields']['body'] ?? null;
    $announcementText = is_string($body) && $body !== '' ? $body : ($announcement['title'] ?? null);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($pageTitle) ?></title>
    <link rel="stylesheet" href="/theme/assets/app.css">
</head>
<body>
<?php if ($announcementText !== null && $announcementText !== ''): ?>
    <div class="announcement"><?= $e($announcementText) ?></div>
<?php endif; ?>
<?= $partial('header') ?>
<main>
    <?= $__content ?>
</main>
<?= $partial('footer') ?>
</body>
</html>
