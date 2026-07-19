<?php
/** Admin shell: night-sky sidebar nav + top bar + content. */
use Nimbus\View\View;

$e    = static fn (?string $v): string => View::e($v);
$user = $auth->user();
$logo = file_get_contents(dirname(__DIR__) . '/logo.svg');
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($appName) ?> · Admin</title>
    <style><?= file_get_contents(dirname(__DIR__) . '/theme.css') ?></style>
</head>
<body class="nb">
<aside class="nb-side">
    <a class="nb-brand" href="/admin"><?= $logo ?> <span><?= $e($appName) ?></span></a>
    <nav class="nb-nav">
        <?php foreach (($nav ?? []) as $item): ?>
            <a class="<?= !empty($item['active']) ? 'active' : '' ?>" href="<?= $e($item['url']) ?>">
                <span class="nb-ic"><?= $e($item['icon']) ?></span> <?= $e($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="nb-side-foot">Nimbus ✦ CMS</div>
</aside>

<div class="nb-main">
    <header class="nb-top">
        <div class="nb-top-l"></div>
        <div class="nb-user">
            <span class="nb-avatar"><?= $e($user?->initial()) ?></span>
            <span class="nb-uname"><?= $e($user?->name) ?><small><?= $e($user?->role) ?></small></span>
            <a class="nb-signout" href="/admin/logout">Sign out</a>
        </div>
    </header>
    <main class="nb-content"><?= $__content ?></main>
</div>
</body>
</html>
