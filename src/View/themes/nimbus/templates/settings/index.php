<?php
/**
 * @var array<string,array{name:string,mood:string,gradient:string}> $themes
 * @var string  $current the active theme slug
 * @var ?string $flash
 * @var string  $csrf
 */
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);
?>
<div class="nb-page-head">
    <h1>Settings</h1>
</div>

<?php if ($flash === 'theme'): ?>
    <div class="nb-alert nb-alert-ok">Theme changed. ✦</div>
<?php endif; ?>

<form class="nb-form-card" method="post" action="/admin/settings/theme">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <div class="nb-field">
        <label>Theme <small class="nb-muted">— your admin skin (a personal preference)</small></label>
        <div class="nb-theme-cards">
            <?php foreach ($themes as $slug => $t): ?>
                <label class="nb-theme-card">
                    <input type="radio" name="theme" value="<?= $e($slug) ?>"<?= $slug === $current ? ' checked' : '' ?>>
                    <span class="nb-swatch" style="background: <?= $e($t['gradient']) ?>"></span>
                    <span class="nb-theme-meta">
                        <strong><?= $e($t['name']) ?></strong>
                        <small><?= $e($t['mood']) ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <button type="submit" class="nb-btn nb-btn-primary">Save theme</button>
</form>

<script>
/* Instant preview before saving (progressive enhancement). The radio values are
   server-rendered from the theme allow-list, so they're always known slugs. */
document.querySelectorAll('input[name=theme]').forEach(function (r) {
    r.addEventListener('change', function () { document.documentElement.dataset.theme = r.value; });
});
</script>
