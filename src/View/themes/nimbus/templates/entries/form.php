<?php
/**
 * @var \Nimbus\Content\Collection        $collection
 * @var array{id:?int,title:string,slug:string,status:string,values:array} $model
 * @var array<string,string>              $errors   per-field messages, keyed by input name
 * @var string                            $topError a non-field failure (missing provider), or ''
 * @var \Nimbus\Content\FieldTypeRegistry  $types
 * @var string                            $csrf
 */
use Nimbus\View\View;

$e       = static fn (?string $v): string => View::e($v);
$editing = $model['id'] !== null;
$single  = $collection->isSingle();
$h       = $e($collection->handle);
$action  = $editing
    ? '/admin/collections/' . $collection->handle . '/entries/' . (int) $model['id']
    : '/admin/collections/' . $collection->handle . '/entries';
$backUrl = $single ? '/admin/collections' : '/admin/collections/' . $collection->handle . '/entries';
$heading = $single ? $e($collection->name) : ($editing ? 'Edit' : 'New') . ' · ' . $e($collection->name);
?>
<div class="nb-page-head">
    <h1><?= $heading ?></h1>
    <a class="nb-btn" href="<?= $e($backUrl) ?>">← Back</a>
</div>

<?php if (!empty($flash)): ?><div class="nb-alert nb-alert-ok"><?= $e(ucfirst($flash)) ?>.</div><?php endif; ?>
<?php if ($topError !== ''): ?>
    <div class="nb-alert nb-alert-error"><?= $e($topError) ?></div>
<?php elseif ($errors !== []): ?>
    <div class="nb-alert nb-alert-error">Please fix the highlighted fields.</div>
<?php endif; ?>

<form class="nb-form-card" method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

    <?php if (!$single): ?>
        <div class="nb-grid-2">
            <div class="nb-field <?= isset($errors['title']) ? 'has-error' : '' ?>">
                <label>Title <span class="nb-req">*</span></label>
                <input name="title" value="<?= $e($model['title']) ?>" required>
                <?php if (isset($errors['title'])): ?><span class="nb-field-error"><?= $e($errors['title']) ?></span><?php endif; ?>
            </div>
            <div class="nb-field">
                <label>Slug <small class="nb-muted">(auto from title)</small></label>
                <input name="slug" value="<?= $e($model['slug']) ?>" placeholder="auto">
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($collection->fields as $f): $err = $errors[$f->handle] ?? null; ?>
        <div class="nb-field <?= $err ? 'has-error' : '' ?>">
            <?php if ($f->type !== 'boolean'): ?>
                <label for="f_<?= $e($f->handle) ?>"><?= $e($f->label) ?><?= $f->required ? ' <span class="nb-req">*</span>' : '' ?></label>
            <?php endif; ?>

            <?php if ($f->type === 'relation'):
                $opts     = $relationOptions[$f->handle] ?? [];
                $selected = array_map('intval', (array) ($model['values'][$f->handle] ?? []));
                $multiple = (bool) $f->option('multiple', false);
            ?>
                <?php if ($opts === []): ?>
                    <p class="nb-help">No <?= $e((string) $f->option('target', 'target')) ?> entries to link yet.</p>
                <?php else: ?>
                    <select id="f_<?= $e($f->handle) ?>" name="f[<?= $e($f->handle) ?>]<?= $multiple ? '[]' : '' ?>" <?= $multiple ? 'multiple size="5"' : '' ?>>
                        <?php if (!$multiple): ?><option value="">—</option><?php endif; ?>
                        <?php foreach ($opts as $oid => $otitle): ?>
                            <option value="<?= (int) $oid ?>" <?= in_array((int) $oid, $selected, true) ? 'selected' : '' ?>><?= $e($otitle) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            <?php elseif ($f->type === 'media'):
                $chosen = (int) ($model['values'][$f->handle] ?? 0);
            ?>
                <?php if ($mediaOptions === []): ?>
                    <p class="nb-help">No files yet — upload some in <a href="/admin/media">Media</a>.</p>
                    <input type="hidden" name="f[<?= $e($f->handle) ?>]" value="<?= $chosen ?: '' ?>">
                <?php else: ?>
                    <select id="f_<?= $e($f->handle) ?>" name="f[<?= $e($f->handle) ?>]">
                        <option value="">— none —</option>
                        <?php foreach ($mediaOptions as $mid => $mname): ?>
                            <option value="<?= (int) $mid ?>" <?= $chosen === (int) $mid ? 'selected' : '' ?>><?= $e($mname) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            <?php else: ?>
                <?= $types->forDisplay($f->type)->renderInput($f, $model['values'][$f->handle] ?? '') ?>
            <?php endif; ?>

            <?php if ((string) $f->option('help', '') !== ''): ?><span class="nb-help"><?= $e((string) $f->option('help')) ?></span><?php endif; ?>
            <?php if ($err): ?><span class="nb-field-error"><?= $e($err) ?></span><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="nb-grid-2">
        <div class="nb-field">
            <label>Status</label>
            <select name="status">
                <option value="draft" <?= $model['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="published" <?= $model['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="archived" <?= $model['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
            </select>
        </div>
        <div class="nb-field <?= isset($errors['published_at']) ? 'has-error' : '' ?>">
            <label>Publish at <small class="nb-muted">— a future time schedules it</small></label>
            <input type="datetime-local" name="published_at" value="<?= $e($model['published_at_input'] ?? '') ?>">
            <?php if (isset($errors['published_at'])): ?><span class="nb-field-error"><?= $e($errors['published_at']) ?></span><?php endif; ?>
        </div>
    </div>
    <?php if (($model['state'] ?? '') === 'scheduled'): ?>
        <p class="nb-help">Scheduled — this entry goes live automatically at the time above.</p>
    <?php endif; ?>

    <div class="nb-form-actions">
        <button type="submit" class="nb-btn nb-btn-primary"><?= $single ? 'Save' : ($editing ? 'Save entry' : 'Create entry') ?></button>
        <a class="nb-btn" href="<?= $e($backUrl) ?>">Cancel</a>
    </div>
</form>
