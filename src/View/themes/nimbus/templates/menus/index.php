<?php
/**
 * The admin Menus editor — one form per menu (main, footer). Each item is a
 * Label + URL pair; a few blank rows let you add items, and clearing a row's
 * fields removes it on save. No inline styles/scripts (the admin CSP is
 * nonce-only) — layout uses the shared admin classes only.
 *
 * @var array<string,list<array{label:string,url:string}>> $menus  editable menus by name
 * @var string                                             $csrf
 * @var array{kind:string,message:string}|null             $notice
 */
use Nimbus\View\View;

$e     = static fn (?string $v): string => View::e($v);
$title = static fn (string $name): string => ucfirst($name) . ' menu';
$blank = ['label' => '', 'url' => ''];
?>
<div class="nb-page-head"><h1>Menus</h1></div>
<?php if ($notice !== null): ?>
    <div class="nb-notice nb-notice-<?= $notice['kind'] === 'ok' ? 'ok' : 'error' ?>"><?= $e($notice['message']) ?></div>
<?php endif; ?>
<p class="nb-muted">Edit the site’s navigation. The <strong>Main</strong> menu is the header; the <strong>Footer</strong> menu is the footer. Links may be a full <code>https://…</code> URL, a path like <code>/menu</code>, a <code>mailto:</code>/<code>tel:</code>, or a <code>#anchor</code>. Leave a row blank to drop it.</p>

<?php foreach ($menus as $name => $items): ?>
    <?php $rows = array_merge($items, [$blank, $blank, $blank]); ?>
    <form class="nb-form-card" method="post" action="/admin/menus">
        <h2><?= $e($title($name)) ?></h2>
        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="menu" value="<?= $e($name) ?>">
        <?php foreach ($rows as $i => $row): ?>
            <div class="nb-field">
                <label for="<?= $e($name) ?>-label-<?= $i ?>">Label</label>
                <input type="text" id="<?= $e($name) ?>-label-<?= $i ?>" name="label[]" value="<?= $e($row['label']) ?>" placeholder="e.g. About" autocomplete="off">
            </div>
            <div class="nb-field">
                <label for="<?= $e($name) ?>-url-<?= $i ?>">URL</label>
                <input type="text" id="<?= $e($name) ?>-url-<?= $i ?>" name="url[]" value="<?= $e($row['url']) ?>" placeholder="e.g. /pages/about" autocomplete="off">
            </div>
            <hr class="nb-rule">
        <?php endforeach; ?>
        <button type="submit" class="nb-btn nb-btn-primary">Save <?= $e($name) ?> menu</button>
    </form>
<?php endforeach; ?>
