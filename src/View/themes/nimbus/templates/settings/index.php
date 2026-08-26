<?php
/**
 * @var array<string,array{name:string,mood:string}> $themes
 * @var string  $current the active theme slug
 * @var ?string $flash
 * @var string  $csrf
 * @var bool    $canEditSite
 * @var list<array{key:string,type:string,label:string,help:string,value:string}> $siteFields
 * @var array<string,string> $siteErrors key => validator message (a failed save)
 * @var array<string,string> $collections handle => name
 * @var list<array{key:string,label:string,linked:bool,email:?string}> $oauthAccounts
 * @var array{kind:string,message:string}|null $oauthFlash
 * @var ?string $passwordError fixed message when a change-password submit fails
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
<?php elseif ($flash === 'password'): ?>
    <div class="nb-alert nb-alert-ok">Password changed. ✦</div>
<?php endif; ?>

<?php if (!empty($siteErrors)): ?>
    <div class="nb-alert nb-alert-error">Some settings couldn’t be saved — please check the highlighted fields.</div>
<?php endif; ?>

<?php if ($canEditSite): ?>
<form class="nb-form-card" method="post" action="/admin/settings/site">
    <h2>Site</h2>
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <?php foreach ($siteFields as $field): $fieldError = $siteErrors[$field['key']] ?? null; ?>
        <div class="nb-field <?= $fieldError !== null ? 'has-error' : '' ?>">
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
            <?php if ($fieldError !== null): ?><span class="nb-field-error"><?= $e($fieldError) ?></span><?php endif; ?>
            <small class="nb-muted"><?= $e($field['help']) ?></small>
        </div>
    <?php endforeach; ?>
    <button type="submit" class="nb-btn nb-btn-primary">Save site settings</button>
</form>
<?php endif; ?>

<?php if (!empty($oauthAccounts)): ?>
<div class="nb-form-card">
    <h2>Connected accounts</h2>
    <p class="nb-muted">Link a provider to sign in without a password. Your password sign-in always stays available.</p>
    <?php if ($oauthFlash !== null): ?>
        <div class="nb-alert nb-alert-<?= $oauthFlash['kind'] === 'ok' ? 'ok' : 'error' ?>"><?= $e($oauthFlash['message']) ?></div>
    <?php endif; ?>
    <ul class="nb-oauth-list">
        <?php foreach ($oauthAccounts as $acct): ?>
            <li class="nb-oauth-row">
                <span class="nb-oauth-name">
                    <strong><?= $e($acct['label']) ?></strong>
                    <?php if ($acct['linked'] && $acct['email'] !== null): ?>
                        <small class="nb-muted"><?= $e($acct['email']) ?></small>
                    <?php endif; ?>
                </span>
                <?php if ($acct['linked']): ?>
                    <form method="post" action="/admin/oauth/<?= $e($acct['key']) ?>/disconnect">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <button type="submit" class="nb-btn nb-btn-sm">Disconnect</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/admin/oauth/<?= $e($acct['key']) ?>/link">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <button type="submit" class="nb-btn nb-btn-sm">Connect</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php // Change password — a personal action, any signed-in user. Fields never
      // carry a value: a submitted password is never echoed back into the form. ?>
<form class="nb-form-card" method="post" action="/admin/settings/password" autocomplete="off">
    <h2>Change password</h2>
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <?php if ($passwordError !== null): ?>
        <div class="nb-alert nb-alert-error"><?= $e($passwordError) ?></div>
    <?php endif; ?>
    <div class="nb-field">
        <label for="cur-pw">Current password</label>
        <input type="password" id="cur-pw" name="current_password" autocomplete="current-password" required>
    </div>
    <div class="nb-field">
        <label for="new-pw">New password</label>
        <input type="password" id="new-pw" name="new_password" autocomplete="new-password" required minlength="<?= (int) \Nimbus\Auth\Password::MIN_LENGTH ?>">
        <small class="nb-muted">At least <?= (int) \Nimbus\Auth\Password::MIN_LENGTH ?> characters.</small>
    </div>
    <div class="nb-field">
        <label for="confirm-pw">Confirm new password</label>
        <input type="password" id="confirm-pw" name="confirm_password" autocomplete="new-password" required>
    </div>
    <button type="submit" class="nb-btn nb-btn-primary">Change password</button>
</form>

<form class="nb-form-card" method="post" action="/admin/settings/theme">
    <h2>Appearance</h2>
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <div class="nb-field">
        <label>Theme <small class="nb-muted">— your admin skin (a personal preference)</small></label>
        <div class="nb-theme-cards">
            <?php foreach ($themes as $slug => $t): ?>
                <label class="nb-theme-card">
                    <input type="radio" name="theme" value="<?= $e($slug) ?>"<?= $slug === $current ? ' checked' : '' ?>>
                    <span class="nb-swatch nb-swatch--<?= $e($slug) ?>"></span>
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
