<?php
/**
 * One row of the field builder. Expects: $i (index), $f (Field|null),
 * $typeChoices (type=>label), $choiceTypes (types using the choices box).
 */
use Nimbus\View\View;

$e        = static fn (?string $v): string => View::e($v);
$handle   = $f?->handle ?? '';
$label    = $f?->label ?? '';
$type     = $f?->type ?? 'text';
$required = $f?->required ?? false;
$choices  = $f ? implode("\n", (array) $f->option('choices', [])) : '';
$default  = $f ? (string) $f->option('default', '') : '';
$holder   = $f ? (string) $f->option('placeholder', '') : '';
$help     = $f ? (string) $f->option('help', '') : '';
$target   = $f ? (string) $f->option('target', '') : '';
$multiple = $f ? (bool) $f->option('multiple', false) : false;
$isRel    = in_array($type, $relationTypes ?? [], true);
$name     = 'fields[' . $i . ']';
?>
<div class="nb-field-row" data-row>
    <div class="nb-field-row-main">
        <input class="nb-fr-label" name="<?= $e($name) ?>[label]" placeholder="Field label" value="<?= $e($label) ?>" data-label>
        <input class="nb-fr-handle" name="<?= $e($name) ?>[handle]" placeholder="handle" value="<?= $e($handle) ?>" <?= ($f && ($lockHandles ?? true)) ? 'readonly title="Handle can’t change once entries exist"' : '' ?> data-handle>
        <select class="nb-fr-type" name="<?= $e($name) ?>[type]" data-type>
            <?php foreach ($typeChoices as $tk => $tl): ?>
                <option value="<?= $e($tk) ?>" <?= $type === $tk ? 'selected' : '' ?>><?= $e($tl) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="nb-check nb-fr-req"><input type="checkbox" name="<?= $e($name) ?>[required]" value="1" <?= $required ? 'checked' : '' ?>> Req.</label>
        <button type="button" class="nb-fr-remove" data-remove title="Remove field">✕</button>
    </div>
    <details class="nb-fr-more" data-more>
        <summary>Options</summary>
        <div class="nb-fr-opts">
            <input name="<?= $e($name) ?>[default]" placeholder="Default value" value="<?= $e($default) ?>">
            <input name="<?= $e($name) ?>[placeholder]" placeholder="Placeholder" value="<?= $e($holder) ?>">
            <input name="<?= $e($name) ?>[help]" placeholder="Help text" value="<?= $e($help) ?>">
            <textarea name="<?= $e($name) ?>[choices]" placeholder="One choice per line" data-choices <?= in_array($type, $choiceTypes, true) ? '' : 'hidden' ?>><?= $e($choices) ?></textarea>
            <div class="nb-fr-relation" data-relation <?= $isRel ? '' : 'hidden' ?>>
                <select name="<?= $e($name) ?>[target]">
                    <option value="">Target collection…</option>
                    <?php foreach (($collectionOptions ?? []) as $ch => $cn): ?>
                        <option value="<?= $e($ch) ?>" <?= $target === $ch ? 'selected' : '' ?>><?= $e($cn) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="nb-check"><input type="checkbox" name="<?= $e($name) ?>[multiple]" value="1" <?= $multiple ? 'checked' : '' ?>> Allow many</label>
            </div>
        </div>
    </details>
</div>
