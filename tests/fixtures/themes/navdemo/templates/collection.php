<?php /** @var array $nav @var callable $e */ ?>
INDEX NAV{<?php foreach (($nav ?? []) as $n) { echo $e($n['slug']), '=', $e((string) ($n['fields']['body'] ?? '')), ';'; } ?>}
