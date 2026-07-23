<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\AtlasOpenBrainMemoryProjectionSafetyGate;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\SemanticContextRetrievalService;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;
use Throwable;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim memory family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class MemorySection
{
    /**
     * Glue terms that should not make a global memory look task-relevant.
     *
     * @var array<string,true>
     */
    private const RELEVANCE_STOP_TERMS = [
        'aobg' => true, 'atlas' => true, 'context' => true, 'contexto' => true,
        'quality' => true, 'qualidade' => true, 'melhorar' => true, 'arrumar' => true,
        'implementar' => true, 'debug' => true, 'loop' => true, 'service' => true,
        'para' => true, 'com' => true, 'sem' => true, 'que' => true, 'uma' => true,
        'the' => true, 'and' => true, 'for' => true, 'with' => true,
    ];

    /** RAG-04 — per-item floor when splitting the memory sub-budget across recalls. */
    private const MEMORY_ITEM_MIN_BUDGET_CHARS = 400;

    private const MEMORY_BODY_TRUNCATION_MARKER = '… [truncated]';

    public function __construct(
        private readonly Support $support,
        private readonly AtlasHybridMemoryRetrievalService $memory,
        private readonly AtlasOpenBrainMemoryProjectionSafetyGate $memoryProjectionSafetyGate,
        private readonly AtlasMemoryUsageService $memoryUsage,
        private readonly SemanticContextRetrievalService $semanticContext,
    ) {}

    /**
     * Semantic memory section — the hybrid recall returns provider-safe REDACTED
     * projections only (it filters by AtlasMemoryPrivacyService and the verbatim
     * external_ai_allowed flag). We surface the redacted titles/summaries/bodies
     * + ids/hashes, never raw bodies, and trim to the char sub-budget.
     *
     * @return array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    public function memorySection(string $task, string $workspaceId, int $budgetChars, array $opts = []): array
    {
        $empty = [
            'present' => false,
            'items' => [],
            'chars' => 0,
            'provenance' => [
                'policy' => 'provider_safe_only',
                'status' => 'empty',
                'status_reason' => 'no_candidates',
                'note' => AtlasOpenBrainContextPackService::HONESTY_LABEL,
            ],
        ];

        if ($task === '' || $budgetChars <= 0) {
            $empty['provenance']['status_reason'] = $task === '' ? 'blank_task' : 'budget_zero';

            return $empty;
        }

        try {
            $recall = $this->memory->recall(
                $task,
                ['workspace' => $workspaceId],
                [],
                [
                    'budget_chars' => $budgetChars,
                    'requester' => 'atlas_context_pack',
                    'record_usage' => false,
                ],
            );
        } catch (Throwable) {
            $empty['provenance']['status'] = 'retrieval_error';
            $empty['provenance']['status_reason'] = 'memory_recall_exception';

            return $empty;
        }

        $candidates = [];
        foreach ((array) ($recall['recall'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            $summary = (string) ($row['summary'] ?? '');
            $body = (string) ($row['body'] ?? ($row['snippet'] ?? ($row['excerpt'] ?? '')));
            $lineage = is_array($row['lineage'] ?? null) ? $row['lineage'] : [];
            $freshness = is_array($row['freshness'] ?? null) ? $row['freshness'] : [];
            $candidates[] = [
                // Interno ao floor (removido antes de servir): score do ranker híbrido —
                // é o que autoriza o rank-escape do floor lexical (P0 do pack).
                '_recall_score' => AiValueNormalizer::finiteFloatOrNull($row['score'] ?? null) ?? 0.0,
                // RAG-03: stable concept guard input, never rendered into the pack.
                '_incident_scope' => (string) data_get($row, 'metadata.incident_scope', ''),
                // T4-S5: the recalled entry id (provider-safe provenance) so the dialectic
                // engine can look up OPEN conflict relations among the delivered memories.
                'id' => (string) ($row['source_ref_id'] ?? ($row['id'] ?? '')),
                'type' => (string) ($row['type'] ?? ''),
                'scope' => (string) ($row['scope'] ?? ''),
                'title' => $title,
                'summary' => $summary,
                'body' => $body,
                'privacy_class' => (string) ($row['privacy_class'] ?? ''),
                // ids/hashes only — provenance the consumer can audit, no raw content.
                'source_type' => (string) ($row['source_type'] ?? data_get($lineage, 'origin_type', $row['source'] ?? '')),
                'content_hash' => (string) ($row['content_hash'] ?? data_get($lineage, 'content_hash', data_get($row, 'audit_trail.content_hash', ''))),
                'recorded_at' => (string) ($row['recorded_at'] ?? data_get($freshness, 'recorded_at', '')),
                'provider_projection' => is_array($row['provider_projection'] ?? null) ? $row['provider_projection'] : [],
            ];
        }
        $this->recordPackMemoryPreFilterUsage($task, $workspaceId, $recall);
        [$candidates, $demotedCount] = $this->filterDemotedMemoryItems($candidates, $this->support->stringList($opts['_demote_context_refs'] ?? []));
        [$candidates, $relevanceFilteredCount] = $this->filterLowRelevanceMemoryItems($task, $candidates);
        [$candidates, $projectionSafetyBlockedCount] = $this->filterProviderSafeMemoryCandidates($candidates);

        // L3-6: optional semantic re-rank over the recalled items (symbols+docs) via
        // the REAL local embedding engine. Flag-gated (atlas.aobg.semantic_retrieval,
        // default OFF) and fail-open — on any miss the lexical recall order stands.
        [$candidates, $memoryMode] = $this->semanticallyReorderMemory($task, $candidates);

        [$items, $chars] = $this->packMemoryItemsWithinBudget($candidates, $budgetChars);
        $this->recordPackMemoryDeliveryUsage($task, $workspaceId, $recall, $items);
        $recalledCount = (int) data_get($recall, 'summary.recall_count', count($items));
        $status = $items !== []
            ? 'ready'
            : ($recalledCount > 0 ? 'filtered' : 'empty');
        $statusReason = match ($status) {
            'ready' => 'items_delivered',
            'filtered' => 'provider_safe_relevance_policy',
            default => 'no_candidates',
        };

        return [
            'present' => $items !== [],
            'items' => $items,
            'chars' => $chars,
            'provenance' => [
                'policy' => (string) data_get($recall, 'summary.policy', 'provider_safe_only'),
                'status' => $status,
                'status_reason' => $statusReason,
                'recall_count' => $recalledCount,
                'redacted_ref_count' => (int) data_get($recall, 'summary.redacted_ref_count', 0),
                'retrieval_mode' => $memoryMode,
                'feedback_demoted_count' => $demotedCount,
                'relevance_filtered_count' => $relevanceFilteredCount,
                'projection_safety_blocked_count' => $projectionSafetyBlockedCount,
                'note' => AtlasOpenBrainContextPackService::HONESTY_LABEL,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public function filterProviderSafeMemoryCandidates(array $candidates): array
    {
        if ($candidates === []) {
            return [[], 0];
        }

        $kept = [];
        $blocked = 0;
        foreach ($candidates as $candidate) {
            $safeText = trim((string) data_get($candidate, 'provider_projection.safe_text', ''));
            $classification = data_get($candidate, 'provider_projection.classification');
            $gate = $this->memoryProjectionSafetyGate->evaluate([
                'summary' => (string) ($candidate['summary'] ?? ''),
                'excerpt' => (string) ($candidate['body'] ?? ''),
                'title' => (string) ($candidate['title'] ?? ''),
                'source' => (string) ($candidate['source_type'] ?? ($candidate['id'] ?? '')),
                'freshness' => (string) ($candidate['content_hash'] ?? ''),
                'recorded_at' => (string) ($candidate['recorded_at'] ?? ''),
                'safe_text' => $safeText,
                'classification' => $classification,
            ]);
            if (($gate['accepted'] ?? false) !== true) {
                $blocked++;

                continue;
            }
            if ($safeText !== '' && ! empty($classification)) {
                $candidate['title'] = 'sanitized:'.$this->classificationLabel($classification);
                $candidate['summary'] = $safeText;
                $candidate['body'] = '';
            }
            $kept[] = $candidate;
        }

        return [$kept, $blocked];
    }

    public function classificationLabel(mixed $classification): string
    {
        if (is_scalar($classification)) {
            $label = trim((string) $classification);

            return $label !== '' ? Str::limit($label, 80, '') : 'memory_projection';
        }

        return 'memory_projection';
    }

    /**
     * RAG-01 — persist ranking signal before pack filters (dominance sensor feed).
     *
     * @param  array<string,mixed>  $recall
     */
    public function recordPackMemoryPreFilterUsage(string $task, string $workspaceId, array $recall): void
    {
        $rows = $this->recallRowsForUsage($recall);
        if ($rows === []) {
            return;
        }

        $this->memoryUsage->recordRecallUsages($task, ['workspace' => $workspaceId], $rows, [
            'source' => 'atlas_context_pack',
            'usage_source_type' => AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER,
            'delivery_surface' => AtlasMemoryUsageService::DELIVERY_SURFACE_CONTEXT_PACK,
            'created_by' => 'atlas_context_pack_pre_filter',
        ]);
    }

    /**
     * RAG-01 — record delivery-point usage only for memories actually served.
     *
     * @param  array<string,mixed>  $recall
     * @param  array<int,array<string,mixed>>  $deliveredItems
     */
    public function recordPackMemoryDeliveryUsage(string $task, string $workspaceId, array $recall, array $deliveredItems): void
    {
        if ($deliveredItems === []) {
            return;
        }

        $deliveredIds = [];
        foreach ($deliveredItems as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $deliveredIds[$id] = true;
            }
        }
        if ($deliveredIds === []) {
            return;
        }

        $rows = array_values(array_filter(
            $this->recallRowsForUsage($recall),
            static fn (array $row): bool => isset($deliveredIds[(string) ($row['source_ref_id'] ?? '')]),
        ));
        if ($rows === []) {
            return;
        }

        $this->memoryUsage->recordRecallUsages($task, ['workspace' => $workspaceId], $rows, [
            'source' => 'atlas_context_pack',
            'usage_source_type' => AtlasMemoryUsageService::SOURCE_TYPE_MEMORY_RECALL,
            'delivery_surface' => AtlasMemoryUsageService::DELIVERY_SURFACE_CONTEXT_PACK,
            'created_by' => 'atlas_context_pack_delivery',
        ]);
    }

    /**
     * @param  array<string,mixed>  $recall
     * @return array<int,array<string,mixed>>
     */
    public function recallRowsForUsage(array $recall): array
    {
        $rows = [];
        foreach ((array) ($recall['recall'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['source_ref_type'] ?? null) !== 'atlas_memory_entry') {
                continue;
            }
            if (! is_string($row['source_ref_id'] ?? null) || $row['source_ref_id'] === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public function filterLowRelevanceMemoryItems(string $task, array $items): array
    {
        if ($items === []) {
            return [$items, 0];
        }

        $taskTokens = $this->relevanceTokens($task);
        if ($taskTokens === []) {
            return [$items, 0];
        }

        $filtered = [];
        $removed = 0;
        foreach ($items as $item) {
            if ($this->memoryItemRelevantToTask($task, $taskTokens, $item)) {
                $filtered[] = $item;

                continue;
            }
            $removed++;
        }

        return [$filtered, $removed];
    }

    /**
     * @param  array<string,true>  $taskTokens
     * @param  array<string,mixed>  $item
     */
    public function memoryItemRelevantToTask(string $task, array $taskTokens, array $item): bool
    {
        $title = (string) ($item['title'] ?? '');
        $summary = (string) ($item['summary'] ?? '');
        $body = (string) ($item['body'] ?? '');
        $text = $title.' '.$summary.' '.$body;

        if ($this->isStableWiperIncidentMemory($item)) {
            return $this->taskAllowsWiperMemory($task);
        }

        // P0 do pack (09/07): overlap lexical NÃO separa sinal de lixo neste corpus —
        // títulos EN vs queries PT dão 0 overlap no sinal real, e o floor zerava a seção
        // memory em TODA query natural. Autoridade de relevância = o SCORE do ranker
        // híbrido (lexical+semântico+recência): item que o ranker PONTUOU é entregue
        // (demotion por feedback e o wiper-guard acima continuam valendo); o floor
        // lexical >=2 fica como rede só pra itens que chegaram SEM pontuação.
        if ((AiValueNormalizer::finiteFloatOrNull($item['_recall_score'] ?? null) ?? 0.0) > 0) {
            return true;
        }

        $memoryTokens = $this->relevanceTokens($text);
        if ($memoryTokens === []) {
            return false;
        }

        $overlap = 0;
        foreach ($taskTokens as $token => $_) {
            if (isset($memoryTokens[$token])) {
                $overlap++;
            }
        }

        return $overlap >= 2;
    }

    /**
     * RAG-03: the incident guard is keyed by stable metadata/ref identity, not a
     * volatile text list that changes as the corpus evolves.
     *
     * @param  array<string,mixed>  $item
     */
    public function isStableWiperIncidentMemory(array $item): bool
    {
        if ((string) ($item['_incident_scope'] ?? '') === 'wiper') {
            return true;
        }

        $stableRefs = $this->support->stringList(config('atlas.semantic_memory.wiper_incident_context_refs', []));
        if ($stableRefs === []) {
            return false;
        }

        return $this->support->matchesDemotedRef($this->memoryItemRefs($item), $stableRefs);
    }

    public function taskAllowsWiperMemory(string $task): bool
    {
        $text = $this->support->normalizedIntentText($task);

        return $this->support->containsAny($text, ['wiper', 'drop table', 'refreshdatabase', 'vendor symlink', 'pgsql', 'postgres', 'test safety', 'suite frankenstein']);
    }

    /**
     * @return array<string,true>
     */
    public function relevanceTokens(string $text): array
    {
        $text = $this->support->normalizedIntentText($text);
        preg_match_all('/[a-z0-9][a-z0-9._-]{2,}/', $text, $matches);

        $tokens = [];
        foreach ($matches[0] ?? [] as $token) {
            $token = trim((string) $token, '._-');
            if ($token === '' || isset(self::RELEVANCE_STOP_TERMS[$token])) {
                continue;
            }
            $tokens[$token] = true;
        }

        return $tokens;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,string>  $demoteRefs
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public function filterDemotedMemoryItems(array $items, array $demoteRefs): array
    {
        if ($items === [] || $demoteRefs === []) {
            return [$items, 0];
        }

        $filtered = [];
        $demoted = 0;
        foreach ($items as $item) {
            if ($this->support->matchesDemotedRef($this->memoryItemRefs($item), $demoteRefs)) {
                $demoted++;

                continue;
            }
            $filtered[] = $item;
        }

        return [$filtered, $demoted];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<int,string>
     */
    public function memoryItemRefs(array $item): array
    {
        $refs = AtlasCanonicalContextRef::memoryItemForms($item);
        $id = trim((string) ($item['id'] ?? ''));
        if ($id !== '') {
            $refs[] = $id;
            $refs[] = 'atlas_memory_entry:'.$id;
        }

        return AtlasCanonicalContextRef::uniqueStrings($refs);
    }

    /**
     * L3-6 semantic re-rank of recalled memory items via the real local embedding
     * engine. Returns [reordered items, mode] where mode is 'semantic' (real
     * embeddings reordered the set) or 'lexical' (off / unavailable / fail-open).
     * The item text NEVER leaves the local runtime; only the reordering is applied.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    public function semanticallyReorderMemory(string $task, array $candidates): array
    {
        if (count($candidates) < 2 || trim($task) === '') {
            return [$candidates, 'lexical'];
        }

        $byId = [];
        $items = [];
        foreach ($candidates as $index => $candidate) {
            $id = 'mem_'.$index;
            $byId[$id] = $candidate;
            $items[] = [
                'id' => $id,
                'text' => trim(implode(' ', array_filter([
                    (string) ($candidate['title'] ?? ''),
                    (string) ($candidate['summary'] ?? ''),
                    (string) ($candidate['body'] ?? ''),
                ]))),
            ];
        }

        try {
            $ranked = $this->semanticContext->rank($task, $items, count($items));
        } catch (Throwable) {
            return [$candidates, 'lexical'];
        }

        $mode = (string) ($ranked['mode'] ?? 'lexical');
        if (! in_array($mode, ['semantic', 'cross_encoder', 'late_interaction'], true) || ($ranked['ranked'] ?? []) === []) {
            return [$candidates, 'lexical'];
        }

        $reordered = [];
        foreach ((array) $ranked['ranked'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && isset($byId[$id])) {
                $reordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        if ($mode === 'semantic') {
            // Append anything the vector ranker omitted, preserving the original recall order.
            foreach ($byId as $candidate) {
                $reordered[] = $candidate;
            }
        }

        return [$reordered, $mode];
    }

    /**
     * RAG-04 — deliver N compact memory items instead of one oversized first hit.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public function packMemoryItemsWithinBudget(array $candidates, int $budgetChars): array
    {
        if ($candidates === [] || $budgetChars <= 0) {
            return [[], 0];
        }

        $count = count($candidates);
        $perItemCap = max(self::MEMORY_ITEM_MIN_BUDGET_CHARS, intdiv($budgetChars, $count));
        $items = [];
        $chars = 0;

        foreach ($candidates as $candidate) {
            unset($candidate['_recall_score']); // interno ao floor — nunca servido
            unset($candidate['_incident_scope']); // internal stable-concept guard marker
            $remaining = $budgetChars - $chars;
            if ($remaining <= 0 && $items !== []) {
                break;
            }

            $cap = min($perItemCap, max($remaining, 0));
            if ($items !== [] && $cap < self::MEMORY_ITEM_MIN_BUDGET_CHARS) {
                break;
            }

            $compact = $this->compactMemoryItemForPack($candidate, $cap);
            $entryChars = $this->memoryItemRenderedChars($compact);
            if ($items !== [] && $chars + $entryChars > $budgetChars) {
                break;
            }

            $items[] = $compact;
            $chars += $entryChars;
        }

        return [$items, $chars];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    public function compactMemoryItemForPack(array $item, int $maxChars): array
    {
        $title = (string) ($item['title'] ?? '');
        $summary = (string) ($item['summary'] ?? '');
        $body = (string) ($item['body'] ?? '');
        $fixedChars = strlen($title.$summary);
        $maxBodyChars = max(0, $maxChars - $fixedChars);

        if (strlen($body) <= $maxBodyChars) {
            return $item;
        }

        // MAXE-08 degradation ladder: body-inteiro → summary-inteiro (body omitted)
        // → truncated body with marker (last resort). Title+summary are only
        // sacrificed when the item cannot fit at all with body omitted.
        if ($body !== '' && $fixedChars <= $maxChars) {
            $item['body'] = '';
            $item['body_omitted'] = true;

            return $item;
        }

        $marker = self::MEMORY_BODY_TRUNCATION_MARKER;
        $markerLen = strlen($marker);
        if ($maxBodyChars <= $markerLen) {
            $item['body'] = mb_substr($body, 0, $maxBodyChars);
        } else {
            $item['body'] = mb_substr($body, 0, $maxBodyChars - $markerLen).$marker;
        }

        return $item;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    public function memoryItemRenderedChars(array $item): int
    {
        return strlen(
            (string) ($item['title'] ?? '')
            .(string) ($item['summary'] ?? '')
            .(string) ($item['body'] ?? ''),
        );
    }
}
