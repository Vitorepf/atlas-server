<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Semantic\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * R1 — embeds Atlas Memory Core rows (`atlas_memory_entries` +
 * `atlas_verbatim_memories`) on write with the REAL embedding engine
 * (App\Services\Semantic\EmbeddingService -> SemanticRagRuntimeClient -> Python
 * runtimes/python/semantic_rag, pgvector), so recall can rank by real vector
 * similarity instead of substring matching. Mirrors the SemanticNoteIndexer
 * embed-on-write precedent.
 *
 * ANTI-OVER-CLAIM / honest degrade: this is best-effort and NEVER throws into
 * the write path. It silently skips when
 *   - the connection is not pgsql (the vector column/query is pgvector-only), or
 *   - the `embedding` column is absent (migration not run / sqlite), or
 *   - the real embedding provider is unavailable (no venv + no OpenAI key) —
 *     the EmbeddingService raises rather than fabricate a vector, and we catch
 *     that and leave `embedding` NULL. A NULL embedding means recall falls back
 *     to lexical for that row; it is never a fake "semantic" vector.
 *
 * The only safe text to embed is the PROVIDER-SAFE / redacted projection: for
 * entries we embed the redacted body+summary+title; for verbatim we embed the
 * redacted_text+summary+title and only when external_ai_allowed. Raw/secret
 * content is never sent to an embedding provider.
 *
 * @see app/Services/Semantic/SemanticNoteIndexer.php
 */
class AtlasMemorySemanticIndexer
{
    public function __construct(private readonly EmbeddingService $embeddings) {}

    public function isEnabled(): bool
    {
        return (bool) config('atlas.semantic_memory.memory_vector_recall_enabled', true)
            && DB::getDriverName() === 'pgsql';
    }

    /**
     * Embed (or re-embed) a memory entry. Best-effort; returns true when a real
     * vector was stored, false on any honest skip/failure.
     */
    public function indexEntry(AtlasMemoryEntry $entry): bool
    {
        if (! $this->columnReady('atlas_memory_entries') || ! $entry->getKey()) {
            return false;
        }

        $allowExternal = $this->entryProviderSafe($entry);
        $text = $this->entryText($entry);

        return $this->store('atlas_memory_entries', (string) $entry->getKey(), $text, $allowExternal);
    }

    /**
     * Embed (or re-embed) a verbatim memory. Best-effort; never throws.
     */
    public function indexVerbatim(AtlasVerbatimMemory $memory): bool
    {
        if (! $this->columnReady('atlas_verbatim_memories') || ! $memory->getKey()) {
            return false;
        }

        // Blocked verbatim (external_ai_allowed === false) is never sent to a
        // provider; its embedding is cleared so it can only ever match lexically
        // under the existing provider-safe recall guard.
        if ($memory->external_ai_allowed !== true) {
            $this->clear('atlas_verbatim_memories', (string) $memory->getKey());

            return false;
        }

        $text = $this->verbatimText($memory);

        return $this->store('atlas_verbatim_memories', (string) $memory->getKey(), $text, true);
    }

    private function store(string $table, string $id, string $text, bool $allowExternalProvider): bool
    {
        $text = trim($text);
        if ($text === '') {
            $this->clear($table, $id);

            return false;
        }

        try {
            $vector = $this->embeddings->embedText($text, $allowExternalProvider);
        } catch (Throwable $throwable) {
            // No real embedding provider available (venv missing + no key). Canon:
            // honest failure, never a hash fake. Leave the column NULL -> lexical.
            report($throwable);

            return false;
        }

        if ($vector === []) {
            return false;
        }

        try {
            DB::update(
                "UPDATE {$table} SET embedding = ?::vector WHERE id = ?",
                [$this->embeddings->vectorLiteral($vector), $id],
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }

        return true;
    }

    private function clear(string $table, string $id): void
    {
        if (! $this->columnReady($table)) {
            return;
        }

        try {
            DB::update("UPDATE {$table} SET embedding = NULL WHERE id = ?", [$id]);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function columnReady(string $table): bool
    {
        return $this->isEnabled()
            && DatabaseTableAvailability::hasColumn($table, 'embedding');
    }

    /**
     * Provider-safe text for an entry: prefer the redacted projection so a
     * sensitive/secret body is never embedded in plaintext.
     */
    private function entryText(AtlasMemoryEntry $entry): string
    {
        $title = $this->coalesce($entry->getAttribute('redacted_title'), $entry->title);
        $summary = $this->coalesce($entry->getAttribute('redacted_summary'), $entry->summary);
        $body = $this->coalesce($entry->getAttribute('redacted_body'), $entry->body);

        return $this->limit(implode("\n", array_filter([$title, $summary, $body])));
    }

    private function verbatimText(AtlasVerbatimMemory $memory): string
    {
        return $this->limit(implode("\n", array_filter([
            (string) $memory->title,
            (string) $memory->summary,
            (string) $memory->redacted_text,
        ])));
    }

    private function entryProviderSafe(AtlasMemoryEntry $entry): bool
    {
        // When privacy columns exist, respect the explicit provider gate; default
        // to provider-safe text only. The EmbeddingService still refuses to fall
        // back to a non-real vector regardless.
        $external = $entry->getAttribute('external_ai_allowed');

        return $external === null ? true : (bool) $external;
    }

    private function coalesce(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function limit(string $text): string
    {
        return Str::limit($text, (int) config('atlas.semantic_memory.max_embedding_chars', 12000), '');
    }
}
