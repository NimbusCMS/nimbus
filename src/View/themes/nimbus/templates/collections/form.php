<?php
/**
 * @var \Nimbus\Content\Collection|null $collection  stored collection (null when creating)
 * @var array<string,mixed>             $draft       form values: stored, blank, or resubmitted
 * @var array<string,string>            $errors      field handle => message
 * @var array<string,string>            $typeChoices
 * @var string[]                        $choiceTypes
 * @var string[]                        $roles
 * @var string                          $csrf
 */
use Nimbus\View\View;

$e           = static fn (?string $v): string => View::e($v);
$editing     = $collection !== null;
$action      = $editing ? '/admin/collections/' . $collection->id : '/admin/collections';
$manageRoles = $draft['roles'] ?? [];
$draftFields = $draft['fields'] ?? [];
$isSingle    = ($draft['kind'] ?? 'collection') === 'single';
$lockHandles = $editing;
$err         = static fn (string $k): string => isset($errors[$k])
    ? '<p class="nb-field-error">' . View::e($errors[$k]) . '</p>'
    : '';
?>
<?php if ($errors !== []): ?>
    <div class="nb-alert nb-alert-error">Please fix the highlighted field<?= count($errors) > 1 ? 's' : '' ?> below — nothing has been saved yet.</div>
<?php endif; ?>
<div class="nb-page-head">
    <h1><?= $editing ? 'Edit' : 'New' ?> collection</h1>
    <a class="nb-btn" href="/admin/collections">← Back</a>
</div>

<form class="nb-form-card" method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

    <div class="nb-grid-2">
        <div class="nb-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
            <label>Name</label>
            <input name="name" value="<?= $e($draft['name'] ?? '') ?>" required>
            <?= $err('name') ?>
        </div>
        <div class="nb-field <?= isset($errors['handle']) ? 'has-error' : '' ?>">
            <label>Handle <small class="nb-muted">(used in URLs &amp; the API)</small></label>
            <input name="handle" value="<?= $e($draft['handle'] ?? '') ?>" <?= $editing ? 'readonly' : '' ?> placeholder="auto from name">
            <?= $err('handle') ?>
        </div>
        <div class="nb-field">
            <label>Icon</label>
            <input name="icon" value="<?= $e($draft['icon'] ?? '❑') ?>" maxlength="4">
        </div>
        <div class="nb-field">
            <label>Description</label>
            <input name="description" value="<?= $e($draft['description'] ?? '') ?>">
        </div>
    </div>

    <div class="nb-field">
        <label>Type</label>
        <select name="kind">
            <option value="collection" <?= !$isSingle ? 'selected' : '' ?>>Collection — many entries (Posts, Products…)</option>
            <option value="single" <?= $isSingle ? 'selected' : '' ?>>Single — exactly one entry (Homepage, Settings…)</option>
        </select>
    </div>

    <div class="nb-field">
        <label>Managed by <small class="nb-muted">— which roles may add/edit entries (admins always can)</small></label>
        <div class="nb-checks">
            <?php foreach ($roles as $role): ?>
                <?php if ($role === 'admin') { continue; } ?>
                <label class="nb-check"><input type="checkbox" name="roles[]" value="<?= $e($role) ?>" <?= in_array($role, $manageRoles, true) ? 'checked' : '' ?>> <?= $e(ucfirst($role)) ?></label>
            <?php endforeach; ?>
        </div>
    </div>

    <h2 class="nb-section-title">Fields</h2>
    <div class="nb-fields" id="nb-fields">
        <?php foreach ($draftFields as $i => $f): ?>
            <?php include __DIR__ . '/_field_row.php'; ?>
        <?php endforeach; ?>
    </div>
    <button type="button" class="nb-btn" id="nb-add-field">+ Add field</button>

    <div class="nb-form-actions">
        <button type="submit" class="nb-btn nb-btn-primary"><?= $editing ? 'Save collection' : 'Create collection' ?></button>
        <a class="nb-btn" href="/admin/collections">Cancel</a>
    </div>
</form>

<template id="nb-field-template"><?php $i = '__i__'; $f = null; include __DIR__ . '/_field_row.php'; ?></template>

<script>
(function () {
    var choiceTypes   = <?= json_encode($choiceTypes) ?>;
    var relationTypes = <?= json_encode($relationTypes) ?>;
    var list = document.getElementById('nb-fields');
    var tpl  = document.getElementById('nb-field-template');
    var next = 1000;

    function slugify(s) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, ''); }

    function wire(row) {
        var type     = row.querySelector('[data-type]');
        var choices  = row.querySelector('[data-choices]');
        var relation = row.querySelector('[data-relation]');
        var more     = row.querySelector('[data-more]');
        var label    = row.querySelector('[data-label]');
        var handle   = row.querySelector('[data-handle]');
        function toggle() {
            var isChoice = choiceTypes.indexOf(type.value) !== -1;
            var isRel    = relationTypes.indexOf(type.value) !== -1;
            choices.hidden = !isChoice;
            if (relation) { relation.hidden = !isRel; }
            if ((isChoice || isRel) && more) { more.open = true; }
        }
        type.addEventListener('change', toggle); toggle();
        row.querySelector('[data-remove]').addEventListener('click', function () { row.remove(); });
        if (label && handle && !handle.readOnly) {
            label.addEventListener('input', function () { if (!handle.dataset.touched) handle.value = slugify(label.value); });
            handle.addEventListener('input', function () { handle.dataset.touched = '1'; });
        }
    }

    Array.prototype.forEach.call(list.querySelectorAll('[data-row]'), wire);

    document.getElementById('nb-add-field').addEventListener('click', function () {
        var html = tpl.innerHTML.split('__i__').join(String(next++));
        var tmp = document.createElement('div');
        tmp.innerHTML = html.trim();
        var row = tmp.firstElementChild;
        list.appendChild(row);
        wire(row);
        var l = row.querySelector('[data-label]'); if (l) l.focus();
    });
})();
</script>
