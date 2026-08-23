<?php
/**
 * @var array<string,array{name:string,mood:string,gradient:string}> $themes
 * @var string  $current the active theme slug
 * @var ?string $flash
 * @var string  $csrf
 * @var bool    $canEditSite
 * @var list<array{key:string,type:string,label:string,help:string,value:string}> $siteFields
 * @var array<string,string> $collections handle => name
 */
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);
?>
<div class="nb-page-head">
    <h1>Settings</h1>
</div>

<?php if ($flash === 'theme'): ?>
    <div class="nb-alert nb-alert-ok">Theme changed. ✦</div>
<?php elseif ($flash === 'site'): ?>
    <div class="nb-alert nb-alert-ok">Site settings saved. ✦</div>
<?php elseif ($flash === 'site-error'): ?>
    <div class="nb-alert nb-alert-error">Some settings couldn’t be saved — please check the values and try again.</div>
<?php endif; ?>

<?php if ($canEditSite): ?>
<form class="nb-form-card" method="post" action="/admin/settings/site">
    <h2>Site</h2>
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <?php foreach ($siteFields as $field): ?>
        <div class="nb-field">
            <label for="set-<?= $e($field['key']) ?>"><?= $e($field['label']) ?></label>
            <?php if ($field['type'] === 'collection'): ?>
                <select id="set-<?= $e($field['key']) ?>" name="settings[<?= $e($field['key']) ?>]">
                    <option value="">— none (default placeholder) —</option>
                    <?php foreach ($collections as $handle => $name): ?>
                        <option value="<?= $e($handle) ?>"<?= $handle === $field['value'] ? ' selected' : '' ?>>
                            <?= $e($name) ?> (<?= $e($handle) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($field['type'] === 'text'): ?>
                <input type="text" id="set-<?= $e($field['key']) ?>" name="settings[<?= $e($field['key']) ?>]" value="<?= $e($field['value']) ?>">
            <?php else: ?>
                <textarea id="set-<?= $e($field['key']) ?>" name="settings[<?= $e($field['key']) ?>]" rows="3"><?= $e($field['value']) ?></textarea>
            <?php endif; ?>
            <small class="nb-muted"><?= $e($field['help']) ?></small>
        </div>
    <?php endforeach; ?>
    <button type="submit" class="nb-btn nb-btn-primary">Save site settings</button>
</form>
<?php endif; ?>

<form class="nb-form-card" method="post" action="/admin/settings/theme">
    <h2>Appearance</h2>
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

<script nonce="<?= $e($cspNonce) ?>">
/* Instant preview before saving (progressive enhancement). The radio values are
   server-rendered from the theme allow-list, so they're always known slugs. */
document.querySelectorAll('input[name=theme]').forEach(function (r) {
    r.addEventListener('change', function () { document.documentElement.dataset.theme = r.value; });
});
</script>
