<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

final class LedgerProjectionWorker
{
    public const SCHEMA_VERSION = 'atlas.ledger_projection_worker.v1';

    public function __construct(
        private readonly LedgerProjectionRegistry $registry,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function project(int $limit = 500, ?int $hours = null, bool $dryRun = false): array
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'available' => false,
                'status' => 'ledger_missing',
                'dry_run' => $dryRun,
                'limit' => $limit,
                'hours' => $hours,
                'event_count' => 0,
                'projected_count' => 0,
                'skipped_count' => 0,
                'projection_results' => [],
            ];
        }

        $projections = $this->registry->projections();
        $sourceEvents = collect($projections)
            ->flatMap(fn (array $projection): array => (array) $projection['source_events'])
            ->unique()
            ->values()
            ->all();

        $query = AtlasLedgerEvent::query()
            ->whereIn('event_type', $sourceEvents)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->limit(max(1, min(5000, $limit)));

        if ($hours !== null) {
            $query->where('occurred_at', '>=', now()->subHours(max(1, min(8760, $hours))));
        }

        $events = $query->get();
        $results = [];
        $projected = 0;
        $skipped = 0;

        foreach ($events as $event) {
            foreach ($projections as $projection) {
                if (! in_array($event->event_type, (array) $projection['source_events'], true)) {
                    continue;
                }

                $result = $this->projectEvent($projection, $event, $dryRun);
                $results[] = $result;
                $projected += (int) ($result['projected'] ?? false);
                $skipped += (int) (! ($result['projected'] ?? false));
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'available' => true,
            'status' => 'ok',
            'dry_run' => $dryRun,
            'limit' => max(1, min(5000, $limit)),
            'hours' => $hours,
            'event_count' => $events->count(),
            'projected_count' => $projected,
            'skipped_count' => $skipped,
            'projection_results' => $results,
        ];
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function projectEvent(array $projection, AtlasLedgerEvent $event, bool $dryRun): array
    {
        $id = (string) $projection['id'];
        $table = (string) $projection['table'];

        if (! DatabaseTableAvailability::has($table)) {
            return $this->result($id, $event, false, 'projection_table_missing');
        }

        return match ($id) {
            'ai_traces' => $this->projectTrace($projection, $event, $dryRun),
            'atlas_engineering_runs' => $this->projectEngineeringRun($projection, $event, $dryRun),
            'atlas_tool_runs' => $this->projectToolRun($projection, $event, $dryRun),
            default => $this->result($id, $event, false, 'unknown_projection'),
        };
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function projectTrace(array $projection, AtlasLedgerEvent $event, bool $dryRun): array
    {
        $traceKey = $this->identity('trace', $event->trace_id ?: $event->correlation_id ?: $event->envelope_id);
        $payload = (array) $event->payload;
        $values = [
            'id' => $this->uuidFromSeed('ai_trace:'.$traceKey),
            'trace_key' => $traceKey,
            'source_type' => 'system',
            'source_id' => null,
            'status' => $this->traceStatus($event),
            'operator_input' => $this->operatorInput($event),
            'intent' => $this->nullableString(data_get($payload, 'intent') ?? data_get($payload, 'flow')),
            'agent_slug' => $this->nullableString(data_get($payload, 'agent_slug')) ?? 'atlas_ai',
            'provider' => $this->nullableString(data_get($payload, 'provider_cli') ?? data_get($payload, 'provider') ?? data_get($payload, 'provider_selection.provider_cli')),
            'model' => $this->nullableString(data_get($payload, 'model') ?? data_get($payload, 'provider_selection.model')),
            'skill_versions' => '{}',
            'context_refs' => '[]',
            'metadata' => $this->json($this->metadata($event, 'ai_traces')),
            'created_at' => $event->occurred_at ?? now(),
            'updated_at' => now(),
        ];

        return $this->upsert((string) $projection['id'], (string) $projection['table'], ['trace_key' => $traceKey], $values, $event, $dryRun);
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function projectEngineeringRun(array $projection, AtlasLedgerEvent $event, bool $dryRun): array
    {
        $payload = (array) $event->payload;
        $id = $this->uuidValue(data_get($payload, 'engineering_run_id') ?? data_get($payload, 'run_id'))
            ?? $this->uuidFromSeed('engineering_run:'.$event->envelope_id);
        $taskId = $this->uuidValue(data_get($payload, 'task_id'))
            ?? $this->uuidFromSeed('engineering_task:'.$event->envelope_id);
        $traceId = $this->uuidValue($event->trace_id);

        $values = [
            'id' => $id,
            'task_id' => $taskId,
            'trace_id' => $traceId,
            'workspace_path_hash' => $this->hashValue(data_get($payload, 'workspace_path_hash') ?? $event->envelope_id),
            'workspace_label' => $this->nullableString(data_get($payload, 'workspace_label')) ?? 'ledger projection',
            'provider_strategy_json' => $this->json(data_get($payload, 'provider_strategy') ?? []),
            'status' => $this->engineeringStatus($event),
            'metadata' => $this->json($this->metadata($event, 'atlas_engineering_runs')),
            'created_at' => $event->occurred_at ?? now(),
            'updated_at' => now(),
        ];

        return $this->upsert((string) $projection['id'], (string) $projection['table'], ['id' => $id], $values, $event, $dryRun);
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function projectToolRun(array $projection, AtlasLedgerEvent $event, bool $dryRun): array
    {
        $payload = (array) $event->payload;
        $toolSlug = $this->nullableString(
            data_get($payload, 'tool_slug')
            ?? data_get($payload, 'tool.id')
            ?? data_get($payload, 'tool')
        );

        if ($toolSlug === null) {
            return $this->result((string) $projection['id'], $event, false, 'tool_slug_missing');
        }

        $runContextId = $this->nullableString(data_get($payload, 'run_context_id')) ?? $event->envelope_id;
        $id = $this->uuidValue(data_get($payload, 'tool_run_id') ?? data_get($payload, 'run_id'))
            ?? $this->uuidFromSeed('tool_run:'.$toolSlug.':'.$runContextId);

        $values = [
            'id' => $id,
            'tool_slug' => $toolSlug,
            'surface' => $this->nullableString(data_get($payload, 'surface')) ?? $event->emitter_stage,
            'run_context_type' => $this->nullableString(data_get($payload, 'run_context_type')) ?? 'ledger_event',
            'run_context_id' => $runContextId,
            'status' => $this->toolStatus($event),
            'required' => (bool) data_get($payload, 'required', false),
            'failure_policy' => $this->nullableString(data_get($payload, 'failure_policy')) ?? 'advisory',
            'policy_decision' => $this->nullableString(data_get($payload, 'policy_decision')) ?? 'allowed',
            'summary_json' => $this->json([
                'event_type' => $event->event_type,
                'emitter_stage' => $event->emitter_stage,
            ]),
            'normalized_result_json' => $this->json(data_get($payload, 'normalized_result') ?? data_get($payload, 'result') ?? []),
            'metadata_json' => $this->json($this->metadata($event, 'atlas_tool_runs')),
            'created_at' => $event->occurred_at ?? now(),
            'updated_at' => now(),
        ];

        return $this->upsert((string) $projection['id'], (string) $projection['table'], ['id' => $id], $values, $event, $dryRun);
    }

    /**
     * @param  array<string,mixed>  $identity
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function upsert(string $projectionId, string $table, array $identity, array $values, AtlasLedgerEvent $event, bool $dryRun): array
    {
        $identity = $this->filterColumns($table, $identity);
        $values = $this->filterColumns($table, $values);

        if ($identity === []) {
            return $this->result($projectionId, $event, false, 'projection_identity_columns_missing');
        }

        if (! $dryRun) {
            DB::table($table)->updateOrInsert($identity, $values);
        }

        return $this->result($projectionId, $event, true, $dryRun ? 'would_project' : 'projected');
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function filterColumns(string $table, array $values): array
    {
        return array_filter(
            $values,
            fn (string $column): bool => DatabaseTableAvailability::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(AtlasLedgerEvent $event, string $projectionId): array
    {
        return [
            'schema_version' => 'atlas.ledger_projection.metadata.v1',
            'projection_id' => $projectionId,
            'ledger_event_id' => $event->event_id,
            'ledger_event_type' => $event->event_type,
            'envelope_id' => $event->envelope_id,
            'receipt_id' => $event->receipt_id,
            'trace_id' => $event->trace_id,
            'correlation_id' => $event->correlation_id,
            'payload_hash' => $event->payload_hash,
            'emitter_stage' => $event->emitter_stage,
            'emitter_version' => $event->emitter_version,
            'occurred_at' => $event->occurred_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $projectionId, AtlasLedgerEvent $event, bool $projected, string $status): array
    {
        return [
            'projection_id' => $projectionId,
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'envelope_id' => $event->envelope_id,
            'projected' => $projected,
            'status' => $status,
        ];
    }

    private function traceStatus(AtlasLedgerEvent $event): string
    {
        return match ($event->event_type) {
            LedgerEventType::OperationCompleted->value, LedgerEventType::ProviderReturned->value => 'succeeded',
            LedgerEventType::OperationFailed->value, LedgerEventType::OperationBlocked->value => 'failed',
            default => 'processing',
        };
    }

    private function engineeringStatus(AtlasLedgerEvent $event): string
    {
        return match ($event->event_type) {
            LedgerEventType::OperationCompleted->value, LedgerEventType::RepairCompleted->value => 'completed',
            LedgerEventType::OperationFailed->value => 'failed',
            LedgerEventType::OperationBlocked->value => 'blocked',
            default => 'running',
        };
    }

    private function toolStatus(AtlasLedgerEvent $event): string
    {
        return match ($event->event_type) {
            LedgerEventType::ToolPlanned->value => 'planned',
            LedgerEventType::ToolApproved->value => 'approved',
            LedgerEventType::ToolInvoked->value => 'running',
            LedgerEventType::ToolReturned->value, LedgerEventType::ToolNormalized->value, LedgerEventType::ToolEvidenceRecorded->value => 'completed',
            LedgerEventType::GateEvaluated->value => 'evaluated',
            default => 'observed',
        };
    }

    private function operatorInput(AtlasLedgerEvent $event): string
    {
        $input = $this->nullableString(data_get($event->payload, 'operator_input') ?? data_get($event->payload, 'input.summary'));

        return $input ?? '[projected from Evidence Ledger: '.$event->event_type.']';
    }

    private function identity(string $prefix, string $value): string
    {
        return $prefix.':'.substr(hash('sha256', $value), 0, 40);
    }

    private function hashValue(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $value ?: 'ledger_projection');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function uuidValue(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null || ! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value)) {
            return null;
        }

        return strtolower($value);
    }

    private function uuidFromSeed(string $seed): string
    {
        $hash = sha1($seed);
        $variant = dechex((hexdec($hash[16]) & 0x3) | 0x8);

        return substr($hash, 0, 8).'-'
            .substr($hash, 8, 4).'-'
            .'4'.substr($hash, 13, 3).'-'
            .$variant.substr($hash, 17, 3).'-'
            .substr($hash, 20, 12);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
