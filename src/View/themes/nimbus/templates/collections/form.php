<?php
/**
 * @var \Nimbus\Content\Collection|null $collection
 * @var array<string,string>            $typeChoices
 * @var string[]                        $choiceTypes
 * @var string[]                        $roles
 * @var string                          $csrf
 */
use Nimbus\View\View;

$e           = static fn (?string $v): string => View::e($v);
$editing     = $collection !== null;
$action      = $editing ? '/admin/collections/' . $collection->id : '/admin/collections';
$manageRoles = $editing ? $collection->managerRoles() : [];
?>
<div class="nb-page-head">
    <h1><?= $editing ? 'Edit' : 'New' ?> collection</h1>
    <a class="nb-btn" href="/admin/collections">← Back</a>
</div>

<form class="nb-form-card" method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">

    <div class="nb-grid-2">
        <div class="nb-field">
            <label>Name</label>
            <input name="name" value="<?= $e($editing ? $collection->name : '') ?>" required>
        </div>
        <div class="nb-field">
            <label>Handle <small class="nb-muted">(used in URLs &amp; the API)</small></label>
            <input name="handle" value="<?= $e($editing ? $collection->handle : '') ?>" <?= $editing ? 'readonly' : '' ?> placeholder="auto from name">
        </div>
        <div class="nb-field">
            <label>Icon</label>
            <input name="icon" value="<?= $e($editing ? $collection->icon : '❑') ?>" maxlength="4">
        </div>
        <div class="nb-field">
            <label>Description</label>
            <input name="description" value="<?= $e($editing ? $collection->description : '') ?>">
        </div>
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
        <?php foreach (($editing ? $collection->fields : []) as $i => $f): ?>
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
    var choiceTypes = <?= json_encode($choiceTypes) ?>;
    var list = document.getElementById('nb-fields');
    var tpl  = document.getElementById('nb-field-template');
    var next = 1000;

    function slugify(s) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, ''); }

    function wire(row) {
        var type    = row.querySelector('[data-type]');
        var choices = row.querySelector('[data-choices]');
        var label   = row.querySelector('[data-label]');
        var handle  = row.querySelector('[data-handle]');
        function toggle() { choices.hidden = choiceTypes.indexOf(type.value) === -1; }
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
