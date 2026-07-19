<?php
use Nimbus\Http\Csrf;
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);
?>
<div class="nb-page-head">
    <h1>Collections</h1>
    <?php if ($isAdmin): ?><a class="nb-btn nb-btn-primary" href="/admin/collections/new">+ New collection</a><?php endif; ?>
</div>

<?php if (!empty($flash)): ?><div class="nb-alert nb-alert-ok"><?= $e(ucfirst($flash)) ?>.</div><?php endif; ?>

<?php if ($rows === []): ?>
    <div class="nb-empty-panel">
        <span class="nb-empty-ic">❑</span>
        <h2>No collections yet</h2>
        <p>A collection is a content type — like Posts or Products. Create one to start adding entries.</p>
    </div>
<?php else: ?>
    <div class="nb-table-wrap">
        <table class="nb-table">
            <thead><tr><th>Name</th><th>Handle</th><th>Fields</th><th>Entries</th><th class="nb-actions-col"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): $c = $row['c']; ?>
                <tr>
                    <td>
                        <span class="nb-ic-badge"><?= $e($c->iconChar()) ?></span>
                        <a href="/admin/collections/<?= $e($c->handle) ?>/entries"><strong><?= $e($c->name) ?></strong></a>
                    </td>
                    <td><code><?= $e($c->handle) ?></code></td>
                    <td><?= (int) $row['fields'] ?></td>
                    <td><?= (int) $row['entries'] ?></td>
                    <td class="nb-row-actions">
                        <a href="/admin/collections/<?= $e($c->handle) ?>/entries">Entries</a>
                        <?php if ($isAdmin): ?>
                            <a href="/admin/collections/<?= (int) $c->id ?>/edit">Edit</a>
                            <form method="post" action="/admin/collections/<?= (int) $c->id ?>/delete" onsubmit="return confirm('Delete this collection and all its entries?');">
                                <input type="hidden" name="_token" value="<?= $e(Csrf::token()) ?>">
                                <button type="submit" class="nb-link-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
