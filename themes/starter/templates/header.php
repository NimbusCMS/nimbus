<?php
/**
 * Site header — included by layout via $partial('header').
 *
 * @var string   $appName the site name
 * @var callable $e       escape a value for output
 */
?>
<header>
    <a href="/"><?= $e($appName) ?></a>
</header>
