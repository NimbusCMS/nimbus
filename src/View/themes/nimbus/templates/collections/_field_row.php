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
$name     = 'fields[' . $i . ']';
?>
<div class="nb-field-row" data-row>
    <div class="nb-field-row-main">
        <input class="nb-fr-label" name="<?= $e($name) ?>[label]" placeholder="Field label" value="<?= $e($label) ?>" data-label>
        <input class="nb-fr-handle" name="<?= $e($name) ?>[handle]" placeholder="handle" value="<?= $e($handle) ?>" <?= $f ? 'readonly title="Handle can’t change once entries exist"' : '' ?> data-handle>
        <select class="nb-fr-type" name="<?= $e($name) ?>[type]" data-type>
            <?php foreach ($typeChoices as $tk => $tl): ?>
                <option value="<?= $e($tk) ?>" <?= $type === $tk ? 'selected' : '' ?>><?= $e($tl) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="nb-check nb-fr-req"><input type="checkbox" name="<?= $e($name) ?>[required]" value="1" <?= $required ? 'checked' : '' ?>> Req.</label>
        <button type="button" class="nb-fr-remove" data-remove title="Remove field">✕</button>
    </div>
    <textarea class="nb-fr-choices" name="<?= $e($name) ?>[choices]" placeholder="One choice per line" data-choices <?= in_array($type, $choiceTypes, true) ? '' : 'hidden' ?>><?= $e($choices) ?></textarea>
</div>
