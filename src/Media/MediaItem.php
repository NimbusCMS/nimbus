<?php

declare(strict_types=1);

namespace Nimbus\Media;

/**
 * One stored media file, as a value object (an nb_media row).
 *
 * `filename` is the original name the uploader chose, kept only for display.
 * `path` and `url` point at the actual stored file, whose name is random — the
 * uploader never trusts the client's filename for where a file lands on disk.
 */
final readonly class MediaItem
{
    public function __construct(
        public int $id,
        public string $filename,
        public string $path,
        public string $url,
        public string $mime,
        public int $size,
        public ?int $width,
        public ?int $height,
        public ?string $alt,
        public ?string $title,
        public string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['filename'],
            (string) $row['path'],
            (string) $row['url'],
            (string) $row['mime'],
            (int) $row['size'],
            isset($row['width']) ? (int) $row['width'] : null,
            isset($row['height']) ? (int) $row['height'] : null,
            $row['alt'] ?? null,
            $row['title'] ?? null,
            (string) $row['created_at'],
        );
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
