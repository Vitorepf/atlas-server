<?php

namespace App\Services\Ai\Cognitive\PersonalWorkedExample;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PersonalWorkedExampleExtractionRepository
{
    public const SCHEMA_VERSION = 'atlas.cognitive.personal_worked_example_extraction.v1';

    public function alreadyProcessed(string $sourceType, string $sourceRef): bool
    {
        return $this->tableReady()
            && DB::table('worked_example_extractions')
                ->where('source_type', $sourceType)
                ->where('source_ref', $sourceRef)
                ->exists();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $sourceType, string $sourceRef): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = DB::table('worked_example_extractions')
            ->where('source_type', $sourceType)
            ->where('source_ref', $sourceRef)
            ->first();

        return $row ? $this->normalize((array) $row) : null;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $qualitySignals
     * @param  array<string,mixed>|null  $redaction
     * @return array<string,mixed>
     */
    public function record(array $candidate, string $status, ?int $workedExampleId = null, ?string $discardReason = null, array $qualitySignals = [], ?array $redaction = null): array
    {
        if (! $this->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $now = now();
        $sourceType = (string) ($candidate['source_type'] ?? 'unknown');
        $sourceRef = (string) ($candidate['source_ref'] ?? sha1(json_encode($candidate, JSON_THROW_ON_ERROR)));
        $exists = DB::table('worked_example_extractions')
            ->where('source_type', $sourceType)
            ->where('source_ref', $sourceRef)
            ->exists();

        DB::table('worked_example_extractions')->updateOrInsert(
            ['source_type' => $sourceType, 'source_ref' => $sourceRef],
            [
                'source_metadata' => json_encode((array) ($candidate['source_metadata'] ?? []), JSON_THROW_ON_ERROR),
                'extraction_status' => $status,
                'discard_reason' => $discardReason,
                'worked_example_id' => $workedExampleId,
                'quality_signals' => json_encode($qualitySignals ?: (array) ($candidate['quality_signals'] ?? []), JSON_THROW_ON_ERROR),
                'redaction_applied' => $redaction ? json_encode($redaction, JSON_THROW_ON_ERROR) : null,
                'processed_at' => $now,
                'updated_at' => $now,
                'created_at' => $exists ? DB::raw('created_at') : $now,
            ]
        );

        $row = DB::table('worked_example_extractions')
            ->where('source_type', $sourceType)
            ->where('source_ref', $sourceRef)
            ->first();

        return $row ? $this->normalize((array) $row) : ['schema_version' => self::SCHEMA_VERSION, 'status' => 'missing_after_record'];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(string $status = 'any', int $limit = 50): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return DB::table('worked_example_extractions')
            ->when($status !== 'any', fn ($query) => $query->where('extraction_status', $status))
            ->orderByDesc('processed_at')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (object $row): array => $this->normalize((array) $row))
            ->values()
            ->all();
    }

    public function tableReady(): bool
    {
        return DatabaseTableAvailability::has('worked_example_extractions');
    }

    /**
     * @return array<string,mixed>
     */
    public function scheduleStatus(): array
    {
        if (! $this->scheduleTableReady()) {
            return [
                'schema_version' => 'atlas.cognitive.personal_extraction_schedule.v1',
                'status' => 'table_missing',
                'scheduler_registered' => true,
                'registration_status' => 'registered_fail_closed_table_missing',
                'job' => null,
            ];
        }

        return [
            'schema_version' => 'atlas.cognitive.personal_extraction_schedule.v1',
            'status' => 'ok',
            'scheduler_registered' => true,
            'registration_status' => 'registered_review_only',
            'job' => $this->defaultScheduleJob(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function setScheduleEnabled(bool $enabled): array
    {
        if (! $this->scheduleTableReady()) {
            return [
                'schema_version' => 'atlas.cognitive.personal_extraction_schedule.v1',
                'status' => 'table_missing',
                'scheduler_registered' => true,
                'registration_status' => 'registered_fail_closed_table_missing',
                'job' => null,
            ];
        }

        $now = now();
        $exists = DB::table('personal_extraction_jobs')
            ->where('cadence', 'weekly')
            ->exists();

        DB::table('personal_extraction_jobs')->updateOrInsert(
            ['cadence' => 'weekly'],
            [
                'source_filters' => json_encode([
                    'domains' => ['programming', 'strategic_decision', 'learning'],
                    'sources' => ['programming_pr', 'strategic_decision', 'feynman_session'],
                    'window_days' => 90,
                    'min_quality_score' => 7,
                    'max_candidates' => 50,
                    'auto_apply' => false,
                    'review_required' => true,
                ], JSON_THROW_ON_ERROR),
                'enabled' => $enabled,
                'next_run_at' => $enabled ? $now->copy()->addWeek() : null,
                'last_run_summary' => json_encode([
                    'status' => 'not_run_by_scheduler',
                    'reason' => 'registered_review_only_waiting_for_due_time',
                ], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
                'created_at' => $exists ? DB::raw('created_at') : $now,
            ]
        );

        return $this->scheduleStatus();
    }

    /**
     * @return array<string,mixed>
     */
    public function scheduledRunReadiness(): array
    {
        $status = $this->scheduleStatus();
        $job = (array) ($status['job'] ?? []);

        if (($status['status'] ?? null) !== 'ok') {
            return array_merge($status, [
                'ready_to_run' => false,
                'skip_reason' => $status['status'] ?? 'schedule_unavailable',
            ]);
        }

        if ($job === []) {
            return array_merge($status, [
                'ready_to_run' => false,
                'skip_reason' => 'schedule_job_not_configured',
            ]);
        }

        if (! (bool) ($job['enabled'] ?? false)) {
            return array_merge($status, [
                'ready_to_run' => false,
                'skip_reason' => 'schedule_disabled',
            ]);
        }

        $nextRunAt = $job['next_run_at'] ?? null;
        if (is_string($nextRunAt) && trim($nextRunAt) !== '' && now()->lt(Carbon::parse($nextRunAt))) {
            return array_merge($status, [
                'ready_to_run' => false,
                'skip_reason' => 'not_due',
            ]);
        }

        return array_merge($status, [
            'ready_to_run' => true,
            'skip_reason' => null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    public function markScheduledRunCompleted(array $summary): array
    {
        if (! $this->scheduleTableReady()) {
            return $this->scheduleStatus();
        }

        $now = now();
        DB::table('personal_extraction_jobs')
            ->where('cadence', 'weekly')
            ->update([
                'last_run_at' => $now,
                'next_run_at' => $now->copy()->addWeek(),
                'last_run_summary' => json_encode([
                    'status' => 'completed',
                    'review_required' => true,
                    'auto_apply' => false,
                    'summary' => $summary,
                ], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

        return $this->scheduleStatus();
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
            'source_type' => (string) ($row['source_type'] ?? ''),
            'source_ref' => (string) ($row['source_ref'] ?? ''),
            'source_metadata' => $this->jsonArray($row['source_metadata'] ?? []),
            'extraction_status' => (string) ($row['extraction_status'] ?? ''),
            'discard_reason' => $row['discard_reason'] ?? null,
            'worked_example_id' => $row['worked_example_id'] ?? null,
            'quality_signals' => $this->jsonArray($row['quality_signals'] ?? []),
            'redaction_applied' => $this->jsonArray($row['redaction_applied'] ?? []),
            'processed_at' => $row['processed_at'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function defaultScheduleJob(): ?array
    {
        $row = DB::table('personal_extraction_jobs')
            ->where('cadence', 'weekly')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'cadence' => (string) $row->cadence,
            'enabled' => (bool) $row->enabled,
            'source_filters' => $this->jsonArray($row->source_filters ?? []),
            'last_run_at' => $row->last_run_at,
            'next_run_at' => $row->next_run_at,
            'last_run_summary' => $this->jsonArray($row->last_run_summary ?? []),
        ];
    }

    private function scheduleTableReady(): bool
    {
        return DatabaseTableAvailability::has('personal_extraction_jobs');
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
