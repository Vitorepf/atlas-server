<?php

namespace App\Services\Ai\Cognitive\Failure;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class FailureSignatureRepository
{
    public const SCHEMA_VERSION = 'atlas.cognitive.failure_signature_repository.v1';

    public function __construct(
        private readonly FailureSignatureClassifier $classifier,
        private readonly FailureSimilarityComputer $similarity,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  AtlasLedgerEvent|array<string,mixed>|string  $failure
     * @return array<string,mixed>
     */
    public function record(AtlasLedgerEvent|array|string $failure, ?string $message = null): array
    {
        if (! $this->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $signature = $this->classifier->classify($failure, $message);
        $previous = $this->recentRows((string) $signature['domain'], 30);
        $signature['similarity_to_previous'] = $this->similarity->similarity($signature, $previous);
        $signature['recurrence_count'] = $this->recurrenceCount((string) $signature['signature_key'], 30) + 1;

        $id = DB::table('failure_signatures')->insertGetId([
            'envelope_id' => $signature['envelope_id'],
            'source_ledger_event_id' => $signature['source_ledger_event_id'],
            'signature_key' => $signature['signature_key'],
            'domain' => $signature['domain'],
            'category' => $signature['category'],
            'sub_cause' => $signature['sub_cause'],
            'context_summary' => $signature['context_summary'],
            'canonical_features' => json_encode($signature['canonical_features'], JSON_THROW_ON_ERROR),
            'vector_embedding' => null,
            'similarity_to_previous' => $signature['similarity_to_previous'],
            'recurrence_count' => $signature['recurrence_count'],
            'recorded_at' => $signature['recorded_at'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stored = $this->find((int) $id);
        $this->ledger->record(LedgerEventType::FailureSignatureRecorded, [
            'schema_version' => 'atlas.cognitive.failure_signature_recorded.v1',
            'signature' => $stored,
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_failure_tracker',
            'envelope_id' => (string) $signature['envelope_id'],
            'correlation_id' => (string) $signature['signature_key'],
            'emitter_stage' => 'atlas.cognitive.failure_signature',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        return $stored ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'not_found_after_insert'];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = DB::table('failure_signatures')->where('id', $id)->first();

        return $row ? $this->normalizeSignature((array) $row) : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(?string $domain = null, int $days = 14): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $query = DB::table('failure_signatures')
            ->where('recorded_at', '>=', now()->subDays(max(1, $days))->toJSON())
            ->orderByDesc('recorded_at');

        if ($domain !== null && $domain !== '') {
            $query->where('domain', $domain);
        }

        return $query->limit(100)->get()
            ->map(fn (object $row): array => $this->normalizeSignature((array) $row))
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function alerts(string $status = 'open'): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $query = DB::table('failure_repetition_alerts')->orderByDesc('latest_occurrence_at');
        if ($status !== 'all') {
            $query->where('alert_status', $status);
        }

        return $query->get()
            ->map(fn (object $row): array => $this->normalizeAlert((array) $row))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function acknowledge(int $alertId, string $reflection): array
    {
        if (! $this->tableReady()) {
            return ['status' => 'table_missing'];
        }

        $updated = DB::table('failure_repetition_alerts')->where('id', $alertId)->update([
            'alert_status' => 'acknowledged',
            'operator_reflection' => trim($reflection),
            'acknowledged_at' => now(),
            'updated_at' => now(),
        ]);

        $alert = $this->alert($alertId);
        if ($updated > 0 && $alert !== null) {
            $this->ledger->record(LedgerEventType::FailureAlertAcknowledged, [
                'schema_version' => 'atlas.cognitive.failure_alert_acknowledged.v1',
                'alert' => $alert,
                'reflection_present' => trim($reflection) !== '',
            ], [
                'tenant_id' => 'default',
                'operator_id' => 'atlas_failure_cli',
                'envelope_id' => 'failure_alert:'.$alertId,
                'correlation_id' => (string) $alert['signature_key'],
                'emitter_stage' => 'atlas.cognitive.failure_alert',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);
        }

        return $alert ?? ['status' => 'missing'];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function alert(int $id): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = DB::table('failure_repetition_alerts')->where('id', $id)->first();

        return $row ? $this->normalizeAlert((array) $row) : null;
    }

    public function tableReady(): bool
    {
        return DatabaseTableAvailability::all([
            'failure_signatures',
            'failure_diversity_metrics',
            'failure_repetition_alerts',
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentRows(string $domain, int $days): array
    {
        return $this->recent($domain, $days);
    }

    public function recurrenceCount(string $signatureKey, int $days): int
    {
        if (! $this->tableReady()) {
            return 0;
        }

        return DB::table('failure_signatures')
            ->where('signature_key', $signatureKey)
            ->where('recorded_at', '>=', now()->subDays(max(1, $days))->toJSON())
            ->count();
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeSignature(array $row): array
    {
        return [
            'schema_version' => FailureSignatureClassifier::SCHEMA_VERSION,
            'id' => (int) ($row['id'] ?? 0),
            'envelope_id' => (string) ($row['envelope_id'] ?? ''),
            'source_ledger_event_id' => $row['source_ledger_event_id'] ?? null,
            'signature_key' => (string) ($row['signature_key'] ?? ''),
            'domain' => (string) ($row['domain'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'sub_cause' => (string) ($row['sub_cause'] ?? ''),
            'context_summary' => (string) ($row['context_summary'] ?? ''),
            'canonical_features' => $this->jsonArray($row['canonical_features'] ?? []),
            'similarity_to_previous' => $row['similarity_to_previous'] !== null ? (float) $row['similarity_to_previous'] : null,
            'recurrence_count' => (int) ($row['recurrence_count'] ?? 1),
            'recorded_at' => $this->dateString($row['recorded_at'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeAlert(array $row): array
    {
        return [
            'schema_version' => 'atlas.cognitive.failure_repetition_alert.v1',
            'id' => (int) ($row['id'] ?? 0),
            'signature_key' => (string) ($row['signature_key'] ?? ''),
            'domain' => (string) ($row['domain'] ?? ''),
            'repetition_count' => (int) ($row['repetition_count'] ?? 0),
            'severity' => (string) ($row['severity'] ?? 'warning'),
            'alert_status' => (string) ($row['alert_status'] ?? 'open'),
            'first_occurrence_at' => $this->dateString($row['first_occurrence_at'] ?? null),
            'latest_occurrence_at' => $this->dateString($row['latest_occurrence_at'] ?? null),
            'operator_reflection' => $row['operator_reflection'] ?? null,
        ];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return $value !== null ? (string) $value : null;
    }

    /**
     * @return array<mixed>
     */
    private function jsonArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
