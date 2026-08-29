<?php
use Nimbus\Http\Csrf;
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);
?>
<div class="nb-page-head">
    <h1>Collections</h1>
    <?php if ($isAdmin): ?><a class="nb-btn nb-btn-primary" href="/admin/collections/new">+ New collection</a><?php endif; ?>
</div>

<?php if ($error !== null): ?><div class="nb-alert nb-alert-error"><?= $e($error) ?></div>
<?php elseif ($notice !== null): ?><div class="nb-alert nb-alert-<?= $notice['kind'] === 'ok' ? 'ok' : 'error' ?>"><?= $e($notice['message']) ?></div><?php endif; ?>

<?php if ($rows === []): ?>
    <div class="nb-empty-panel">
        <span class="nb-empty-ic">❑</span>
        <h2>No collections yet</h2>
        <p>A collection is a content type — like Posts or Products.<?php if ($isAdmin): ?> Create one to start adding entries.<?php endif; ?></p>
    </div>
<?php else: ?>
    <div class="nb-table-wrap nb-stack">
        <table class="nb-table">
            <thead><tr><th>Name</th><th>Handle</th><th>Fields</th><th>Entries</th><th class="nb-actions-col"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): $c = $row['c']; $linkable = !$c->isSingle() || $row['manage']; ?>
                <tr>
                    <td data-label="Name">
                        <span class="nb-ic-badge"><?= $e($c->iconChar()) ?></span>
                        <?php if ($linkable): ?>
                            <a href="/admin/collections/<?= $e($c->handle) ?>/entries"><strong><?= $e($c->name) ?></strong></a>
                        <?php else: ?>
                            <strong><?= $e($c->name) ?></strong>
                        <?php endif; ?>
                        <?php if ($c->isSingle()): ?><span class="nb-badge nb-badge-muted">Single</span><?php endif; ?>
                    </td>
                    <td data-label="Handle"><code><?= $e($c->handle) ?></code></td>
                    <td data-label="Fields"><?= (int) $row['fields'] ?></td>
                    <td data-label="Entries"><?= $c->isSingle() ? '—' : (int) $row['entries'] ?></td>
                    <td class="nb-row-actions">
                        <?php if ($linkable): ?>
                            <a href="/admin/collections/<?= $e($c->handle) ?>/entries"><?= $c->isSingle() ? 'Edit' : 'Entries' ?></a>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <a href="/admin/collections/<?= (int) $c->id ?>/edit">Edit</a>
                            <form method="post" action="/admin/collections/<?= (int) $c->id ?>/delete" data-confirm="Delete this collection and all its entries?">
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
