<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasToolRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LedgerProjectionRegistry
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function projections(): array
    {
        return [
            [
                'id' => 'ai_traces',
                'table' => 'ai_traces',
                'model' => AiTrace::class,
                'domain' => 'ai_interactions',
                'projection_role' => 'UI/CLI trace timeline derived from envelope, provider and operation events.',
                'source_events' => [
                    LedgerEventType::EnvelopeCreated->value,
                    LedgerEventType::ExecutionStarted->value,
                    LedgerEventType::ProviderCalled->value,
                    LedgerEventType::ProviderReturned->value,
                    LedgerEventType::ProviderFallback->value,
                    LedgerEventType::OperationCompleted->value,
                    LedgerEventType::OperationFailed->value,
                    LedgerEventType::OperationBlocked->value,
                ],
                'identity_keys' => ['trace_id', 'envelope_id', 'correlation_id'],
                'required_columns' => ['id', 'trace_key', 'status', 'agent_slug', 'provider', 'model', 'metadata', 'created_at', 'updated_at'],
            ],
            [
                'id' => 'atlas_engineering_runs',
                'table' => 'atlas_engineering_runs',
                'model' => AtlasEngineeringRun::class,
                'domain' => 'programming',
                'projection_role' => 'Programming harness run projection derived from kernel execution, repair, gate and evidence events.',
                'source_events' => [
                    LedgerEventType::ExecutionStarted->value,
                    LedgerEventType::DecisionIssued->value,
                    LedgerEventType::ProviderCalled->value,
                    LedgerEventType::ProviderReturned->value,
                    LedgerEventType::GateEvaluated->value,
                    LedgerEventType::RepairInitiated->value,
                    LedgerEventType::RepairCompleted->value,
                    LedgerEventType::OperationCompleted->value,
                    LedgerEventType::OperationFailed->value,
                ],
                'identity_keys' => ['envelope_id', 'trace_id', 'task_id'],
                'required_columns' => ['id', 'task_id', 'trace_id', 'workspace_path_hash', 'workspace_label', 'status', 'metadata', 'created_at', 'updated_at'],
            ],
            [
                'id' => 'atlas_tool_runs',
                'table' => 'atlas_tool_runs',
                'model' => AtlasToolRun::class,
                'domain' => 'tool_runtime',
                'projection_role' => 'Super Tool Runtime run projection derived from tool lifecycle and normalized evidence events.',
                'source_events' => [
                    LedgerEventType::ToolPlanned->value,
                    LedgerEventType::ToolApproved->value,
                    LedgerEventType::ToolInvoked->value,
                    LedgerEventType::ToolReturned->value,
                    LedgerEventType::ToolNormalized->value,
                    LedgerEventType::ToolEvidenceRecorded->value,
                    LedgerEventType::GateEvaluated->value,
                ],
                'identity_keys' => ['run_context_id', 'envelope_id', 'tool_slug'],
                'required_columns' => ['id', 'tool_slug', 'surface', 'run_context_type', 'run_context_id', 'status', 'summary_json', 'normalized_result_json', 'metadata_json', 'created_at', 'updated_at'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function complianceReport(): array
    {
        $events = array_map(fn (LedgerEventType $event): string => $event->value, LedgerEventType::cases());
        $projections = collect($this->projections())
            ->map(function (array $projection) use ($events): array {
                $table = (string) $projection['table'];
                $model = (string) $projection['model'];
                $sourceEvents = (array) $projection['source_events'];
                $missingEvents = array_values(array_diff($sourceEvents, $events));
                $tableExists = Schema::hasTable($table);
                $requiredColumns = (array) $projection['required_columns'];
                $missingColumns = $tableExists
                    ? array_values(array_filter(
                        $requiredColumns,
                        fn (string $column): bool => ! Schema::hasColumn($table, $column),
                    ))
                    : [];

                return [
                    ...$projection,
                    'model_exists' => class_exists($model),
                    'source_events_valid' => $missingEvents === [],
                    'missing_source_events' => $missingEvents,
                    'table_exists' => $tableExists,
                    'missing_columns' => $missingColumns,
                    'ready' => class_exists($model) && $missingEvents === [] && $tableExists && $missingColumns === [],
                ];
            })
            ->values()
            ->all();

        $contractErrors = collect($projections)
            ->flatMap(function (array $projection): array {
                $errors = [];
                if (! (bool) $projection['model_exists']) {
                    $errors[] = $projection['id'].':model_missing';
                }
                foreach ((array) $projection['missing_source_events'] as $event) {
                    $errors[] = $projection['id'].':unknown_source_event:'.$event;
                }

                return $errors;
            })
            ->values()
            ->all();

        $readinessWarnings = collect($projections)
            ->flatMap(function (array $projection): array {
                if ((bool) $projection['ready']) {
                    return [];
                }

                $warnings = [];
                if (! (bool) $projection['table_exists']) {
                    $warnings[] = $projection['id'].':table_missing';
                }
                foreach ((array) $projection['missing_columns'] as $column) {
                    $warnings[] = $projection['id'].':column_missing:'.$column;
                }

                return $warnings;
            })
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.ledger_projection_registry.v1',
            'ok' => $contractErrors === [],
            'ready' => $readinessWarnings === [],
            'count' => count($projections),
            'ready_count' => collect($projections)->where('ready', true)->count(),
            'projection_ids' => collect($projections)->pluck('id')->values()->all(),
            'errors' => $contractErrors,
            'warnings' => $readinessWarnings,
            'projections' => $projections,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function driftReport(): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'schema_version' => 'atlas.ledger_projection_drift.v1',
                'available' => false,
                'status' => 'ledger_missing',
                'projection_count' => count($this->projections()),
                'drifted_count' => 0,
                'attention_count' => 0,
                'ledger_latest_occurred_at' => null,
                'projections' => [],
            ];
        }

        $ledgerLatest = $this->parseTimestamp(
            DB::table('atlas_ledger_events')->max('occurred_at')
        );

        $projections = collect($this->projections())
            ->map(fn (array $projection): array => $this->projectionDrift($projection))
            ->values()
            ->all();

        $driftedCount = collect($projections)->where('drifted', true)->count();
        $attentionCount = collect($projections)->where('needs_attention', true)->count();

        return [
            'schema_version' => 'atlas.ledger_projection_drift.v1',
            'available' => true,
            'status' => $attentionCount === 0 ? 'ok' : 'attention_required',
            'projection_count' => count($projections),
            'drifted_count' => $driftedCount,
            'attention_count' => $attentionCount,
            'ledger_latest_occurred_at' => $ledgerLatest?->toJSON(),
            'projections' => $projections,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function healthReport(?int $maxLagSeconds = null): array
    {
        $maxLagSeconds = max(60, min(86400, $maxLagSeconds ?? (int) config('atlas_ai.ledger_projection.max_lag_seconds', 900)));
        $drift = $this->driftReport();

        if (! (bool) ($drift['available'] ?? false)) {
            return [
                'schema_version' => 'atlas.ledger_projection_health.v1',
                'available' => false,
                'status' => 'unavailable',
                'severity' => 'unknown',
                'reason' => $drift['status'] ?? 'ledger_unavailable',
                'max_lag_seconds' => $maxLagSeconds,
                'scheduler' => $this->schedulerConfig(),
                'review_signal' => [
                    'status' => 'unknown',
                    'severity' => 'medium',
                    'reason' => 'ledger_projection_unavailable',
                    'recommended_action' => 'wait_for_ledger_initialization',
                ],
                'drift' => $drift,
                'projections' => [],
            ];
        }

        $projections = collect((array) ($drift['projections'] ?? []))
            ->map(fn (array $projection): array => $this->projectionHealth($projection, $maxLagSeconds))
            ->values()
            ->all();
        $criticalCount = collect($projections)->where('severity', 'critical')->count();
        $warningCount = collect($projections)->where('severity', 'warning')->count();
        $pendingCount = collect($projections)->where('severity', 'info')->count();
        $status = match (true) {
            $criticalCount > 0 => 'critical',
            $warningCount > 0 => 'warning',
            $pendingCount > 0 => 'pending',
            default => 'healthy',
        };

        return [
            'schema_version' => 'atlas.ledger_projection_health.v1',
            'available' => true,
            'status' => $status,
            'severity' => $status === 'healthy' ? 'none' : $status,
            'max_lag_seconds' => $maxLagSeconds,
            'projection_count' => (int) ($drift['projection_count'] ?? count($projections)),
            'drifted_count' => (int) ($drift['drifted_count'] ?? 0),
            'attention_count' => (int) ($drift['attention_count'] ?? 0),
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'pending_count' => $pendingCount,
            'ledger_latest_occurred_at' => $drift['ledger_latest_occurred_at'] ?? null,
            'scheduler' => $this->schedulerConfig(),
            'review_signal' => [
                'status' => $status === 'healthy' ? 'ok' : 'warning',
                'severity' => $status === 'healthy' ? 'none' : ($criticalCount > 0 ? 'high' : 'medium'),
                'reason' => $status === 'healthy' ? 'ledger_projection_current' : 'ledger_projection_health_attention',
                'recommended_action' => $status === 'healthy' ? 'none' : 'run_atlas_ai_ledger_project_or_review_projection_tables',
            ],
            'projections' => $projections,
        ];
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function projectionDrift(array $projection): array
    {
        $table = (string) $projection['table'];
        $sourceEvents = (array) $projection['source_events'];
        $sourceQuery = DB::table('atlas_ledger_events')->whereIn('event_type', $sourceEvents);
        $sourceEventCount = (clone $sourceQuery)->count();
        $latestSourceEvent = $this->parseTimestamp((clone $sourceQuery)->max('occurred_at'));

        if (! Schema::hasTable($table)) {
            return [
                'id' => $projection['id'],
                'table' => $table,
                'status' => $sourceEventCount > 0 ? 'projection_unavailable' : 'projection_table_missing',
                'table_exists' => false,
                'source_event_count' => $sourceEventCount,
                'latest_source_event_at' => $latestSourceEvent?->toJSON(),
                'projection_row_count' => 0,
                'latest_projection_updated_at' => null,
                'lag_seconds' => null,
                'drifted' => false,
                'needs_attention' => $sourceEventCount > 0,
            ];
        }

        $projectionTimestampColumn = $this->projectionTimestampColumn($table);
        $projectionRowCount = DB::table($table)->count();
        $latestProjection = $projectionTimestampColumn === null
            ? null
            : $this->parseTimestamp(DB::table($table)->max($projectionTimestampColumn));

        if ($sourceEventCount === 0) {
            return [
                'id' => $projection['id'],
                'table' => $table,
                'status' => 'no_source_events',
                'table_exists' => true,
                'source_event_count' => 0,
                'latest_source_event_at' => null,
                'projection_row_count' => $projectionRowCount,
                'latest_projection_updated_at' => $latestProjection?->toJSON(),
                'lag_seconds' => null,
                'drifted' => false,
                'needs_attention' => false,
            ];
        }

        $lagSeconds = ($latestSourceEvent !== null && $latestProjection !== null)
            ? $latestSourceEvent->getTimestamp() - $latestProjection->getTimestamp()
            : null;
        $drifted = $latestSourceEvent !== null && ($latestProjection === null || $latestSourceEvent->greaterThan($latestProjection));

        return [
            'id' => $projection['id'],
            'table' => $table,
            'status' => $drifted ? 'drift_detected' : 'current',
            'table_exists' => true,
            'source_event_count' => $sourceEventCount,
            'latest_source_event_at' => $latestSourceEvent?->toJSON(),
            'projection_row_count' => $projectionRowCount,
            'latest_projection_updated_at' => $latestProjection?->toJSON(),
            'lag_seconds' => $lagSeconds,
            'drifted' => $drifted,
            'needs_attention' => $drifted,
        ];
    }

    private function projectionTimestampColumn(string $table): ?string
    {
        if (Schema::hasColumn($table, 'updated_at')) {
            return 'updated_at';
        }

        if (Schema::hasColumn($table, 'created_at')) {
            return 'created_at';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function projectionHealth(array $projection, int $maxLagSeconds): array
    {
        $status = (string) ($projection['status'] ?? 'unknown');
        $lagSeconds = is_numeric($projection['lag_seconds'] ?? null) ? (int) $projection['lag_seconds'] : null;
        $needsAttention = (bool) ($projection['needs_attention'] ?? false);
        $severity = match (true) {
            in_array($status, ['projection_unavailable', 'projection_table_missing'], true) && $needsAttention => 'critical',
            $status === 'drift_detected' && $lagSeconds === null => 'critical',
            $status === 'drift_detected' && $lagSeconds > $maxLagSeconds => 'warning',
            $status === 'drift_detected' => 'info',
            default => 'none',
        };

        return [
            'id' => $projection['id'] ?? null,
            'table' => $projection['table'] ?? null,
            'status' => $status,
            'severity' => $severity,
            'source_event_count' => (int) ($projection['source_event_count'] ?? 0),
            'projection_row_count' => (int) ($projection['projection_row_count'] ?? 0),
            'latest_source_event_at' => $projection['latest_source_event_at'] ?? null,
            'latest_projection_updated_at' => $projection['latest_projection_updated_at'] ?? null,
            'lag_seconds' => $lagSeconds,
            'needs_attention' => $needsAttention,
            'recommended_action' => $severity === 'none' ? 'none' : 'run_atlas_ai_ledger_project',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function schedulerConfig(): array
    {
        return [
            'enabled' => (bool) config('atlas_ai.ledger_projection.enabled', true),
            'cadence' => 'every_ten_minutes',
            'command' => 'atlas:ai:ledger-project --hours='.(int) config('atlas_ai.ledger_projection.hours', 24).' --limit='.(int) config('atlas_ai.ledger_projection.limit', 500).' --json',
            'hours' => (int) config('atlas_ai.ledger_projection.hours', 24),
            'limit' => (int) config('atlas_ai.ledger_projection.limit', 500),
        ];
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
