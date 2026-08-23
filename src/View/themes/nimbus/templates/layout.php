<?php
/** Admin shell: night-sky sidebar nav + top bar + content. */
use Nimbus\View\View;

$e     = static fn (?string $v): string => View::e($v);
$user  = $auth->user();
$logo  = file_get_contents(dirname(__DIR__) . '/logo.svg');
$theme = \Nimbus\View\AdminTheme::sanitize($user?->theme);  // allow-listed at render, then escaped
?>
<!doctype html>
<html lang="en" data-theme="<?= $e($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($appName) ?> · Admin</title>
    <style nonce="<?= $e($cspNonce) ?>"><?= file_get_contents(dirname(__DIR__) . '/theme.css') ?></style>
</head>
<body class="nb">
<input type="checkbox" id="nb-nav-toggle" class="nb-nav-toggle" aria-label="Menu">
<aside class="nb-side">
    <a class="nb-brand" href="/admin"><?= $logo ?> <span><?= $e($appName) ?></span></a>
    <nav class="nb-nav">
        <?php foreach (($nav ?? []) as $item): ?>
            <a class="<?= !empty($item['active']) ? 'active' : '' ?>" href="<?= $e($item['url']) ?>"<?= !empty($item['active']) ? ' aria-current="page"' : '' ?>>
                <span class="nb-ic"><?= $e($item['icon']) ?></span> <?= $e($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="nb-side-foot">
        <?php if (defined('NB_START')): ?>
            Nimbus ✦ summoned in <span class="nb-summon"><?= (int) round((microtime(true) - NB_START) * 1000) ?> ms</span>
        <?php else: ?>
            Nimbus ✦ CMS
        <?php endif; ?>
    </div>
</aside>
<label class="nb-scrim" for="nb-nav-toggle" aria-hidden="true"></label>

<div class="nb-main">
    <header class="nb-top">
        <div class="nb-top-l">
            <label class="nb-menu" for="nb-nav-toggle"><span aria-hidden="true">☰</span><span class="nb-sr">Menu</span></label>
            <a class="nb-top-brand" href="/admin" aria-label="Dashboard"><?= $logo ?></a>
        </div>
        <div class="nb-user">
            <span class="nb-avatar"><?= $e($user?->initial()) ?></span>
            <span class="nb-uname"><?= $e($user?->name) ?><small><?= $e($user?->role) ?></small></span>
            <form method="post" action="/admin/logout" class="nb-signout-form">
                <input type="hidden" name="_token" value="<?= $e(\Nimbus\Http\Csrf::token()) ?>">
                <button type="submit" class="nb-signout">Sign out</button>
            </form>
        </div>
    </header>
    <main class="nb-content"><?= $__content ?></main>
</div>
<script nonce="<?= $e($cspNonce) ?>">
document.addEventListener('keydown', function (e) {
    var t = document.getElementById('nb-nav-toggle');
    if (e.key === 'Escape' && t && t.checked) { t.checked = false; t.focus(); }
});
/* Confirm destructive submits without an inline handler (CSP: no 'unsafe-inline').
   Forms opt in with data-confirm="message". With JS off the POST proceeds — the
   guard is UX; every destructive route is CSRF- and capability-gated regardless. */
document.addEventListener('submit', function (e) {
    var form = e.target;
    var message = form && form.getAttribute ? form.getAttribute('data-confirm') : null;
    if (message && !window.confirm(message)) { e.preventDefault(); }
});
</script>
</body>
</html>
