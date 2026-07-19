<?php
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);
?>
<div class="nb-page-head">
    <h1><?= $e($title) ?></h1>
</div>
<div class="nb-empty-panel">
    <span class="nb-empty-ic">✦</span>
    <h2><?= $e($title) ?> is being conjured</h2>
    <p>This section isn’t wired up yet — it’s next on the roadmap.</p>
</div>
