<?php

declare(strict_types=1);

/**
 * Deterministic content seed for the performance workflow (.github/workflows/
 * performance.yml). Creates a fixed, representative set of the page *types*
 * NimbusCMS renders, so Lighthouse numbers are comparable release-to-release:
 *
 *   /                     the site home — a collection index (the common landing)
 *   /blog/sample-post-1   a single entry (detail) page
 *   /blog?page=2          a paginated deep index
 *
 * Idempotent: safe to re-run. Run against an installed instance (bin/nimbus
 * install has already created the schema + admin).
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Database\Connection;
use Nimbus\Support\Config;
use Nimbus\Support\Env;

Env::load(Config::basePath() . '/.env');

const ENTRY_COUNT = 25; // > PER_PAGE (20) so ?page=2 exists

$db   = new Connection(Config::db());
$repo = new CollectionRepository($db);
$svc  = new CollectionService($db, $repo);

$collection = $repo->findByHandle('blog');
if ($collection === null) {
    $id = $svc->create('blog', 'Blog', '📝', 'The NimbusCMS performance-fixture blog', [
        'kind'        => 'collection',
        'permissions' => ['manage' => []],
    ], [
        ['handle' => 'body', 'label' => 'Body', 'type' => 'textarea', 'required' => false, 'options' => []],
    ]);
    $collection = $repo->find($id);
}
$cid = $collection->id;

$paragraph = str_repeat(
    'NimbusCMS renders this page as plain server HTML with one small stylesheet and no client JavaScript. ',
    6,
);

$have = (int) $db->selectOne('SELECT COUNT(*) AS n FROM nb_entries WHERE collection_id = :c', ['c' => $cid])['n'];
for ($i = $have + 1; $i <= ENTRY_COUNT; $i++) {
    $db->execute(
        "INSERT INTO nb_entries (collection_id, title, slug, status, data, published_at, created_at, updated_at)
         VALUES (:c, :t, :s, 'published', :d, NOW(), NOW(), NOW())",
        [
            'c' => $cid,
            't' => "Sample Post {$i}",
            's' => "sample-post-{$i}",
            'd' => json_encode(['body' => "Post number {$i}. {$paragraph}"], JSON_THROW_ON_ERROR),
        ],
    );
}

$db->execute(
    "INSERT INTO nb_settings (`key`, `value`) VALUES ('site.home', 'blog')
     ON DUPLICATE KEY UPDATE `value` = 'blog'",
);

$total = (int) $db->selectOne('SELECT COUNT(*) AS n FROM nb_entries WHERE collection_id = :c', ['c' => $cid])['n'];
fwrite(STDOUT, "perf seed: collection 'blog', {$total} published entries, site.home=blog\n");
