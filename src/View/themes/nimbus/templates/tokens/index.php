<?php
/**
 * @var \Nimbus\Api\ApiToken[] $tokens
 * @var string[]               $expiries
 * @var ?string                $justCreated one-time plaintext to display, or null
 * @var ?string                $flash
 * @var ?string                $error
 * @var string                 $csrf
 */
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);

$expiryLabel = ['never' => 'Never', '30d' => 'In 30 days', '90d' => 'In 90 days', '1y' => 'In 1 year'];
$flashLabel  = ['revoked' => 'Token revoked.', 'paused' => 'Token paused.', 'resumed' => 'Token resumed.'];

// Reuse the existing badge palette: active is good, revoked is danger, the rest muted.
$badge = static fn (string $status): string => match ($status) {
    'active'  => 'nb-badge-ok',
    'revoked' => 'nb-badge-danger',
    default   => 'nb-badge-muted',
};
?>
<div class="nb-page-head">
    <h1>API tokens</h1>
</div>

<?php if ($error !== null): ?>
    <div class="nb-alert nb-alert-error"><?= $e($error) ?></div>
<?php elseif ($flash !== null && isset($flashLabel[$flash])): ?>
    <div class="nb-alert nb-alert-ok"><?= $e($flashLabel[$flash]) ?></div>
<?php endif; ?>

<?php if ($justCreated !== null): ?>
    <div class="nb-alert nb-alert-ok">
        <strong>Token created.</strong> Copy it now — for security it will never be shown again.
        <code class="nb-token-secret"><?= $e($justCreated) ?></code>
    </div>
<?php endif; ?>

<form class="nb-token-new" method="post" action="/admin/tokens">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <div class="nb-field">
        <label>Name <small class="nb-muted">— what this token is for</small></label>
        <input type="text" name="name" placeholder="e.g. Marketing site" required>
    </div>
    <div class="nb-field">
        <label>Expires</label>
        <select name="expires">
            <?php foreach ($expiries as $key): ?>
                <option value="<?= $e($key) ?>"><?= $e($expiryLabel[$key] ?? $key) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="nb-btn nb-btn-primary">Create token</button>
    <p class="nb-muted">Tokens are read-only. Per-collection scopes arrive in a later release.</p>
</form>

<?php if ($tokens === []): ?>
    <div class="nb-empty-panel">
        <span class="nb-empty-ic">⚿</span>
        <h2>No API tokens yet</h2>
        <p>Create one above to let an external site or service read your published
           content through the API.</p>
    </div>
<?php else: ?>
    <table class="nb-table">
        <thead>
            <tr>
                <th>Name</th><th>Status</th><th>Expires</th><th>Last used</th><th>Uses</th><th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tokens as $t): $status = $t->status(); ?>
                <tr>
                    <td><?= $e($t->name) ?></td>
                    <td><span class="nb-badge <?= $badge($status) ?>"><?= $e($status) ?></span></td>
                    <td><?= $t->expiresAt !== null ? $e($t->expiresAt) : '<span class="nb-muted">never</span>' ?></td>
                    <td>
                        <?php if ($t->lastUsedAt !== null): ?>
                            <?= $e($t->lastUsedAt) ?><?php if ($t->lastUsedIp !== null): ?> <span class="nb-muted">· <?= $e($t->lastUsedIp) ?></span><?php endif; ?>
                        <?php else: ?>
                            <span class="nb-muted">never</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $t->usedCount ?></td>
                    <td class="nb-row-actions">
                        <?php if ($t->isActive()): ?>
                            <form method="post" action="/admin/tokens/<?= (int) $t->id ?>/pause">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                                <button type="submit" class="nb-link">Pause</button>
                            </form>
                        <?php elseif ($t->isPaused()): ?>
                            <form method="post" action="/admin/tokens/<?= (int) $t->id ?>/resume">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                                <button type="submit" class="nb-link">Resume</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$t->isRevoked()): ?>
                            <form method="post" action="/admin/tokens/<?= (int) $t->id ?>/revoke" onsubmit="return confirm('Revoke this token? This cannot be undone.');">
                                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                                <button type="submit" class="nb-link-danger">Revoke</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
