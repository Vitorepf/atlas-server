<?php

namespace App\Services\Ai\Memory;

use App\Models\AiMemoryDelta;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasMemoryEntryUsage;
use App\Models\AtlasMemoryQualitySnapshot;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\MemoryGovernance\MemoryHealthCompositePolicy;
use App\Services\Ai\MemoryGovernance\MemoryQualityStatusPolicy;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AtlasMemoryQualityService
{
    private const FEEDBACK_NO_SIGNAL_SCORE = 50;

    public function __construct(
        private readonly AtlasMemoryPrivacyService $privacy,
        private readonly MemoryQueryInput $input,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function scorecard(array $filters = []): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return [
                'ok' => false,
                'status' => 'not_migrated',
                'score' => 0,
                'components' => [],
                'counts' => ['total' => 0, 'active' => 0],
                'ratios' => [],
                'issues' => [['code' => 'memory_table_missing', 'severity' => 'critical']],
                'recommendations' => ['/opt/homebrew/bin/php artisan migrate'],
                'generated_at' => now()->toJSON(),
            ];
        }

        $entries = $this->entryQuery($filters)->get();
        $active = $entries
            ->filter(fn (AtlasMemoryEntry $entry): bool => $entry->status === 'active' && $entry->archived_at === null)
            ->values();
        $providerSafe = $active->filter(fn (AtlasMemoryEntry $entry): bool => $this->privacy->providerAllowed($entry))->values();
        $activeEntryIds = $active
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
        $counts = $this->counts($entries, $active, $providerSafe);
        $relations = $this->relationCounts($activeEntryIds);
        $feedback = $this->feedbackCounts($activeEntryIds);
        $retrievalEval = $this->retrievalEvalCounts($activeEntryIds, $active);
        $deltas = $this->deltaCounts($filters);
        $sourceIntegrity = $this->sourceIntegrity($active);
        $ratios = $this->ratios($counts, $relations, $feedback, $sourceIntegrity, $retrievalEval);
        $components = $this->components($counts, $relations, $feedback, $sourceIntegrity, $retrievalEval, $ratios);
        $score = MemoryHealthCompositePolicy::compose($components)['composite_score'];
        $issues = $this->issues($counts, $relations, $feedback, $deltas, $sourceIntegrity, $retrievalEval, $ratios, $score);
        $statusPolicy = MemoryQualityStatusPolicy::classify(
            (int) ($counts['active'] ?? 0),
            $score,
            collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? null) === 'critical'),
        );
        $status = $statusPolicy['status'];
        $aggregateCounts = $counts + [
            'relations' => $relations,
            'feedback' => $feedback,
            'retrieval_eval' => $retrievalEval,
            'deltas' => $deltas,
            'source_integrity' => $sourceIntegrity,
        ];
        $trend = $this->trend($filters, $score, $components, $aggregateCounts, $issues);

        return [
            'ok' => $statusPolicy['ok'],
            'status' => $status,
            'score' => $score,
            'components' => $components,
            'counts' => $aggregateCounts,
            'ratios' => $ratios,
            'issues' => $issues,
            'trend' => $trend,
            'recommendations' => $this->recommendations($filters, $counts, $relations, $deltas, $sourceIntegrity, $retrievalEval, $status, $trend),
            'latest_snapshot' => $this->latestSnapshotPayload($filters),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @param  array<string,mixed>  $context
     */
    public function recordSnapshot(array $scorecard, array $context = []): ?AtlasMemoryQualitySnapshot
    {
        if (! DatabaseTableAvailability::has('atlas_memory_quality_snapshots')) {
            return null;
        }

        $workspace = $this->workspace($context['workspace'] ?? null);

        return AtlasMemoryQualitySnapshot::query()->create([
            'workspace' => $workspace,
            'workspace_hash' => $workspace ? hash('sha256', $workspace) : null,
            'source_type' => $this->string($context['source_type'] ?? null) ?: 'manual',
            'source_id' => $this->string($context['source_id'] ?? null),
            'status' => $this->string($scorecard['status'] ?? null) ?: 'unknown',
            'score' => max(0, min(100, (int) ($scorecard['score'] ?? 0))),
            'components_json' => (array) ($scorecard['components'] ?? []),
            'counts_json' => (array) ($scorecard['counts'] ?? []),
            'ratios_json' => (array) ($scorecard['ratios'] ?? []),
            'issues_json' => array_values((array) ($scorecard['issues'] ?? [])),
            'recommendations_json' => array_values((array) ($scorecard['recommendations'] ?? [])),
            'metadata' => array_merge([
                'created_by' => 'atlas_memory_quality_service',
            ], (array) ($context['metadata'] ?? [])),
            'snapshot_at' => $context['snapshot_at'] ?? now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function history(array $filters = [], int $days = 30, int $limit = 50): array
    {
        $days = $this->input->qualityHistoryDays($days);
        $limit = $this->input->registryLimit($limit);
        $since = now()->subDays($days);

        if (! DatabaseTableAvailability::has('atlas_memory_quality_snapshots')) {
            return [
                'ok' => true,
                'status' => 'snapshot_table_missing',
                'period_days' => $days,
                'since_at' => $since->toJSON(),
                'summary' => [
                    'total' => 0,
                    'latest_score' => null,
                    'oldest_score' => null,
                    'score_delta' => null,
                ],
                'snapshots' => [],
                'generated_at' => now()->toJSON(),
            ];
        }

        $query = $this->snapshotQuery($filters)
            ->where('snapshot_at', '>=', $since);
        $all = (clone $query)
            ->orderBy('snapshot_at')
            ->get();
        $snapshots = (clone $query)
            ->latest('snapshot_at')
            ->limit($limit)
            ->get();
        $oldest = $all->first();
        $latest = $all->last();

        return [
            'ok' => true,
            'status' => $all->isEmpty() ? 'empty' : 'ready',
            'period_days' => $days,
            'since_at' => $since->toJSON(),
            'summary' => $this->historySummary($all, $oldest, $latest),
            'snapshots' => $snapshots
                ->map(fn (AtlasMemoryQualitySnapshot $snapshot): array => $this->snapshotPayload($snapshot))
                ->values()
                ->all(),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshotPayload(AtlasMemoryQualitySnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'workspace' => $snapshot->workspace,
            'workspace_hash' => $snapshot->workspace_hash,
            'source_type' => $snapshot->source_type,
            'source_id' => $snapshot->source_id,
            'status' => $snapshot->status,
            'score' => $snapshot->score,
            'components' => $snapshot->components_json ?? [],
            'counts' => $snapshot->counts_json ?? [],
            'ratios' => $snapshot->ratios_json ?? [],
            'issues' => $snapshot->issues_json ?? [],
            'recommendations' => $snapshot->recommendations_json ?? [],
            'metadata' => $snapshot->metadata ?? [],
            'snapshot_at' => $snapshot->snapshot_at?->toJSON(),
            'created_at' => $snapshot->created_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function entryQuery(array $filters): Builder
    {
        $query = AtlasMemoryEntry::query();

        foreach (['scope_type', 'scope_id', 'project_id', 'task_id', 'engineering_run_id', 'source_type', 'status'] as $column) {
            if (is_string($filters[$column] ?? null) && trim((string) $filters[$column]) !== '') {
                $query->where($column, trim((string) $filters[$column]));
            }
        }

        $workspace = $this->workspace($filters['workspace'] ?? null);
        if ($workspace !== null) {
            $query->where(function (Builder $query) use ($workspace): void {
                $query->where('scope_type', 'global')
                    ->orWhere(fn (Builder $nested) => $nested
                        ->where('scope_type', 'workspace')
                        ->where('scope_id', hash('sha256', $workspace)));
            });
        }

        $types = array_values(array_filter((array) ($filters['types'] ?? []), 'is_string'));
        if ($types !== []) {
            $query->whereIn('memory_type', $types);
        }

        return $query->latest('recorded_at');
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function snapshotQuery(array $filters): Builder
    {
        $query = AtlasMemoryQualitySnapshot::query();
        $workspace = $this->workspace($filters['workspace'] ?? null);
        if ($workspace !== null) {
            $query->where('workspace_hash', hash('sha256', $workspace));
        }

        foreach (['status', 'source_type'] as $column) {
            if (is_string($filters[$column] ?? null) && trim((string) $filters[$column]) !== '') {
                $query->where($column, trim((string) $filters[$column]));
            }
        }

        return $query;
    }

    /**
     * @param  Collection<int,AtlasMemoryEntry>  $entries
     * @param  Collection<int,AtlasMemoryEntry>  $active
     * @param  Collection<int,AtlasMemoryEntry>  $providerSafe
     * @return array<string,int>
     */
    private function counts(Collection $entries, Collection $active, Collection $providerSafe): array
    {
        $staleBefore = now()->subDays(45);
        $privacyReviewNeeded = $active->filter(function (AtlasMemoryEntry $entry): bool {
            $privacyClass = (string) ($entry->privacy_class ?? data_get($entry->metadata, 'privacy.class', 'normal'));

            return in_array($privacyClass, ['private', 'sensitive', 'secret'], true)
                && ($entry->privacy_reviewed_at ?? null) === null;
        })->count();

        return [
            'total' => $entries->count(),
            'active' => $active->count(),
            'inactive' => $entries->where('status', 'inactive')->count(),
            'archived' => $entries->where('status', 'archived')->count(),
            'provider_safe_active' => $providerSafe->count(),
            'provider_blocked_active' => max(0, $active->count() - $providerSafe->count()),
            'global_active' => $active->where('scope_type', 'global')->count(),
            'non_global_active' => $active->where('scope_type', '!=', 'global')->count(),
            'privacy_review_needed' => $privacyReviewNeeded,
            'redacted_active' => $active->filter(fn (AtlasMemoryEntry $entry): bool => ($entry->redaction_status ?? null) === 'redacted')->count(),
            'missing_title_active' => $active->filter(fn (AtlasMemoryEntry $entry): bool => trim((string) ($entry->title ?? '')) === '')->count(),
            'missing_summary_active' => $active->filter(fn (AtlasMemoryEntry $entry): bool => trim((string) ($entry->summary ?? '')) === '')->count(),
            'missing_confidence_active' => $active->filter(fn (AtlasMemoryEntry $entry): bool => $entry->confidence === null)->count(),
            'stale_unused_active' => $active->filter(fn (AtlasMemoryEntry $entry): bool => $entry->last_used_at === null
                && ($entry->recorded_at === null || $entry->recorded_at->lessThan($staleBefore)))->count(),
            // D5 (Obra #18) — crude, SQL-verifiable structural markers of the wiper state:
            // a title that just repeats the summary carries no extra information, and a
            // body with no rationale is a stub. First-class inputs to the honest score.
            'title_equals_summary_active' => $active->filter(function (AtlasMemoryEntry $entry): bool {
                $title = trim((string) ($entry->title ?? ''));

                return $title !== '' && $title === trim((string) ($entry->summary ?? ''));
            })->count(),
            'with_rationale_active' => $active->filter(function (AtlasMemoryEntry $entry): bool {
                return AtlasMemoryRationalePolicy::hasRationale((string) ($entry->body ?? ''));
            })->count(),
        ];
    }

    /**
     * @param  array<int,string>  $activeEntryIds
     * @return array<string,int>
     */
    private function relationCounts(array $activeEntryIds): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            return [
                'table_present' => 0,
                'open' => 0,
                'open_duplicates' => 0,
                'open_conflicts' => 0,
                'entries_with_relation' => 0,
            ];
        }

        if ($activeEntryIds === []) {
            return [
                'table_present' => 1,
                'open' => 0,
                'open_duplicates' => 0,
                'open_conflicts' => 0,
                'entries_with_relation' => 0,
            ];
        }

        $query = AtlasMemoryEntryRelation::query()
            ->where('status', 'open')
            ->where(function (Builder $query) use ($activeEntryIds): void {
                $query->whereIn('source_memory_entry_id', $activeEntryIds)
                    ->orWhereIn('target_memory_entry_id', $activeEntryIds);
            });

        // D5: relation DENSITY — distinct active entries that carry ANY relation (not
        // just open), the crude number D3 must move. Wiper state = 0.
        $activeSet = array_flip($activeEntryIds);
        $withRelation = [];
        foreach (AtlasMemoryEntryRelation::query()
            ->where(function (Builder $query) use ($activeEntryIds): void {
                $query->whereIn('source_memory_entry_id', $activeEntryIds)
                    ->orWhereIn('target_memory_entry_id', $activeEntryIds);
            })
            ->get(['source_memory_entry_id', 'target_memory_entry_id']) as $relation) {
            foreach ([$relation->source_memory_entry_id, $relation->target_memory_entry_id] as $id) {
                if (isset($activeSet[(string) $id])) {
                    $withRelation[(string) $id] = true;
                }
            }
        }

        return [
            'table_present' => 1,
            'open' => (clone $query)->count(),
            'open_duplicates' => (clone $query)->where('relation_type', 'duplicate')->count(),
            'open_conflicts' => (clone $query)->where('relation_type', 'conflict')->count(),
            'entries_with_relation' => count($withRelation),
        ];
    }

    /**
     * @param  array<int,string>  $activeEntryIds
     * @return array<string,int>
     */
    private function feedbackCounts(array $activeEntryIds): array
    {
        $windowDays = $this->memoryQualityWindowDays();
        $minSignalUsage = $this->memoryQualityWindowSignalFloor();

        if (! DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
            return [
                'table_present' => 0,
                'window_days' => $windowDays,
                'min_signal_usage' => $minSignalUsage,
                'usage_total' => 0,
                'usage_window_total' => 0,
                'feedback_total' => 0,
                'feedback_window_total' => 0,
                'positive' => 0,
                'positive_window' => 0,
                'positive_explicit' => 0,
                'positive_explicit_window' => 0,
                'positive_implicit' => 0,
                'positive_implicit_window' => 0,
                'ignored_implicit' => 0,
                'ignored_implicit_window' => 0,
                'negative' => 0,
                'negative_window' => 0,
                'wrong_context' => 0,
                'wrong_context_window' => 0,
                'stale' => 0,
                'stale_window' => 0,
            ];
        }

        if ($activeEntryIds === []) {
            return [
                'table_present' => 1,
                'window_days' => $windowDays,
                'min_signal_usage' => $minSignalUsage,
                'usage_total' => 0,
                'usage_window_total' => 0,
                'feedback_total' => 0,
                'feedback_window_total' => 0,
                'positive' => 0,
                'positive_window' => 0,
                'positive_explicit' => 0,
                'positive_explicit_window' => 0,
                'positive_implicit' => 0,
                'positive_implicit_window' => 0,
                'ignored_implicit' => 0,
                'ignored_implicit_window' => 0,
                'negative' => 0,
                'negative_window' => 0,
                'wrong_context' => 0,
                'wrong_context_window' => 0,
                'stale' => 0,
                'stale_window' => 0,
            ];
        }

        $query = AtlasMemoryEntryUsage::query()
            ->whereIn('memory_entry_id', $activeEntryIds);
        $windowQuery = (clone $query)->where('created_at', '>=', now()->subDays($windowDays));

        $feedback = (clone $query)->whereNotNull('feedback_action');
        $windowFeedback = (clone $windowQuery)->whereNotNull('feedback_action');
        $positiveExplicit = AtlasMemoryEntryUsage::positiveExplicitFeedbackActions();
        $positiveImplicit = AtlasMemoryEntryUsage::positiveImplicitFeedbackActions();
        $negative = AtlasMemoryEntryUsage::negativeFeedbackActions();
        $positiveExplicitCount = (clone $feedback)->whereIn('feedback_action', $positiveExplicit)->count();
        $positiveImplicitCount = (clone $feedback)->whereIn('feedback_action', $positiveImplicit)->count();
        $positiveExplicitWindowCount = (clone $windowFeedback)->whereIn('feedback_action', $positiveExplicit)->count();
        $positiveImplicitWindowCount = (clone $windowFeedback)->whereIn('feedback_action', $positiveImplicit)->count();

        return [
            'table_present' => 1,
            'window_days' => $windowDays,
            'min_signal_usage' => $minSignalUsage,
            'usage_total' => (clone $query)->count(),
            'usage_window_total' => (clone $windowQuery)->count(),
            'feedback_total' => (clone $feedback)->count(),
            'feedback_window_total' => (clone $windowFeedback)->count(),
            'positive' => $positiveExplicitCount + $positiveImplicitCount,
            'positive_window' => $positiveExplicitWindowCount + $positiveImplicitWindowCount,
            'positive_explicit' => $positiveExplicitCount,
            'positive_explicit_window' => $positiveExplicitWindowCount,
            'positive_implicit' => $positiveImplicitCount,
            'positive_implicit_window' => $positiveImplicitWindowCount,
            'ignored_implicit' => (clone $feedback)->where('feedback_action', 'ignored_implicit')->count(),
            'ignored_implicit_window' => (clone $windowFeedback)->where('feedback_action', 'ignored_implicit')->count(),
            'negative' => (clone $feedback)->whereIn('feedback_action', $negative)->count(),
            'negative_window' => (clone $windowFeedback)->whereIn('feedback_action', $negative)->count(),
            'wrong_context' => (clone $feedback)->where('feedback_action', 'wrong_context')->count(),
            'wrong_context_window' => (clone $windowFeedback)->where('feedback_action', 'wrong_context')->count(),
            'stale' => (clone $feedback)->where('feedback_action', 'stale')->count(),
            'stale_window' => (clone $windowFeedback)->where('feedback_action', 'stale')->count(),
        ];
    }

    /**
     * @param  array<int,string>  $activeEntryIds
     * @param  Collection<int,AtlasMemoryEntry>  $active
     * @return array<string,int>
     */
    private function retrievalEvalCounts(array $activeEntryIds, Collection $active): array
    {
        $windowDays = $this->memoryQualityWindowDays();

        if (! DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
            return [
                'table_present' => 0,
                'window_days' => $windowDays,
                'min_signal_usage' => $this->memoryQualityWindowSignalFloor(),
                'recall_usage_total' => 0,
                'recall_usage_window_total' => 0,
                'entries_recalled' => 0,
                'top_entry_recall_count' => 0,
                'pre_filter_recall_usage_total' => 0,
                'pre_filter_top_entry_recall_count' => 0,
                'active_entries_never_recalled' => count($activeEntryIds),
                'stale_never_recalled_active' => $this->staleNeverRecalledCount($active, collect()),
                'thin_never_recalled_active' => $this->thinNeverRecalledCount($active, collect()),
                'recall_feedback_total' => 0,
                'recall_negative_feedback' => 0,
                'recall_stale_feedback' => 0,
                'recall_wrong_context_feedback' => 0,
                'recall_ignored_implicit_feedback' => 0,
                'all_time_recall_usage_total' => 0,
                'all_time_entries_recalled' => 0,
                'all_time_top_entry_recall_count' => 0,
                'all_time_pre_filter_recall_usage_total' => 0,
                'all_time_pre_filter_top_entry_recall_count' => 0,
                'all_time_active_entries_never_recalled' => count($activeEntryIds),
                'all_time_recall_feedback_total' => 0,
                'all_time_recall_negative_feedback' => 0,
                'all_time_recall_stale_feedback' => 0,
                'all_time_recall_wrong_context_feedback' => 0,
                'all_time_recall_ignored_implicit_feedback' => 0,
            ];
        }

        if ($activeEntryIds === []) {
            return [
                'table_present' => 1,
                'window_days' => $windowDays,
                'min_signal_usage' => $this->memoryQualityWindowSignalFloor(),
                'recall_usage_total' => 0,
                'recall_usage_window_total' => 0,
                'entries_recalled' => 0,
                'top_entry_recall_count' => 0,
                'pre_filter_recall_usage_total' => 0,
                'pre_filter_top_entry_recall_count' => 0,
                'active_entries_never_recalled' => 0,
                'stale_never_recalled_active' => 0,
                'thin_never_recalled_active' => 0,
                'recall_feedback_total' => 0,
                'recall_negative_feedback' => 0,
                'recall_stale_feedback' => 0,
                'recall_wrong_context_feedback' => 0,
                'recall_ignored_implicit_feedback' => 0,
                'all_time_recall_usage_total' => 0,
                'all_time_entries_recalled' => 0,
                'all_time_top_entry_recall_count' => 0,
                'all_time_pre_filter_recall_usage_total' => 0,
                'all_time_pre_filter_top_entry_recall_count' => 0,
                'all_time_active_entries_never_recalled' => 0,
                'all_time_recall_feedback_total' => 0,
                'all_time_recall_negative_feedback' => 0,
                'all_time_recall_stale_feedback' => 0,
                'all_time_recall_wrong_context_feedback' => 0,
                'all_time_recall_ignored_implicit_feedback' => 0,
            ];
        }

        $allTimeQuery = AtlasMemoryEntryUsage::query()
            ->whereIn('memory_entry_id', $activeEntryIds)
            ->where('source_type', 'memory_recall');
        $query = (clone $allTimeQuery)->where('created_at', '>=', now()->subDays($windowDays));
        $allTimePreFilterQuery = AtlasMemoryEntryUsage::query()
            ->whereIn('memory_entry_id', $activeEntryIds)
            ->where('source_type', AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER);
        $preFilterQuery = (clone $allTimePreFilterQuery)->where('created_at', '>=', now()->subDays($windowDays));
        $recalledIds = (clone $query)
            ->distinct()
            ->pluck('memory_entry_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values();
        $allTimeRecalledIds = (clone $allTimeQuery)
            ->distinct()
            ->pluck('memory_entry_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values();
        $feedback = (clone $query)
            ->whereNotNull('feedback_action');
        $allTimeFeedback = (clone $allTimeQuery)
            ->whereNotNull('feedback_action');
        $explicitFeedback = (clone $feedback)
            ->whereNotIn('feedback_action', ['useful_implicit', 'ignored_implicit']);
        $allTimeExplicitFeedback = (clone $allTimeFeedback)
            ->whereNotIn('feedback_action', ['useful_implicit', 'ignored_implicit']);
        $negative = AtlasMemoryEntryUsage::negativeFeedbackActions();

        // D5 — recall CONCENTRATION: how many recalls pile on the single most-recalled
        // entry. The wiper audit found 90% of 18 320 recalls returning ONE memory — a
        // degenerate retrieval that a coverage-only score hides. This is the crude number
        // behind that pathology.
        $topEntryRecallCount = (int) ((clone $query)
            ->selectRaw('memory_entry_id, COUNT(*) as aggregate_count')
            ->groupBy('memory_entry_id')
            ->orderByDesc('aggregate_count')
            ->limit(1)
            ->get()
            ->value('aggregate_count') ?? 0);
        $allTimeTopEntryRecallCount = (int) ((clone $allTimeQuery)
            ->selectRaw('memory_entry_id, COUNT(*) as aggregate_count')
            ->groupBy('memory_entry_id')
            ->orderByDesc('aggregate_count')
            ->limit(1)
            ->get()
            ->value('aggregate_count') ?? 0);
        $preFilterTopEntryRecallCount = (int) ((clone $preFilterQuery)
            ->selectRaw('memory_entry_id, COUNT(*) as aggregate_count')
            ->groupBy('memory_entry_id')
            ->orderByDesc('aggregate_count')
            ->limit(1)
            ->get()
            ->value('aggregate_count') ?? 0);
        $allTimePreFilterTopEntryRecallCount = (int) ((clone $allTimePreFilterQuery)
            ->selectRaw('memory_entry_id, COUNT(*) as aggregate_count')
            ->groupBy('memory_entry_id')
            ->orderByDesc('aggregate_count')
            ->limit(1)
            ->get()
            ->value('aggregate_count') ?? 0);

        return [
            'table_present' => 1,
            'window_days' => $windowDays,
            'min_signal_usage' => $this->memoryQualityWindowSignalFloor(),
            'recall_usage_total' => (clone $query)->count(),
            'recall_usage_window_total' => (clone $query)->count(),
            'entries_recalled' => $recalledIds->count(),
            'top_entry_recall_count' => $topEntryRecallCount,
            'pre_filter_recall_usage_total' => (clone $preFilterQuery)->count(),
            'pre_filter_top_entry_recall_count' => $preFilterTopEntryRecallCount,
            'active_entries_never_recalled' => max(0, count($activeEntryIds) - $recalledIds->count()),
            'stale_never_recalled_active' => $this->staleNeverRecalledCount($active, $recalledIds),
            'thin_never_recalled_active' => $this->thinNeverRecalledCount($active, $recalledIds),
            'recall_feedback_total' => (clone $explicitFeedback)->count(),
            'recall_negative_feedback' => (clone $feedback)->whereIn('feedback_action', $negative)->count(),
            'recall_stale_feedback' => (clone $feedback)->where('feedback_action', 'stale')->count(),
            'recall_wrong_context_feedback' => (clone $feedback)->where('feedback_action', 'wrong_context')->count(),
            'recall_ignored_implicit_feedback' => (clone $feedback)->where('feedback_action', 'ignored_implicit')->count(),
            'all_time_recall_usage_total' => (clone $allTimeQuery)->count(),
            'all_time_entries_recalled' => $allTimeRecalledIds->count(),
            'all_time_top_entry_recall_count' => $allTimeTopEntryRecallCount,
            'all_time_pre_filter_recall_usage_total' => (clone $allTimePreFilterQuery)->count(),
            'all_time_pre_filter_top_entry_recall_count' => $allTimePreFilterTopEntryRecallCount,
            'all_time_active_entries_never_recalled' => max(0, count($activeEntryIds) - $allTimeRecalledIds->count()),
            'all_time_recall_feedback_total' => (clone $allTimeExplicitFeedback)->count(),
            'all_time_recall_negative_feedback' => (clone $allTimeFeedback)->whereIn('feedback_action', $negative)->count(),
            'all_time_recall_stale_feedback' => (clone $allTimeFeedback)->where('feedback_action', 'stale')->count(),
            'all_time_recall_wrong_context_feedback' => (clone $allTimeFeedback)->where('feedback_action', 'wrong_context')->count(),
            'all_time_recall_ignored_implicit_feedback' => (clone $allTimeFeedback)->where('feedback_action', 'ignored_implicit')->count(),
        ];
    }

    /**
     * @param  Collection<int,AtlasMemoryEntry>  $active
     * @param  Collection<int,string>  $recalledIds
     */
    private function thinNeverRecalledCount(Collection $active, Collection $recalledIds): int
    {
        $recalled = $recalledIds->flip();

        return $active->filter(function (AtlasMemoryEntry $entry) use ($recalled): bool {
            $id = (string) $entry->id;
            if ($id === '' || $recalled->has($id)) {
                return false;
            }

            if (! is_string($entry->summary) || ! is_string($entry->title)) {
                return false;
            }

            $summary = $this->memoryTextKey($entry->summary);
            $title = $this->memoryTextKey($entry->title);

            return $summary !== '' && $summary === $title;
        })->count();
    }

    /**
     * @param  Collection<int,AtlasMemoryEntry>  $active
     * @param  Collection<int,string>  $recalledIds
     */
    private function staleNeverRecalledCount(Collection $active, Collection $recalledIds): int
    {
        $staleBefore = now()->subDays(45);
        $recalled = $recalledIds->flip();

        return $active->filter(function (AtlasMemoryEntry $entry) use ($recalled, $staleBefore): bool {
            $id = (string) $entry->id;

            return $id !== ''
                && ! $recalled->has($id)
                && ($entry->recorded_at === null || $entry->recorded_at->lessThan($staleBefore));
        })->count();
    }

    private function memoryTextKey(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function memoryQualityWindowDays(): int
    {
        return max(1, (int) config('atlas.semantic_memory.recall_concentration_window_days', 45));
    }

    private function memoryQualityWindowSignalFloor(): int
    {
        return max(1, (int) config('atlas.semantic_memory.recall_concentration_min_recalls', 50));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,int>
     */
    private function deltaCounts(array $filters): array
    {
        if (! DatabaseTableAvailability::has('ai_memory_deltas')) {
            return [
                'table_present' => 0,
                'pending' => 0,
                'accepted' => 0,
                'promoted' => 0,
                'review_required' => 0,
                'trusted_auto_candidates' => 0,
            ];
        }

        $query = AiMemoryDelta::query();
        $workspace = $this->workspace($filters['workspace'] ?? null);
        if ($workspace !== null) {
            $workspaceSlug = Str::limit(str_replace(['/', '\\', ' '], '_', trim($workspace)), 150, '');
            $query->whereIn('scope', array_values(array_unique([
                'workspace:'.$workspace,
                'workspace:'.(realpath($workspace) ?: $workspace),
                'workspace:'.$workspaceSlug,
            ])));
        }

        return [
            'table_present' => 1,
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'accepted' => (clone $query)->where('status', 'accepted')->count(),
            'promoted' => (clone $query)->where('status', 'promoted')->count(),
            'review_required' => (clone $query)->where('status', 'pending')->where('requires_confirmation', true)->count(),
            'trusted_auto_candidates' => (clone $query)
                ->where('status', 'pending')
                ->where('requires_confirmation', false)
                ->where('confidence', '>=', 0.86)
                ->count(),
        ];
    }

    /**
     * @param  Collection<int,AtlasMemoryEntry>  $active
     * @return array<string,int>
     */
    private function sourceIntegrity(Collection $active): array
    {
        $knownSources = [
            'ai_memory_delta' => 'ai_memory_deltas',
            'engineering_run' => 'atlas_engineering_runs',
            'atlas_verbatim_memory' => 'atlas_verbatim_memories',
            'semantic_note' => 'semantic_notes',
            'engineering_knowledge' => 'atlas_engineering_knowledge_items',
        ];
        $checked = 0;
        $orphaned = 0;

        foreach ($active as $entry) {
            $sourceType = (string) $entry->source_type;
            $sourceId = trim((string) ($entry->source_id ?? ''));
            if ($sourceId === '' || ! isset($knownSources[$sourceType]) || ! DatabaseTableAvailability::has($knownSources[$sourceType])) {
                continue;
            }

            $checked++;
            // Every canonical source table above uses a UUID primary key.
            // Legacy/provider projections may retain a human source label; do
            // not send that value to a PostgreSQL uuid comparison, where it
            // raises 22P02 and hides the entire quality report. It is an
            // orphaned source reference by definition.
            if (! Str::isUuid($sourceId)) {
                $orphaned++;

                continue;
            }
            if (! DB::table($knownSources[$sourceType])->where('id', $sourceId)->exists()) {
                $orphaned++;
            }
        }

        return [
            'checked' => $checked,
            'orphaned' => $orphaned,
        ];
    }

    /**
     * @param  array<string,int>  $counts
     * @param  array<string,int>  $relations
     * @param  array<string,int>  $feedback
     * @param  array<string,int>  $sourceIntegrity
     * @return array<string,float>
     */
    private function ratios(array $counts, array $relations, array $feedback, array $sourceIntegrity, array $retrievalEval): array
    {
        $active = max(1, (int) $counts['active']);
        $feedbackTotal = max(1, (int) ($feedback['feedback_window_total'] ?? $feedback['feedback_total'] ?? 0));
        $recallFeedbackTotal = max(1, (int) $retrievalEval['recall_feedback_total']);
        $checked = max(1, (int) $sourceIntegrity['checked']);

        return [
            'provider_safe_ratio' => $this->ratio((int) $counts['provider_safe_active'], $active),
            'blocked_ratio' => $this->ratio((int) $counts['provider_blocked_active'], $active),
            'stale_unused_ratio' => $this->ratio((int) $counts['stale_unused_active'], $active),
            'privacy_review_needed_ratio' => $this->ratio((int) $counts['privacy_review_needed'], $active),
            'negative_feedback_ratio' => $this->ratio((int) ($feedback['negative_window'] ?? $feedback['negative'] ?? 0), $feedbackTotal),
            'retrieval_recall_coverage_ratio' => $this->ratio((int) $retrievalEval['entries_recalled'], max(1, (int) $retrievalEval['entries_recalled'] + (int) ($retrievalEval['stale_never_recalled_active'] ?? 0))),
            'retrieval_negative_feedback_ratio' => $this->ratio((int) $retrievalEval['recall_negative_feedback'], $recallFeedbackTotal),
            // D5 — share of recalls piling on the single most-recalled entry (the wiper's
            // degenerate-retrieval signature). 0.9 ⇒ 90% of recalls return one memory.
            'recall_concentration_ratio' => $this->ratio((int) ($retrievalEval['top_entry_recall_count'] ?? 0), max(1, (int) ($retrievalEval['recall_usage_total'] ?? 0))),
            'pre_filter_recall_concentration_ratio' => $this->ratio(
                (int) ($retrievalEval['pre_filter_top_entry_recall_count'] ?? 0),
                max(1, (int) ($retrievalEval['pre_filter_recall_usage_total'] ?? 0)),
            ),
            'open_relation_ratio' => $this->ratio((int) $relations['open'], $active),
            'source_orphan_ratio' => $this->ratio((int) $sourceIntegrity['orphaned'], $checked),
            'global_scope_ratio' => $this->ratio(
                (int) ($counts['global_active'] ?? 0),
                max(1, (int) ($counts['active'] ?? 0)),
            ),
        ];
    }

    /**
     * @param  array<string,int>  $counts
     * @param  array<string,int>  $relations
     * @param  array<string,int>  $feedback
     * @param  array<string,int>  $sourceIntegrity
     * @param  array<string,float>  $ratios
     * @return array<string,int>
     */
    private function components(array $counts, array $relations, array $feedback, array $sourceIntegrity, array $retrievalEval, array $ratios): array
    {
        $active = (int) $counts['active'];
        $completenessPenalty = min(70, ((int) $counts['missing_title_active'] * 8)
            + ((int) $counts['missing_summary_active'] * 5)
            + ((int) $counts['missing_confidence_active'] * 3));
        $governancePenalty = min(80, ((int) $relations['open_conflicts'] * 20)
            + ((int) $relations['open_duplicates'] * 10)
            + ((int) $sourceIntegrity['orphaned'] * 15));

        $activeForRatio = max(1, $active);
        $usageTotal = (int) ($feedback['usage_window_total'] ?? $feedback['usage_total'] ?? 0);
        $feedbackTotal = (int) ($feedback['feedback_window_total'] ?? $feedback['feedback_total'] ?? 0);

        return [
            'readiness' => $active > 0 ? 100 : 0,
            'provider_safety' => (int) round(($ratios['provider_safe_ratio'] ?? 0.0) * 100),
            'governance' => max(0, 100 - $governancePenalty),
            'freshness' => max(0, 100 - (int) round(($ratios['stale_unused_ratio'] ?? 0.0) * 100)),
            // D5 — the feedback dimension is now the FILL rate, not a free 72. A system
            // with many usages and ZERO feedback (the wiper: 0/18320) is a dead write and
            // scores ~0 here; it rises only when real feedback_action is recorded (D4).
            'feedback' => $this->feedbackComponent(
                $usageTotal,
                $feedbackTotal,
                (float) ($ratios['negative_feedback_ratio'] ?? 0.0),
                (int) ($feedback['min_signal_usage'] ?? $this->memoryQualityWindowSignalFloor()),
            ),
            // D5 — retrieval quality is coverage MINUS negative feedback MINUS the
            // degenerate-concentration penalty: a store whose recalls all pile on one
            // entry is NOT well-retrieved, however high its nominal coverage. Honest, not
            // weight-tuning — it makes the dimension measure what it claims (the wiper's
            // 90%-to-one-entry pathology the audit named).
            // Obra 3 / OPT-03: concentration penalty soft-capped (still honest, not free).
            'retrieval_eval' => (int) ($retrievalEval['recall_usage_total'] > 0
                ? max(0, round(($ratios['retrieval_recall_coverage_ratio'] ?? 0.0) * 100)
                    - round(($ratios['retrieval_negative_feedback_ratio'] ?? 0.0) * 40)
                    - round(min(0.45, (float) ($ratios['recall_concentration_ratio'] ?? 0.0)) * 100))
                : 60),
            'completeness' => max(0, 100 - $completenessPenalty),
            // D5 — the three crude wiper markers as FIRST-CLASS dimensions. Each is a
            // verifiable SQL ratio, so the composite rises only when D1-D4 move the raw
            // numbers (re-hydration, relations, feedback) — never by weight-tuning.
            'structural_honesty' => $active > 0
                ? (int) round((1 - min(1.0, (int) $counts['title_equals_summary_active'] / $activeForRatio)) * 100)
                : 0,
            'rationale' => $active > 0
                ? (int) round(min(1.0, (int) $counts['with_rationale_active'] / $activeForRatio) * 100)
                : 0,
            'relation_density' => $active > 0
                ? (int) round(min(1.0, (int) ($relations['entries_with_relation'] ?? 0) / $activeForRatio) * 100)
                : 0,
        ];
    }

    /**
     * D5 — the feedback dimension. No usage yet ⇒ 50 (no signal, not a free pass);
     * otherwise the fraction of usages that carry a feedback_action, docked by the
     * negative-feedback ratio. The wiper (0 feedback over 18 320 usages) scores ~0.
     */
    private function feedbackComponent(int $usageTotal, int $feedbackTotal, float $negativeRatio, int $minSignalUsage): int
    {
        if ($usageTotal < max(1, $minSignalUsage)) {
            return self::FEEDBACK_NO_SIGNAL_SCORE;
        }
        // Obra 3 / MEM-03 OPT-01: log-scaled fill so sparse-but-real feedback is not
        // crushed by historical recall volume (wiper-era usage piles). Still docks
        // on negative ratio; never a free pass when feedback_total=0.
        if ($feedbackTotal <= 0) {
            return 0;
        }
        $rawFill = $feedbackTotal / max(1, $usageTotal);
        $logFill = min(1.0, log(1 + ($feedbackTotal * 40)) / log(1 + max(40, $usageTotal * 0.02)));
        $fill = max($rawFill, $logFill * 0.85);

        return (int) round(min(1.0, $fill) * max(0.0, 100 - $negativeRatio * 100));
    }

    /**
     * @param  array<string,int>  $counts
     * @param  array<string,int>  $relations
     * @param  array<string,int>  $feedback
     * @param  array<string,int>  $deltas
     * @param  array<string,int>  $sourceIntegrity
     * @return array<int,array<string,mixed>>
     */
    private function issues(array $counts, array $relations, array $feedback, array $deltas, array $sourceIntegrity, array $retrievalEval, array $ratios, int $score): array
    {
        $issues = [];
        if ($counts['active'] < 1) {
            $issues[] = ['code' => 'empty_active_memory', 'severity' => 'critical'];
        }
        if ($counts['provider_safe_active'] < 1) {
            $issues[] = ['code' => 'no_provider_safe_memory', 'severity' => 'critical'];
        }
        if ($relations['open_conflicts'] > 0) {
            $issues[] = ['code' => 'open_memory_conflicts', 'severity' => 'warning', 'count' => $relations['open_conflicts']];
        }
        if ($relations['open_duplicates'] > 0) {
            $issues[] = ['code' => 'open_memory_duplicates', 'severity' => 'warning', 'count' => $relations['open_duplicates']];
        }
        if ($counts['privacy_review_needed'] > 0) {
            $issues[] = ['code' => 'privacy_review_needed', 'severity' => 'warning', 'count' => $counts['privacy_review_needed']];
        }
        if ($sourceIntegrity['orphaned'] > 0) {
            $issues[] = ['code' => 'orphaned_memory_sources', 'severity' => 'warning', 'count' => $sourceIntegrity['orphaned']];
        }
        if ((int) ($counts['active'] ?? 0) > 0
            && (int) ($counts['global_active'] ?? 0) === (int) ($counts['active'] ?? 0)) {
            $issues[] = [
                'code' => 'global_scope_concentration',
                'severity' => 'info',
                'count' => (int) $counts['global_active'],
            ];
        }
        if ($deltas['accepted'] > 0) {
            $issues[] = ['code' => 'accepted_learning_not_promoted', 'severity' => 'info', 'count' => $deltas['accepted']];
        }
        if ($feedback['wrong_context'] > 0 || $feedback['stale'] > 0) {
            $issues[] = ['code' => 'negative_memory_feedback', 'severity' => 'warning', 'count' => $feedback['negative']];
        }
        if ($counts['active'] > 0 && $retrievalEval['recall_usage_total'] < 1) {
            $issues[] = ['code' => 'retrieval_eval_missing_usage', 'severity' => 'info'];
        }
        if ((int) ($retrievalEval['stale_never_recalled_active'] ?? 0) > 0) {
            $issues[] = ['code' => 'retrieval_eval_stale_never_recalled_entries', 'severity' => 'info', 'count' => $retrievalEval['stale_never_recalled_active']];
        }
        if ((int) ($retrievalEval['thin_never_recalled_active'] ?? 0) > 0) {
            $issues[] = ['code' => 'thin_never_recalled_memory_entries', 'severity' => 'info', 'count' => $retrievalEval['thin_never_recalled_active']];
        }
        if ($retrievalEval['recall_wrong_context_feedback'] > 0 || $retrievalEval['recall_stale_feedback'] > 0) {
            $issues[] = ['code' => 'retrieval_eval_negative_feedback', 'severity' => 'warning', 'count' => $retrievalEval['recall_negative_feedback']];
        }
        if ($score < 70 && $counts['active'] > 0) {
            $issues[] = ['code' => 'memory_quality_score_low', 'severity' => $score < 50 ? 'critical' : 'warning', 'score' => $score];
        }
        if (($ratios['recall_concentration_ratio'] ?? 0.0) >= 0.5 && ($retrievalEval['recall_usage_total'] ?? 0) > 0) {
            $issues[] = [
                'code' => 'recall_concentration_high',
                'severity' => ($ratios['recall_concentration_ratio'] ?? 0.0) >= 0.65 ? 'warning' : 'info',
                'ratio' => $ratios['recall_concentration_ratio'],
            ];
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @param  array<string,int>  $counts
     * @param  array<string,int>  $relations
     * @param  array<string,int>  $deltas
     * @param  array<string,int>  $sourceIntegrity
     * @return array<int,string>
     */
    private function recommendations(array $filters, array $counts, array $relations, array $deltas, array $sourceIntegrity, array $retrievalEval, string $status, array $trend = []): array
    {
        $workspaceArg = is_string($filters['workspace'] ?? null) && trim((string) $filters['workspace']) !== ''
            ? ' --workspace="'.str_replace('"', '\"', trim((string) $filters['workspace'])).'"'
            : '';
        $actions = [];

        if ($counts['provider_safe_active'] < 1) {
            $actions[] = '/opt/homebrew/bin/php artisan atlas:memory:seed-core';
        }
        if ($deltas['accepted'] > 0) {
            $actions[] = './bin/atlas memory maintain'.$workspaceArg.' --json';
        }
        if ($deltas['review_required'] > 0) {
            $actions[] = '/opt/homebrew/bin/php artisan atlas:cli:memory review'.$workspaceArg;
        }
        if ($relations['open'] > 0) {
            $actions[] = './bin/atlas memory relations --status=open --json';
        }
        if ((int) ($retrievalEval['stale_never_recalled_active'] ?? 0) > 0) {
            $actions[] = './bin/atlas memory list --status=active --never-recalled --compact --limit=50 --json';
        }
        if ((int) ($retrievalEval['thin_never_recalled_active'] ?? 0) > 0) {
            $actions[] = './bin/atlas memory list --status=active --never-recalled --thin --compact --limit=50 --json';
            $actions[] = 'Review thin never-recalled memory entries: enrich summaries or archive low-value entries.';
        }
        if ((int) ($counts['missing_confidence_active'] ?? 0) > 0) {
            $actions[] = './bin/atlas memory maintain'.$workspaceArg.' --dry-run --no-sync --no-index-code --no-promote-learnings --no-quality-snapshot --json';
        }
        if ($counts['privacy_review_needed'] > 0 || $counts['provider_blocked_active'] > 0) {
            $actions[] = './bin/atlas memory review-queue --json';
        }
        if ($sourceIntegrity['orphaned'] > 0 || in_array($status, ['critical', 'needs_review'], true)) {
            $actions[] = './bin/atlas memory govern --dry-run --json';
        }
        if ((int) ($counts['active'] ?? 0) > 0
            && (int) ($counts['global_active'] ?? 0) === (int) ($counts['active'] ?? 0)) {
            $actions[] = './bin/atlas memory list --scope-type=global --status=active --compact --limit=50 --json';
        }
        if (in_array($trend['status'] ?? null, ['regressed', 'watch_regressed'], true)) {
            $actions[] = './bin/atlas memory quality history'.$workspaceArg.' --days=30 --json';
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    /**
     * @param  array<string,int>  $components
     * @param  array<string,mixed>  $counts
     * @param  array<int,array<string,mixed>>  $issues
     */
    private function trend(array $filters, int $currentScore, array $components, array $counts, array $issues): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_quality_snapshots')) {
            return [
                'status' => 'snapshot_table_missing',
                'current_score' => $currentScore,
                'snapshot_count' => 0,
                'current_delta_from_latest' => null,
                'latest_delta_from_previous' => null,
                'window_delta' => null,
            ];
        }

        $snapshots = $this->snapshotQuery($this->latestSnapshotFilters($filters))
            ->latest('snapshot_at')
            ->limit(8)
            ->get();

        if ($snapshots->isEmpty()) {
            return [
                'status' => 'no_history',
                'current_score' => $currentScore,
                'snapshot_count' => 0,
                'current_delta_from_latest' => null,
                'latest_delta_from_previous' => null,
                'window_delta' => null,
            ];
        }

        $latest = $snapshots->first();
        $previous = $snapshots->skip(1)->first();
        $oldest = $snapshots->last();
        $latestScore = (int) $latest->score;
        $previousScore = $previous ? (int) $previous->score : null;
        $oldestScore = $oldest ? (int) $oldest->score : null;
        $currentDelta = $currentScore - $latestScore;
        $latestDelta = $previousScore !== null ? $latestScore - $previousScore : null;
        $windowDelta = $oldestScore !== null && $oldest?->id !== $latest->id ? $latestScore - $oldestScore : null;
        $status = $this->trendStatus($currentDelta, $latestDelta, $windowDelta, $snapshots->count());

        return [
            'status' => $status,
            'current_score' => $currentScore,
            'latest_snapshot_score' => $latestScore,
            'previous_snapshot_score' => $previousScore,
            'snapshot_count' => $snapshots->count(),
            'current_delta_from_latest' => $currentDelta,
            'latest_delta_from_previous' => $latestDelta,
            'window_delta' => $windowDelta,
            'latest_snapshot_id' => $latest->id,
            'latest_snapshot_at' => $latest->snapshot_at?->toJSON(),
            'drivers' => $this->trendDrivers($status, $latest, $currentScore, $components, $counts, $issues),
        ];
    }

    /**
     * @param  array<string,int>  $components
     * @param  array<string,mixed>  $counts
     * @param  array<int,array<string,mixed>>  $issues
     * @return array<int,array<string,mixed>>
     */
    private function trendDrivers(
        string $status,
        AtlasMemoryQualitySnapshot $latest,
        int $currentScore,
        array $components,
        array $counts,
        array $issues,
    ): array {
        if (! in_array($status, ['regressed', 'watch_regressed'], true)) {
            return [];
        }

        $drivers = [];
        $baselineComponents = (array) ($latest->components_json ?? []);
        foreach ($components as $component => $value) {
            $previous = $baselineComponents[$component] ?? null;
            if (! is_numeric($previous)) {
                continue;
            }

            $delta = (int) $value - (int) $previous;
            if ($delta <= -5) {
                $drivers[] = [
                    'kind' => 'component_drop',
                    'key' => $component,
                    'severity' => $delta <= -15 ? 'warning' : 'info',
                    'delta' => $delta,
                    'current' => (int) $value,
                    'previous' => (int) $previous,
                ];
            }
        }

        $baselineCounts = (array) ($latest->counts_json ?? []);
        foreach ($this->regressionCountPaths() as $path => $label) {
            $current = data_get($counts, $path);
            $previous = data_get($baselineCounts, $path);
            if (! is_numeric($current) || ! is_numeric($previous)) {
                continue;
            }

            $delta = (int) $current - (int) $previous;
            if ($delta > 0) {
                $drivers[] = [
                    'kind' => 'count_increase',
                    'key' => $label,
                    'severity' => $delta >= 3 ? 'warning' : 'info',
                    'delta' => $delta,
                    'current' => (int) $current,
                    'previous' => (int) $previous,
                ];
            }
        }

        $currentIssueCounts = collect($issues)
            ->filter(fn (mixed $issue): bool => is_array($issue) && is_string($issue['code'] ?? null))
            ->countBy(fn (array $issue): string => (string) $issue['code']);
        $baselineIssueCounts = collect((array) ($latest->issues_json ?? []))
            ->filter(fn (mixed $issue): bool => is_array($issue) && is_string($issue['code'] ?? null))
            ->countBy(fn (array $issue): string => (string) $issue['code']);

        foreach ($currentIssueCounts as $code => $count) {
            $previous = (int) ($baselineIssueCounts[$code] ?? 0);
            $delta = (int) $count - $previous;
            if ($delta > 0) {
                $drivers[] = [
                    'kind' => 'issue_increase',
                    'key' => (string) $code,
                    'severity' => 'warning',
                    'delta' => $delta,
                    'current' => (int) $count,
                    'previous' => $previous,
                ];
            }
        }

        if ($drivers === []) {
            $drivers[] = [
                'kind' => 'score_drop',
                'key' => 'score',
                'severity' => 'warning',
                'delta' => $currentScore - (int) $latest->score,
                'current' => $currentScore,
                'previous' => (int) $latest->score,
            ];
        }

        return collect($drivers)
            ->sortBy([
                fn (array $driver): int => ($driver['severity'] ?? null) === 'warning' ? 0 : 1,
                fn (array $driver): int => (int) ($driver['delta'] ?? 0),
            ])
            ->values()
            ->take(8)
            ->all();
    }

    /**
     * @return array<string,string>
     */
    private function regressionCountPaths(): array
    {
        return [
            'provider_blocked_active' => 'provider_blocked_active',
            'privacy_review_needed' => 'privacy_review_needed',
            'missing_title_active' => 'missing_title_active',
            'missing_summary_active' => 'missing_summary_active',
            'missing_confidence_active' => 'missing_confidence_active',
            'stale_unused_active' => 'stale_unused_active',
            'relations.open_conflicts' => 'open_conflicts',
            'relations.open_duplicates' => 'open_duplicates',
            'feedback.negative' => 'negative_feedback',
            'feedback.wrong_context' => 'wrong_context_feedback',
            'feedback.stale' => 'stale_feedback',
            'deltas.accepted' => 'accepted_learning_backlog',
            'source_integrity.orphaned' => 'orphaned_sources',
        ];
    }

    /**
     * @param  Collection<int,AtlasMemoryQualitySnapshot>  $snapshots
     * @return array<string,mixed>
     */
    private function historySummary(Collection $snapshots, ?AtlasMemoryQualitySnapshot $oldest, ?AtlasMemoryQualitySnapshot $latest): array
    {
        $scores = $snapshots->pluck('score')->filter(fn (mixed $score): bool => is_numeric($score))->map(fn (mixed $score): int => (int) $score);

        return [
            'total' => $snapshots->count(),
            'latest_status' => $latest?->status,
            'latest_score' => $latest?->score,
            'oldest_score' => $oldest?->score,
            'score_delta' => $latest && $oldest ? $latest->score - $oldest->score : null,
            'trend_status' => $latest && $oldest
                ? $this->trendStatus(null, null, $latest->score - $oldest->score, $snapshots->count())
                : 'no_history',
            'min_score' => $scores->isEmpty() ? null : $scores->min(),
            'max_score' => $scores->isEmpty() ? null : $scores->max(),
            'avg_score' => $scores->isEmpty() ? null : round((float) $scores->avg(), 2),
            'latest_at' => $latest?->snapshot_at?->toJSON(),
            'oldest_at' => $oldest?->snapshot_at?->toJSON(),
            'by_status' => $snapshots
                ->groupBy(fn (AtlasMemoryQualitySnapshot $snapshot): string => (string) ($snapshot->status ?: 'unknown'))
                ->map(fn (Collection $group, string $status): array => [
                    'status' => $status,
                    'total' => $group->count(),
                    'latest_score' => $group->sortByDesc('snapshot_at')->first()?->score,
                ])
                ->sortKeys()
                ->values()
                ->all(),
            'issue_counts' => $this->issueCounts($snapshots),
        ];
    }

    /**
     * @param  Collection<int,AtlasMemoryQualitySnapshot>  $snapshots
     * @return array<string,int>
     */
    private function issueCounts(Collection $snapshots): array
    {
        return $snapshots
            ->flatMap(fn (AtlasMemoryQualitySnapshot $snapshot): array => array_values((array) ($snapshot->issues_json ?? [])))
            ->filter(fn (mixed $issue): bool => is_array($issue) && is_string($issue['code'] ?? null))
            ->countBy(fn (array $issue): string => (string) $issue['code'])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>|null
     */
    private function latestSnapshotPayload(array $filters): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_quality_snapshots')) {
            return null;
        }

        $snapshot = $this->snapshotQuery($this->latestSnapshotFilters($filters))
            ->latest('snapshot_at')
            ->first();

        return $snapshot ? [
            'id' => $snapshot->id,
            'status' => $snapshot->status,
            'score' => $snapshot->score,
            'source_type' => $snapshot->source_type,
            'snapshot_at' => $snapshot->snapshot_at?->toJSON(),
            'metadata' => $snapshot->metadata ?? [],
        ] : null;
    }

    /**
     * Keep scorecard entry filters from leaking into snapshot history filters.
     * `status` means memory entry status in scorecard, but snapshot status in history.
     *
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function latestSnapshotFilters(array $filters): array
    {
        return [
            'workspace' => $filters['workspace'] ?? null,
        ];
    }

    private function trendStatus(?int $currentDelta, ?int $latestDelta, ?int $windowDelta, int $snapshotCount): string
    {
        $deltas = collect([$currentDelta, $latestDelta, $windowDelta])
            ->filter(fn (mixed $delta): bool => is_numeric($delta))
            ->map(fn (mixed $delta): int => (int) $delta)
            ->values();

        if ($deltas->isEmpty()) {
            return $snapshotCount > 0 ? 'stable' : 'no_history';
        }

        $min = $deltas->min();
        $max = $deltas->max();

        if ($min <= -15) {
            return 'regressed';
        }
        if ($min <= -5) {
            return 'watch_regressed';
        }
        if ($max >= 5) {
            return 'improved';
        }

        return 'stable';
    }

    private function ratio(int $part, int $total): float
    {
        return $total <= 0 ? 0.0 : round($part / $total, 4);
    }

    private function workspace(mixed $workspace): ?string
    {
        if (! is_scalar($workspace) || trim((string) $workspace) === '') {
            return null;
        }

        $workspace = trim((string) $workspace);

        return realpath($workspace) ?: $workspace;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
