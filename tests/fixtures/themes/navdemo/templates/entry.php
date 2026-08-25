<?php /** @var array $entry @var array $nav @var callable $e */ ?>
ENTRY <?= $e($entry['title']) ?> NAV{<?php foreach (($nav ?? []) as $n) { echo $e($n['slug']), '=', $e((string) ($n['fields']['body'] ?? '')), ';'; } ?>}
