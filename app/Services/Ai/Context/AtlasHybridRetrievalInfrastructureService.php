<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * AUCRI readiness CHECK — not the prompt's retrieval engine (R4 PART B).
 *
 * Despite the "Hybrid Retrieval" name, report() produces a provider-safe READINESS/ROUTING
 * VERDICT (a retrieval-plan report consumed by {@see AtlasAucriRuntimeEnforcementService} as
 * one input to its binary pass/block gate). It does NOT surface recalled content into a
 * provider prompt. The actual semantic recall that reaches the prompt is
 * {@see \App\Services\Ai\AtlasHybridMemoryRetrievalService} via
 * {@see \App\Services\Ai\AtlasOpenBrainContextInjectionService}. The class name and
 * SCHEMA_VERSION constants are retained because they are load-bearing (DI + persisted/hashed
 * schemas).
 */
final class AtlasHybridRetrievalInfrastructureService
{
    public const SCHEMA_VERSION = 'atlas.aucri.hybrid_retrieval_infrastructure.v1';

    public const RETRIEVAL_REPORT_SCHEMA = 'atlas.aucri.retrieval_report.v1';

    public function __construct(
        private readonly ContextRetrievalRouter $router,
        private readonly AtlasSemanticEmbeddingFoundationService $semanticFoundation,
        private readonly SemanticRetrievalRuntime $semanticRuntime,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function report(array $input): array
    {
        $objective = trim((string) ($input['objective'] ?? $input['prompt'] ?? $input['query'] ?? ''));
        $payload = (array) ($input['payload'] ?? []);
        $task = AiTaskRequest::fromInput($objective, [
            'source_type' => 'aucri_ahri',
            'payload' => [
                'task_type' => (string) ($input['task_type'] ?? 'direct'),
                'atlas_workflow_mode' => (string) ($input['workflow_mode'] ?? 'auto'),
                'domain' => (string) ($input['domain'] ?? 'atlas'),
                'risk_level' => (string) ($input['risk_level'] ?? 'low'),
                'desired_mode' => (string) ($input['desired_mode'] ?? 'auto'),
            ],
        ], [
            'agent' => 'atlas_hybrid_retrieval_infrastructure',
            'intent' => 'hybrid_retrieval_report',
        ]);

        $plan = $this->router->plan($objective, $task, $payload, [
            'include_memory_registry' => (bool) ($input['include_memory_registry'] ?? true),
        ]);
        $asef = $objective !== ''
            ? $this->semanticFoundation->candidateSet([[
                'source_ref' => 'objective://query',
                'text' => $objective,
                'privacy_class' => (string) ($input['privacy_class'] ?? 'normal'),
                'authority_level' => 'operator_request',
            ]])
            : $this->semanticFoundation->readiness();

        $candidates = $this->dedupeCandidates(array_merge(
            $this->candidatesFromPlan($plan),
            $this->applyLocalSemanticScores($this->candidatesFromAsef($asef), $objective),
            $this->candidatesFromContextRefs((array) ($input['context_refs'] ?? [])),
        ));
        $misses = $this->misses($plan, $asef);
        $status = $this->status($plan, $asef, $candidates, $misses);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'retrieval_report' => [
                'schema_version' => self::RETRIEVAL_REPORT_SCHEMA,
                'query_hash' => MissionCanonicalHash::sha256($objective),
                'mode' => (string) ($plan['mode'] ?? 'balanced'),
                'source_plan' => [
                    'schema_version' => $plan['schema_version'] ?? ContextRetrievalRouter::SCHEMA_VERSION,
                    'readiness' => $plan['readiness'] ?? [],
                    'selected_sources' => $this->sourceSummaries((array) ($plan['selected_sources'] ?? [])),
                    'skipped_sources' => $this->sourceSummaries((array) ($plan['skipped_sources'] ?? [])),
                ],
                'candidates' => $candidates,
                'misses' => $misses,
                'excluded_refs' => $this->excludedRefs($candidates),
                'policy' => [
                    'provider_safe_only' => true,
                    'raw_text_exposed' => false,
                    'provider_bypass_allowed' => false,
                    'dedupe_by_source_hash' => true,
                ],
            ],
            'summary' => [
                'candidate_count' => count($candidates),
                'source_count' => count((array) ($plan['selected_sources'] ?? [])),
                'miss_count' => count($misses),
                'provider_safe_candidates' => count(array_filter($candidates, static fn (array $candidate): bool => (bool) $candidate['provider_safe'])),
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'external_vector_store_used' => false,
                'ranking_decision_made' => false,
                'context_pack_compiled' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['retrieval_report_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<int,array<string,mixed>>
     */
    private function candidatesFromPlan(array $plan): array
    {
        return array_values(array_map(function (array $source): array {
            $type = (string) ($source['type'] ?? 'unknown');
            $sourceRef = 'source://'.$type;

            return [
                'candidate_id' => 'ahri_'.substr(MissionCanonicalHash::sha256([$type, $source['owner_doc'] ?? null]), 0, 24),
                'source_type' => $type,
                'source_ref' => $sourceRef,
                'source_hash' => MissionCanonicalHash::sha256($sourceRef),
                'reason' => (string) ($source['reason'] ?? 'retrieval_source_selected'),
                'owner_doc' => (string) ($source['owner_doc'] ?? ''),
                'available' => (bool) ($source['available'] ?? false),
                'required' => (bool) ($source['required'] ?? false),
                'provider_safe' => (bool) ($source['provider_bypass_allowed'] ?? false) === false,
                'privacy_class' => 'normal',
                'authority_level' => 'source_adapter',
                'score_hint' => max(0.0, 1.0 - (((int) ($source['priority'] ?? 50)) / 100)),
                'status' => (string) ($source['status'] ?? 'unknown'),
            ];
        }, array_values((array) ($plan['selected_sources'] ?? []))));
    }

    /**
     * @param  array<string,mixed>  $asef
     * @return array<int,array<string,mixed>>
     */
    private function candidatesFromAsef(array $asef): array
    {
        return array_values(array_map(static function (array $chunk): array {
            $sourceRef = (string) ($chunk['source_ref'] ?? 'objective://query');

            return [
                'candidate_id' => 'ahri_semantic_'.substr((string) ($chunk['chunk_hash'] ?? MissionCanonicalHash::sha256($chunk)), 0, 18),
                'source_type' => 'semantic_candidate',
                'source_ref' => $sourceRef,
                'source_hash' => (string) ($chunk['source_hash'] ?? MissionCanonicalHash::sha256($sourceRef)),
                'reason' => 'asef_candidate_manifest_chunk',
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md',
                'available' => true,
                'required' => false,
                'provider_safe' => (bool) ($chunk['provider_safe'] ?? false),
                'privacy_class' => (string) ($chunk['privacy_class'] ?? 'normal'),
                'authority_level' => (string) ($chunk['authority_level'] ?? 'source_observed'),
                // Static MANIFEST PLACEHOLDER score. When the local semantic_rag
                // runtime is available, applyLocalSemanticScores() replaces it
                // with a REAL cosine score and stamps score_origin=local_semantic_vector.
                'score_hint' => 0.60,
                'status' => (string) ($chunk['embedding_status'] ?? 'candidate_manifest_only'),
                'chunk_hash' => (string) ($chunk['chunk_hash'] ?? ''),
                'token_estimate' => (int) ($chunk['token_estimate'] ?? 0),
            ];
        }, (array) data_get($asef, 'candidate_set.chunks', [])));
    }

    /**
     * Score the ASEF manifest chunks with REAL cosine similarity from the LOCAL
     * Python semantic_rag runtime ({@see SemanticRetrievalRuntime}) — one batched
     * retrieve() per report, never one call per candidate. Scored candidates get
     * `score_hint` = real cosine (clamped [0,1]) and `score_origin` =
     * `local_semantic_vector`, which is what lets the ranking layer relabel the
     * retrieval channel honestly.
     *
     * HONEST DEGRADE: when the flag is off, the runtime is unavailable, the
     * runtime errors, or a chunk text cannot be recovered, candidates pass
     * through UNTOUCHED (static 0.60 placeholder, no `score_origin` stamp) so the
     * channel stays `manifest_pending_embedding`. No fabricated vectors, no
     * lexical stand-in — per the runtime_language_boundary canon. The chunk text
     * goes only TO the local runtime; it never enters this report's payload.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    private function applyLocalSemanticScores(array $candidates, string $objective): array
    {
        if ($candidates === [] || $objective === '') {
            return $candidates;
        }

        if (! (bool) config('atlas.aucri.local_semantic_scoring', true)) {
            return $candidates;
        }

        if (! $this->semanticRuntime->available()) {
            return $candidates;
        }

        $chunkTexts = $this->semanticFoundation->chunkTextsByHash($objective);
        $documents = [];
        foreach ($candidates as $candidate) {
            $chunkHash = (string) ($candidate['chunk_hash'] ?? '');
            if ($chunkHash !== '' && isset($chunkTexts[$chunkHash])) {
                $documents[$chunkHash] = ['id' => $chunkHash, 'text' => $chunkTexts[$chunkHash]];
            }
        }

        if ($documents === []) {
            return $candidates;
        }

        try {
            $result = $this->semanticRuntime->retrieve(
                documents: array_values($documents),
                query: $objective,
                k: count($documents),
            );
        } catch (Throwable) {
            return $candidates;
        }

        $scores = [];
        foreach ((array) ($result['matches'] ?? []) as $match) {
            if (! is_array($match)) {
                continue;
            }
            $id = (string) ($match['id'] ?? '');
            if ($id !== '' && is_numeric($match['score'] ?? null)) {
                $scores[$id] = round(max(0.0, min(1.0, (float) $match['score'])), 4);
            }
        }

        if ($scores === []) {
            return $candidates;
        }

        return array_values(array_map(static function (array $candidate) use ($scores): array {
            $chunkHash = (string) ($candidate['chunk_hash'] ?? '');
            if ($chunkHash === '' || ! array_key_exists($chunkHash, $scores)) {
                return $candidate;
            }

            $candidate['score_hint'] = $scores[$chunkHash];
            $candidate['score_origin'] = 'local_semantic_vector';

            return $candidate;
        }, $candidates));
    }

    /**
     * @param  array<int,mixed>  $contextRefs
     * @return array<int,array<string,mixed>>
     */
    private function candidatesFromContextRefs(array $contextRefs): array
    {
        return array_values(array_filter(array_map(static function (mixed $ref): ?array {
            if (! is_scalar($ref) || trim((string) $ref) === '') {
                return null;
            }

            $sourceRef = 'context://'.trim((string) $ref);

            return [
                'candidate_id' => 'ahri_context_'.substr(MissionCanonicalHash::sha256($sourceRef), 0, 20),
                'source_type' => 'explicit_context_ref',
                'source_ref' => $sourceRef,
                'source_hash' => MissionCanonicalHash::sha256($sourceRef),
                'reason' => 'operator_or_runtime_supplied_context_ref',
                'owner_doc' => '',
                'available' => true,
                'required' => false,
                'provider_safe' => true,
                'privacy_class' => 'normal',
                'authority_level' => 'explicit_ref',
                'score_hint' => 0.80,
                'status' => 'provided',
            ];
        }, $contextRefs)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    private function dedupeCandidates(array $candidates): array
    {
        $seen = [];
        $deduped = [];

        foreach ($candidates as $candidate) {
            $key = ($candidate['source_type'] ?? 'unknown').'|'.($candidate['source_hash'] ?? '').'|'.($candidate['chunk_hash'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $candidate['candidate_hash'] = MissionCanonicalHash::sha256($candidate);
            $deduped[] = $candidate;
        }

        return $deduped;
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<int,array<string,mixed>>
     */
    private function sourceSummaries(array $sources): array
    {
        return array_values(array_map(static fn (array $source): array => [
            'type' => (string) ($source['type'] ?? 'unknown'),
            'available' => (bool) ($source['available'] ?? false),
            'required' => (bool) ($source['required'] ?? false),
            'status' => (string) ($source['status'] ?? 'unknown'),
            'runtime' => (string) ($source['runtime'] ?? 'unknown'),
            'owner_doc' => (string) ($source['owner_doc'] ?? ''),
            'reason' => (string) ($source['reason'] ?? ''),
        ], $sources));
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $asef
     * @return array<int,array<string,mixed>>
     */
    private function misses(array $plan, array $asef): array
    {
        $misses = [];

        foreach ((array) data_get($plan, 'readiness.unavailable_selected_sources', []) as $sourceType) {
            $misses[] = [
                'source_type' => (string) $sourceType,
                'reason' => 'selected_source_unavailable',
                'required' => in_array((string) $sourceType, (array) data_get($plan, 'readiness.required_unavailable_sources', []), true),
            ];
        }

        foreach ((array) ($asef['blockers'] ?? []) as $blocker) {
            $misses[] = [
                'source_type' => 'semantic_candidate',
                'reason' => (string) ($blocker['reason'] ?? 'asef_blocker'),
                'required' => false,
            ];
        }

        return $misses;
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    private function excludedRefs(array $candidates): array
    {
        return array_values(array_map(static fn (array $candidate): array => [
            'source_ref_hash' => MissionCanonicalHash::sha256((string) ($candidate['source_ref'] ?? '')),
            'reason' => 'not_provider_safe',
        ], array_values(array_filter($candidates, static fn (array $candidate): bool => ! (bool) ($candidate['provider_safe'] ?? false)))));
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $asef
     * @param  array<int,array<string,mixed>>  $candidates
     * @param  array<int,array<string,mixed>>  $misses
     */
    private function status(array $plan, array $asef, array $candidates, array $misses): string
    {
        if ($candidates === []) {
            return 'blocked';
        }

        if ((array) data_get($plan, 'readiness.required_unavailable_sources', []) !== []) {
            return 'blocked';
        }

        if (($asef['status'] ?? null) === 'blocked') {
            return 'blocked';
        }

        return $misses === [] ? 'ready' : 'degraded';
    }
}
