<?php

declare(strict_types=1);

namespace Nimbus\Site;

use Nimbus\Database\Connection;
use Nimbus\Support\Config;

/**
 * Editable navigation menus — the DB-backed store behind the admin Menus editor,
 * mirroring how {@see \Nimbus\Settings\Settings} overrides file-default settings.
 *
 * `config/menus.php` is the seed/default; a `nb_menus` row overrides that menu by
 * name. The site renders whatever {@see all} returns, so an admin edit shows up
 * with the file as fallback.
 *
 * Every URL is **scheme-validated** ({@see safeUrl}) both on save and again on
 * read, so a menu link can only ever be `http(s)`, root-relative, `mailto:`,
 * `tel:`, or a `#fragment` — never a `javascript:`/`data:` payload rendered into
 * `<a href>` on a public page. Malformed rows and over-long/over-many items fail
 * safe (dropped), so a bad row never 500s a public page.
 */
final class Menus
{
    /** Menu names the editor manages and the themes consume. */
    public const EDITABLE = ['main', 'footer'];

    private const MAX_ITEMS = 50;
    private const MAX_LEN   = 200;

    public function __construct(private Connection $db)
    {
    }

    /**
     * Every menu, file defaults overridden per-name by DB rows, each item cleaned
     * and scheme-validated on the way out.
     *
     * @return array<string, list<array{label:string,url:string}>>
     */
    public function all(): array
    {
        /** @var array<string, list<array{label:string,url:string}>> $out */
        $out = Config::menus();
        foreach ($this->db->select('SELECT name, items FROM nb_menus') as $row) {
            $out[(string) $row['name']] = $this->decode((string) $row['items']);
        }
        foreach ($out as $name => $items) {
            $out[$name] = $this->clean($items);
        }
        return $out;
    }

    /** @return list<array{label:string,url:string}> */
    public function get(string $name): array
    {
        return $this->all()[$name] ?? [];
    }

    /**
     * Store a menu (upsert). Items are cleaned + scheme-validated first, so an
     * unsafe or malformed item is never persisted.
     *
     * @param list<array{label:string,url:string}> $items
     */
    public function save(string $name, array $items): void
    {
        $json = json_encode(array_values($this->clean($items)), JSON_THROW_ON_ERROR);
        $now  = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO nb_menus (name, items, updated_at) VALUES (:n, :i, :u)
             ON DUPLICATE KEY UPDATE items = :i2, updated_at = :u2',
            ['n' => $name, 'i' => $json, 'u' => $now, 'i2' => $json, 'u2' => $now],
        );
    }

    /**
     * Validate a link URL by scheme, returning the URL or null (rejected).
     * Allowed: `http(s)://…`, a root-relative `/…` (but not protocol-relative
     * `//…`), `mailto:`/`tel:`, and a bare `#fragment`. Everything else —
     * `javascript:`, `data:`, `vbscript:`, control chars, or anything over-long —
     * is rejected, so it can never become an executable `href`.
     */
    public static function safeUrl(string $url): ?string
    {
        $u = trim($url);
        if ($u === '' || mb_strlen($u) > self::MAX_LEN) {
            return null;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $u) === 1) {
            return null; // control characters (incl. the tab/newline tricks around "javascript:")
        }
        if ($u[0] === '#') {
            return $u; // same-page fragment
        }
        if ($u[0] === '/') {
            return str_starts_with($u, '//') ? null : $u; // root-relative, but never protocol-relative
        }
        if (preg_match('#^https?://#i', $u) === 1 || preg_match('#^(mailto|tel):#i', $u) === 1) {
            return $u;
        }
        return null;
    }

    /**
     * Normalise a list of items: drop non-arrays, empties, and unsafe URLs; clip
     * label/url length; cap the count. The one place item shape is enforced.
     *
     * @param mixed $items
     * @return list<array{label:string,url:string}>
     */
    private function clean(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $safe  = self::safeUrl((string) ($item['url'] ?? ''));
            if ($label === '' || $safe === null) {
                continue;
            }
            $out[] = ['label' => mb_substr($label, 0, self::MAX_LEN), 'url' => $safe];
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Decode a stored items JSON, fail-safe to an empty list on anything malformed.
     *
     * @return list<array{label:string,url:string}>
     */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return $this->clean($decoded);
    }
}
