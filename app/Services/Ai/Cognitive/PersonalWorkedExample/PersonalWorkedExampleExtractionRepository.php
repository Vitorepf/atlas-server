<?php

namespace App\Services\Ai\Cognitive\PersonalWorkedExample;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        return Schema::hasTable('worked_example_extractions');
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
