<?php

declare(strict_types=1);

namespace Nimbus\Media;

/**
 * The one place a media item is deleted, so the usage guard is shared by every
 * caller (admin, API, MCP) instead of each re-checking. Deleting a file that is
 * still referenced by content is refused — the reference must be cleared first —
 * so an image never silently disappears from a live page.
 */
final class MediaService
{
    public function __construct(
        private MediaRepository $media,
        private MediaUsageRepository $usage,
        private string $basePath,
    ) {
    }

    /**
     * Delete a media item and its file.
     *
     * @return bool false when no such item existed
     * @throws MediaInUse when content still references it (carrying where)
     */
    public function delete(int $id): bool
    {
        $item = $this->media->find($id);
        if ($item === null) {
            return false;
        }

        $usage = $this->usage->usageOf($id);
        if ($usage !== []) {
            throw new MediaInUse($id, $usage);
        }

        // Row first, then the file: a leftover file is harmless, a row pointing
        // at a deleted file is not.
        $this->media->delete($id);
        $abs = rtrim($this->basePath, '/') . '/' . ltrim($item->path, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
        return true;
    }
}
