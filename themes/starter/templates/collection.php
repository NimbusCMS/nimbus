<?php
/**
 * A collection's live entries, newest first.
 *
 * @var array{handle:string,name:string}                          $collection
 * @var list<array{slug:string,title:string,published_at:?string}> $entries
 * @var int                                                       $page
 * @var int                                                       $total_pages
 * @var callable                                                  $e  escape helper
 */
?>
<h1><?= $e($collection['name']) ?></h1>

<?php if ($entries === []): ?>
    <p>Nothing published here yet.</p>
<?php else: ?>
    <ul class="entry-list">
        <?php foreach ($entries as $entry): ?>
            <li>
                <a href="/<?= $e($collection['handle']) ?>/<?= $e($entry['slug']) ?>"><?= $e($entry['title']) ?></a>
                <?php if ($entry['published_at'] !== null): ?>
                    <br><time datetime="<?= $e($entry['published_at']) ?>"><?= $e(date('j M Y', strtotime($entry['published_at']))) ?></time>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($total_pages > 1): ?>
        <nav class="pager">
            <?php if ($page > 1): ?>
                <a href="/<?= $e($collection['handle']) ?>?page=<?= $page - 1 ?>">← Newer</a>
            <?php endif; ?>
            <?php if ($page < $total_pages): ?>
                <a href="/<?= $e($collection['handle']) ?>?page=<?= $page + 1 ?>">Older →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
