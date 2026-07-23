<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * Obra 5 / MEM-04 + OPT-01 — live recall-path demotion for degenerate concentration:
 * when one memory entry absorbs an outsized share of recall usages, demote it in ranking
 * and surface provider-safe refs for feedback-driven context demotion.
 */
final class AtlasMemoryRecallConcentrationDemotion
{
    /**
     * @return array<int,string>
     */
    public function dominantEntryIds(): array
    {
        if (! (bool) config('atlas.semantic_memory.recall_concentration_demotion_enabled', true)) {
            return [];
        }
        if (! DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
            return [];
        }

        $windowDays = max(1, (int) config('atlas.semantic_memory.recall_concentration_window_days', 45));
        $threshold = (float) config('atlas.semantic_memory.recall_concentration_demote_ratio', 0.35);
        $minRecalls = max(10, (int) config('atlas.semantic_memory.recall_concentration_min_recalls', 100));
        $since = now()->subDays($windowDays);

        $query = AtlasMemoryEntryUsage::query()
            ->where('source_type', AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER)
            ->where('created_at', '>=', $since);
        $total = (int) (clone $query)->count();
        if ($total < $minRecalls) {
            return [];
        }
        // Compare integer counts instead of dividing inside SQL. PostgreSQL
        // infers COUNT(*) and the first placeholder as bigint; the previous
        // `COUNT(*) / ? > ?` therefore both performed integer division and
        // tried to bind a fractional threshold as bigint. For an integer
        // recall count, ratio > threshold is exactly count > floor(total * threshold).
        $exclusiveCountFloor = (int) floor(
            $total * max(0.0, min(1.0, $threshold)),
        );

        return (clone $query)
            ->selectRaw('memory_entry_id, COUNT(*) as recall_count')
            ->groupBy('memory_entry_id')
            ->havingRaw('COUNT(*) > ?', [$exclusiveCountFloor])
            ->orderByDesc('recall_count')
            ->limit(8)
            ->pluck('memory_entry_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
    }

    public function scoreMultiplier(string $entryId, array $dominantIds): float
    {
        if ($entryId === '' || $dominantIds === []) {
            return 1.0;
        }

        if (! in_array($entryId, $dominantIds, true)) {
            return 1.0;
        }

        return max(0.05, min(1.0, (float) config('atlas.semantic_memory.recall_concentration_score_factor', 0.35)));
    }

    /**
     * @param  array<int,string>  $entryIds
     * @return array<int,string>
     */
    public function demoteContextRefsForEntries(array $entryIds): array
    {
        if ($entryIds === [] || ! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return [];
        }

        $refs = [];
        foreach (AtlasMemoryEntry::query()->whereIn('id', $entryIds)->get(['id', 'content_hash']) as $entry) {
            $id = (string) $entry->id;
            $hash = trim((string) ($entry->content_hash ?? ''));
            if ($id !== '') {
                $refs[] = $id;
            }
            if ($hash !== '') {
                $refs[] = $hash;
                $refs[] = 'hash:'.substr(hash('sha256', $hash), 0, 24);
                $refs[] = 'memory:'.substr(hash('sha256', $hash), 0, 32);
            }
        }

        return array_values(array_unique(array_filter($refs, static fn (string $ref): bool => $ref !== '')));
    }

    /**
     * @param  array<int,string>  $entryIds
     * @return array<string,array{positive_count:int,positive_explicit_count:int,positive_implicit_count:int,negative_count:int,wrong_context_count:int,stale_count:int,recall_eval_hit_rate:float|null}>
     */
    public function feedbackStatsForEntries(array $entryIds): array
    {
        if ($entryIds === [] || ! DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
            return [];
        }

        $stats = [];
        foreach ($entryIds as $entryId) {
            $stats[$entryId] = [
                'positive_count' => 0,
                'positive_explicit_count' => 0,
                'positive_implicit_count' => 0,
                'negative_count' => 0,
                'wrong_context_count' => 0,
                'stale_count' => 0,
                'recall_eval_hit_rate' => null,
            ];
        }

        $usageQuery = AtlasMemoryEntryUsage::query()
            ->whereIn('memory_entry_id', $entryIds);
        $recallTotal = (int) (clone $usageQuery)->where('source_type', 'memory_recall')->count();

        foreach ((clone $usageQuery)->whereNotNull('feedback_action')->get(['memory_entry_id', 'feedback_action']) as $usage) {
            $id = (string) $usage->memory_entry_id;
            if (! isset($stats[$id])) {
                continue;
            }
            $action = (string) $usage->feedback_action;
            if (AtlasMemoryEntryUsage::isPositiveFeedback($action)) {
                $stats[$id]['positive_count']++;
            }
            if (AtlasMemoryEntryUsage::isPositiveExplicitFeedback($action)) {
                $stats[$id]['positive_explicit_count']++;
            }
            if (AtlasMemoryEntryUsage::isPositiveImplicitFeedback($action)) {
                $stats[$id]['positive_implicit_count']++;
            }
            if (AtlasMemoryEntryUsage::isNegativeFeedback($action)) {
                $stats[$id]['negative_count']++;
            }
            if ($action === 'wrong_context') {
                $stats[$id]['wrong_context_count']++;
            }
            if ($action === 'stale') {
                $stats[$id]['stale_count']++;
            }
        }

        if ($recallTotal > 0) {
            foreach ($entryIds as $entryId) {
                $recalled = (int) AtlasMemoryEntryUsage::query()
                    ->where('memory_entry_id', $entryId)
                    ->where('source_type', 'memory_recall')
                    ->count();
                // Never-delivered entries must stay null (insufficient signal).
                // A literal 0.0 triggers MemoryFeedbackDecayScorer degrade and
                // masks brand-new correct targets (RAG-05 live failure mode).
                $stats[$entryId]['recall_eval_hit_rate'] = $recalled > 0
                    ? round($recalled / $recallTotal, 4)
                    : null;
            }
        }

        return $stats;
    }

    /**
     * Entry ids superseded by another candidate in the same recall pool (open supersedes edge).
     *
     * @param  array<int,string>  $entryIds
     * @return array<int,string>
     */
    public function supersededEntryIds(array $entryIds): array
    {
        if (count($entryIds) < 2 || ! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            return [];
        }

        $set = array_flip($entryIds);
        $targets = AtlasMemoryEntryRelation::query()
            ->where('relation_type', AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES)
            ->where('status', 'open')
            ->whereIn('source_memory_entry_id', $entryIds)
            ->whereIn('target_memory_entry_id', $entryIds)
            ->pluck('target_memory_entry_id')
            ->filter(fn (mixed $id): bool => is_string($id) && isset($set[$id]))
            ->values()
            ->all();

        return array_values(array_unique($targets));
    }

    /**
     * @param  array<int,string>  $entryIds
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function relatedConflictsForEntries(array $entryIds): array
    {
        if ($entryIds === [] || ! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            return [];
        }

        $resolver = app(AtlasMemoryConflictResolutionService::class);
        $out = [];
        foreach ($entryIds as $entryId) {
            $conflicts = $resolver->relatedConflicts($entryId);
            if ($conflicts !== []) {
                $out[$entryId] = $conflicts;
            }
        }

        return $out;
    }
}
