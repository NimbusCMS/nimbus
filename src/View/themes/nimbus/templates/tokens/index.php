<?php
/**
 * @var \Nimbus\Api\ApiToken[]         $tokens
 * @var string[]                       $expiries
 * @var \Nimbus\Content\Collection[]   $collections
 * @var ?string                        $justCreated one-time plaintext to display, or null
 * @var ?string                        $flash
 * @var ?string                        $error
 * @var string                         $csrf
 * @var string                         $nonce single-use, guards against reload-resubmit
 */
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);

$expiryLabel = ['never' => 'Never', '30d' => 'In 30 days', '90d' => 'In 90 days', '1y' => 'In 1 year'];
$flashLabel  = [
    'revoked'  => 'Token revoked.',
    'paused'   => 'Token paused.',
    'resumed'  => 'Token resumed.',
    'resubmit' => 'That form had already been submitted — no token was created.',
];

// Reuse the existing badge palette: active is good, revoked is danger, the rest muted.
$badge = static fn (string $status): string => match ($status) {
    'active'  => 'nb-badge-ok',
    'revoked' => 'nb-badge-danger',
    default   => 'nb-badge-muted',
};

// A compact summary of a token's read scopes for the list.
$access = static function (array $abilities): string {
    if ($abilities === []) {
        return 'all (legacy)';
    }
    if (in_array('*:read', $abilities, true)) {
        return 'all collections';
    }
    return implode(', ', array_map(static fn (string $s): string => explode(':', $s)[0], $abilities));
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
        <strong>Token created.</strong> Copy it now — it will never be shown again. ✦
        <code class="nb-token-secret"><?= $e($justCreated) ?></code>
    </div>
<?php endif; ?>

<form class="nb-token-new" method="post" action="/admin/tokens">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <input type="hidden" name="_nonce" value="<?= $e($nonce) ?>">
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
    <div class="nb-field">
        <label>Access <small class="nb-muted">— which collections this token may read</small></label>
        <label class="nb-check">
            <input type="checkbox" name="scope_all" value="1" checked>
            All collections <small class="nb-muted">— including ones added later</small>
        </label>
        <?php if ($collections !== []): ?>
            <div class="nb-scope-list">
                <?php foreach ($collections as $c): ?>
                    <label class="nb-check">
                        <input type="checkbox" name="scopes[]" value="<?= $e($c->handle) ?>">
                        <?= $e($c->name) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="nb-muted">Uncheck “All collections” to grant only the ones you tick. Tokens are read-only.</p>
        <?php endif; ?>
    </div>
    <button type="submit" class="nb-btn nb-btn-primary">Create token</button>
</form>

<?php if ($tokens === []): ?>
    <div class="nb-empty-panel">
        <span class="nb-empty-ic">⚿</span>
        <h2>No API tokens yet</h2>
        <p>Create one above to let an external site or service read your published
           content through the API.</p>
    </div>
<?php else: ?>
    <div class="nb-table-wrap nb-stack">
    <table class="nb-table">
        <thead>
            <tr>
                <th>Name</th><th>Access</th><th>Status</th><th>Expires</th><th>Last used</th><th>Uses</th><th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tokens as $t): $status = $t->status(); ?>
                <tr>
                    <td><?= $e($t->name) ?></td>
                    <td data-label="Access"><span class="nb-muted"><?= $e($access($t->abilities)) ?></span></td>
                    <td data-label="Status"><span class="nb-badge <?= $badge($status) ?>"><?= $e($status) ?></span></td>
                    <td data-label="Expires"><?= $t->expiresAt !== null ? $e($t->expiresAt) : '<span class="nb-muted">never</span>' ?></td>
                    <td data-label="Last used">
                        <?php if ($t->lastUsedAt !== null): ?>
                            <?= $e($t->lastUsedAt) ?><?php if ($t->lastUsedIp !== null): ?> <span class="nb-muted">· <?= $e($t->lastUsedIp) ?></span><?php endif; ?>
                        <?php else: ?>
                            <span class="nb-muted">never</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Uses"><?= (int) $t->usedCount ?></td>
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
    </div>
<?php endif; ?>
