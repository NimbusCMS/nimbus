<?php
/**
 * Request a password-reset link. Always shows the same confirmation once
 * submitted, whether or not the email is registered (no account enumeration).
 *
 * @var ?string $error
 * @var bool    $sent
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
    <title>Reset password · <?= $e($appName) ?></title>
    <style nonce="<?= $e($cspNonce) ?>"><?= file_get_contents(dirname(__DIR__) . '/theme.css') ?></style>
</head>
<body class="nb nb-centered nb-night">
<div class="nb-auth">
    <div class="nb-auth-brand"><?= $logo ?> <?= $e($appName) ?></div>

    <?php if ($sent): ?>
        <p class="nb-muted">Check your inbox</p>
        <div class="nb-alert nb-alert-ok">If an account matches that email, a reset link is on its way. It's valid for one hour.</div>
        <p><a class="nb-link" href="/admin/login">← Back to sign in</a></p>
    <?php else: ?>
        <p class="nb-muted">Enter your email and we'll send a reset link</p>

        <?php if (!empty($error)): ?>
            <div class="nb-alert nb-alert-error"><?= $e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/forgot">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
            <div class="nb-field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" autocomplete="username" autofocus required>
            </div>
            <button type="submit" class="nb-btn nb-btn-primary nb-btn-block">Send reset link</button>
        </form>
        <p class="nb-auth-alt"><a class="nb-link" href="/admin/login">← Back to sign in</a></p>
    <?php endif; ?>
</div>
</body>
</html>
