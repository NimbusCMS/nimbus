<?php
/**
 * @var \Nimbus\Content\Collection        $collection
 * @var array<string,mixed>|null          $entry
 * @var \Nimbus\Content\FieldTypeRegistry  $types
 * @var string                            $csrf
 */
use Nimbus\View\View;

$e       = static fn (?string $v): string => View::e($v);
$editing = $entry !== null;
$h       = $e($collection->handle);
$action  = $editing
    ? '/admin/collections/' . $collection->handle . '/entries/' . (int) $entry['id']
    : '/admin/collections/' . $collection->handle . '/entries';
$data    = $editing ? $entry['data'] : [];
$status  = $editing ? (string) $entry['status'] : 'draft';
?>
<div class="nb-page-head">
    <h1><?= $editing ? 'Edit' : 'New' ?> · <?= $e($collection->name) ?></h1>
    <a class="nb-btn" href="/admin/collections/<?= $h ?>/entries">← Back</a>
</div>

<form class="nb-form-card" method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

    <div class="nb-grid-2">
        <div class="nb-field">
            <label>Title</label>
            <input name="title" value="<?= $e($editing ? (string) $entry['title'] : '') ?>" required>
        </div>
        <div class="nb-field">
            <label>Slug <small class="nb-muted">(auto from title)</small></label>
            <input name="slug" value="<?= $e($editing ? (string) $entry['slug'] : '') ?>" placeholder="auto">
        </div>
    </div>

    <?php foreach ($collection->fields as $f): ?>
        <div class="nb-field">
            <?php if ($f->type !== 'boolean'): ?>
                <label for="f_<?= $e($f->handle) ?>"><?= $e($f->label) ?><?= $f->required ? ' <span class="nb-req">*</span>' : '' ?></label>
            <?php endif; ?>
            <?= $types->get($f->type)->renderInput($f, $data[$f->handle] ?? null) ?>
        </div>
    <?php endforeach; ?>

    <div class="nb-field">
        <label>Status</label>
        <select name="status">
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
        </select>
    </div>

    <div class="nb-form-actions">
        <button type="submit" class="nb-btn nb-btn-primary"><?= $editing ? 'Save entry' : 'Create entry' ?></button>
        <a class="nb-btn" href="/admin/collections/<?= $h ?>/entries">Cancel</a>
    </div>
</form>
