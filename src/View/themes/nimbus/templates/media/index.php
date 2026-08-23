<?php
/**
 * @var \Nimbus\Media\MediaItem[] $items
 * @var string                    $maxLabel
 * @var ?string                   $flash
 * @var ?string                   $error
 * @var string                    $csrf
 */
use Nimbus\View\View;

$e = static fn (?string $v): string => View::e($v);

$human = static function (int $bytes): string {
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
};
?>
<div class="nb-page-head">
    <h1>Media</h1>
</div>

<?php if ($error !== null): ?>
    <div class="nb-alert nb-alert-error"><?= $e($error) ?></div>
<?php elseif ($flash === 'uploaded'): ?>
    <div class="nb-alert nb-alert-ok">File uploaded.</div>
<?php elseif ($flash === 'deleted'): ?>
    <div class="nb-alert nb-alert-ok">File deleted.</div>
<?php endif; ?>

<form class="nb-media-upload" method="post" action="/admin/media" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <div class="nb-field">
        <label>Upload a file <small class="nb-muted">— JPEG, PNG, GIF, WebP or PDF, up to <?= $e($maxLabel) ?></small></label>
        <input type="file" name="file" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf" required>
    </div>
    <div class="nb-field">
        <label>Alt text <small class="nb-muted">— optional, for accessibility</small></label>
        <input type="text" name="alt" placeholder="Describe the image">
    </div>
    <button type="submit" class="nb-btn nb-btn-primary">Upload</button>
</form>

<?php if ($items === []): ?>
    <div class="nb-empty-panel">
        <span class="nb-empty-ic">❖</span>
        <h2>No media yet</h2>
        <p>Upload an image or PDF above. Files are available to any collection's
           media fields and served through the API.</p>
    </div>
<?php else: ?>
    <div class="nb-media-grid">
        <?php foreach ($items as $item): ?>
            <figure class="nb-media-card">
                <div class="nb-media-thumb">
                    <?php if ($item->isImage()): ?>
                        <img src="<?= $e($item->url) ?>" alt="<?= $e($item->alt ?? $item->filename) ?>" loading="lazy">
                    <?php else: ?>
                        <span class="nb-media-file">PDF</span>
                    <?php endif; ?>
                </div>
                <figcaption>
                    <span class="nb-media-name" title="<?= $e($item->filename) ?>"><?= $e($item->filename) ?></span>
                    <span class="nb-muted">
                        <?= $human($item->size) ?><?php if ($item->width !== null): ?> · <?= (int) $item->width ?>×<?= (int) $item->height ?><?php endif; ?>
                    </span>
                    <form method="post" action="/admin/media/<?= (int) $item->id ?>/delete" data-confirm="Delete this file?">
                        <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                        <button type="submit" class="nb-link-danger">Delete</button>
                    </form>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
