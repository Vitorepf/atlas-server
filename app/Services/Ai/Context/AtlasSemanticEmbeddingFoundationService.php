<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AtlasSemanticEmbeddingFoundationService
{
    public const SCHEMA_VERSION = 'atlas.aucri.semantic_embedding_foundation.v1';

    private const MAX_CHARS_PER_CHUNK = 1600;

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    public function candidateSet(array $sources, array $options = []): array
    {
        $chunks = [];
        $blockers = [];

        foreach (array_values($sources) as $sourceIndex => $source) {
            $sourceRef = $this->sourceRef($source, $sourceIndex);
            $text = trim((string) ($source['text'] ?? ''));
            $privacyClass = $this->privacyClass((string) ($source['privacy_class'] ?? 'normal'), $text);

            if ($text === '') {
                $blockers[] = [
                    'source_ref' => $sourceRef,
                    'reason' => 'empty_source_text',
                ];

                continue;
            }

            foreach ($this->chunkText($text) as $chunkIndex => $chunkText) {
                $contentHash = MissionCanonicalHash::sha256($chunkText);
                $providerSafe = $this->providerSafe($privacyClass);

                $chunks[] = [
                    'chunk_id' => 'asef_'.substr(MissionCanonicalHash::sha256([$sourceRef, $chunkIndex, $contentHash]), 0, 24),
                    'source_ref' => $sourceRef,
                    'source_hash' => MissionCanonicalHash::sha256($sourceRef),
                    'chunk_index' => $chunkIndex,
                    'chunk_hash' => $contentHash,
                    'token_estimate' => $this->tokenEstimate($chunkText),
                    'char_count' => mb_strlen($chunkText),
                    'privacy_class' => $privacyClass,
                    'provider_safe' => $providerSafe,
                    'redaction_required' => ! $providerSafe,
                    'delete_cascade_key' => 'delete:'.substr(MissionCanonicalHash::sha256([$sourceRef, $contentHash]), 0, 32),
                    'embedding_status' => 'candidate_manifest_only',
                    'embedding_runtime' => 'python_ai_data_future',
                    'local_fallback' => 'local_hash_signature',
                    'lexical_signature' => $providerSafe ? $this->lexicalSignature($chunkText) : [],
                    'authority_level' => (string) ($source['authority_level'] ?? 'source_observed'),
                    'valid_from' => (string) ($source['valid_from'] ?? Carbon::now()->toDateString()),
                ];
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $chunks === [] ? 'blocked' : ($blockers === [] ? 'ready' : 'partial'),
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'sources' => count($sources),
                'chunks' => count($chunks),
                'provider_safe_chunks' => count(array_filter($chunks, static fn (array $chunk): bool => (bool) $chunk['provider_safe'])),
                'blocked_sources' => count($blockers),
                'total_token_estimate' => array_sum(array_map(static fn (array $chunk): int => (int) $chunk['token_estimate'], $chunks)),
            ],
            'candidate_set' => [
                'schema_version' => 'atlas.aucri.embedding_candidate_set.v1',
                'mode' => 'manifest_without_external_embedding',
                'chunks' => $chunks,
            ],
            'quality_gates' => [
                'deterministic_chunking' => true,
                'chunk_hash_present' => $this->allChunksHave($chunks, 'chunk_hash'),
                'source_hash_present' => $this->allChunksHave($chunks, 'source_hash'),
                'delete_cascade_key_present' => $this->allChunksHave($chunks, 'delete_cascade_key'),
                'privacy_gate_applied' => true,
                'raw_text_exposed' => false,
            ],
            'blockers' => $blockers,
            'claims' => [
                'providers_invoked' => false,
                'external_vector_store_used' => false,
                'embedding_generated' => false,
                'writes' => false,
                'laravel_heavy_rag_or_ml' => false,
            ],
            'next_actions' => [
                'Route this manifest to AHRI as candidate input.',
                'Promote semantic embedding generation only through python_ai_data runtime with Decision Receipt.',
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['candidate_set_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function readiness(): array
    {
        $semanticNotes = Schema::hasTable('semantic_notes');
        $attachments = Schema::hasTable('ai_attachment_index_entries');
        $semanticEmbedding = $semanticNotes && Schema::hasColumn('semantic_notes', 'embedding');
        $attachmentEmbedding = $attachments && Schema::hasColumn('ai_attachment_index_entries', 'embedding');
        $provider = (string) config('atlas.semantic_memory.embedding_provider', 'local_hash');

        $checks = [
            'semantic_notes_table' => $semanticNotes,
            'semantic_notes_embedding_column' => $semanticEmbedding,
            'attachment_index_table' => $attachments,
            'attachment_embedding_column' => $attachmentEmbedding,
            'local_hash_default_or_provider_review_required' => $provider === 'local_hash' || $provider === 'openai',
            'laravel_manifest_only' => true,
            'python_runtime_required_for_heavy_embeddings' => true,
            'external_vector_store_disabled' => true,
        ];

        $criticalMissing = array_values(array_filter(
            ['semantic_notes_table', 'semantic_notes_embedding_column', 'local_hash_default_or_provider_review_required'],
            static fn (string $key): bool => ! (bool) $checks[$key],
        ));
        $warnings = array_values(array_filter(
            ['attachment_index_table', 'attachment_embedding_column'],
            static fn (string $key): bool => ! (bool) $checks[$key],
        ));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $criticalMissing !== [] ? 'blocked' : ($warnings !== [] ? 'partial' : 'ready'),
            'generated_at' => Carbon::now()->toIso8601String(),
            'driver' => DB::getDriverName(),
            'embedding_policy' => [
                'provider' => $provider,
                'model' => (string) config('atlas.semantic_memory.embedding_model', 'text-embedding-3-small'),
                'dimensions' => (int) config('atlas.semantic_memory.embedding_dimensions', 1536),
                'semantic_embeddings_runtime' => 'python_ai_data_future',
                'laravel_scope' => 'manifest_chunking_privacy_hashes_only',
            ],
            'checks' => $checks,
            'critical_missing' => $criticalMissing,
            'warnings' => $warnings,
            'stores' => [
                'semantic_notes' => [
                    'table_exists' => $semanticNotes,
                    'embedding_column_exists' => $semanticEmbedding,
                ],
                'ai_attachment_index_entries' => [
                    'table_exists' => $attachments,
                    'embedding_column_exists' => $attachmentEmbedding,
                ],
            ],
            'claims' => [
                'providers_invoked' => false,
                'external_vector_store_used' => false,
                'writes' => false,
                'runtime_implemented' => true,
                'semantic_embedding_generation_implemented' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['readiness_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function chunkText(string $text): array
    {
        $normalized = preg_replace("/\r\n?/", "\n", trim($text)) ?? trim($text);
        $paragraphs = preg_split("/\n{2,}/", $normalized) ?: [$normalized];
        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) > self::MAX_CHARS_PER_CHUNK) {
                foreach ($this->splitLongText($paragraph) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            $candidate = trim($buffer === '' ? $paragraph : $buffer."\n\n".$paragraph);
            if (mb_strlen($candidate) > self::MAX_CHARS_PER_CHUNK && $buffer !== '') {
                $chunks[] = $buffer;
                $buffer = $paragraph;
            } else {
                $buffer = $candidate;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return array_values(array_filter($chunks, static fn (string $chunk): bool => trim($chunk) !== ''));
    }

    /**
     * @return array<int,string>
     */
    private function splitLongText(string $text): array
    {
        $pieces = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $pieces[] = trim(mb_substr($text, $offset, self::MAX_CHARS_PER_CHUNK));
            $offset += self::MAX_CHARS_PER_CHUNK;
        }

        return array_values(array_filter($pieces));
    }

    private function privacyClass(string $declared, string $text): string
    {
        $declared = in_array($declared, ['normal', 'private', 'sensitive', 'secret'], true) ? $declared : 'normal';
        $lower = Str::lower($text);

        if (preg_match('/(api[_-]?key|secret|private[_-]?key|password|senha|bearer\s+[a-z0-9._-]+)/i', $text) === 1) {
            return 'secret';
        }

        if (preg_match('/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b|\b\d{3}-\d{2}-\d{4}\b|\b\d{13,16}\b/', $text) === 1) {
            return $declared === 'secret' ? 'secret' : 'sensitive';
        }

        if (str_contains($lower, 'confidencial') || str_contains($lower, 'private') || str_contains($lower, 'privado')) {
            return in_array($declared, ['secret', 'sensitive'], true) ? $declared : 'private';
        }

        return $declared;
    }

    private function providerSafe(string $privacyClass): bool
    {
        return $privacyClass === 'normal';
    }

    /**
     * @return array<int,string>
     */
    private function lexicalSignature(string $text): array
    {
        preg_match_all('/[\pL\pN]{4,}/u', Str::lower($text), $matches);
        $tokens = array_values(array_filter($matches[0] ?? [], static fn (string $token): bool => ! in_array($token, [
            'para', 'com', 'como', 'uma', 'que', 'this', 'that', 'with', 'from', 'sobre', 'atlas',
        ], true)));
        $counts = array_count_values($tokens);
        arsort($counts);

        return array_slice(array_keys($counts), 0, 12);
    }

    private function tokenEstimate(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function sourceRef(array $source, int $sourceIndex): string
    {
        $ref = trim((string) ($source['source_ref'] ?? ''));

        return $ref !== '' ? $ref : 'inline://source/'.$sourceIndex;
    }

    /**
     * @param  array<int,array<string,mixed>>  $chunks
     */
    private function allChunksHave(array $chunks, string $key): bool
    {
        return $chunks !== [] && array_reduce(
            $chunks,
            static fn (bool $carry, array $chunk): bool => $carry && isset($chunk[$key]) && $chunk[$key] !== '',
            true,
        );
    }
}
