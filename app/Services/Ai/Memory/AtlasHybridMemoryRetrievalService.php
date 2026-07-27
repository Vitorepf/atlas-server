<?php

namespace App\Services\Ai\Memory;

use App\Models\AiCompoundingMemory;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasVerbatimMemory;
use App\Models\SemanticNote;
use App\Services\Ai\Context\Retrieval\DomainLexicalNormalizer;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\MemoryGovernance\AtlasMemorySourcePrivacyPolicy;
use App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Semantic\SemanticSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class AtlasHybridMemoryRetrievalService
{
    /**
     * MAXH-09 — canonical option keys for the temporal-recall projection.
     * `as_of`: consulta ficta "o que era verdade em D" — peek forçado (record_usage=false).
     * `current_only`: só entradas vigentes agora (canônico do `HasTemporalTruth::current`).
     */
    public const OPTION_AS_OF = 'temporal_as_of';

    public const OPTION_CURRENT_ONLY = 'temporal_current_only';

    /**
     * On pgsql, recall ranks PRIMARILY by real vector similarity (R1). The
     * lexical token-overlap score is kept as a tiebreaker/fallback. The blended
     * hybrid_score = max(vector-dominant, lexical) so a strong semantic match
     * always outranks a weak substring match, but a row without a vector (NULL
     * embedding / sqlite / no venv) HONESTLY degrades to its lexical score.
     */
    private const VECTOR_WEIGHT = 0.85;

    private const LEXICAL_WEIGHT = 0.15;

    /** @var array<string,float> entry id => query vector similarity for the current recall */
    private array $entryVectorScores = [];

    /** @var array<string,float> verbatim id => query vector similarity for the current recall */
    private array $verbatimVectorScores = [];

    public function __construct(
        private readonly AtlasMemoryRegistryService $registry,
        private readonly AtlasVerbatimMemoryService $verbatim,
        private readonly SemanticSearchService $semantic,
        private readonly AtlasMemoryPrivacyService $privacy,
        private readonly AtlasMemorySourcePrivacyPolicy $sourcePrivacy,
        private readonly AtlasMemoryContextComposer $composer,
        private readonly MemoryRecallInput $input,
        private readonly AtlasMemoryUsageService $usage,
        private readonly AtlasMemoryVectorSearchService $vectorSearch,
        private readonly AtlasMemoryRecallConcentrationDemotion $concentrationDemotion = new AtlasMemoryRecallConcentrationDemotion,
        private readonly AtlasMemoryRecallCache $recallCache = new AtlasMemoryRecallCache,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function recall(string $query = '', array $context = [], array $filters = [], array $options = []): array
    {
        $latencyStartedAt = hrtime(true);
        $query = trim($query);

        // MAXH-09 — `as_of` queries are PEEK-forced (record_usage=false) so historical
        // consultations never mutate the live usage series.
        $asOfInstant = $this->resolveAsOfInstant($options[self::OPTION_AS_OF] ?? null);
        if ($asOfInstant !== null) {
            $options['record_usage'] = false;
        }

        $cacheKey = $this->recallCache->keyFor($query, $context, $this->cacheFilterKeys($filters), $options);
        $cacheEnabled = $this->recallCache->isEnabled();
        $cached = $cacheEnabled ? $this->recallCache->get($cacheKey) : null;
        if (is_array($cached) && is_array($cached['recall'] ?? null)) {
            return $this->handleRecallCacheHit($cached, $query, $context, $options, $latencyStartedAt);
        }

        $limit = $this->input->recallLimit($options['limit'] ?? null);
        $registryLimit = $this->input->registryCandidateLimit($options['registry_limit'] ?? null, $limit);
        $verbatimLimit = $this->input->verbatimCandidateLimit($options['verbatim_limit'] ?? null, $limit);
        $semanticLimit = $this->input->semanticCandidateLimit($options['semantic_limit'] ?? null, $limit);

        $registry = $this->registryItems(
            $query,
            $context,
            $filters,
            $registryLimit,
            (bool) ($options['include_registry'] ?? true),
            $this->temporalFilterFromOptions($options, $asOfInstant),
        );
        $dominantRecallCount = collect($registry)->filter(fn (array $item): bool => ($item['concentration_demoted'] ?? false) === true)->count();
        $verbatim = $this->verbatimItems($query, $context, $filters, $verbatimLimit, (bool) ($options['include_verbatim'] ?? true));
        $semantic = $this->semanticItems($query, $filters, $semanticLimit, (bool) ($options['include_semantic'] ?? true));
        // ACDE #3 — compounding-recall arm. Flag-gated default-OFF => [] => the 4th source is absent and the
        // compose call is byte-identical to today. Callers may force-disable with include_compounding=false.
        $compounding = $this->compoundingItems($query, $limit, (bool) ($options['include_compounding'] ?? true));

        $recall = $this->composer->compose($registry, $verbatim, $semantic, [
            'memory_recall_limit' => $limit,
            'memory_recall_budget_chars' => $this->input->budgetChars($options['budget_chars'] ?? null),
            'memory_recall_item_chars' => $this->input->itemChars($options['item_chars'] ?? null),
        ], $compounding);
        // O3 · peek: consulta exploratória ("será que lembro de X?") não deve mutar a
        // estatística de recall/concentração — senão o próprio --never-recalled mente.
        $usage = ($options['record_usage'] ?? true)
            ? $this->usage->recordRecallUsages($query, $this->publicContext($context), $recall, [
                'source' => is_scalar($options['requester'] ?? null) ? (string) $options['requester'] : 'atlas_memory_recall',
            ])
            : ['recorded_count' => 0, 'audit_id' => null, 'skipped' => 'peek_no_usage'];

        $result = [
            'query' => $query,
            'context' => $this->publicContext($context),
            'cached' => false,
            'summary' => [
                'registry_candidates' => count($registry),
                'verbatim_candidates' => count($verbatim),
                'semantic_candidates' => count($semantic),
                'compounding_candidates' => count($compounding),
                'recall_count' => count($recall),
                'usage_recorded_count' => $usage['recorded_count'],
                'usage_audit_id' => $usage['audit_id'],
                'budget_chars' => collect($recall)->sum(fn (array $item): int => (int) ($item['estimated_chars'] ?? 0)),
                'redacted_ref_count' => collect($recall)->filter(fn (array $item): bool => data_get($item, 'audit_trail.redacted_hash') !== null)->count(),
                'raw_content_persisted_count' => collect($recall)->filter(fn (array $item): bool => data_get($item, 'audit_trail.raw_content_persisted') === true)->count(),
                'concentration_demoted_count' => $dominantRecallCount,
                'policy' => 'provider_safe_only',
            ],
            'recall' => $recall,
            'sources' => [
                'registry' => $registry,
                'verbatim' => $verbatim,
                'semantic' => $semantic,
                'compounding' => $compounding,
            ],
        ];

        $this->recordLatencySample($latencyStartedAt, $result);

        if ($cacheEnabled && $query !== '') {
            try {
                $this->recallCache->put($cacheKey, [
                    'schema_version' => 'atlas.memory.recall_cache.v1',
                    'stored_at' => now()?->toJSON() ?? date(DATE_ATOM),
                    'recall' => $result,
                ]);
            } catch (\Throwable) {
                // fail-open: cache is optimization, never a correctness gate.
            }
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $cached
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function handleRecallCacheHit(
        array $cached,
        string $query,
        array $context,
        array $options,
        int $latencyStartedAt,
    ): array {
        $result = is_array($cached['recall'] ?? null) ? $cached['recall'] : [];
        $result['cached'] = true;

        // Delivery usage is re-recorded on hit (context_pack surface is delivered
        // regardless of cache). We NEVER re-record pre_filter (denominator inflation).
        if (($options['record_usage'] ?? true) === true) {
            $recall = is_array($result['recall'] ?? null) ? $result['recall'] : [];
            $usage = $this->usage->recordRecallUsages(
                $query,
                $this->publicContext($context),
                $recall,
                [
                    'source' => is_scalar($options['requester'] ?? null)
                        ? (string) $options['requester']
                        : 'atlas_memory_recall.cache_hit',
                    'cached' => true,
                ],
            );
            if (isset($result['summary']) && is_array($result['summary'])) {
                $result['summary']['usage_recorded_count'] = (int) ($usage['recorded_count'] ?? 0);
                $result['summary']['usage_audit_id'] = $usage['audit_id'] ?? null;
                $result['summary']['cache_hit'] = true;
            }
        }

        $this->recordLatencySample($latencyStartedAt, $result);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function cacheFilterKeys(array $filters): array
    {
        return $filters;
    }

    /** @param array<string,mixed> $recall */
    private function recordLatencySample(int $startedAt, array $recall): void
    {
        try {
            app(AtlasAobgLatencyLedger::class)->recordRecall((hrtime(true) - $startedAt) / 1_000_000, $recall);
        } catch (\Throwable) {
            // Measurement is fail-open; recall must keep returning provider-safe context.
        }
    }

    /**
     * ACDE #3 — the compounding-recall arm: PROMOTED compounding learnings (AiCompoundingMemory) surfaced
     * into the SAME hybrid recall the live provider injection consumes, so every session reads what the loop
     * already learned. Flag-gated default-OFF => [] (no 4th source => byte-identical recall). PROVIDER-SAFE by
     * construction: only status=active (promotion is the quality gate that already filtered noise) AND
     * confidence >= floor; only the provider-safe `claim` is emitted (never the raw payload); count-capped and
     * lexical-ranked. Fail-open: a missing table / any error yields [] (recall never breaks).
     *
     * @return array<int,array<string,mixed>>
     */
    private function compoundingItems(string $query, int $limit, bool $enabled): array
    {
        if (! $enabled
            || ! (bool) config('atlas.semantic_memory.compounding_recall_enabled', false)
            || ! DatabaseTableAvailability::has('ai_compounding_memories')) {
            return [];
        }

        $cap = (int) config('atlas.semantic_memory.compounding_recall_limit', 6);
        $cap = $cap > 0 ? min($cap, max(1, $limit)) : max(1, $limit);
        $minConfidence = (int) config('atlas.semantic_memory.compounding_recall_min_confidence', 0);

        try {
            // Pull a wider active+confident pool, then keep the top-$cap by lexical relevance to the query.
            $rows = AiCompoundingMemory::query()
                ->active()
                ->where('confidence', '>=', $minConfidence)
                ->orderByDesc('confidence')
                ->limit(max($cap * 4, 24))
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $items = $rows
            ->map(function (AiCompoundingMemory $memory) use ($query): array {
                $claim = trim((string) $memory->claim);

                return [
                    'id' => $memory->id,
                    'type' => (string) ($memory->memory_type ?: 'compounding_learning'),
                    'scope' => (string) ($memory->scope ?: 'global'),
                    'scope_type' => (string) ($memory->scope ?: 'global'),
                    'title' => Str::limit($claim, 80, '...'),
                    'summary' => '',
                    'claim' => $claim,
                    'confidence' => (int) $memory->confidence,
                    'flow_id' => $memory->flow_id,
                    'source_type' => 'ai_compounding_memory',
                    'source_id' => $memory->id,
                    'source_label' => (string) ($memory->flow_id ?: $memory->memory_type),
                    'content_hash' => (string) $memory->memory_hash,
                    'recorded_at' => $memory->last_revalidated_at?->toJSON() ?? $memory->updated_at?->toJSON(),
                    'reason' => $query !== '' ? 'aprendizado compounding promovido com sinal lexical da consulta' : 'aprendizado compounding promovido (ativo)',
                    'hybrid_score' => $this->lexicalScore($query, [$claim, $memory->memory_type, $memory->flow_id]),
                ];
            })
            ->sortByDesc('hybrid_score')
            ->take($cap)
            ->values()
            ->all();

        return $items;
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @param  array{as_of?:CarbonImmutable,current_only:bool}|null  $temporalFilter
     * @return array<int,array<string,mixed>>
     */
    private function registryItems(string $query, array $context, array $filters, int $limit, bool $enabled, ?array $temporalFilter = null): array
    {
        if (! $enabled || $limit <= 0 || ! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return [];
        }

        // WO-17-T0.2 — forward the QUESTION into candidate selection. T0.1 made
        // relevantForContext() query-aware, but recall() never passed the query, so
        // every consumer (guard, context pack, projection) got a candidate set that
        // was blind to the question — the vectors below only reordered a wrong set.
        // Empty query, or a caller that set its own, ⇒ byte-identical to before.
        $registryContext = ($query !== '' && ! array_key_exists('query', $context))
            ? $context + ['query' => $query]
            : $context;

        $entries = $this->registry
            ->relevantForContext($registryContext, $this->registryFilters($filters), $limit)
            ->filter(fn (AtlasMemoryEntry $entry): bool => $this->privacy->providerAllowed($entry))
            ->values();

        // MAXH-09 — post-fetch temporal projection: when the caller asks for
        // `--as-of=D` or `--current-only`, apply the same predicate that
        // `HasTemporalTruth::current($at)` uses at the query layer. Post-filter
        // (not query-time) keeps registry SQL untouched and honest: absent
        // temporal columns (legacy NULL rows) are treated as current, exactly
        // like the trait scope.
        if ($temporalFilter !== null) {
            $entries = $entries
                ->filter(fn (AtlasMemoryEntry $entry): bool => $this->matchesTemporalFilter($entry, $temporalFilter))
                ->values();
        }

        // R1: rank PRIMARILY by real vector similarity on pgsql; empty map ->
        // honest lexical fallback (sqlite / no embeddings / no venv).
        $this->entryVectorScores = $this->vectorSearch->scoreEntries(
            $query,
            $entries->map(fn (AtlasMemoryEntry $entry): string => (string) $entry->id)->all(),
        );

        $entryIds = $entries->map(fn (AtlasMemoryEntry $entry): string => (string) $entry->id)->all();
        $dominantIds = $this->concentrationDemotion->dominantEntryIds();
        $supersededIds = array_flip($this->concentrationDemotion->supersededEntryIds($entryIds));
        $feedbackStats = $this->concentrationDemotion->feedbackStatsForEntries($entryIds);
        $relatedConflicts = $this->concentrationDemotion->relatedConflictsForEntries($entryIds);
        $knowledgeRelations = $this->knowledgeRelationsForEntries($entryIds);

        return $entries
            ->map(function (AtlasMemoryEntry $entry) use ($query, $dominantIds, $supersededIds, $feedbackStats, $relatedConflicts, $knowledgeRelations, $entryIds): ?array {
                $entryId = (string) $entry->id;
                if ($entryId !== '' && isset($supersededIds[$entryId])) {
                    return null;
                }

                $stats = $feedbackStats[$entryId] ?? [];
                $baseHybridScore = $this->blendedScore(
                    $this->entryVectorScores[$entryId] ?? null,
                    $this->lexicalScore($query, [
                        $entry->title,
                        $entry->summary,
                        $this->privacy->providerBody($entry),
                        $entry->source_type,
                    ]),
                );
                $feedbackRanking = $this->feedbackRankingExplain($stats);
                $temporalRanking = $this->temporalRankingExplain($entry, $entryIds);
                $hybridScore = $baseHybridScore
                    * (AiValueNormalizer::finiteFloatOrNull($feedbackRanking['factor'] ?? null) ?? 1.0)
                    * $this->concentrationDemotion->scoreMultiplier($entryId, $dominantIds)
                    * (AiValueNormalizer::finiteFloatOrNull($temporalRanking['factor'] ?? null) ?? 1.0);

                return [
                    'id' => $entry->id,
                    'type' => $entry->memory_type,
                    'scope' => $entry->scope_id ? $entry->scope_type.':'.$entry->scope_id : $entry->scope_type,
                    'scope_type' => $entry->scope_type,
                    'scope_id' => $entry->scope_id,
                    'title' => $this->privacy->providerTitle($entry),
                    'summary' => $this->privacy->providerSummary($entry),
                    'body' => Str::limit($this->privacy->providerBody($entry), $this->input->registryExcerptChars(), '...'),
                    'importance' => $entry->importance,
                    'priority' => $entry->priority,
                    'confidence' => $entry->confidence,
                    'privacy_class' => $entry->privacy_class,
                    'redaction_status' => $entry->redaction_status,
                    'source_type' => $entry->source_type,
                    'source_id' => $entry->source_id,
                    'source_label' => $entry->source_label,
                    'content_hash' => $entry->content_hash,
                    'provider_projection' => $this->providerProjectionMetadata($entry),
                    'recorded_at' => $entry->recorded_at?->toJSON(),
                    'last_used_at' => $entry->last_used_at?->toJSON(),
                    'governance_checked_at' => $entry->governance_checked_at?->toJSON(),
                    'privacy_reviewed_at' => $entry->privacy_reviewed_at?->toJSON(),
                    'reason' => $this->reasonForRegistry($entry, $query),
                    'hybrid_score' => round($hybridScore, 4),
                    'positive_count' => (int) ($stats['positive_count'] ?? 0),
                    'positive_explicit_count' => (int) ($stats['positive_explicit_count'] ?? 0),
                    'positive_implicit_count' => (int) ($stats['positive_implicit_count'] ?? 0),
                    'negative_count' => (int) ($stats['negative_count'] ?? 0),
                    'wrong_context_count' => (int) ($stats['wrong_context_count'] ?? 0),
                    'stale_count' => (int) ($stats['stale_count'] ?? 0),
                    'recall_eval_hit_rate' => $stats['recall_eval_hit_rate'] ?? null,
                    'concentration_demoted' => in_array($entryId, $dominantIds, true),
                    'related_conflicts' => array_values($relatedConflicts[$entryId] ?? []),
                    'relations' => $knowledgeRelations[$entryId] ?? [],
                    'explain' => [
                        'feedback_ranking' => $feedbackRanking + [
                            'base_hybrid_score' => round($baseHybridScore, 4),
                        ],
                        'temporal_ranking' => $temporalRanking,
                    ],
                ];
            })
            ->filter(fn (?array $item): bool => $item !== null)
            ->values()
            ->all();
    }

    /**
     * @return array{safe_text?:string,classification?:mixed}
     */
    private function providerProjectionMetadata(AtlasMemoryEntry $entry): array
    {
        $safeText = trim((string) data_get($entry->metadata, 'provider_projection.safe_text', ''));
        $classification = data_get($entry->metadata, 'provider_projection.classification');
        if ($safeText === '' && empty($classification)) {
            return [];
        }

        $metadata = [];
        if ($safeText !== '') {
            $metadata['safe_text'] = $safeText;
        }
        if (! empty($classification)) {
            $metadata['classification'] = $classification;
        }

        return $metadata;
    }

    /**
     * @param  array<int,string>  $poolEntryIds
     * @return array{factor:float,flags:list<string>,recoverable:bool}
     */
    private function temporalRankingExplain(AtlasMemoryEntry $entry, array $poolEntryIds): array
    {
        if (! (bool) config('atlas.semantic_memory.temporal_recall_demotion_enabled', false)) {
            return ['factor' => 1.0, 'flags' => [], 'recoverable' => true];
        }

        $factor = 1.0;
        $flags = [];
        $now = now();
        $staleAfter = $entry->stale_after;
        if ($staleAfter !== null && method_exists($staleAfter, 'lessThanOrEqualTo') && $staleAfter->lessThanOrEqualTo($now)) {
            $factor *= 0.5;
            $flags[] = 'stale_after_elapsed';
        }

        $validUntil = $entry->valid_until;
        if ($validUntil !== null && method_exists($validUntil, 'lessThanOrEqualTo') && $validUntil->lessThanOrEqualTo($now)) {
            $factor *= 0.35;
            $flags[] = 'valid_until_elapsed';
        }

        $supersededBy = is_scalar($entry->superseded_by_id ?? null) ? trim((string) $entry->superseded_by_id) : '';
        if ($supersededBy !== '' && ! in_array($supersededBy, $poolEntryIds, true)) {
            $factor *= 0.35;
            $flags[] = 'superseded_without_superseder_in_pool';
        }

        return [
            'factor' => round(max(0.05, min(1.0, $factor)), 4),
            'flags' => array_values(array_unique($flags)),
            'recoverable' => true,
        ];
    }

    /**
     * MEM-07: expose real atlas:memory:relations knowledge links to recall
     * consumers as a bounded 1-hop provider-safe annotation.
     *
     * @param  array<int,string>  $entryIds
     * @return array<string,array<string,array<int,array<string,mixed>>>>
     */
    private function knowledgeRelationsForEntries(array $entryIds): array
    {
        if ($entryIds === [] || ! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            return [];
        }

        $entrySet = array_flip($entryIds);
        $relations = AtlasMemoryEntryRelation::query()
            ->whereIn('relation_type', AtlasMemoryConflictResolutionService::KNOWLEDGE_RELATION_TYPES)
            ->whereIn('status', ['open', 'resolved'])
            ->where(function ($query) use ($entryIds): void {
                $query->whereIn('source_memory_entry_id', $entryIds)
                    ->orWhereIn('target_memory_entry_id', $entryIds);
            })
            ->latest('updated_at')
            ->limit(200)
            ->get();

        $out = [];
        foreach ($relations as $relation) {
            $source = (string) $relation->source_memory_entry_id;
            $target = (string) $relation->target_memory_entry_id;
            $type = (string) $relation->relation_type;

            if (isset($entrySet[$source])) {
                $bucket = $type === AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES ? 'superseded_by' : $type;
                $out[$source][$bucket][] = $this->relationAnnotation($relation, $target, 'source');
            }
            if (isset($entrySet[$target])) {
                $bucket = $type === AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES ? 'supersedes' : $type;
                $out[$target][$bucket][] = $this->relationAnnotation($relation, $source, 'target');
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function relationAnnotation(AtlasMemoryEntryRelation $relation, string $partnerId, string $role): array
    {
        return [
            'relation_id' => (string) $relation->id,
            'id' => $partnerId,
            'relation_type' => (string) $relation->relation_type,
            'status' => (string) $relation->status,
            'role' => $role,
            'confidence' => $relation->confidence,
            'reason' => $relation->reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function verbatimItems(string $query, array $context, array $filters, int $limit, bool $enabled): array
    {
        if (! $enabled || $limit <= 0 || ! DatabaseTableAvailability::has('atlas_verbatim_memories')) {
            return [];
        }

        $memories = $this->verbatim
            ->relevantForContext($context, $this->verbatimFilters($filters), $limit)
            ->filter(fn (AtlasVerbatimMemory $memory): bool => $memory->external_ai_allowed === true && trim((string) $memory->redacted_text) !== '')
            ->values();

        // R1: real vector similarity over the provider-safe verbatim candidates;
        // empty on sqlite / no embeddings -> existing lexical score.
        $this->verbatimVectorScores = $this->vectorSearch->scoreVerbatims(
            $query,
            $memories->map(fn (AtlasVerbatimMemory $memory): string => (string) $memory->id)->all(),
        );

        return $memories
            ->map(fn (AtlasVerbatimMemory $memory): array => [
                'id' => $memory->id,
                'type' => $memory->verbatim_type,
                'scope' => $memory->scope_id ? $memory->scope_type.':'.$memory->scope_id : $memory->scope_type,
                'scope_type' => $memory->scope_type,
                'scope_id' => $memory->scope_id,
                'title' => $memory->title,
                'summary' => $memory->summary,
                'snippet' => Str::limit((string) $memory->redacted_text, $this->input->itemChars(), '...'),
                'privacy_class' => $memory->privacy_class,
                'redaction_status' => $memory->redaction_status,
                'source_type' => $memory->source_type,
                'source_id' => $memory->source_id,
                'source_label' => $memory->source_label,
                'content_hash' => $memory->content_hash,
                'redacted_hash' => $memory->redacted_hash,
                'recorded_at' => $memory->recorded_at?->toJSON(),
                'reviewed_at' => $memory->reviewed_at?->toJSON(),
                'reason' => $query !== '' ? 'recall verbatim provider-safe filtrado por contexto e query' : 'recall verbatim provider-safe por escopo',
                'hybrid_score' => $this->blendedScore(
                    $this->verbatimVectorScores[(string) $memory->id] ?? null,
                    $this->lexicalScore($query, [
                        $memory->title,
                        $memory->summary,
                        $memory->redacted_text,
                        $memory->source_type,
                    ]),
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function semanticItems(string $query, array $filters, int $limit, bool $enabled): array
    {
        if (! $enabled || $limit <= 0 || ! DatabaseTableAvailability::has('semantic_notes')) {
            return [];
        }

        return $this->semantic
            ->search($query, (array) ($filters['semantic'] ?? []), $limit)
            ->map(fn (SemanticNote $note): array => $this->semanticItem($note))
            ->filter(fn (array $item): bool => ($item['external_ai_allowed'] ?? false) === true)
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function semanticItem(SemanticNote $note): array
    {
        $privacy = $this->sourcePrivacy->project('semantic_note', [
            'title' => $note->title,
            'summary' => $note->summary,
            'body_excerpt' => $note->body_excerpt,
            'path' => $note->path,
            'frontmatter' => $note->frontmatter ?? [],
            'metadata' => $note->metadata ?? [],
            'domains' => $note->domains ?? [],
        ]);

        return [
            'id' => $note->id,
            'type' => $note->type,
            'path' => $note->path,
            'title' => data_get($privacy, 'fields.title') ?? $note->title,
            'summary' => data_get($privacy, 'fields.summary') ?? $note->summary,
            'excerpt' => data_get($privacy, 'fields.body') ?? data_get($privacy, 'fields.summary'),
            'score' => AiValueNormalizer::finiteFloatOrNull($note->score ?? null) ?? 0.55,
            'privacy_class' => $privacy['privacy_class'],
            'external_ai_allowed' => $privacy['external_ai_allowed'],
            'redaction_status' => $privacy['redaction_status'],
            'source_type' => 'semantic_note',
            'source_id' => $note->id,
            'source_label' => $note->path,
            'content_hash' => is_scalar(data_get($note->metadata, 'content_hash')) ? (string) data_get($note->metadata, 'content_hash') : hash('sha256', implode('|', [
                (string) $note->path,
                (string) $note->title,
                (string) $note->summary,
                (string) $note->body_excerpt,
            ])),
            'recorded_at' => $note->updated_at?->toJSON() ?? $note->created_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function registryFilters(array $filters): array
    {
        return array_filter([
            'types' => $filters['types'] ?? $filters['memory_type'] ?? [],
            'privacy_class' => $filters['privacy_class'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function verbatimFilters(array $filters): array
    {
        return array_filter([
            'types' => $filters['verbatim_types'] ?? $filters['verbatim_type'] ?? [],
            'privacy_class' => $filters['privacy_class'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * Blend the REAL vector similarity (primary) with the lexical token-overlap
     * score (tiebreaker/fallback) into the single `hybrid_score` the composer
     * weights. When no vector exists for the row (NULL embedding / sqlite / no
     * embedding engine) this is exactly the lexical score — an honest degrade,
     * never a fabricated semantic number.
     */
    private function blendedScore(?float $vectorScore, float $lexicalScore): float
    {
        if ($vectorScore === null) {
            return $lexicalScore;
        }

        $vectorScore = max(0.0, min(1.0, $vectorScore));
        $blended = (self::VECTOR_WEIGHT * $vectorScore) + (self::LEXICAL_WEIGHT * $lexicalScore);

        // Never let the blend rank a real semantic hit BELOW a pure substring hit.
        return round(max($blended, $lexicalScore), 4);
    }

    /**
     * @param  array<int,mixed>  $fields
     */
    private function lexicalScore(string $query, array $fields): float
    {
        if ($query === '') {
            return 0.0;
        }

        return DomainLexicalNormalizer::score($query, $fields);
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $query): array
    {
        return DomainLexicalNormalizer::tokens($query);
    }

    private function reasonForRegistry(AtlasMemoryEntry $entry, string $query): string
    {
        $base = match ($entry->scope_type) {
            'global' => 'memoria global provider-safe',
            'workspace' => 'memoria provider-safe ligada ao workspace',
            default => 'memoria provider-safe ligada ao contexto atual',
        };

        return $query !== '' ? $base.' com sinal lexical da consulta' : $base;
    }

    /**
     * @param  array<string,mixed>  $stats
     * @return array<string,mixed>
     */
    private function feedbackRankingExplain(array $stats): array
    {
        $explicitPositive = max(0, (int) ($stats['positive_explicit_count'] ?? 0));
        $implicitPositive = max(0, (int) ($stats['positive_implicit_count'] ?? 0));
        $negative = max(0, (int) ($stats['negative_count'] ?? 0));
        $implicitWeight = 0.01;

        if (! (bool) config('atlas.memory.feedback_ranking_enabled', false)) {
            return [
                'enabled' => false,
                'factor' => 1.0,
                'positive_explicit_count' => $explicitPositive,
                'positive_implicit_count' => $implicitPositive,
                'negative_count' => $negative,
                'implicit_positive_weight' => $implicitWeight,
            ];
        }

        $weightedPositive = $explicitPositive + ($implicitPositive * $implicitWeight);
        $rawFactor = ($weightedPositive + 1.0) / ($negative + 1.0);
        $factor = round(max(0.7, min(1.15, $rawFactor)), 4);

        return [
            'enabled' => true,
            'factor' => $factor,
            'raw_factor' => round($rawFactor, 4),
            'positive_explicit_count' => $explicitPositive,
            'positive_implicit_count' => $implicitPositive,
            'negative_count' => $negative,
            'weighted_positive_count' => round($weightedPositive, 4),
            'implicit_positive_weight' => $implicitWeight,
            'clamp' => ['min' => 0.7, 'max' => 1.15],
        ];
    }

    /**
     * MAXH-09 — parse the caller-supplied `--as-of` payload into a CarbonImmutable
     * instant (or `null` when omitted/invalid). Accepts ISO-8601 strings,
     * `Y-m-d` dates, epoch seconds, `CarbonImmutable`, or `DateTimeInterface`.
     * Anything unparseable degrades to `null` (fail-open: no as-of applied,
     * recall stays byte-identical).
     */
    private function resolveAsOfInstant(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if ($value instanceof CarbonImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($value));
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            try {
                return CarbonImmutable::createFromTimestamp((int) $value);
            } catch (\Throwable) {
                return null;
            }
        }
        if (is_string($value)) {
            try {
                return CarbonImmutable::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * MAXH-09 — assemble the temporal filter payload from the option bag.
     * Returns `null` when neither knob is set → callers stay on the byte-
     * identical fast path (no post-fetch filter, no scope work).
     *
     * @param  array<string,mixed>  $options
     * @return array{as_of:?CarbonImmutable,current_only:bool}|null
     */
    private function temporalFilterFromOptions(array $options, ?CarbonImmutable $asOfInstant): ?array
    {
        $currentOnly = (bool) ($options[self::OPTION_CURRENT_ONLY] ?? false);
        if ($asOfInstant === null && ! $currentOnly) {
            return null;
        }

        return [
            'as_of' => $asOfInstant,
            'current_only' => $currentOnly,
        ];
    }

    /**
     * MAXH-09 — same predicate the `HasTemporalTruth::current($at)` scope
     * applies at the query layer:
     *   - `valid_from` is NULL or ≤ reference
     *   - `valid_until` is NULL or > reference
     *   - `superseded_by_id` is NULL
     *
     * `as_of` shifts the reference instant; `current_only` alone uses `now()`.
     * All-NULL temporal columns (legacy rows) pass — mirrors the trait's
     * "no expiry" tolerance so this projection never lies by omission.
     *
     * @param  array{as_of:?CarbonImmutable,current_only:bool}  $filter
     */
    private function matchesTemporalFilter(AtlasMemoryEntry $entry, array $filter): bool
    {
        $asOf = $filter['as_of'] ?? null;
        $reference = $asOf ?? CarbonImmutable::now();

        $validFrom = $entry->valid_from;
        if ($validFrom !== null && method_exists($validFrom, 'greaterThan') && $validFrom->greaterThan($reference)) {
            return false;
        }

        $validUntil = $entry->valid_until;
        if ($validUntil !== null && method_exists($validUntil, 'lessThanOrEqualTo') && $validUntil->lessThanOrEqualTo($reference)) {
            return false;
        }

        $supersededBy = is_scalar($entry->superseded_by_id ?? null) ? trim((string) $entry->superseded_by_id) : '';
        if ($supersededBy !== '') {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function publicContext(array $context): array
    {
        $workspace = $context['workspace'] ?? $context['workspace_path'] ?? null;

        return array_filter([
            'workspace' => is_scalar($workspace) ? (string) $workspace : null,
            'project_id' => $context['project_id'] ?? null,
            'task_id' => $context['task_id'] ?? null,
            'engineering_run_id' => $context['engineering_run_id'] ?? ($context['run_id'] ?? null),
            'session_id' => $context['session_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
