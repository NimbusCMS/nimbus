<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Database\Connection;
use Nimbus\Media\MediaUsageRepository;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;
use Nimbus\Support\Str;

/**
 * The entry write workflow, lifted out of the controller. Owns validation
 * orchestration, title/slug resolution, singleton rules, the transaction
 * boundary (entry + its relations), and post-commit event dispatch. The
 * database — via its unique constraints — is the final authority; the app-level
 * checks here are only for friendly feedback.
 */
final class EntryService
{
    /** Deterministic slug for singletons — with UNIQUE(collection_id, slug) it guarantees one row. */
    public const SINGLETON_SLUG = '__singleton';

    private Validator $validator;
    private MediaUsageRepository $mediaUsage;

    public function __construct(
        private Connection $db,
        private EntryRepository $entries,
        private RelationRepository $relations,
        private FieldTypeRegistry $types,
        private EventDispatcher $events,
    ) {
        $this->validator  = new Validator($types);
        $this->mediaUsage = new MediaUsageRepository($db);
    }

    public function save(Collection $collection, EntryInput $input, ?int $entryId, ?int $userId): SaveEntryResult
    {
        // Refuse to write through a field type nobody provides: normalizing the
        // value with the wrong type would silently rewrite what is stored.
        // Nothing is touched, so the data survives until the plugin is back.
        $missing = $this->types->missingFor($collection->fields);
        if ($missing !== []) {
            return SaveEntryResult::missingProvider(
                'This entry cannot be saved: the field type(s) “' . implode('”, “', $missing)
                    . '” are unavailable. Install or reactivate the plugin that provides them. Existing content is unchanged.',
                $input,
            );
        }

        // A singleton never creates a second row: target the existing one if present.
        if ($collection->isSingle() && $entryId === null) {
            $existing = $this->entries->firstForCollection($collection->id);
            if ($existing !== null) {
                $entryId = (int) $existing['id'];
            }
        }

        $errors = $this->validator->validate($collection, $input->values);
        if (!$collection->isSingle() && trim($input->title) === '') {
            // `title` is a writable input alongside the collection's fields, so it
            // keys into the same error map (no more `__title` pseudo-field).
            $errors['title'] = FieldError::required('Title is required.');
        }
        if ($errors !== []) {
            return SaveEntryResult::invalid($errors, $input);
        }

        [$title, $slug] = $this->resolveTitleSlug($collection, $input, $entryId);
        [$data, $relationValues, $mediaValues] = $this->splitValues($collection, $input);

        // Publishing with no time goes live now; a future time schedules it;
        // draft/archived keep the existing timestamp. One definition, in Publication.
        $current     = $entryId !== null ? $this->entries->publishedAt($collection->id, $entryId) : null;
        $publishedAt = Publication::resolvePublishedAt($input->status, $input->publishedAt, $current);

        $created = $entryId === null;
        try {
            $id = $this->persist($collection, $entryId, $title, $slug, $input->status, $publishedAt, $data, $relationValues, $mediaValues, $userId);
        } catch (\PDOException $e) {
            if (!Connection::isDuplicateKey($e)) {
                throw $e;
            }
            // Lost a concurrency race on the unique index — recover, don't corrupt.
            if ($collection->isSingle()) {
                $existing = $this->entries->firstForCollection($collection->id);
                if ($existing === null) {
                    throw $e;
                }
                $entryId = (int) $existing['id'];
                $created = false;
            } else {
                $slug = $this->uniqueSlug($collection->id, $slug . '-' . bin2hex(random_bytes(2)), $entryId ?? 0);
            }
            $id = $this->persist($collection, $entryId, $title, $slug, $input->status, $publishedAt, $data, $relationValues, $mediaValues, $userId);
        }

        // Events fire only after a successful commit — consistency never depends on listeners.
        $this->events->dispatch($created ? CoreEvents::ENTRY_CREATED : CoreEvents::ENTRY_UPDATED, [
            'id' => $id, 'collection_id' => $collection->id, 'title' => $title, 'slug' => $slug, 'status' => $input->status,
        ]);
        $this->events->dispatch(CoreEvents::ENTRY_SAVED, ['id' => $id, 'collection_id' => $collection->id, 'created' => $created]);

        return SaveEntryResult::ok($id, $input);
    }

    /**
     * Delete an entry.
     *
     * Returns whether a row was actually removed, and dispatches ENTRY_DELETED
     * only in that case. A delete for an id that is absent — or that belongs to
     * a different collection — is a no-op, and a listener that heard about it
     * would be acting on a deletion that never happened. Callers are free to
     * redirect identically either way; events must not be.
     */
    public function delete(Collection $collection, int $entryId): bool
    {
        $deleted = $this->db->transaction(
            fn (): int => $this->entries->delete($collection->id, $entryId),
        ) > 0;

        if ($deleted) {
            $this->events->dispatch(CoreEvents::ENTRY_DELETED, ['id' => $entryId, 'collection_id' => $collection->id]);
        }
        return $deleted;
    }

    /**
     * Persist the entry + its relations atomically; returns the entry id.
     *
     * @param array<string,mixed> $data
     * @param array<int,int[]>    $relationValues
     * @param array<int,int[]>    $mediaValues media ids per field id, for the usage index
     */
    private function persist(Collection $c, ?int $entryId, string $title, string $slug, string $status, ?string $publishedAt, array $data, array $relationValues, array $mediaValues, ?int $userId): int
    {
        return $this->db->transaction(function () use ($c, $entryId, $title, $slug, $status, $publishedAt, $data, $relationValues, $mediaValues, $userId): int {
            $attrs = ['title' => $title, 'slug' => $slug, 'status' => $status, 'published_at' => $publishedAt, 'data' => $data];
            if ($entryId === null) {
                $id = $this->entries->create($c->id, $attrs, $userId);
            } else {
                $id = $entryId;
                $this->entries->update($c->id, $id, $attrs);
            }
            foreach ($relationValues as $fieldId => $ids) {
                $this->relations->sync($id, $fieldId, $ids);
            }
            // Keep the media-usage index in step, in the same transaction.
            $this->mediaUsage->sync($id, $mediaValues);
            return $id;
        });
    }

    /**
     * @return array{0:array<string,mixed>,1:array<int,int[]>,2:array<int,int[]>} [dataForJson, relationValuesByFieldId, mediaIdsByFieldId]
     */
    private function splitValues(Collection $collection, EntryInput $input): array
    {
        $data      = [];
        $relations = [];
        $media     = [];
        foreach ($collection->fields as $field) {
            $value = $input->values[$field->handle] ?? null;
            if ($field->type === 'relation') {
                $ids = is_array($value) ? $value : ($value !== null && $value !== '' ? [$value] : []);
                $relations[$field->id] = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
            } else {
                $data[$field->handle] = $value;
                // A media field's value is stored in the JSON like any other, but
                // its id is *also* recorded in the usage index so the file can't
                // be deleted out from under this entry. (Single-value today; the
                // array shape is ready for a future multi-file media field.)
                if ($field->type === 'media') {
                    $ids = is_array($value) ? $value : [$value];
                    $media[$field->id] = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
                }
            }
        }
        return [$data, $relations, $media];
    }

    /** @return array{0:string,1:string} [title, slug] */
    private function resolveTitleSlug(Collection $collection, EntryInput $input, ?int $entryId): array
    {
        if ($collection->isSingle()) {
            // The title input is hidden for singletons — default it to the collection name.
            $title = trim($input->title) !== '' ? $input->title : $collection->name;
            return [$title, self::SINGLETON_SLUG];
        }
        $slug = $this->uniqueSlug($collection->id, Str::slug($input->slug !== '' ? $input->slug : $input->title) ?: 'entry', $entryId ?? 0);
        return [$input->title, $slug];
    }

    private function uniqueSlug(int $collectionId, string $slug, int $exceptId): string
    {
        $base = $slug;
        $n    = 2;
        while ($this->entries->slugExists($collectionId, $slug, $exceptId)) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }
}
