<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Models\AiRagFeedbackEvent;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasOpenBrainAccessLog;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * GOD-DEBULK: read-only health/maintenance metric aggregators extracted verbatim
 * from AtlasOpenBrainMcpService — the prompt-metric and context-feedback rollups
 * plus the memory summary and overall-status/next-actions helpers that the
 * façade's atlas_memory_maintenance_status tool composes. Pure reads, no provider
 * spend, no writes. Bodies are byte-identical; the façade delegates here.
 */
class HealthMetricsTools
{
    public function __construct(
        private readonly AtlasMemoryPrivacyService $privacy,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function openBrainPromptMetrics(int $hours = 168): array
    {
        if (! Schema::hasTable('atlas_open_brain_access_logs')) {
            return [
                'schema_version' => 'atlas.open_brain.prompt_metric_aggregate.v1',
                'status' => 'not_migrated',
                'window_hours' => $hours,
                'observed_count' => 0,
                'review_signal' => [
                    'status' => 'unavailable',
                    'severity' => 'low',
                    'reasons' => ['open_brain_audit_table_missing'],
                    'recommended_action' => 'run_open_brain_audit_migrations_before_prompt_metric_review',
                    'recommended_command' => 'php artisan migrate --path=database/migrations/2026_05_03_130000_create_atlas_open_brain_access_logs_table.php',
                ],
            ];
        }

        $logs = AtlasOpenBrainAccessLog::query()
            ->where('accessed_at', '>=', now()->subHours($hours))
            ->where('action', 'context_pack_export')
            ->orderByDesc('accessed_at')
            ->limit(200)
            ->get();

        $promptRows = $logs
            ->map(function (AtlasOpenBrainAccessLog $log): ?array {
                $summary = (array) ($log->result_summary_json ?? []);
                $prompt = data_get($summary, 'prompt');
                if (! is_array($prompt)) {
                    return null;
                }

                return [
                    'id' => $log->id,
                    'mode' => (string) ($prompt['mode'] ?? 'unknown'),
                    'chars' => (int) ($prompt['chars'] ?? 0),
                    'lines' => (int) ($prompt['lines'] ?? 0),
                    'estimated_tokens' => (int) ($prompt['estimated_tokens'] ?? 0),
                    'full_chars' => (int) ($prompt['full_chars'] ?? 0),
                    'saved_chars' => (int) ($prompt['saved_chars'] ?? 0),
                    'estimated_tokens_saved' => (int) ($prompt['estimated_tokens_saved'] ?? 0),
                    'savings_ratio' => AiValueNormalizer::finiteFloatOrNull($prompt['savings_ratio'] ?? null) ?? 0.0,
                    'compact_to_full_ratio' => AiValueNormalizer::finiteFloatOrNull($prompt['compact_to_full_ratio'] ?? null) ?? 0.0,
                    'raw_prompt_persisted' => (bool) ($prompt['raw_prompt_persisted'] ?? false)
                        || (bool) data_get($summary, 'safety.prompt_raw_prompt_persisted', false)
                        || array_key_exists('prompt_section', $summary),
                    'accessed_at' => $log->accessed_at?->toJSON(),
                ];
            })
            ->filter()
            ->values();

        if ($promptRows->isEmpty()) {
            return [
                'schema_version' => 'atlas.open_brain.prompt_metric_aggregate.v1',
                'status' => 'no_data',
                'window_hours' => $hours,
                'observed_count' => 0,
                'total_context_pack_exports' => $logs->count(),
                'review_signal' => [
                    'status' => 'observe',
                    'severity' => 'low',
                    'reasons' => ['no_prompt_metric_exports_in_window'],
                    'recommended_action' => 'collect_include_prompt_exports_before_prompt_metric_review',
                    'recommended_command' => './bin/atlas open-brain context "AOBG prompt metric calibration" --include-prompt --prompt-mode=compact --json',
                ],
            ];
        }

        $compactRows = $promptRows->where('mode', 'compact')->values();
        $fullRows = $promptRows->where('mode', 'full')->values();
        $unknownRows = $promptRows
            ->reject(fn (array $row): bool => in_array($row['mode'], ['compact', 'full'], true))
            ->values();
        $rawPromptViolations = $promptRows
            ->filter(fn (array $row): bool => (bool) ($row['raw_prompt_persisted'] ?? false))
            ->values();
        $lowSavingsRows = $compactRows
            ->filter(fn (array $row): bool => (AiValueNormalizer::finiteFloatOrNull($row['savings_ratio'] ?? null) ?? 0.0) < 0.25)
            ->values();

        $observedCount = $promptRows->count();
        $fullModeRatio = $observedCount > 0 ? round($fullRows->count() / $observedCount, 4) : 0.0;
        $fullModeDominant = $observedCount >= 3 && $fullModeRatio > 0.5;
        $reasons = [];
        if ($rawPromptViolations->isNotEmpty()) {
            $reasons[] = 'raw_prompt_persistence_detected';
        }
        if ($lowSavingsRows->isNotEmpty()) {
            $reasons[] = 'compact_prompt_savings_below_threshold';
        }
        if ($fullModeDominant) {
            $reasons[] = 'full_prompt_mode_dominant';
        }
        if ($unknownRows->isNotEmpty()) {
            $reasons[] = 'unknown_prompt_mode_observed';
        }

        $status = 'ready';
        if ($rawPromptViolations->isNotEmpty()) {
            $status = 'critical';
        } elseif ($reasons !== []) {
            $status = 'warning';
        }

        return [
            'schema_version' => 'atlas.open_brain.prompt_metric_aggregate.v1',
            'status' => $status,
            'window_hours' => $hours,
            'observed_count' => $observedCount,
            'total_context_pack_exports' => $logs->count(),
            'compact_count' => $compactRows->count(),
            'full_count' => $fullRows->count(),
            'unknown_mode_count' => $unknownRows->count(),
            'full_mode_ratio' => $fullModeRatio,
            'raw_prompt_persistence_violation_count' => $rawPromptViolations->count(),
            'low_savings_count' => $lowSavingsRows->count(),
            'averages' => [
                'chars' => $this->averageMetric($promptRows, 'chars'),
                'estimated_tokens' => $this->averageMetric($promptRows, 'estimated_tokens'),
                'saved_chars' => $this->averageMetric($promptRows, 'saved_chars'),
                'estimated_tokens_saved' => $this->averageMetric($promptRows, 'estimated_tokens_saved'),
                'savings_ratio' => $this->averageMetric($promptRows, 'savings_ratio', 4),
            ],
            'compact' => [
                'count' => $compactRows->count(),
                'avg_chars' => $this->averageMetric($compactRows, 'chars'),
                'avg_full_chars' => $this->averageMetric($compactRows, 'full_chars'),
                'avg_saved_chars' => $this->averageMetric($compactRows, 'saved_chars'),
                'avg_estimated_tokens_saved' => $this->averageMetric($compactRows, 'estimated_tokens_saved'),
                'avg_savings_ratio' => $this->averageMetric($compactRows, 'savings_ratio', 4),
                'avg_compact_to_full_ratio' => $this->averageMetric($compactRows, 'compact_to_full_ratio', 4),
            ],
            'latest' => $promptRows->first(),
            'review_signal' => [
                'status' => $status === 'ready' ? 'ready' : ($status === 'critical' ? 'blocking' : 'review'),
                'severity' => $status === 'critical' ? 'high' : ($status === 'warning' ? 'medium' : 'low'),
                'reasons' => $reasons,
                'recommended_action' => $status === 'ready'
                    ? 'keep_compact_prompt_default_and_continue_measuring'
                    : 'review_open_brain_prompt_metric_regression_before_changing_prompt_delivery_policy',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function contextFeedbackMetrics(int $hours = 168): array
    {
        if (! Schema::hasTable('ai_rag_feedback_events')) {
            return [
                'schema_version' => 'atlas.open_brain.context_feedback_metric_aggregate.v1',
                'status' => 'not_migrated',
                'window_hours' => $hours,
                'observed_count' => 0,
                'review_signal' => [
                    'status' => 'unavailable',
                    'severity' => 'low',
                    'reasons' => ['rag_feedback_table_missing'],
                    'recommended_action' => 'run_ai_rag_feedback_events_migration_before_context_feedback_review',
                    'recommended_command' => 'php artisan migrate --path=database/migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php && php artisan migrate --path=database/migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php',
                    'schema_repair_commands' => [
                        'php artisan migrate --path=database/migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php',
                        'php artisan migrate --path=database/migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php',
                    ],
                    'drift_hint' => 'If migrations are already recorded but ai_rag_feedback_events is missing, run the two migration up() methods idempotently or repair the migration ledger before collecting feedback.',
                ],
            ];
        }

        $events = AiRagFeedbackEvent::query()
            ->where('created_at', '>=', now()->subHours($hours))
            ->latest('created_at')
            ->limit(200)
            ->get();

        if ($events->isEmpty()) {
            return [
                'schema_version' => 'atlas.open_brain.context_feedback_metric_aggregate.v1',
                'status' => 'no_data',
                'window_hours' => $hours,
                'observed_count' => 0,
                'review_signal' => [
                    'status' => 'observe',
                    'severity' => 'low',
                    'reasons' => ['no_context_feedback_events_in_window'],
                    'recommended_action' => 'ask_external_providers_to_call_atlas_context_feedback_after_context_sensitive_runs',
                ],
            ];
        }

        $rows = $events
            ->map(function (AiRagFeedbackEvent $event): array {
                $roi = (array) (data_get($event->payload, 'context_roi') ?: data_get($event->payload, 'payload.context_roi', []));
                $attribution = (array) (data_get($event->payload, 'context_ref_attribution') ?: data_get($event->payload, 'payload.context_ref_attribution', []));
                $policy = (array) (data_get($event->payload, 'next_context_policy') ?: data_get($event->payload, 'payload.next_context_policy', []));
                $missedCount = count((array) $event->missed_required_sources);
                $hasRoiSignal = $roi !== [] || $attribution !== [];
                $actionableFeedback = $hasRoiSignal || $policy !== [] || $missedCount > 0 || (int) $event->noise_sources > 0;
                $measured = $this->feedbackEventMeasured($event);

                return [
                    'feedback_hash' => $event->feedback_hash,
                    'flow_id' => $event->flow_id,
                    'outcome_status' => (string) ($event->outcome_status ?: 'unknown'),
                    'included_sources' => (int) $event->included_sources,
                    'used_sources' => (int) $event->used_sources,
                    'noise_sources' => (int) $event->noise_sources,
                    'missed_required_source_count' => $missedCount,
                    'context_sufficiency' => (int) $event->context_sufficiency,
                    'post_execution_utility' => (int) $event->post_execution_utility,
                    'measured' => $measured,
                    'has_roi_signal' => $hasRoiSignal,
                    'actionable_feedback' => $actionableFeedback,
                    'roi_score' => array_key_exists('roi_score', $roi) ? AiValueNormalizer::finiteFloatOrNull($roi['roi_score']) : null,
                    'quality_band' => (string) ($roi['quality_band'] ?? 'unknown'),
                    'use_ratio' => array_key_exists('use_ratio', $attribution) ? AiValueNormalizer::finiteFloatOrNull($attribution['use_ratio']) : null,
                    'waste_ratio' => array_key_exists('waste_ratio', $attribution) ? AiValueNormalizer::finiteFloatOrNull($attribution['waste_ratio']) : null,
                    'policy_actions' => array_values(array_filter((array) ($policy['actions'] ?? []), 'is_string')),
                    'created_at' => $event->created_at?->toJSON(),
                ];
            })
            ->values();

        $totalEventCount = $rows->count();
        $rows = $rows->filter(fn (array $row): bool => (bool) ($row['measured'] ?? false))->values();
        if ($rows->isEmpty()) {
            return [
                'schema_version' => 'atlas.open_brain.context_feedback_metric_aggregate.v1',
                'status' => 'no_measured_data',
                'window_hours' => $hours,
                'observed_count' => 0,
                'measured_count' => 0,
                'total_event_count' => $totalEventCount,
                'quality_band_counts' => [
                    'strong' => 0,
                    'mixed' => 0,
                    'weak' => 0,
                    'unknown' => 0,
                ],
                'outcome_counts' => [],
                'low_roi_count' => 0,
                'waste_count' => 0,
                'noise_count' => 0,
                'missed_required_source_feedback_count' => 0,
                'non_passing_count' => 0,
                'roi_signal_count' => 0,
                'actionable_feedback_count' => 0,
                'non_actionable_feedback_count' => 0,
                'missing_roi_signal_count' => $totalEventCount,
                'weak_ratio' => 0.0,
                'non_passing_ratio' => 0.0,
                'averages' => [
                    'roi_score' => 0.0,
                    'use_ratio' => 0.0,
                    'waste_ratio' => 0.0,
                    'context_sufficiency' => 0.0,
                    'post_execution_utility' => 0.0,
                    'included_sources' => 0.0,
                    'used_sources' => 0.0,
                    'noise_sources' => 0.0,
                ],
                'latest' => null,
                'review_signal' => [
                    'status' => 'observe',
                    'severity' => 'low',
                    'reasons' => ['context_feedback_events_unmeasured'],
                    'recommended_action' => 'collect_explicit_used_refs_and_post_execution_utility_before_aggregating_context_feedback',
                    'auto_apply_threshold' => 0,
                    'auto_apply_ready' => false,
                    'remaining_feedback_events_before_auto_apply' => 0,
                ],
            ];
        }

        $observedCount = $rows->count();
        $weakRows = $rows->where('quality_band', 'weak')->values();
        $mixedRows = $rows->where('quality_band', 'mixed')->values();
        $strongRows = $rows->where('quality_band', 'strong')->values();
        $roiSignalRows = $rows->filter(fn (array $row): bool => (bool) $row['has_roi_signal'])->values();
        $actionableRows = $rows->filter(fn (array $row): bool => (bool) $row['actionable_feedback'])->values();
        $lowRoiRows = $roiSignalRows->filter(fn (array $row): bool => $row['roi_score'] !== null && (AiValueNormalizer::finiteFloatOrNull($row['roi_score']) ?? 0.0) < 0.50)->values();
        $wasteRows = $rows->filter(fn (array $row): bool => $row['waste_ratio'] !== null && (AiValueNormalizer::finiteFloatOrNull($row['waste_ratio']) ?? 0.0) >= 0.40)->values();
        $noiseRows = $rows->filter(fn (array $row): bool => (int) $row['noise_sources'] > 0)->values();
        $missedRows = $rows->filter(fn (array $row): bool => (int) $row['missed_required_source_count'] > 0)->values();
        $nonPassingRows = $rows
            ->filter(fn (array $row): bool => $this->isNonPassingContextOutcome((string) $row['outcome_status']))
            ->values();
        $missingRoiRows = $rows->reject(fn (array $row): bool => (bool) $row['has_roi_signal'])->values();

        $reasons = [];
        if ($lowRoiRows->isNotEmpty()) {
            $reasons[] = 'low_context_roi_observed';
        }
        if ($wasteRows->isNotEmpty()) {
            $reasons[] = 'context_waste_observed';
        }
        if ($noiseRows->isNotEmpty()) {
            $reasons[] = 'noise_context_observed';
        }
        if ($missedRows->isNotEmpty()) {
            $reasons[] = 'missed_required_sources_observed';
        }
        if ($nonPassingRows->isNotEmpty()) {
            $reasons[] = 'non_passing_context_outcome_observed';
        }
        if ($missingRoiRows->isNotEmpty()) {
            $reasons[] = 'context_feedback_missing_roi_signal';
        }

        $weakRatio = round($weakRows->count() / max(1, $observedCount), 4);
        $nonPassingRatio = round($nonPassingRows->count() / max(1, $observedCount), 4);
        $status = ($weakRatio >= 0.50 && $observedCount >= 3) || ($nonPassingRatio >= 0.75 && $observedCount >= 3)
            ? 'critical'
            : ($reasons !== [] ? 'warning' : 'ready');
        $latest = $rows->first();
        $latestPolicyAction = is_array($latest) ? (string) (($latest['policy_actions'][0] ?? '') ?: '') : '';
        $recommendedAction = $status === 'ready'
            ? 'keep_collecting_provider_safe_context_feedback'
            : ($reasons === ['context_waste_observed'] && $latestPolicyAction !== ''
                ? $latestPolicyAction
                : 'review_context_feedback_before_expanding_initial_context_or_demoting_sources');
        $autoApplyThreshold = $recommendedAction === 'shrink_initial_context' ? 2 : 0;
        $remainingBeforeAutoApply = $autoApplyThreshold > 0 ? max(0, $autoApplyThreshold - $observedCount) : 0;

        return [
            'schema_version' => 'atlas.open_brain.context_feedback_metric_aggregate.v1',
            'status' => $status,
            'window_hours' => $hours,
            'observed_count' => $observedCount,
            'measured_count' => $observedCount,
            'total_event_count' => $totalEventCount,
            'quality_band_counts' => [
                'strong' => $strongRows->count(),
                'mixed' => $mixedRows->count(),
                'weak' => $weakRows->count(),
                'unknown' => $observedCount - $strongRows->count() - $mixedRows->count() - $weakRows->count(),
            ],
            'outcome_counts' => $rows->map(fn (array $row): string => (string) $row['outcome_status'])->countBy()->all(),
            'low_roi_count' => $lowRoiRows->count(),
            'waste_count' => $wasteRows->count(),
            'noise_count' => $noiseRows->count(),
            'missed_required_source_feedback_count' => $missedRows->count(),
            'non_passing_count' => $nonPassingRows->count(),
            'roi_signal_count' => $roiSignalRows->count(),
            'actionable_feedback_count' => $actionableRows->count(),
            'non_actionable_feedback_count' => $observedCount - $actionableRows->count(),
            'missing_roi_signal_count' => $missingRoiRows->count(),
            'weak_ratio' => $weakRatio,
            'non_passing_ratio' => $nonPassingRatio,
            'averages' => [
                'roi_score' => $this->averageMetric($rows, 'roi_score', 4),
                'use_ratio' => $this->averageMetric($rows, 'use_ratio', 4),
                'waste_ratio' => $this->averageMetric($rows, 'waste_ratio', 4),
                'context_sufficiency' => $this->averageMetric($rows, 'context_sufficiency'),
                'post_execution_utility' => $this->averageMetric($rows, 'post_execution_utility'),
                'included_sources' => $this->averageMetric($rows, 'included_sources'),
                'used_sources' => $this->averageMetric($rows, 'used_sources'),
                'noise_sources' => $this->averageMetric($rows, 'noise_sources'),
            ],
            'latest' => $latest,
            'review_signal' => [
                'status' => $status === 'ready' ? 'ready' : ($status === 'critical' ? 'blocking' : 'review'),
                'severity' => $status === 'critical' ? 'high' : ($status === 'warning' ? 'medium' : 'low'),
                'reasons' => $reasons,
                'recommended_action' => $recommendedAction,
                'auto_apply_threshold' => $autoApplyThreshold,
                'auto_apply_ready' => $autoApplyThreshold > 0 && $remainingBeforeAutoApply === 0,
                'remaining_feedback_events_before_auto_apply' => $remainingBeforeAutoApply,
            ],
        ];
    }

    private function isNonPassingContextOutcome(string $status): bool
    {
        $status = strtolower(trim($status));
        if ($status === '' || in_array($status, ['passed', 'success', 'succeeded', 'ok', 'ready', 'completed'], true)) {
            return false;
        }

        if (in_array($status, ['ready_for_provider', 'unknown', 'observed', 'no_data'], true)) {
            return false;
        }

        return true;
    }

    private function feedbackEventMeasured(AiRagFeedbackEvent $event): bool
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        return (bool) data_get(
            $payload,
            'measured',
            data_get(
                $payload,
                'payload.measured',
                data_get($payload, 'payload.context_roi.measured', data_get($payload, 'context_roi.measured', false)),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function memorySummary(?string $workspace): array
    {
        $memoryTable = Schema::hasTable('atlas_memory_entries');
        $verbatimTable = Schema::hasTable('atlas_verbatim_memories');
        $openBrainAuditTable = Schema::hasTable('atlas_open_brain_access_logs');
        $providerSafeCount = 0;
        if ($memoryTable) {
            $providerSafeCount = AtlasMemoryEntry::query()
                ->where('status', 'active')
                ->get()
                ->filter(fn (AtlasMemoryEntry $entry): bool => $this->privacy->providerAllowed($entry))
                ->count();
        }

        return [
            'status' => $memoryTable ? ($providerSafeCount > 0 ? 'ready' : 'empty_provider_safe_memory') : 'not_migrated',
            'tables' => [
                'atlas_memory_entries' => $memoryTable,
                'atlas_verbatim_memories' => $verbatimTable,
                'atlas_open_brain_access_logs' => $openBrainAuditTable,
            ],
            'active_memory_count' => $memoryTable ? AtlasMemoryEntry::query()->where('status', 'active')->count() : 0,
            'provider_safe_memory_count' => $providerSafeCount,
            'verbatim_active_count' => $verbatimTable ? AtlasVerbatimMemory::query()->where('status', 'active')->count() : 0,
            'open_brain_audit_count' => $openBrainAuditTable ? AtlasOpenBrainAccessLog::query()->count() : 0,
            'workspace' => $workspace,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $codeAudit
     */
    public function overallStatus(array $memory, array $memoryQuality, array $promptMetrics, array $contextFeedbackMetrics, array $runtimeSourceProbe, array $knowledge, array $code, array $projection, ?array $codeAudit): string
    {
        if (($runtimeSourceProbe['status'] ?? null) === 'stale_source_mismatch') {
            return 'mcp_runtime_stale';
        }
        if (($memory['status'] ?? null) !== 'ready') {
            return 'needs_memory';
        }
        if (in_array($memoryQuality['status'] ?? null, ['critical'], true)) {
            return 'needs_memory_quality_review';
        }
        if (in_array($promptMetrics['status'] ?? null, ['critical'], true)) {
            return 'needs_prompt_metric_review';
        }
        if (in_array($contextFeedbackMetrics['status'] ?? null, ['critical'], true)) {
            return 'needs_context_feedback_review';
        }
        if (($knowledge['status'] ?? null) !== 'ready') {
            return 'needs_knowledge_sync';
        }
        if (($code['status'] ?? null) !== 'ready') {
            return 'needs_code_index';
        }
        if (($projection['status'] ?? null) !== 'passed') {
            return 'needs_projection_review';
        }
        if ($codeAudit !== null && ($codeAudit['status'] ?? null) !== 'fresh') {
            return 'needs_code_index_refresh';
        }

        return 'ready';
    }

    /**
     * @param  array<string,mixed>|null  $codeAudit
     * @return array<int,string>
     */
    public function nextActions(?string $workspace, array $memory, array $memoryQuality, array $promptMetrics, array $contextFeedbackMetrics, array $runtimeSourceProbe, array $knowledge, array $code, array $projection, ?array $codeAudit): array
    {
        $workspaceArg = $workspace ? ' --workspace="'.str_replace('"', '\"', $workspace).'"' : '';
        $actions = [];

        if (($runtimeSourceProbe['status'] ?? null) === 'stale_source_mismatch') {
            $actions[] = 'Restart the provider MCP client/session; until then use /opt/homebrew/bin/php artisan atlas:context-pack "<task>" --workspace="'.$workspace.'" --json as the fresh CLI fallback.';
        }
        if (($memory['provider_safe_memory_count'] ?? 0) < 1) {
            $actions[] = '/opt/homebrew/bin/php artisan atlas:memory:seed-core';
        }
        foreach ((array) ($memoryQuality['recommendations'] ?? []) as $action) {
            if (is_string($action) && $action !== '') {
                $actions[] = $action;
            }
        }
        if (in_array($promptMetrics['status'] ?? null, ['critical', 'warning'], true)) {
            $actions[] = 'Review open_brain_prompt_metrics before changing prompt delivery policy.';
        }
        if (($contextFeedbackMetrics['status'] ?? null) === 'no_data') {
            $actions[] = 'Ask external providers to call atlas_context_feedback after context-sensitive runs.';
        }
        if (in_array($contextFeedbackMetrics['status'] ?? null, ['critical', 'warning'], true)) {
            $flow = (string) data_get($contextFeedbackMetrics, 'latest.flow_id', '');
            if (data_get($contextFeedbackMetrics, 'review_signal.recommended_action') === 'shrink_initial_context') {
                $remaining = (int) data_get($contextFeedbackMetrics, 'review_signal.remaining_feedback_events_before_auto_apply', 0);
                $actions[] = $remaining > 0
                    ? 'Collect one more AOBG context feedback'.($flow !== '' ? ' for flow '.$flow : '').' before auto-shrinking initial context budget.'
                    : 'Shrink initial AOBG context budget'.($flow !== '' ? ' for flow '.$flow : '').' before expanding source coverage.';
            } else {
                $actions[] = 'Review context_feedback_metrics before expanding initial context or demoting sources.';
            }
        }
        if (($knowledge['status'] ?? null) !== 'ready') {
            $actions[] = './bin/atlas engineering knowledge sync --prune --json';
        }
        if (($code['status'] ?? null) !== 'ready' || ($codeAudit !== null && ($codeAudit['status'] ?? null) !== 'fresh')) {
            $actions[] = './bin/atlas engineering knowledge index-code --prune'.$workspaceArg.' --summary-only --json';
        }
        if (($projection['status'] ?? null) !== 'passed') {
            $actions[] = './bin/atlas memory projection review --target=all'.$workspaceArg.' --json';
            $actions[] = './bin/atlas memory projection apply --target=all'.$workspaceArg.' --yes --json';
        }

        return array_values(array_unique($actions));
    }

    // ponytail: averageMetric copied verbatim from the façade (which keeps its own
    // pinned copy). Matches the existing per-Tools-class primitive convention.

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function averageMetric(Collection $rows, string $key, int $precision = 2): float
    {
        if ($rows->isEmpty()) {
            return 0.0;
        }

        return round(AiValueNormalizer::finiteFloatOrNull($rows->avg($key)) ?? 0.0, $precision);
    }
}
