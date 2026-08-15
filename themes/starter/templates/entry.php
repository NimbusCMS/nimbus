<?php
/**
 * A single entry.
 *
 * The view-model is the same shape the read API serializes: scalars, `null`,
 * an expanded media object, or a list of expanded relation targets. The theme
 * decides how each looks — it never queries for them.
 *
 * @var array{handle:string,name:string} $collection
 * @var array{title:string,published_at:?string,fields:array<string,mixed>} $entry
 * @var callable $e escape helper
 */

/** Render one prepared field value. Presentation only — no logic beyond shape. */
$renderField = static function (mixed $value) use ($e): string {
    if ($value === null || $value === '' || $value === []) {
        return '';
    }
    // An expanded media object.
    if (is_array($value) && isset($value['url'])) {
        return '<img src="' . $e((string) $value['url']) . '" alt="' . $e((string) ($value['alt'] ?? '')) . '">';
    }
    // A list of expanded relation targets ({id, slug, title}).
    if (is_array($value) && isset($value[0]) && is_array($value[0])) {
        $titles = array_map(static fn (array $t): string => $e((string) ($t['title'] ?? '')), $value);
        return implode(', ', $titles);
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if (is_scalar($value)) {
        return nl2br($e((string) $value));
    }
    return '';
};
?>
<article>
    <h1><?= $e($entry['title']) ?></h1>
    <?php if ($entry['published_at'] !== null): ?>
        <time datetime="<?= $e($entry['published_at']) ?>"><?= $e(date('j M Y', strtotime($entry['published_at']))) ?></time>
    <?php endif; ?>

    <?php foreach ($entry['fields'] as $handle => $value): ?>
        <?php $rendered = $renderField($value); ?>
        <?php if ($rendered !== ''): ?>
            <section class="field field-<?= $e($handle) ?>">
                <?= $rendered ?>
            </section>
        <?php endif; ?>
    <?php endforeach; ?>
</article>

<p><a href="/<?= $e($collection['handle']) ?>">← <?= $e($collection['name']) ?></a></p>
