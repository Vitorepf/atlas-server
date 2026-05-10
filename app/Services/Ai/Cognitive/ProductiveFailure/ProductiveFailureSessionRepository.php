<?php

namespace App\Services\Ai\Cognitive\ProductiveFailure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductiveFailureSessionRepository
{
    public const SCHEMA_VERSION = 'atlas.cognitive.productive_failure_session.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        if (! $this->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $now = now();
        $id = DB::table('productive_failure_sessions')->insertGetId([
            'envelope_id' => (string) $input['envelope_id'],
            'knowledge_node_id' => (string) $input['knowledge_node_id'],
            'domain' => (string) $input['domain'],
            'dreyfus_stage_target' => (int) $input['dreyfus_stage_target'],
            'phase_1_problem' => json_encode($input['phase_1_problem'], JSON_THROW_ON_ERROR),
            'completion_status' => 'in_progress',
            'phase_1_started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id) ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'not_found_after_create'];
    }

    /**
     * @param  array<string,mixed>  $attempt
     * @return array<string,mixed>
     */
    public function recordAttempt(int $id, array $attempt): array
    {
        return $this->updateJson($id, [
            'phase_1_attempt' => $attempt,
            'completion_status' => 'phase_1_attempt_recorded',
        ]);
    }

    /**
     * @param  array<string,mixed>  $comparison
     * @return array<string,mixed>
     */
    public function recordComparison(int $id, ?int $workedExampleId, array $comparison): array
    {
        return $this->updateJson($id, [
            'phase_2_worked_example_id' => $workedExampleId,
            'phase_2_comparison' => $comparison,
            'completion_status' => 'phase_2_comparison_recorded',
            'phase_2_started_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $articulation
     * @param  array<string,mixed>  $transferTest
     * @return array<string,mixed>
     */
    public function recordArticulation(int $id, array $articulation, array $transferTest): array
    {
        return $this->updateJson($id, [
            'phase_3_articulation' => $articulation,
            'phase_3_transfer_test_id' => $transferTest['id'] ?? null,
            'phase_3_transfer_test' => $transferTest,
            'completion_status' => 'phase_3_articulation_recorded',
            'phase_3_started_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function complete(int $id): array
    {
        return $this->updateJson($id, [
            'completion_status' => 'complete',
            'completed_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function abandon(int $id, string $reason): array
    {
        return $this->updateJson($id, [
            'completion_status' => 'abandoned',
            'phase_3_transfer_test' => [
                'schema_version' => 'atlas.cognitive.productive_failure.abandonment.v1',
                'reason' => trim($reason) !== '' ? trim($reason) : 'operator_abandoned',
                'recorded_at' => now()->toIso8601String(),
            ],
            'completed_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = DB::table('productive_failure_sessions')->where('id', $id)->first();

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

        return DB::table('productive_failure_sessions')
            ->when($domain, fn ($query) => $query->where('domain', $domain))
            ->where('created_at', '>=', now()->subDays(max(1, $days)))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => $this->normalize((array) $row))
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function transferTestProposals(?string $domain = null, int $days = 60, bool $dueOnly = false): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $today = now()->toDateString();

        return DB::table('productive_failure_sessions')
            ->when($domain, fn ($query) => $query->where('domain', $domain))
            ->whereNotNull('phase_3_transfer_test_id')
            ->where('created_at', '>=', now()->subDays(max(1, $days)))
            ->orderBy('phase_3_started_at')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => $this->normalize((array) $row))
            ->filter(function (array $session) use ($dueOnly, $today): bool {
                $transferTest = (array) ($session['phase_3_transfer_test'] ?? []);

                if (($transferTest['status'] ?? null) !== 'proposal_only') {
                    return false;
                }

                if ((bool) ($transferTest['auto_apply_to_curriculum'] ?? true) !== false) {
                    return false;
                }

                if (! $dueOnly) {
                    return true;
                }

                $scheduledFor = (string) ($transferTest['scheduled_for'] ?? '');

                return $scheduledFor !== '' && $scheduledFor <= $today;
            })
            ->map(fn (array $session): array => $this->transferTestProjection($session))
            ->values()
            ->all();
    }

    public function tableReady(): bool
    {
        return Schema::hasTable('productive_failure_sessions');
    }

    /**
     * @param  array<string,mixed>  $updates
     * @return array<string,mixed>
     */
    private function updateJson(int $id, array $updates): array
    {
        if (! $this->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $payload = ['updated_at' => now()];
        foreach ($updates as $key => $value) {
            $payload[$key] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
        }

        DB::table('productive_failure_sessions')->where('id', $id)->update($payload);

        return $this->find($id) ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'missing_after_update'];
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
            'knowledge_node_id' => (string) ($row['knowledge_node_id'] ?? ''),
            'domain' => (string) ($row['domain'] ?? ''),
            'dreyfus_stage_target' => (int) ($row['dreyfus_stage_target'] ?? 0),
            'phase_1_problem' => $this->jsonArray($row['phase_1_problem'] ?? []),
            'phase_1_attempt' => $this->jsonArray($row['phase_1_attempt'] ?? []),
            'phase_2_worked_example_id' => $row['phase_2_worked_example_id'] ?? null,
            'phase_2_comparison' => $this->jsonArray($row['phase_2_comparison'] ?? []),
            'phase_3_articulation' => $this->jsonArray($row['phase_3_articulation'] ?? []),
            'phase_3_transfer_test_id' => $row['phase_3_transfer_test_id'] ?? null,
            'phase_3_transfer_test' => $this->jsonArray($row['phase_3_transfer_test'] ?? []),
            'completion_status' => (string) ($row['completion_status'] ?? ''),
            'phase_1_started_at' => $row['phase_1_started_at'] ?? null,
            'phase_2_started_at' => $row['phase_2_started_at'] ?? null,
            'phase_3_started_at' => $row['phase_3_started_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    private function transferTestProjection(array $session): array
    {
        $transferTest = (array) ($session['phase_3_transfer_test'] ?? []);
        $scheduledFor = (string) ($transferTest['scheduled_for'] ?? '');

        return [
            'schema_version' => 'atlas.cognitive.productive_failure.transfer_test_review.v1',
            'session_id' => (int) ($session['id'] ?? 0),
            'envelope_id' => (string) ($session['envelope_id'] ?? ''),
            'knowledge_node_id' => (string) ($session['knowledge_node_id'] ?? ''),
            'domain' => (string) ($session['domain'] ?? ''),
            'topic' => (string) ($transferTest['topic'] ?? data_get($session, 'phase_1_problem.topic', 'unknown')),
            'transfer_test_id' => (string) ($transferTest['id'] ?? ''),
            'scheduled_for' => $scheduledFor,
            'due' => $scheduledFor !== '' && $scheduledFor <= now()->toDateString(),
            'status' => (string) ($transferTest['status'] ?? 'unknown'),
            'review_required' => (bool) ($transferTest['review_required'] ?? true),
            'auto_apply_to_curriculum' => (bool) ($transferTest['auto_apply_to_curriculum'] ?? true),
            'prompt' => (string) ($transferTest['prompt'] ?? ''),
            'completion_status' => (string) ($session['completion_status'] ?? ''),
            'source_session' => [
                'phase_2_delta' => data_get($session, 'phase_2_comparison.prediction_error_delta'),
                'principle_extracted' => data_get($session, 'phase_3_articulation.principle_extracted'),
                'completed_at' => $session['completed_at'] ?? null,
            ],
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
