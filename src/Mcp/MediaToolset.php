<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Media\MediaInUse;
use Nimbus\Media\MediaItem;
use Nimbus\Media\MediaRepository;
use Nimbus\Media\MediaService;
use Nimbus\Media\MediaUploader;
use Nimbus\Media\MediaUsageRepository;
use Nimbus\Media\UploadError;
use Nimbus\Support\Config;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The MCP media tools (ADR 0009, Slice 5b) — the library over JSON-RPC, gated on
 * the `media:read` / `media:write` capabilities.
 *
 * Upload is base64: MCP has no multipart, so bytes arrive in the JSON, are size-
 * capped, written to a temp file, and handed to the *same* {@see MediaUploader}
 * the admin uses. All its safety is reused unchanged — the type is sniffed from
 * the real bytes (finfo) against a fixed allow-list (no SVG), the stored name is
 * random, the size cap is enforced. The only deliberate change is a copy-based
 * mover instead of move_uploaded_file, because the bytes are a legitimate token
 * upload, not an HTTP file upload.
 *
 * Delete goes through the shared {@see MediaService}, so an agent can no more
 * orphan an in-use file than an admin can — it's refused, with the usage
 * pinpointed (and `media_usage` lets the agent check first).
 */
final class MediaToolset implements Toolset
{
    private const CAPABILITY = 'media';
    /** @var list<string> */
    private const READ_TOOLS = ['list_media', 'get_media', 'media_usage'];
    /** @var list<string> */
    private const WRITE_TOOLS = ['upload_media', 'delete_media'];

    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 50;

    public function __construct(
        private MediaRepository $media,
        private MediaUploader $uploader,
        private MediaService $service,
        private MediaUsageRepository $usage,
        private EventDispatcher $events,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function definitions(TokenPrincipal $principal): array
    {
        $tools = [];
        if ($principal->can(self::CAPABILITY, 'read')) {
            $tools[] = $this->listDefinition();
            $tools[] = $this->getDefinition();
            $tools[] = $this->usageDefinition();
        }
        if ($principal->can(self::CAPABILITY, 'write')) {
            $tools[] = $this->uploadDefinition();
            $tools[] = $this->deleteDefinition();
        }
        return $tools;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function call(string $name, array $args, TokenPrincipal $principal, EntryOpContext $ctx): ?array
    {
        $action = in_array($name, self::READ_TOOLS, true) ? 'read'
            : (in_array($name, self::WRITE_TOOLS, true) ? 'write' : null);
        if ($action === null) {
            return null; // not a media tool — defer to the next toolset
        }
        if (!$principal->can(self::CAPABILITY, $action)) {
            $this->events->emitBestEffort(CoreEvents::API_ACCESS_DENIED, [
                'token_id'   => $principal->tokenId,
                'token_name' => $principal->name,
                'resource'   => self::CAPABILITY,
                'action'     => $action,
                'ip'         => $ctx->ip,
                'path'       => $ctx->path,
                'at'         => date('Y-m-d H:i:s'),
            ]);
            throw new McpError(JsonRpc::INVALID_PARAMS, "Unknown tool \"{$name}\".");
        }

        return match ($name) {
            'list_media'   => $this->listMedia($args),
            'get_media'    => $this->getMedia($args),
            'media_usage'  => $this->mediaUsage($args),
            'upload_media' => $this->uploadMedia($args, $principal, $ctx),
            'delete_media' => $this->deleteMedia($args, $principal, $ctx),
            default        => null,
        };
    }

    // --------------------------------------------------------------- execution

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function listMedia(array $args): array
    {
        $all     = $this->media->all();
        $perPage = $this->boundPerPage($this->intArg($args, 'per_page', 0));
        $page    = max(1, $this->intArg($args, 'page', 1));
        $slice   = array_slice($all, ($page - 1) * $perPage, $perPage);

        return ToolResult::ok([
            'data' => array_map(fn (MediaItem $m): array => $this->summary($m), $slice),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => count($all)],
        ]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function getMedia(array $args): array
    {
        $item = $this->media->find($this->intArg($args, 'id', 0));
        return $item === null
            ? ToolResult::error('No such media item.', 'not_found')
            : ToolResult::ok(['data' => $this->summary($item)]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function mediaUsage(array $args): array
    {
        $id = $this->intArg($args, 'id', 0);
        if ($this->media->find($id) === null) {
            return ToolResult::error('No such media item.', 'not_found');
        }
        return ToolResult::ok(['media_id' => $id, 'usage' => $this->usage->usageOf($id)]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function uploadMedia(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $filename = trim($this->strArg($args, 'filename'));
        $data     = $this->strArg($args, 'data');
        $alt      = $this->strArg($args, 'alt');
        if ($filename === '' || $data === '') {
            return ToolResult::error('An upload needs a filename and base64 data.', 'invalid');
        }

        // Bound the payload before decoding so a huge string can't exhaust memory
        // (base64 is ~4/3 of the bytes). The uploader re-checks the real size.
        if (strlen($data) * 3 / 4 > Config::uploadMaxBytes()) {
            return ToolResult::error('The file is larger than the allowed maximum.', 'invalid');
        }
        $bytes = base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            return ToolResult::error('The data is not valid base64.', 'invalid');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nimbus_mcp_');
        if ($tmp === false) {
            return ToolResult::error('The upload could not be staged.', 'invalid');
        }
        try {
            file_put_contents($tmp, $bytes);
            // The uploader sniffs the real bytes (finfo) + allow-lists the type;
            // an untrusted filename/mime never decides what is stored.
            $id   = $this->uploader->store(['tmp_name' => $tmp, 'name' => $filename, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK], null, $alt !== '' ? $alt : null);
            $item = $this->media->find($id);
        } catch (UploadError $e) {
            return ToolResult::error($e->getMessage(), 'invalid');
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $this->announce($principal, $ctx, 'upload_media', (string) $id);
        return $item === null
            ? ToolResult::error('The upload could not be read back.', 'invalid')
            : ToolResult::ok(['data' => $this->summary($item)]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function deleteMedia(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $id = $this->intArg($args, 'id', 0);
        try {
            if (!$this->service->delete($id)) {
                return ToolResult::error('No such media item.', 'not_found');
            }
        } catch (MediaInUse $e) {
            // Blocked, not orphaned — pinpoint where so the agent can detach first.
            return ToolResult::error($e->getMessage(), 'in_use', [], ['usage' => $e->usage]);
        }
        $this->announce($principal, $ctx, 'delete_media', (string) $id);
        return ToolResult::ok(['deleted' => $id]);
    }

    // ----------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function summary(MediaItem $m): array
    {
        return [
            'id'       => $m->id,
            'filename' => $m->filename,
            'url'      => $m->url,
            'mime'     => $m->mime,
            'size'     => $m->size,
            'width'    => $m->width,
            'height'   => $m->height,
            'alt'      => $m->alt,
        ];
    }

    private function announce(TokenPrincipal $principal, EntryOpContext $ctx, string $action, string $target): void
    {
        $this->events->emitBestEffort(CoreEvents::API_MANAGEMENT_WRITTEN, [
            'token_id'   => $principal->tokenId,
            'token_name' => $principal->name,
            'capability' => self::CAPABILITY,
            'action'     => $action,
            'target'     => $target,
            'ip'         => $ctx->ip,
            'path'       => $ctx->path,
            'at'         => date('Y-m-d H:i:s'),
        ]);
    }

    private function boundPerPage(int $requested): int
    {
        if ($requested < 1) {
            $requested = self::DEFAULT_PER_PAGE;
        }
        return min($requested, self::MAX_PER_PAGE);
    }

    /** @param array<string,mixed> $args */
    private function intArg(array $args, string $key, int $default): int
    {
        return isset($args[$key]) && is_numeric($args[$key]) ? (int) $args[$key] : $default;
    }

    /** @param array<string,mixed> $args */
    private function strArg(array $args, string $key): string
    {
        return is_string($args[$key] ?? null) ? $args[$key] : '';
    }

    // ------------------------------------------------------------- definitions

    /** @return array<string,mixed> */
    private function listDefinition(): array
    {
        return $this->tool('list_media', 'List media library items (newest first), with their URLs and metadata.', [
            'type'       => 'object',
            'properties' => [
                'page'     => ['type' => 'integer', 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function getDefinition(): array
    {
        return $this->tool('get_media', 'Get one media item by id — its URL and metadata.', [
            'type'       => 'object',
            'required'   => ['id'],
            'properties' => ['id' => ['type' => 'integer']],
        ]);
    }

    /** @return array<string,mixed> */
    private function usageDefinition(): array
    {
        return $this->tool('media_usage', 'List where a media item is used (entry + field) — check before deleting.', [
            'type'       => 'object',
            'required'   => ['id'],
            'properties' => ['id' => ['type' => 'integer']],
        ]);
    }

    /** @return array<string,mixed> */
    private function uploadDefinition(): array
    {
        return $this->tool('upload_media', 'Upload a file to the media library. The type is verified from the bytes; accepted: JPEG, PNG, GIF, WebP, PDF.', [
            'type'       => 'object',
            'required'   => ['filename', 'data'],
            'properties' => [
                'filename' => ['type' => 'string', 'description' => 'Display name; the stored name is generated.'],
                'data'     => ['type' => 'string', 'description' => 'The file bytes, base64-encoded.'],
                'alt'      => ['type' => 'string', 'description' => 'Alt text.'],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function deleteDefinition(): array
    {
        return $this->tool('delete_media', 'Delete a media item. Refused if content still references it — call media_usage first and detach those references.', [
            'type'       => 'object',
            'required'   => ['id'],
            'properties' => ['id' => ['type' => 'integer']],
        ]);
    }

    /**
     * @param array<string,mixed> $inputSchema
     * @return array<string,mixed>
     */
    private function tool(string $name, string $description, array $inputSchema): array
    {
        return ['name' => $name, 'description' => $description, 'inputSchema' => $inputSchema];
    }
}
