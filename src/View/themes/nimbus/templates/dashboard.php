<?php
/** @var array{collections:int,entries:int,media:int,users:int} $stats */
use Nimbus\View\View;

$e     = static fn (?string $v): string => View::e($v);
$cards = [
    ['label' => 'Collections', 'count' => $stats['collections'], 'url' => '/admin/collections', 'icon' => '❑'],
    ['label' => 'Entries',     'count' => $stats['entries'],     'url' => '/admin/collections', 'icon' => '✎'],
    ['label' => 'Media',       'count' => $stats['media'],       'url' => '/admin/media',       'icon' => '❖'],
    ['label' => 'Users',       'count' => $stats['users'],       'url' => '/admin/users',       'icon' => '☾'],
];
?>
<div class="nb-page-head">
    <h1>Dashboard</h1>
</div>

<div class="nb-cards">
    <?php foreach ($cards as $c): ?>
        <a class="nb-card" href="<?= $e($c['url']) ?>">
            <span class="nb-card-ic"><?= $e($c['icon']) ?></span>
            <span class="nb-card-count"><?= (int) $c['count'] ?></span>
            <span class="nb-card-label"><?= $e($c['label']) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="nb-panel">
    <h2>Welcome to <?= $e($appName) ?> ✦</h2>
    <p>Create a <strong>Collection</strong> to define a content type, add entries, and upload media — then read it all back over the headless API. Everything you manage here flies straight to your site.</p>
</div>
