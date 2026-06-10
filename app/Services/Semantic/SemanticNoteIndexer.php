<?php

namespace App\Services\Semantic;

use App\Models\SemanticNote;
use App\Services\Ai\AtlasMemorySourcePrivacyPolicy;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\Metadata;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SemanticNoteIndexer
{
    public function __construct(
        private readonly VaultFileStore $vault,
        private readonly FrontmatterParser $parser,
        private readonly EmbeddingService $embeddings,
        private readonly AtlasMemorySourcePrivacyPolicy $privacyPolicy,
    ) {}

    public function indexAll(bool $changedOnly = false): array
    {
        $seen = collect();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->vault->listMarkdownFiles() as $path) {
            $result = $this->indexFile($path, $changedOnly);
            $seen->push($path);
            $created += $result['created'] ? 1 : 0;
            $updated += $result['updated'] ? 1 : 0;
            $skipped += $result['skipped'] ? 1 : 0;
        }

        $deleted = $this->markMissingAsDeleted($seen);

        return compact('created', 'updated', 'skipped', 'deleted');
    }

    public function indexFile(string $path, bool $changedOnly = false): array
    {
        $markdown = $this->vault->read($path);
        $contentHash = hash('sha256', $markdown);
        $existing = SemanticNote::withTrashed()->where('path', $path)->first();

        if ($changedOnly && $existing && $existing->content_hash === $contentHash && ! $existing->trashed()) {
            $existing->forceFill(['last_seen_at' => now()])->save();

            return ['note' => $existing, 'created' => false, 'updated' => false, 'skipped' => true];
        }

        $parsed = $this->parser->parse($markdown);
        $frontmatter = $parsed['frontmatter'];
        $errors = array_values(array_unique([
            ...$parsed['errors'],
            ...$this->parser->validate($frontmatter),
        ]));
        $body = $parsed['body'];
        $noteKey = $this->noteKey($frontmatter, $path);
        $type = $this->validType((string) ($frontmatter['type'] ?? 'source_note'));
        $status = $errors ? 'invalid' : $this->validStatus((string) ($frontmatter['status'] ?? 'draft'));
        $textForEmbedding = $this->textForEmbedding($frontmatter, $body);
        $privacy = $this->privacyPolicy->project('semantic_note', [
            'title' => $frontmatter['title'] ?? null,
            'summary' => $frontmatter['summary'] ?? null,
            'body_excerpt' => $body,
            'path' => $path,
            'frontmatter' => $frontmatter,
            'metadata' => [],
            'domains' => $this->arrayValue($frontmatter['domains'] ?? []),
        ]);
        $embeddingVector = $this->embeddings->embedText($textForEmbedding, (bool) $privacy['provider_safe']);
        $embedding = $this->embeddings->vectorLiteral($embeddingVector);
        $embeddingInfo = $this->embeddings->lastInfo();

        $payload = [
            'note_key' => $noteKey,
            'path' => $path,
            'title' => trim((string) ($frontmatter['title'] ?? Str::headline(pathinfo($path, PATHINFO_FILENAME)))) ?: pathinfo($path, PATHINFO_FILENAME),
            'type' => $type,
            'status' => $status,
            'confidence' => $this->validConfidence((string) ($frontmatter['confidence'] ?? 'low')),
            'maturity' => $this->validMaturity((string) ($frontmatter['maturity'] ?? 'draft')),
            'domains' => Metadata::forStorage($this->arrayValue($frontmatter['domains'] ?? [])),
            'summary' => $this->stringOrNull($frontmatter['summary'] ?? null),
            'body_excerpt' => Str::limit(trim(strip_tags($body)), 900, ''),
            'frontmatter' => Metadata::forStorage($frontmatter),
            'when_to_use' => Metadata::forStorage($this->arrayValue($frontmatter['when_to_use'] ?? [])),
            'trigger_signals' => Metadata::forStorage($this->arrayValue($frontmatter['trigger_signals'] ?? [])),
            'do_not_use_when' => Metadata::forStorage($this->arrayValue($frontmatter['do_not_use_when'] ?? [])),
            'postgres_refs' => Metadata::forStorage($frontmatter['postgres_refs'] ?? []),
            'content_hash' => $contentHash,
            'indexed_at' => now(),
            'last_seen_at' => now(),
            'validation_errors' => Metadata::forStorage($errors),
            'metadata' => Metadata::forStorage([
                'indexer' => 'semantic-note-indexer-v1',
                'embedding' => $embeddingInfo + [
                    'external_provider_allowed' => (bool) $privacy['provider_safe'],
                    'privacy_class' => $privacy['privacy_class'],
                    'privacy_policy_version' => $privacy['policy_version'],
                ],
            ]),
        ];

        $created = false;
        $updated = false;

        DB::transaction(function () use (&$existing, $payload, $embedding, &$created, &$updated): void {
            if (! $existing) {
                $existing = SemanticNote::create($payload);
                $created = true;
            } else {
                $existing->fill($payload);
                if ($existing->trashed()) {
                    $existing->restore();
                }
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated = true;
                }
            }

            if (DatabaseTableAvailability::hasColumn('semantic_notes', 'embedding')) {
                DB::update('UPDATE semantic_notes SET embedding = ?::vector WHERE id = ?', [$embedding, $existing->id]);
            }
        });

        return ['note' => $existing->refresh(), 'created' => $created, 'updated' => $updated || ! $created, 'skipped' => false];
    }

    /**
     * @param  Collection<int, string>  $seen
     */
    public function markMissingAsDeleted(Collection $seen): int
    {
        $query = SemanticNote::query()->whereNull('deleted_at');
        if ($seen->isNotEmpty()) {
            $query->whereNotIn('path', $seen->all());
        }

        $count = 0;
        $query->each(function (SemanticNote $note) use (&$count): void {
            $note->delete();
            $count++;
        });

        return $count;
    }

    private function noteKey(array $frontmatter, string $path): string
    {
        $key = trim((string) ($frontmatter['id'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        return 'note_'.substr(hash('sha256', $path), 0, 16);
    }

    private function textForEmbedding(array $frontmatter, string $body): string
    {
        $parts = [
            $frontmatter['title'] ?? '',
            $frontmatter['summary'] ?? '',
            implode(' ', $this->arrayValue($frontmatter['when_to_use'] ?? [])),
            implode(' ', $this->arrayValue($frontmatter['trigger_signals'] ?? [])),
            $body,
        ];

        return Str::limit(implode("\n", array_filter($parts)), (int) config('atlas.semantic_memory.max_embedding_chars', 12000), '');
    }

    private function validType(string $type): string
    {
        return in_array($type, ['source_note', 'mental_model', 'principle', 'hypothesis', 'practice', 'synthesis', 'decision_identity', 'cognitive_game'], true)
            ? $type
            : 'source_note';
    }

    private function validStatus(string $status): string
    {
        return in_array($status, ['inbox', 'draft', 'active', 'testing', 'validated', 'archived', 'invalid'], true)
            ? $status
            : 'draft';
    }

    private function validConfidence(string $confidence): string
    {
        return in_array($confidence, ['low', 'medium', 'high', 'validated'], true) ? $confidence : 'low';
    }

    private function validMaturity(string $maturity): string
    {
        return in_array($maturity, ['seed', 'draft', 'useful', 'tested', 'principle', 'archived'], true) ? $maturity : 'draft';
    }

    private function stringOrNull(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if ($value === null || $value === '') {
            return [];
        }

        return [(string) $value];
    }
}
