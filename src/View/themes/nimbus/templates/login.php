<?php
/** Sign-in page over a starry night sky. */
use Nimbus\View\View;

$e    = static fn (?string $v): string => View::e($v);
$logo = file_get_contents(dirname(__DIR__) . '/logo.svg');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= $e($appName) ?></title>
    <style><?= file_get_contents(dirname(__DIR__) . '/theme.css') ?></style>
</head>
<body class="nb nb-centered nb-night">
<div class="nb-auth">
    <div class="nb-auth-brand"><?= $logo ?> <?= $e($appName) ?></div>
    <p class="nb-muted">Sign in to your dashboard</p>

    <?php if (!empty($error)): ?>
        <div class="nb-alert nb-alert-error"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/login">
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
        <div class="nb-field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" autocomplete="username" autofocus required>
        </div>
        <div class="nb-field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="nb-btn nb-btn-primary nb-btn-block">Sign in</button>
    </form>
</div>
</body>
</html>
