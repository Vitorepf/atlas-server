<?php

namespace App\Services\Ai\Memory;

use App\Services\Ai\AgenticEngineeringOs\Scoring\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\ContextParetoDominanceFilter;
use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryFeedbackDecayScorer;
use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryInjectionBudgetAllocator;
use App\Services\Ai\Memory\MemoryMmrTopKSelector;
use App\Services\Ai\Memory\MemoryRecallInput;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

class AtlasMemoryContextComposer
{
    public function __construct(
        private readonly MemoryRecallInput $input,
        private readonly AtlasMemoryRecallRelevanceScorer $scorer,
        private readonly ContextParetoDominanceFilter $paretoFilter = new ContextParetoDominanceFilter,
        private readonly MemoryInjectionBudgetAllocator $budgetAllocator = new MemoryInjectionBudgetAllocator,
        private readonly MemoryFeedbackDecayScorer $decayScorer = new MemoryFeedbackDecayScorer,
        private readonly MemoryMmrTopKSelector $mmrSelector = new MemoryMmrTopKSelector,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $registry
     * @param  array<int,array<string,mixed>>  $verbatim
     * @param  array<int,array<string,mixed>>  $semantic
     * @param  array<string,mixed>  $options
     * @param  array<int,array<string,mixed>>  $compounding  ACDE #3 — promoted compounding learnings (4th
     *                                                        source, LAST + default [] so every existing
     *                                                        caller is byte-identical; only the recall seam
     *                                                        passes it, flag-gated)
     * @return array<int,array<string,mixed>>
     */
    public function compose(array $registry, array $verbatim, array $semantic, array $options = [], array $compounding = []): array
    {
        $limit = $this->input->recallLimit($options['memory_recall_limit'] ?? null);
        $budget = $this->input->budgetChars($options['memory_recall_budget_chars'] ?? null);
        $itemChars = $this->input->itemChars($options['memory_recall_item_chars'] ?? null);

        if ($budget <= 0 || $itemChars <= 0) {
            return [];
        }

        $candidates = array_merge(
            $this->registryCandidates($registry),
            $this->verbatimCandidates($verbatim),
            $this->semanticCandidates($semantic),
            $this->compoundingCandidates($compounding),
        );

        $candidates = $this->dropParetoDominatedCandidates($candidates);

        usort($candidates, fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
            ?: (($right['health_score'] ?? 0) <=> ($left['health_score'] ?? 0))
            ?: strcmp((string) $left['source'], (string) $right['source'])
            ?: strcmp((string) $left['title'], (string) $right['title']));

        // MAXB-04 — MMR after sort, before greedy budget (default-OFF).
        $candidates = $this->applyMmrTopK($candidates, $limit);

        $totalBudget = $budget;

        $items = [];
        foreach ($candidates as $candidate) {
            if (count($items) >= $limit) {
                break;
            }

            $available = min($budget, $itemChars);
            if ($available < 40) {
                break;
            }

            $excerpt = $this->limitWithin((string) $candidate['text'], $available);
            if ($excerpt === '') {
                continue;
            }

            $items[] = [
                'rank' => count($items) + 1,
                'source' => $candidate['source'],
                'source_ref_type' => $candidate['source_ref_type'],
                'source_ref_id' => $candidate['source_ref_id'],
                'type' => $candidate['type'],
                'scope' => $candidate['scope'],
                'title' => $candidate['title'],
                'summary' => $candidate['summary'],
                'excerpt' => $excerpt,
                'score' => round(AiValueNormalizer::finiteFloatOrNull($candidate['score'] ?? null) ?? 0.0, 3),
                'reason' => $candidate['reason'],
                'estimated_chars' => Str::length($excerpt),
                'lineage' => $candidate['lineage'],
                'freshness' => $candidate['freshness'],
                'provider_projection' => $candidate['provider_projection'],
                'audit' => $candidate['audit'],
                'audit_trail' => $candidate['audit'],
                'explain' => $candidate['explain'],
            ];

            $budget -= Str::length($excerpt);
        }

        return $this->attachBudgetAllocationDiagnostics($items, $totalBudget, $itemChars);
    }

    /**
     * Runs the admitted items back through MemoryInjectionBudgetAllocator so
     * each item's audit trail carries an independent knapsack-packing view of
     * how it was funded (allocated_chars, capped, rank) — a diagnostic cross-
     * check, never a second admission decision: the composer's own greedy
     * loop above remains the sole authority over which items are selected.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function attachBudgetAllocationDiagnostics(array $items, int $totalBudget, int $itemChars): array
    {
        if ($items === []) {
            return $items;
        }

        $rankedItems = array_map(fn (array $item): array => [
            'ref' => (string) $item['rank'],
            'priority' => $item['score'],
            'estimated_chars' => $item['estimated_chars'],
        ], $items);

        $allocation = $this->budgetAllocator->allocate($rankedItems, $totalBudget, $itemChars);

        $byRef = [];
        foreach ($allocation['admitted'] as $entry) {
            $byRef[$entry['ref']] = $entry;
        }

        foreach ($items as &$item) {
            $item['audit']['budget_allocation'] = $byRef[(string) $item['rank']] ?? null;
        }
        unset($item);

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function registryCandidates(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            $text = $this->text($item['body'] ?? null, $item['summary'] ?? null, $item['title'] ?? null);
            if ($text === '') {
                return null;
            }

            $type = (string) ($item['type'] ?? 'memory');
            $scope = (string) ($item['scope_type'] ?? $item['scope'] ?? 'global');

            $decay = $this->decayScorer->score([
                'positive_count' => $item['positive_explicit_count'] ?? ($item['positive_count'] ?? 0),
                'negative_count' => $item['negative_count'] ?? 0,
                'wrong_context_count' => $item['wrong_context_count'] ?? 0,
                'stale_count' => $item['stale_count'] ?? 0,
                'base_priority' => $item['priority'] ?? null,
                'recorded_at_age_days' => $this->ageDaysFromTimestamp($item['recorded_at'] ?? null),
                'last_used_at_age_days' => $this->ageDaysFromTimestamp($item['last_used_at'] ?? null),
                'recall_eval_hit_rate' => $item['recall_eval_hit_rate'] ?? null,
            ]);
            $effectivePriority = (int) $decay['effective_priority'];

            // Feedback-driven lifecycle: archived/inactivated memories never reach the recall
            // candidate pool; a degraded memory is halved so healthier memories rank above it.
            if (in_array($decay['lifecycle_action'], ['archive', 'inactivate'], true)) {
                return null;
            }

            $item['effective_priority'] = $effectivePriority;
            $item['health_score'] = (int) $decay['health_score'];
            data_set($item, 'explain.feedback_decay', [
                'health_score' => (int) $decay['health_score'],
                'effective_priority' => $effectivePriority,
                'lifecycle_action' => (string) $decay['lifecycle_action'],
                'staleness' => (string) $decay['staleness'],
                'threshold_reasons' => $decay['threshold_reasons'],
            ]);
            $rankingScore = $this->scorer->score([
                'source' => 'registry',
                'type' => $type,
                'scope_type' => $scope,
                'priority' => $effectivePriority,
                'importance' => $item['importance'] ?? null,
                'confidence' => $item['confidence'] ?? null,
                'hybrid_score' => $item['hybrid_score'] ?? null,
            ]) * ($decay['lifecycle_action'] === 'degrade' ? 0.5 : 1.0);

            return $this->candidate(
                'registry',
                'atlas_memory_entry',
                $item['id'] ?? null,
                $type,
                (string) ($item['scope'] ?? $scope),
                (string) ($item['title'] ?? $item['summary'] ?? $type),
                (string) ($item['summary'] ?? ''),
                $text,
                $rankingScore,
                (string) ($item['reason'] ?? 'memoria canonica do registry'),
                $item,
            );
        }, $items)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function verbatimCandidates(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            if (($item['blocked'] ?? false) === true) {
                return null;
            }

            $text = $this->text($item['snippet'] ?? null, $item['summary'] ?? null, $item['title'] ?? null);
            if ($text === '') {
                return null;
            }

            $type = (string) ($item['type'] ?? 'verbatim');
            $scope = (string) ($item['scope_type'] ?? $item['scope'] ?? 'global');
            $score = $this->scorer->score([
                'source' => 'verbatim',
                'type' => $type,
                'scope_type' => $scope,
                'hybrid_score' => $item['hybrid_score'] ?? null,
            ]);

            return $this->candidate(
                'verbatim',
                'atlas_verbatim_memory',
                $item['id'] ?? null,
                $type,
                (string) ($item['scope'] ?? $scope),
                (string) ($item['title'] ?? $item['summary'] ?? $type),
                (string) ($item['summary'] ?? ''),
                $text,
                $score,
                (string) ($item['reason'] ?? 'recall verbatim aprovado'),
                $item,
            );
        }, $items)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function semanticCandidates(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            if (($item['blocked'] ?? false) === true || ($item['external_ai_allowed'] ?? true) === false) {
                return null;
            }

            $text = $this->text($item['excerpt'] ?? null, $item['summary'] ?? null, $item['title'] ?? null);
            if ($text === '') {
                return null;
            }

            $type = (string) ($item['type'] ?? 'semantic_note');
            $score = $this->scorer->score([
                'source' => 'semantic',
                'type' => $type,
                'score' => $item['score'] ?? null,
            ]);

            return $this->candidate(
                'semantic',
                'semantic_note',
                $item['id'] ?? null,
                $type,
                (string) ($item['path'] ?? 'semantic_note'),
                (string) ($item['title'] ?? $item['summary'] ?? $type),
                (string) ($item['summary'] ?? ''),
                $text,
                $score,
                'nota semantica provider-safe recuperada por busca local',
                $item,
            );
        }, $items)));
    }

    /**
     * ACDE #3 — promoted compounding learnings as recall candidates. The recall seam already filtered to
     * status=active + confidence-floor + provider-safe `claim` only, so this just maps them into the shared
     * candidate shape. Source 'compounding' / ref 'ai_compounding_memory'; a blocked item is skipped.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function compoundingCandidates(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            if (($item['blocked'] ?? false) === true) {
                return null;
            }

            $text = $this->text($item['claim'] ?? null, $item['summary'] ?? null, $item['title'] ?? null);
            if ($text === '') {
                return null;
            }

            $type = (string) ($item['type'] ?? 'compounding_learning');
            $score = $this->scorer->score([
                'source' => 'compounding',
                'type' => $type,
                'scope_type' => (string) ($item['scope_type'] ?? $item['scope'] ?? 'global'),
                'confidence' => $item['confidence'] ?? null,
                'hybrid_score' => $item['hybrid_score'] ?? null,
            ]);

            return $this->candidate(
                'compounding',
                'ai_compounding_memory',
                $item['id'] ?? null,
                $type,
                (string) ($item['scope'] ?? 'global'),
                (string) ($item['title'] ?? $type),
                (string) ($item['summary'] ?? ''),
                $text,
                $score,
                (string) ($item['reason'] ?? 'aprendizado compounding promovido (provider-safe)'),
                $item,
            );
        }, $items)));
    }

    /**
     * @return array<string,mixed>
     */
    private function candidate(
        string $source,
        string $sourceRefType,
        mixed $sourceRefId,
        string $type,
        string $scope,
        string $title,
        string $summary,
        string $text,
        float $score,
        string $reason,
        array $raw = [],
    ): array {
        return [
            'source' => $source,
            'source_ref_type' => $sourceRefType,
            'source_ref_id' => is_scalar($sourceRefId) ? (string) $sourceRefId : null,
            'type' => $type,
            'scope' => $scope,
            'title' => $title,
            'summary' => $summary,
            'text' => $text,
            'score' => $score,
            'effective_priority' => is_numeric($raw['effective_priority'] ?? null) ? (int) $raw['effective_priority'] : null,
            'health_score' => is_numeric($raw['health_score'] ?? null) ? (int) $raw['health_score'] : null,
            'reason' => $reason,
            'lineage' => $this->lineage($source, $sourceRefType, $sourceRefId, $raw),
            'freshness' => $this->freshness($raw),
            'provider_projection' => is_array($raw['provider_projection'] ?? null) ? $raw['provider_projection'] : [],
            'audit' => $this->audit($raw),
            'explain' => is_array($raw['explain'] ?? null) ? $raw['explain'] : [],
            // MAXB-04 — optional dense vector for MMR (never required; absent ⇒ MMR skips that row).
            'embedding_vector' => is_array($raw['embedding_vector'] ?? null) ? array_values($raw['embedding_vector']) : null,
        ];
    }

    /**
     * MAXB-04 — diversity selection when flag ON and ≥2 registry rows carry embeddings.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private function applyMmrTopK(array $candidates, int $limit): array
    {
        if (! function_exists('config')) {
            return $candidates;
        }

        try {
            $enabled = (bool) config('atlas.semantic_memory.mmr_top_k_enabled', false);
        } catch (\Throwable) {
            return $candidates;
        }

        if (! $enabled) {
            return $candidates;
        }

        try {
            $lambda = AiValueNormalizer::finiteFloatOrNull(config('atlas.semantic_memory.mmr_lambda', MemoryMmrTopKSelector::DEFAULT_LAMBDA))
                ?? MemoryMmrTopKSelector::DEFAULT_LAMBDA;
        } catch (\Throwable) {
            $lambda = MemoryMmrTopKSelector::DEFAULT_LAMBDA;
        }
        $vectors = [];
        foreach ($candidates as $candidate) {
            if (($candidate['source_ref_type'] ?? null) !== 'atlas_memory_entry') {
                continue;
            }
            $id = trim((string) ($candidate['source_ref_id'] ?? ''));
            $vector = $candidate['embedding_vector'] ?? null;
            if ($id === '' || ! is_array($vector) || $vector === []) {
                continue;
            }
            $vectors[$id] = array_values($vector);
        }

        if (count($vectors) < 2) {
            return $candidates;
        }

        return $this->mmrSelector->select(
            $candidates,
            $limit,
            $lambda,
            static function (string $a, string $b) use ($vectors): ?float {
                if (! isset($vectors[$a], $vectors[$b])) {
                    return null;
                }

                return MemoryMmrTopKSelector::cosine($vectors[$a], $vectors[$b]);
            },
        );
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function lineage(string $source, string $sourceRefType, mixed $sourceRefId, array $raw): array
    {
        return [
            'source' => $source,
            'source_ref_type' => $sourceRefType,
            'source_ref_id' => is_scalar($sourceRefId) ? (string) $sourceRefId : null,
            'origin_type' => $this->scalarOrNull($raw['source_type'] ?? null) ?? $sourceRefType,
            'origin_id' => $this->scalarOrNull($raw['source_id'] ?? null),
            'origin_label' => $this->scalarOrNull($raw['source_label'] ?? null),
            'content_hash' => $this->scalarOrNull($raw['content_hash'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function freshness(array $raw): array
    {
        $recordedAt = $this->scalarOrNull($raw['recorded_at'] ?? null);
        $ageDays = null;
        if ($recordedAt !== null) {
            try {
                $ageDays = max(0, now()->diffInDays(\Carbon\CarbonImmutable::parse($recordedAt), true));
            } catch (\Throwable) {
                $ageDays = null;
            }
        }

        return [
            'recorded_at' => $recordedAt,
            'last_used_at' => $this->scalarOrNull($raw['last_used_at'] ?? null),
            'age_days' => $ageDays,
            'status' => $recordedAt === null ? 'unknown' : ($ageDays !== null && $ageDays > 180 ? 'stale_review_recommended' : 'fresh'),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function audit(array $raw): array
    {
        return [
            'schema_version' => 'atlas.memory.recall_audit.v1',
            'provider_safe' => true,
            'privacy_class' => $this->scalarOrNull($raw['privacy_class'] ?? null),
            'redaction_status' => $this->scalarOrNull($raw['redaction_status'] ?? null),
            'governance_checked_at' => $this->scalarOrNull($raw['governance_checked_at'] ?? null),
            'privacy_reviewed_at' => $this->scalarOrNull($raw['privacy_reviewed_at'] ?? null),
            'reviewed_at' => $this->scalarOrNull($raw['reviewed_at'] ?? null),
            'content_hash' => $this->scalarOrNull($raw['content_hash'] ?? null),
            'redacted_hash' => $this->scalarOrNull($raw['redacted_hash'] ?? null),
            'raw_content_persisted' => false,
        ];
    }

    private function scalarOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function ageDaysFromTimestamp(mixed $timestamp): ?int
    {
        $scalar = $this->scalarOrNull($timestamp);
        if ($scalar === null) {
            return null;
        }

        try {
            return max(0, (int) now()->diffInDays(\Carbon\CarbonImmutable::parse($scalar), true));
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function limitWithin(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return Str::length($value) > $limit
            ? Str::limit($value, max(1, $limit - 3), '...')
            : $value;
    }

    /**
     * Drops candidates strictly Pareto-dominated on {score: maximize, age_days:
     * minimize} WITHIN the same source. Registry, verbatim, semantic and
     * compounding memories are distinct evidence forms; an exact reviewed
     * verbatim fact must not disappear merely because a newer registry summary
     * has a higher score. Only candidates with known age are evaluated.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    private function dropParetoDominatedCandidates(array $candidates): array
    {
        $variantsBySource = [];

        foreach ($candidates as $index => $candidate) {
            $ageDays = AiValueNormalizer::finiteFloatOrNull($candidate['freshness']['age_days'] ?? null);
            if ($ageDays === null) {
                continue;
            }

            $source = (string) ($candidate['source'] ?? 'unknown');
            $variantsBySource[$source][] = [
                'id' => (string) $index,
                'score' => AiValueNormalizer::finiteFloatOrNull($candidate['score'] ?? null) ?? 0.0,
                'age_days' => $ageDays,
            ];
        }

        $dominated = [];
        foreach ($variantsBySource as $variants) {
            if (count($variants) < 2) {
                continue;
            }

            $result = $this->paretoFilter->filter(
                $variants,
                ['score' => 'maximize', 'age_days' => 'minimize'],
            );
            foreach ($result['evaluated'] as $row) {
                if ($row['status'] === 'dominated') {
                    $dominated[$row['id']] = true;
                }
            }
        }

        if ($dominated === []) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            fn (array $candidate, int $index): bool => ! isset($dominated[(string) $index]),
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
