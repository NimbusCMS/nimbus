<?php
/**
 * @var \Nimbus\Content\Collection       $collection
 * @var array<int,array<string,mixed>>   $rows
 * @var \Nimbus\Content\FieldTypeRegistry $types
 * @var bool                             $canManage
 * @var int                              $page
 * @var int                              $total_pages
 * @var int                              $total
 * @var ?string                          $q
 */
use Nimbus\Content\Publication;
use Nimbus\Http\Csrf;
use Nimbus\View\View;

$e          = static fn (?string $v): string => View::e($v);
$listFields = array_slice($collection->fields, 0, 2);
$h          = $e($collection->handle);
?>
<div class="nb-page-head">
    <div>
        <h1><?= $e($collection->name) ?></h1>
        <?php if ($collection->description !== ''): ?><p class="nb-muted"><?= $e($collection->description) ?></p><?php endif; ?>
    </div>
    <div class="nb-head-actions">
        <a class="nb-btn" href="/admin/collections/<?= (int) $collection->id ?>/edit">Fields</a>
        <?php if ($canManage): ?><a class="nb-btn nb-btn-primary" href="/admin/collections/<?= $h ?>/entries/new">+ New entry</a><?php endif; ?>
    </div>
</div>

<?php if (!empty($flash)): ?><div class="nb-alert nb-alert-ok"><?= $e(ucfirst($flash)) ?>.</div><?php endif; ?>

<?php if ($rows === []): ?>
    <div class="nb-empty-panel">
        <span class="nb-empty-ic">✎</span>
        <h2>No entries yet</h2>
        <p>Create the first entry in <?= $e($collection->name) ?>.</p>
    </div>
<?php else: ?>
    <div class="nb-table-wrap nb-stack">
        <table class="nb-table">
            <thead><tr>
                <th>Title</th>
                <?php foreach ($listFields as $lf): ?><th><?= $e($lf->label) ?></th><?php endforeach; ?>
                <th>Status</th><th>Updated</th><th class="nb-actions-col"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <a href="/admin/collections/<?= $h ?>/entries/<?= (int) $row['id'] ?>/edit"><strong><?= $e($row['title']) ?></strong></a>
                        <br><code class="nb-slug"><?= $e($row['slug']) ?></code>
                    </td>
                    <?php foreach ($listFields as $lf): ?>
                        <td data-label="<?= $e($lf->label) ?>"><?= $types->forDisplay($lf->type)->renderCell($lf, $row['data'][$lf->handle] ?? null) ?></td>
                    <?php endforeach; ?>
                    <?php $state = Publication::state((string) $row['status'], $row['published_at'] ?? null); ?>
                    <td data-label="Status">
                        <span class="nb-badge nb-badge-state-<?= $e($state) ?>"><?= $e(Publication::label($state)) ?></span>
                        <?php if ($state === 'scheduled'): ?>
                            <div class="nb-muted nb-sched-at"><?= $e(date('M j, Y · g:ia', strtotime((string) $row['published_at']))) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="nb-muted" data-label="Updated"><?= $e(date('M j, Y', strtotime((string) $row['updated_at']))) ?></td>
                    <td class="nb-row-actions">
                        <?php if ($canManage): ?>
                            <a href="/admin/collections/<?= $h ?>/entries/<?= (int) $row['id'] ?>/edit">Edit</a>
                            <?php $action = $state === 'published' || $state === 'scheduled' ? 'unpublish' : 'publish'; ?>
                            <form method="post" action="/admin/collections/<?= $h ?>/entries/<?= (int) $row['id'] ?>/<?= $action ?>">
                                <input type="hidden" name="_token" value="<?= $e(Csrf::token()) ?>">
                                <button type="submit" class="nb-link"><?= $action === 'publish' ? 'Publish' : 'Unpublish' ?></button>
                            </form>
                            <form method="post" action="/admin/collections/<?= $h ?>/entries/<?= (int) $row['id'] ?>/delete" data-confirm="Delete this entry?">
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

    <?php if ($total_pages > 1): ?>
        <?php
        // Build a pager link, preserving the search term. rawurlencode() makes the
        // term safe inside the attribute (no `"`/`<`/`>`); $e() escapes the `&`.
        $pageLink = static function (int $p) use ($collection, $q): string {
            $qs = 'page=' . $p;
            if ($q !== null && $q !== '') {
                $qs .= '&q=' . rawurlencode($q);
            }
            return '/admin/collections/' . rawurlencode($collection->handle) . '/entries?' . $qs;
        };
        ?>
        <nav class="nb-pager" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a class="nb-btn" href="<?= $e($pageLink($page - 1)) ?>" rel="prev">← Prev</a>
            <?php else: ?>
                <span class="nb-btn nb-btn-disabled" aria-disabled="true">← Prev</span>
            <?php endif; ?>
            <span class="nb-pager-status">Page <?= (int) $page ?> of <?= (int) $total_pages ?> <span class="nb-muted">(<?= (int) $total ?> total)</span></span>
            <?php if ($page < $total_pages): ?>
                <a class="nb-btn" href="<?= $e($pageLink($page + 1)) ?>" rel="next">Next →</a>
            <?php else: ?>
                <span class="nb-btn nb-btn-disabled" aria-disabled="true">Next →</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
