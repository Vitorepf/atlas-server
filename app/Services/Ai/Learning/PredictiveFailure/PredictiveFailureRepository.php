<?php

namespace App\Services\Ai\Cognitive\PredictiveFailure;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

class PredictiveFailureRepository
{
    public const SCHEMA_VERSION = 'atlas.cognitive.predictive_failure.insertion.v1';

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function create(array $payload): array
    {
        if (! $this->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $id = DB::table('predictive_failure_insertions')->insertGetId([
            'envelope_id' => $payload['envelope_id'],
            'target_knowledge_node_id' => $payload['target_knowledge_node_id'],
            'domain' => $payload['domain'],
            'signals_used' => json_encode($payload['signals_used'], JSON_THROW_ON_ERROR),
            'predicted_failure_probability' => $payload['predicted_failure_probability'],
            'calibration_band' => $payload['calibration_band'],
            'predicted_failure_signature_key' => $payload['predicted_failure_signature_key'] ?? null,
            'problem_payload' => json_encode($payload['problem_payload'], JSON_THROW_ON_ERROR),
            'source_type' => $payload['source_type'],
            'inserted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find((int) $id) ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'not_found_after_insert'];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = DB::table('predictive_failure_insertions')->where('id', $id)->first();

        return $row ? $this->normalize((array) $row) : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function history(?string $domain = null, int $days = 30): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return DB::table('predictive_failure_insertions')
            ->when($domain, fn ($query) => $query->where('domain', $domain))
            ->where('inserted_at', '>=', now()->subDays(max(1, $days)))
            ->orderByDesc('inserted_at')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => $this->normalize((array) $row))
            ->values()
            ->all();
    }

    /**
     * L6-11: record a prediction AND its observed outcome in one shot — the seam the
     * loop-outcome bridge uses to feed REAL loop grind events into the same calibration
     * surface the metrics service reads. The prediction is formed BEFORE the outcome is
     * known (by the caller, from the task signals); here we persist both and compute the
     * calibration error identically to {@see recordOutcome}, so brier_score becomes
     * non-null on real data without any backfill.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordLoopOutcome(array $payload): array
    {
        if (! $this->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $outcome = (string) ($payload['outcome'] ?? '');
        $predicted = (float) ($payload['predicted_failure_probability'] ?? 0.0);
        $actual = match ($outcome) {
            'success' => 0.0,
            'partial' => 0.5,
            'failure' => 1.0,
            default => null,
        };
        $error = $actual !== null ? round(abs($predicted - $actual), 3) : null;
        $signature = $payload['actual_failure_signature'] ?? null;
        $now = now();

        $id = DB::table('predictive_failure_insertions')->insertGetId([
            'envelope_id' => $payload['envelope_id'],
            'target_knowledge_node_id' => $payload['target_knowledge_node_id'],
            'domain' => $payload['domain'],
            'signals_used' => json_encode($payload['signals_used'] ?? [], JSON_THROW_ON_ERROR),
            'predicted_failure_probability' => $predicted,
            'calibration_band' => $payload['calibration_band'],
            'predicted_failure_signature_key' => $payload['predicted_failure_signature_key'] ?? null,
            'problem_payload' => json_encode($payload['problem_payload'] ?? [], JSON_THROW_ON_ERROR),
            'source_type' => $payload['source_type'] ?? 'loop_grind_outcome',
            'inserted_at' => $now,
            'outcome' => $outcome !== '' ? $outcome : null,
            'actual_failure_signature' => is_array($signature) ? json_encode($signature, JSON_THROW_ON_ERROR) : null,
            'outcome_recorded_at' => $now,
            'prediction_calibration_error' => $error,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find((int) $id) ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'not_found_after_insert'];
    }

    /**
     * @param  array<string,mixed>|null  $signature
     * @return array<string,mixed>
     */
    public function recordOutcome(int $id, string $outcome, ?array $signature = null): array
    {
        $insertion = $this->find($id);
        if (! $insertion) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'missing'];
        }

        $actual = match ($outcome) {
            'success' => 0.0,
            'partial' => 0.5,
            'failure' => 1.0,
            default => null,
        };
        $error = $actual !== null ? abs((float) $insertion['predicted_failure_probability'] - $actual) : null;

        DB::table('predictive_failure_insertions')->where('id', $id)->update([
            'outcome' => $outcome,
            'actual_failure_signature' => $signature ? json_encode($signature, JSON_THROW_ON_ERROR) : null,
            'outcome_recorded_at' => now(),
            'prediction_calibration_error' => $error !== null ? round($error, 3) : null,
            'updated_at' => now(),
        ]);

        return $this->find($id) ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'missing_after_outcome'];
    }

    public function tableReady(): bool
    {
        return DatabaseTableAvailability::all([
            'predictive_failure_insertions',
            'predictive_failure_calibration_metrics',
        ]);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => (int) ($row['id'] ?? 0),
            'envelope_id' => (string) ($row['envelope_id'] ?? ''),
            'target_knowledge_node_id' => (string) ($row['target_knowledge_node_id'] ?? ''),
            'domain' => (string) ($row['domain'] ?? ''),
            'signals_used' => $this->jsonArray($row['signals_used'] ?? []),
            'predicted_failure_probability' => (float) ($row['predicted_failure_probability'] ?? 0),
            'calibration_band' => (string) ($row['calibration_band'] ?? ''),
            'predicted_failure_signature_key' => $row['predicted_failure_signature_key'] ?? null,
            'problem_payload' => $this->jsonArray($row['problem_payload'] ?? []),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'inserted_at' => $row['inserted_at'] ?? null,
            'outcome' => $row['outcome'] ?? null,
            'actual_failure_signature' => $this->jsonArray($row['actual_failure_signature'] ?? []),
            'outcome_recorded_at' => $row['outcome_recorded_at'] ?? null,
            'prediction_calibration_error' => $row['prediction_calibration_error'] !== null ? (float) $row['prediction_calibration_error'] : null,
        ];
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
