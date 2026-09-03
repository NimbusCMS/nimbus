<?php
/** Generic entry template (fixture). @var array{title:string} $entry @var callable $e @var array<string,array<string,mixed>> $contrib */
$contrib = $contrib ?? [];
?>
GENERIC ENTRY: <?= $e($entry['title']) ?><?php if (isset($contrib['test.viewdata']['marker'])): ?> CONTRIB:<?= $e($contrib['test.viewdata']['marker']) ?><?php endif; ?>
