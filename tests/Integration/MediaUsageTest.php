<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Media\MediaInUse;
use Nimbus\Media\MediaRepository;
use Nimbus\Media\MediaService;
use Nimbus\Media\MediaUsageReindexer;
use Nimbus\Media\MediaUsageRepository;
use Nimbus\Support\Config;
use Nimbus\Support\EventDispatcher;

/**
 * Core media-usage tracking (Slice 5a): EntryService keeps the reverse index in
 * step on every save (mirroring relations), and the shared MediaService refuses
 * to delete a file that content still references — pinpointing where — so no
 * path can silently orphan an in-use image.
 */
final class MediaUsageTest extends IntegrationTestCase
{
    private EntryService $service;
    private CollectionRepository $collections;
    private MediaRepository $media;
    private MediaUsageRepository $usage;
    private MediaService $mediaService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collections  = new CollectionRepository($this->db);
        $this->media        = new MediaRepository($this->db);
        $this->usage        = new MediaUsageRepository($this->db);
        $this->service      = new EntryService($this->db, new EntryRepository($this->db), new RelationRepository($this->db), new FieldTypeRegistry(), new EventDispatcher());
        $this->mediaService = new MediaService($this->media, $this->usage, Config::basePath());
    }

    private function galleryWithPhoto(): Collection
    {
        $id = (new CollectionService($this->db, $this->collections))->create(
            'gallery',
            'Gallery',
            '#',
            '',
            ['kind' => 'collection', 'permissions' => ['manage' => []]],
            [['handle' => 'photo', 'label' => 'Photo', 'type' => 'media', 'required' => false, 'options' => []]],
        );
        return $this->collections->find($id);
    }

    private function makeMedia(): int
    {
        return $this->media->create(
            ['filename' => 'x.png', 'path' => 'public/uploads/x.png', 'url' => '/uploads/x.png', 'mime' => 'image/png', 'size' => 10, 'width' => 1, 'height' => 1, 'alt' => null],
            null,
        );
    }

    public function test_saving_an_entry_records_then_updates_media_usage(): void
    {
        $c     = $this->galleryWithPhoto();
        $media = $this->makeMedia();

        $saved = $this->service->save($c, new EntryInput('One', '', 'draft', ['photo' => $media]), null, null);
        self::assertTrue($saved->successful);

        self::assertTrue($this->usage->isUsed($media), 'the reference is indexed on save');
        $usage = $this->usage->usageOf($media);
        self::assertCount(1, $usage);
        self::assertSame('gallery', $usage[0]['collection']);
        self::assertSame('photo', $usage[0]['field_handle']);

        // Clearing the field on update frees the media.
        $this->service->save($c, new EntryInput('One', '', 'draft', ['photo' => null]), $saved->entryId, null);
        self::assertFalse($this->usage->isUsed($media), 'clearing the field removes the usage');
    }

    public function test_media_service_blocks_deleting_used_media_and_pinpoints_it(): void
    {
        $c     = $this->galleryWithPhoto();
        $media = $this->makeMedia();
        $this->service->save($c, new EntryInput('Held', '', 'draft', ['photo' => $media]), null, null);

        try {
            $this->mediaService->delete($media);
            self::fail('deleting in-use media should be refused');
        } catch (MediaInUse $e) {
            self::assertSame($media, $e->mediaId);
            self::assertSame('gallery', $e->usage[0]['collection']);
        }
        self::assertNotNull($this->media->find($media), 'the file is untouched while in use');
    }

    public function test_media_delete_succeeds_once_unused(): void
    {
        $unused = $this->makeMedia();
        self::assertTrue($this->mediaService->delete($unused));
        self::assertNull($this->media->find($unused));
    }

    public function test_deleting_the_referencing_entry_frees_the_media(): void
    {
        $c     = $this->galleryWithPhoto();
        $media = $this->makeMedia();
        $saved = $this->service->save($c, new EntryInput('Doomed', '', 'draft', ['photo' => $media]), null, null);
        self::assertTrue($this->usage->isUsed($media));

        // The entry/usage FK cascade frees the media when the entry goes.
        $this->service->delete($c, (int) $saved->entryId);
        self::assertFalse($this->usage->isUsed($media), 'usage is gone with the entry');
        self::assertTrue($this->mediaService->delete($media), 'now deletable');
    }

    public function test_reindexer_rebuilds_usage_from_existing_entries(): void
    {
        $c     = $this->galleryWithPhoto();
        $media = $this->makeMedia();
        $this->service->save($c, new EntryInput('Legacy', '', 'draft', ['photo' => $media]), null, null);

        // Simulate content that predates the index.
        $this->db->execute('TRUNCATE TABLE nb_media_usage');
        self::assertFalse($this->usage->isUsed($media));

        $scanned = (new MediaUsageReindexer($this->db, $this->collections, $this->usage))->reindex();
        self::assertGreaterThanOrEqual(1, $scanned);
        self::assertTrue($this->usage->isUsed($media), 'the backfill restores the index');
    }
}
