<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringReviewFinding;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EngineeringReviewFindingService
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function record(AtlasEngineeringRun $run, array $data): ?AtlasEngineeringReviewFinding
    {
        if (! Schema::hasTable('atlas_engineering_review_findings')) {
            return null;
        }

        $attempt = $this->attemptForRun($run, $data['attempt_id'] ?? null);

        return AtlasEngineeringReviewFinding::query()->create([
            'engineering_run_id' => $run->id,
            'attempt_id' => $attempt?->id,
            'task_id' => $run->task_id,
            'source' => $this->source($data['source'] ?? 'manual_review'),
            'severity' => $this->severity($data['severity'] ?? 'p2'),
            'status' => $this->status($data['status'] ?? 'open'),
            'title' => Str::limit(trim((string) ($data['title'] ?? 'Review finding')), 180, ''),
            'body' => isset($data['body']) ? trim((string) $data['body']) : null,
            'file_path' => isset($data['file_path']) ? trim((string) $data['file_path']) : null,
            'start_line' => $this->positiveInt($data['start_line'] ?? null),
            'end_line' => $this->positiveInt($data['end_line'] ?? null),
            'evidence_json' => is_array($data['evidence'] ?? null) ? $data['evidence'] : [],
            'resolution_json' => [],
            'detected_at' => now(),
            'resolved_at' => null,
            'metadata' => [
                'recorded_by' => $data['recorded_by'] ?? 'atlas_engineering_runner',
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $resolution
     */
    public function transition(AtlasEngineeringReviewFinding $finding, string $status, array $resolution = []): AtlasEngineeringReviewFinding
    {
        $status = $this->status($status);

        $finding->forceFill([
            'status' => $status,
            'resolution_json' => $resolution,
            'resolved_at' => in_array($status, ['resolved', 'dismissed'], true) ? now() : null,
        ])->save();

        return $finding->refresh();
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(AtlasEngineeringRun $run): array
    {
        $findings = $run->relationLoaded('reviewFindings')
            ? $run->reviewFindings
            : $run->reviewFindings()->get();

        return $this->summaryForFindings($findings);
    }

    /**
     * @param  Collection<int,AtlasEngineeringReviewFinding>  $findings
     * @return array<string,mixed>
     */
    public function summaryForFindings(Collection $findings): array
    {
        $open = $findings->where('status', 'open');
        $blocking = $open->whereIn('severity', ['p0', 'p1']);

        return [
            'total_count' => $findings->count(),
            'open_count' => $open->count(),
            'blocking_count' => $blocking->count(),
            'by_severity' => $open->groupBy('severity')->map->count()->all(),
        ];
    }

    private function attemptForRun(AtlasEngineeringRun $run, mixed $attemptId): ?AtlasEngineeringRunAttempt
    {
        if (is_string($attemptId) && Str::isUuid($attemptId)) {
            $attempt = AtlasEngineeringRunAttempt::query()
                ->where('engineering_run_id', $run->id)
                ->where('id', $attemptId)
                ->first();

            if ($attempt) {
                return $attempt;
            }
        }

        return $run->attempts()->orderByDesc('attempt_number')->first();
    }

    private function severity(mixed $value): string
    {
        $value = Str::lower(trim((string) $value));

        return in_array($value, ['p0', 'p1', 'p2', 'p3'], true) ? $value : 'p2';
    }

    private function status(mixed $value): string
    {
        $value = Str::lower(trim((string) $value));

        return in_array($value, ['open', 'resolved', 'dismissed'], true) ? $value : 'open';
    }

    private function source(mixed $value): string
    {
        $value = Str::of((string) $value)->lower()->snake()->limit(80, '')->value();

        return $value !== '' ? $value : 'manual_review';
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }
}
