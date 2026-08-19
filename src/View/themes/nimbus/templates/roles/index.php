<?php
/**
 * @var \Nimbus\Auth\Role[]           $roles
 * @var \Nimbus\Content\Collection[]  $collections
 * @var array<string,string>          $management capability => label
 * @var ?\Nimbus\Auth\Role            $editing role being edited, or null (create)
 * @var array<int,int>                $counts role id => assigned user count
 * @var ?string                       $flash
 * @var ?string                       $error
 * @var string                        $csrf
 */
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);

$flashLabel = ['created' => 'Role created.', 'updated' => 'Role updated.', 'deleted' => 'Role deleted.'];

$held = $editing !== null ? $editing->capabilities : [];
$has  = static fn (string $cap): string => in_array($cap, $held, true) ? ' checked' : '';

// A compact human summary of a role's capabilities for the list.
$summary = static function (array $caps) use ($e): string {
    if (in_array('admin', $caps, true)) {
        return '<span class="nb-badge nb-badge-danger">Full administrator</span>';
    }
    if ($caps === []) {
        return '<span class="nb-muted">no access</span>';
    }
    return $e(implode(', ', $caps));
};
?>
<div class="nb-page-head"><h1>Roles</h1></div>

<?php if ($error !== null): ?>
    <div class="nb-alert nb-alert-error"><?= $e($error) ?></div>
<?php elseif ($flash !== null && isset($flashLabel[$flash])): ?>
    <div class="nb-alert nb-alert-ok"><?= $e($flashLabel[$flash]) ?></div>
<?php endif; ?>

<form class="nb-token-new" method="post" action="<?= $editing !== null ? '/admin/roles/' . (int) $editing->id : '/admin/roles' ?>">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <div class="nb-field">
        <label>Name</label>
        <input type="text" name="name" placeholder="e.g. Blog editor"
               value="<?= $editing !== null ? $e($editing->name) : '' ?>"
               <?= $editing !== null ? 'readonly' : 'required' ?>>
        <?php if ($editing !== null): ?><small class="nb-muted">Editing <strong><?= $e($editing->name) ?></strong>’s capabilities.</small><?php endif; ?>
    </div>

    <div class="nb-field">
        <label>Full access</label>
        <label class="nb-check"><input type="checkbox" name="caps[]" value="admin"<?= $has('admin') ?>>
            Full administrator <small class="nb-muted">— everything, including managing roles</small></label>
    </div>

    <div class="nb-field">
        <label>Content</label>
        <label class="nb-check"><input type="checkbox" name="caps[]" value="*:read"<?= $has('*:read') ?>>
            Read all content <small class="nb-muted">— including collections added later</small></label>
        <label class="nb-check"><input type="checkbox" name="caps[]" value="*:write"<?= $has('*:write') ?>>
            Manage all content <small class="nb-muted">— including collections added later</small></label>
        <?php if ($collections !== []): ?>
            <div class="nb-scope-list">
                <?php foreach ($collections as $c): ?>
                    <div>
                        <strong><?= $e($c->name) ?></strong>
                        <label class="nb-check"><input type="checkbox" name="caps[]" value="<?= $e($c->handle) ?>:read"<?= $has($c->handle . ':read') ?>> read</label>
                        <label class="nb-check"><input type="checkbox" name="caps[]" value="<?= $e($c->handle) ?>:write"<?= $has($c->handle . ':write') ?>> manage</label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="nb-field">
        <label>Administration</label>
        <?php foreach ($management as $cap => $label): $disabled = $cap === 'settings:write'; ?>
            <label class="nb-check">
                <input type="checkbox" name="caps[]" value="<?= $e($cap) ?>"<?= $has($cap) ?><?= $disabled ? ' disabled' : '' ?>>
                <?= $e($label) ?><?php if ($disabled): ?> <small class="nb-muted">— coming soon</small><?php endif; ?>
            </label>
        <?php endforeach; ?>
    </div>

    <button type="submit" class="nb-btn nb-btn-primary"><?= $editing !== null ? 'Save role' : 'Create role' ?></button>
    <?php if ($editing !== null): ?><a class="nb-link" href="/admin/roles">Cancel</a><?php endif; ?>
</form>

<table class="nb-table">
    <thead><tr><th>Role</th><th>Capabilities</th><th>Users</th><th></th></tr></thead>
    <tbody>
        <?php foreach ($roles as $role): ?>
            <tr>
                <td><?= $e($role->name) ?><?php if ($role->isSystem): ?> <span class="nb-badge nb-badge-muted">built-in</span><?php endif; ?></td>
                <td><?= $summary($role->capabilities) ?></td>
                <td><?= (int) ($counts[$role->id] ?? 0) ?></td>
                <td class="nb-row-actions">
                    <?php if ($role->name !== 'admin'): ?><a class="nb-link" href="/admin/roles?edit=<?= (int) $role->id ?>">Edit</a><?php endif; ?>
                    <?php if (!$role->isSystem): ?>
                        <form method="post" action="/admin/roles/<?= (int) $role->id ?>/delete" onsubmit="return confirm('Delete this role? Users assigned it will lose these capabilities.');">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <button type="submit" class="nb-link-danger">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
