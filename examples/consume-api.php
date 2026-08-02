<?php

declare(strict_types=1);

/**
 * A tiny "another application" that reads content from NimbusCMS over the
 * headless API — the consumer side of the vertical slice.
 *
 * It speaks only HTTP + JSON and knows nothing about Nimbus's internals: give
 * it a base URL, an API token and a collection handle, and it prints the live
 * entries. This is the whole point of the API — your frontend, mobile app or
 * static-site generator is just an HTTP client.
 *
 *   php examples/consume-api.php http://localhost:8080 nbt_xxx articles
 *
 * Mint a token with:  php bin/nimbus token:create --name="My app"
 */

[$self, $base, $token, $handle] = $argv + [null, null, null, 'posts'];

if ($base === null || $token === null) {
    fwrite(STDERR, "Usage: php examples/consume-api.php <base-url> <api-token> [collection-handle]\n");
    exit(1);
}

$base = rtrim($base, '/');

/** GET a JSON path from the API, or exit with a readable error. */
function api(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
    ]);
    $body   = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);
    if ($status !== 200) {
        $message = $json['error']['message'] ?? $body;
        fwrite(STDERR, "API error {$status}: {$message}\n");
        exit(1);
    }
    return is_array($json) ? $json : [];
}

// 1. List the live entries in the collection.
$list = api("{$base}/api/v1/collections/{$handle}/entries?per_page=10", $token);

printf("\n%d live entr%s in \"%s\" (page %d/%d)\n\n",
    $list['meta']['total'],
    $list['meta']['total'] === 1 ? 'y' : 'ies',
    $handle,
    $list['meta']['page'],
    max(1, $list['meta']['total_pages']),
);

foreach ($list['data'] as $entry) {
    printf("  • %-30s  /%s  (%s)\n", $entry['title'], $entry['slug'], $entry['published_at']);
}

if ($list['data'] === []) {
    echo "  (nothing published yet)\n";
    exit(0);
}

// 2. Fetch the newest one in full, to show single-entry retrieval + fields.
$slug   = $list['data'][0]['slug'];
$single = api("{$base}/api/v1/collections/{$handle}/entries/{$slug}", $token)['data'];

echo "\nNewest entry in full:\n";
echo "  title: {$single['title']}\n";
foreach ($single['fields'] as $handle => $value) {
    // A media field arrives as an object with a ready-to-use url.
    if (is_array($value) && isset($value['url'])) {
        printf("  %s: %s  (%s)\n", $handle, $value['url'], $value['mime'] ?? 'file');
        continue;
    }
    $rendered = is_scalar($value) ? (string) $value : json_encode($value);
    printf("  %s: %s\n", $handle, mb_strimwidth((string) $rendered, 0, 70, '…'));
}
echo "\n";
