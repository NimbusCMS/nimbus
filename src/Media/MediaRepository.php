<?php

declare(strict_types=1);

namespace Nimbus\Media;

use Nimbus\Database\Connection;

/** Stores and reads media metadata. The bytes live on disk; this is the index. */
final class MediaRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @param array<string,mixed> $attrs */
    public function create(array $attrs, ?int $authorId): int
    {
        return $this->db->insert(
            'INSERT INTO nb_media (filename, path, url, mime, size, width, height, alt, title, author_id, created_at)
             VALUES (:f, :p, :u, :m, :s, :w, :h, :a, :t, :au, :c)',
            [
                'f' => $attrs['filename'], 'p' => $attrs['path'], 'u' => $attrs['url'], 'm' => $attrs['mime'],
                's' => $attrs['size'], 'w' => $attrs['width'] ?? null, 'h' => $attrs['height'] ?? null,
                'a' => $attrs['alt'] ?? null, 't' => $attrs['title'] ?? null, 'au' => $authorId,
                'c' => date('Y-m-d H:i:s'),
            ],
        );
    }

    /** @return MediaItem[] newest first */
    public function all(): array
    {
        return array_map(
            static fn (array $row): MediaItem => MediaItem::fromRow($row),
            $this->db->select('SELECT * FROM nb_media ORDER BY created_at DESC, id DESC'),
        );
    }

    public function find(int $id): ?MediaItem
    {
        $row = $this->db->selectOne('SELECT * FROM nb_media WHERE id = :id', ['id' => $id]);
        return $row !== null ? MediaItem::fromRow($row) : null;
    }

    /**
     * @param int[] $ids
     * @return array<int,MediaItem> keyed by id, for resolving a field's references
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        // Named placeholders — Connection binds by name, not position.
        $params = [];
        foreach ($ids as $i => $id) {
            $params["id{$i}"] = $id;
        }
        $in    = implode(',', array_map(static fn (int $i): string => ":id{$i}", array_keys($ids)));
        $items = [];
        foreach ($this->db->select("SELECT * FROM nb_media WHERE id IN ({$in})", $params) as $row) {
            $item = MediaItem::fromRow($row);
            $items[$item->id] = $item;
        }
        return $items;
    }

    /** Delete the metadata row; the caller removes the file. Returns rows removed. */
    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM nb_media WHERE id = :id', ['id' => $id]);
    }
}
