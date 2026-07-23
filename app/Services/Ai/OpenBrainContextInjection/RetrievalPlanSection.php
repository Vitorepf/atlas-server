<?php

namespace App\Services\Ai\OpenBrainContextInjection;

/**
 * Godfile split (GOD-DEBULK D3, 2026-07-22): the AP-101/102/103/104 retrieval-plan
 * family, moved VERBATIM out of {@see \App\Services\Ai\AtlasOpenBrainContextInjectionService}.
 * Self-contained (globals only, no injected deps). The AP-102/103/104 scanner pins that
 * used to require these signatures/tokens on the service now require them here — invariant
 * unchanged, only the file location moved. See RetrievalAudit + OpenBrainAudit.
 */
class RetrievalPlanSection
{
    /**
     * @param  array<string,mixed>  $retrievalPlan
     * @return array<string,mixed>|null
     */
    public function retrievalPlanSummary(array $retrievalPlan, array $contextRefs, array $knowledgeRefs, array $codeRefs, array $contextPack): ?array
    {
        if ($retrievalPlan === []) {
            return null;
        }

        $selected = array_values(array_filter((array) ($retrievalPlan['selected_sources'] ?? []), 'is_array'));
        $required = array_values(array_filter($selected, fn (array $source): bool => (bool) ($source['required'] ?? false)));
        $availability = $this->retrievalSourceAvailability($selected, $contextRefs, $knowledgeRefs, $codeRefs, $contextPack);

        $reviewSignal = $this->retrievalReviewSignal($availability);

        return [
            'schema_version' => $retrievalPlan['schema_version'] ?? null,
            'mode' => $retrievalPlan['mode'] ?? null,
            'selected_source_count' => count($selected),
            'selected_sources' => array_values(array_map(fn (array $source): string => (string) ($source['type'] ?? 'unknown'), $selected)),
            'required_sources' => array_values(array_map(fn (array $source): string => (string) ($source['type'] ?? 'unknown'), $required)),
            'available_sources' => array_values(array_keys(array_filter($availability, fn (array $source): bool => (bool) $source['available']))),
            'unavailable_sources' => array_values(array_keys(array_filter($availability, fn (array $source): bool => ! (bool) $source['available']))),
            'required_unavailable_sources' => array_values(array_keys(array_filter($availability, fn (array $source): bool => (bool) $source['required'] && ! (bool) $source['available']))),
            'availability' => $availability,
            'review_signal' => $reviewSignal,
            'provider_safe_only' => (bool) data_get($retrievalPlan, 'policy.provider_safe_only', true),
            'max_context_refs' => data_get($retrievalPlan, 'budgets.max_context_refs'),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $selected
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<int,array<string,mixed>>  $knowledgeRefs
     * @param  array<int,array<string,mixed>>  $codeRefs
     * @param  array<string,mixed>  $contextPack
     * @return array<string,array<string,mixed>>
     */
    private function retrievalSourceAvailability(array $selected, array $contextRefs, array $knowledgeRefs, array $codeRefs, array $contextPack): array
    {
        $refs = collect($contextRefs);
        $counts = [
            'vector_retrieval' => $refs->where('type', 'semantic_note')->count(),
            'memory_signals' => $refs->whereIn('type', ['atlas_memory_entry', 'atlas_verbatim_memory', 'semantic_note'])->count(),
            'code_intelligence' => count($codeRefs),
            'evidence_replay' => $this->evidenceReplayCount($contextRefs, $contextPack),
            'graph_retrieval' => $this->graphRetrievalCount($contextRefs, $contextPack),
            'knowledge_base' => count($knowledgeRefs),
        ];

        $availability = [];
        foreach ($selected as $source) {
            $type = (string) ($source['type'] ?? 'unknown');
            $count = (int) ($counts[$type] ?? 0);
            $availability[$type] = [
                'available' => $count > 0,
                'count' => $count,
                'required' => (bool) ($source['required'] ?? false),
                'unavailable_action' => (string) ($source['unavailable_action'] ?? 'degrade_with_review_signal'),
            ];
        }

        return $availability;
    }

    /**
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<string,mixed>  $contextPack
     */
    private function evidenceReplayCount(array $contextRefs, array $contextPack): int
    {
        $refCount = collect($contextRefs)
            ->whereIn('type', ['atlas_ledger_event', 'atlas_replay_event', 'evidence_replay'])
            ->count();

        return $refCount
            + count((array) data_get($contextPack, 'evidence.previous_traces', []))
            + count((array) data_get($contextPack, 'evidence.replay_events', []))
            + count((array) data_get($contextPack, 'evidence.replay_refs', []));
    }

    /**
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<string,mixed>  $contextPack
     */
    private function graphRetrievalCount(array $contextRefs, array $contextPack): int
    {
        $refCount = collect($contextRefs)
            ->whereIn('type', ['graph_relation', 'knowledge_graph_edge', 'graph_retrieval'])
            ->count();

        return $refCount + count((array) data_get($contextPack, 'graph.relations', []));
    }

    /**
     * @param  array<string,mixed>  $retrievalPlan
     * @return array<int,string>
     */
    public function retrievalPlanWarnings(array $retrievalPlan): array
    {
        $unavailable = array_values((array) ($retrievalPlan['unavailable_sources'] ?? []));
        $requiredUnavailable = array_values((array) ($retrievalPlan['required_unavailable_sources'] ?? []));
        $warnings = [];

        if ($unavailable !== []) {
            $warnings[] = 'retrieval_source_unavailable';
        }

        if ($requiredUnavailable !== []) {
            $warnings[] = 'retrieval_required_source_unavailable';
        }

        return $warnings;
    }

    /**
     * @param  array<string,array<string,mixed>>  $availability
     * @return array<string,mixed>
     */
    private function retrievalReviewSignal(array $availability): array
    {
        $unavailable = array_values(array_keys(array_filter($availability, fn (array $source): bool => ! (bool) $source['available'])));
        $requiredUnavailable = array_values(array_keys(array_filter($availability, fn (array $source): bool => (bool) $source['required'] && ! (bool) $source['available'])));

        if ($requiredUnavailable !== []) {
            return [
                'status' => 'blocking',
                'severity' => 'high',
                'reason' => 'required_retrieval_source_unavailable',
                'sources' => $requiredUnavailable,
                'recommended_action' => $this->retrievalRecommendedAction($requiredUnavailable),
            ];
        }

        if ($unavailable !== []) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reason' => 'optional_retrieval_source_unavailable',
                'sources' => $unavailable,
                'recommended_action' => $this->retrievalRecommendedAction($unavailable),
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'reason' => 'all_selected_retrieval_sources_available',
            'sources' => [],
            'recommended_action' => 'none',
        ];
    }

    /**
     * @param  array<int,string>  $sources
     */
    private function retrievalRecommendedAction(array $sources): string
    {
        if (in_array('evidence_replay', $sources, true)) {
            return 'refresh_evidence_replay_or_attach_trace_before_retry';
        }

        if (in_array('code_intelligence', $sources, true)) {
            return 'refresh_code_intelligence_before_retry';
        }

        if (in_array('memory_signals', $sources, true) || in_array('vector_retrieval', $sources, true)) {
            return 'refresh_memory_context_before_retry';
        }

        if (in_array('graph_retrieval', $sources, true)) {
            return 'degrade_graph_context_or_attach_relationship_evidence';
        }

        return 'refresh_context_sources_before_retry';
    }
}
