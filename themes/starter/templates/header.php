<?php
/**
 * Site header — included by layout via $partial('header').
 *
 * @var string   $appName the site name
 * @var array<string,list<array{label:string,url:string}>> $menus named menus
 * @var callable $e       escape a value for output
 */
$main = ($menus ?? [])['main'] ?? [];
?>
<header>
    <a class="brand" href="/"><?= $e($appName) ?></a>
    <?php if ($main !== []): ?>
        <nav class="site-nav">
            <?php foreach ($main as $item): ?>
                <a href="<?= $e($item['url']) ?>"><?= $e($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</header>
