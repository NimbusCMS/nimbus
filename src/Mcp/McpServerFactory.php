<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Api\EntryOperations;
use Nimbus\Auth\RoleRepository;
use Nimbus\Auth\UserRepository;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;
use Nimbus\Mcp\Guide\CoreGuide;
use Nimbus\Mcp\Guide\GuideLibrary;
use Nimbus\Media\MediaRepository;
use Nimbus\Media\MediaService;
use Nimbus\Media\MediaUploader;
use Nimbus\Media\MediaUsageRepository;
use Nimbus\Settings\Settings;
use Nimbus\Settings\SettingsRegistry;
use Nimbus\Support\Config;
use Nimbus\Support\EventDispatcher;

/**
 * Builds the one {@see McpServer} both transports serve (ADR 0009, ADR 0013).
 *
 * There were two hand-rolled assembly sites — `ApiController` (HTTP) and
 * `bin/nimbus` (stdio) — and they drifted: the stdio site was left constructing
 * `UsersToolset` with the wrong argument count after the toolset grew roles
 * support, so `nimbus mcp` crashed at startup. This factory is the single place
 * that composes the toolset list, the agent-guidance library, and the server
 * version, so the two transports cannot diverge again. Both sites now call
 * {@see build()} and add nothing of their own.
 *
 * Toolsets are ordered **management-first** so a fixed management name (e.g.
 * `create_collection`) is claimed before a content verb could parse it.
 */
final class McpServerFactory
{
    /**
     * @param Settings $settings the composed-once settings store (SUP-10), shared
     *   with the web kernel — not rebuilt here.
     * @param EntryOperations $ops the shared write path, so HTTP and MCP enforce
     *   identical rules.
     */
    public static function build(
        Connection $db,
        FieldTypeRegistry $types,
        EventDispatcher $events,
        Settings $settings,
        EntryOperations $ops,
        string $version,
        string $basePath,
    ): McpServer {
        $collections = new CollectionRepository($db);
        $mediaRepo   = new MediaRepository($db);
        $mediaUsage  = new MediaUsageRepository($db);
        // MCP uploads are base64, not HTTP file uploads, so a copy mover replaces
        // the default is_uploaded_file/move_uploaded_file — the uploader still
        // sniffs + allow-lists the bytes.
        $uploader = new MediaUploader($mediaRepo, Config::uploadPath(), Config::uploadUrl(), Config::uploadMaxBytes(), static fn (string $from, string $to): bool => copy($from, $to));

        $guide = new GuideLibrary(
            CoreGuide::instructions($basePath),
            CoreGuide::document($basePath),
        );

        return new McpServer(
            $guide,
            $version,
            new SchemaToolset($collections, new CollectionService($db, $collections), $types, $events),
            new MediaToolset($mediaRepo, $uploader, new MediaService($mediaRepo, $mediaUsage, Config::basePath()), $mediaUsage, $events),
            new UsersToolset(new UserRepository($db), new RoleRepository($db), $db, $events),
            new TokensToolset(new ApiTokenRepository($db), new RoleRepository($db), $events),
            new SettingsToolset($settings, new SettingsRegistry($collections), $events),
            new ContentToolset($collections, $types, $ops),
        );
    }
}
