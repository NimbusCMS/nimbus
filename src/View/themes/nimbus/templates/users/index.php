<?php
/**
 * @var array<int,array{user:\Nimbus\Auth\User,roles:\Nimbus\Auth\Role[]}> $rows
 * @var \Nimbus\Auth\Role[]  $roles all roles, for the checklist
 * @var array<string,string> $roleHelp built-in role name => plain-language description
 * @var ?\Nimbus\Auth\User   $editing user being edited, or null (create)
 * @var list<int>            $editingRoles role ids assigned to the edited user
 * @var list<int>            $pending user ids with an unused invite (can be re-invited)
 * @var array{kind:string,message:string}|null $notice resolved admin notice (ADMIN-10)
 * @var string               $csrf
 */
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);

$checked = static fn (int $roleId): string => in_array($roleId, $editingRoles, true) ? ' checked' : '';
?>
<div class="nb-page-head"><h1>Users</h1></div>

<?php if ($notice !== null): ?>
    <div class="nb-alert nb-alert-<?= $notice['kind'] === 'ok' ? 'ok' : 'error' ?>"><?= $e($notice['message']) ?></div>
<?php endif; ?>

<form class="nb-token-new" method="post" action="<?= $editing !== null ? '/admin/users/' . (int) $editing->id : '/admin/users' ?>">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

    <?php if ($editing !== null): ?>
        <div class="nb-field">
            <label>User</label>
            <p><strong><?= $e($editing->name) ?></strong> <span class="nb-muted"><?= $e($editing->email) ?></span></p>
        </div>
        <div class="nb-field">
            <label>Name</label>
            <input type="text" name="name" value="<?= $e($editing->name) ?>">
        </div>
    <?php else: ?>
        <div class="nb-field">
            <label>Email</label>
            <input type="email" name="email" placeholder="person@example.com" required>
        </div>
        <div class="nb-field">
            <label>Name <small class="nb-muted">— optional</small></label>
            <input type="text" name="name" placeholder="e.g. Alex Rivera">
        </div>
        <div class="nb-field">
            <label>Password <small class="nb-muted">— leave blank to email an invite; they set their own</small></label>
            <input type="password" name="password" placeholder="blank = send invite" autocomplete="new-password">
        </div>
    <?php endif; ?>

    <div class="nb-field">
        <label>Roles <small class="nb-muted">— a user's access is the union of their roles</small></label>
        <div class="nb-scope-list">
            <?php foreach ($roles as $role): $help = $roleHelp[$role->name] ?? null; ?>
                <label class="nb-check nb-check-described">
                    <input type="checkbox" name="roles[]" value="<?= (int) $role->id ?>"<?= $editing !== null ? $checked($role->id) : '' ?>>
                    <span class="nb-check-body">
                        <span class="nb-check-name"><?= $e($role->name) ?><?php if ($role->isSystem): ?> <small class="nb-muted">built-in</small><?php endif; ?></span>
                        <?php if ($help !== null): ?><small class="nb-check-help nb-muted"><?= $e($help) ?></small><?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <button type="submit" class="nb-btn nb-btn-primary"><?= $editing !== null ? 'Save user' : 'Add user' ?></button>
    <?php if ($editing !== null): ?><a class="nb-link" href="/admin/users">Cancel</a><?php endif; ?>
</form>

<div class="nb-table-wrap">
<table class="nb-table">
    <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th></th></tr></thead>
    <tbody>
        <?php foreach ($rows as $row): $u = $row['user']; ?>
            <tr>
                <td><?= $e($u->name) ?></td>
                <td><span class="nb-muted"><?= $e($u->email) ?></span></td>
                <td>
                    <?php if ($row['roles'] === []): ?>
                        <span class="nb-muted">none</span>
                    <?php else: ?>
                        <?= $e(implode(', ', array_map(static fn ($r): string => $r->name, $row['roles']))) ?>
                    <?php endif; ?>
                </td>
                <td class="nb-row-actions">
                    <a class="nb-link" href="/admin/users?edit=<?= (int) $u->id ?>">Edit</a>
                    <?php if (in_array($u->id, $pending, true)): ?>
                        <form method="post" action="/admin/users/<?= (int) $u->id ?>/invite">
                            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                            <button type="submit" class="nb-link">Resend invite</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
