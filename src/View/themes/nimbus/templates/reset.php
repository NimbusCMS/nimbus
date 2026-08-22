<?php
/**
 * Set a new password from a reset link. Rendered only for a currently-valid
 * token; an invalid/expired/used token shows a dead-end with a way to start over.
 *
 * @var bool    $valid
 * @var string  $token
 * @var ?string $error
 * @var string  $csrf
 */
use Nimbus\View\View;

$e    = static fn (?string $v): string => View::e($v);
$logo = file_get_contents(dirname(__DIR__) . '/logo.svg');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Set a new password · <?= $e($appName) ?></title>
    <style><?= file_get_contents(dirname(__DIR__) . '/theme.css') ?></style>
</head>
<body class="nb nb-centered nb-night">
<div class="nb-auth">
    <div class="nb-auth-brand"><?= $logo ?> <?= $e($appName) ?></div>

    <?php if (!$valid): ?>
        <p class="nb-muted">Link expired</p>
        <div class="nb-alert nb-alert-error">This reset link is invalid, has expired, or has already been used.</div>
        <p><a class="nb-link" href="/admin/forgot">Request a new link</a></p>
    <?php else: ?>
        <p class="nb-muted">Choose a new password</p>

        <?php if (!empty($error)): ?>
            <div class="nb-alert nb-alert-error"><?= $e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/reset">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="token" value="<?= $e($token) ?>">
            <div class="nb-field">
                <label for="password">New password <small class="nb-muted">(at least 12 characters)</small></label>
                <input id="password" type="password" name="password" autocomplete="new-password" minlength="12" autofocus required>
            </div>
            <button type="submit" class="nb-btn nb-btn-primary nb-btn-block">Set password</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
