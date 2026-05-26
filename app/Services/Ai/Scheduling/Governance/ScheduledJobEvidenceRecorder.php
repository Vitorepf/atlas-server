<?php

declare(strict_types=1);

namespace App\Services\Ai\Scheduling\Governance;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler — Evidence Recorder.
 *
 * Append-only persistence of `atlas.scheduling.job_evidence.v1` envelopes.
 * Closes the Evidence half of the matrix gap "Jobs autonomos precisam
 * stop conditions, evidence e proposal gates por fluxo".
 *
 * Each job run writes one envelope; runs are correlated via `job_id` +
 * monotonic timestamp filename. Never overwrites; never reads job payload
 * content — only hashes + counts + decision summary.
 */
final class ScheduledJobEvidenceRecorder
{
    public const SCHEMA_VERSION = 'atlas.scheduling.job_evidence.v1';

    private string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? storage_path('atlas/evidence/scheduled_jobs');
    }

    /**
     * @param  array{
     *   job_id: string,
     *   outcome: string,
     *   duration_ms?: int,
     *   stop_gate_decision?: array<string,mixed>,
     *   payload_hash?: string,
     *   failure_signature?: ?string,
     * }  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $envelope = $this->buildEnvelope($input);

        try {
            $this->write($envelope);
        } catch (\Throwable $e) {
            Log::warning('atlas.scheduling.job_evidence.write_failed', [
                'job_id' => $envelope['job_id'],
                'reason' => $e->getMessage(),
            ]);
        }

        return $envelope;
    }

    private function buildEnvelope(array $input): array
    {
        $jobId = (string) ($input['job_id'] ?? 'unknown');
        $outcome = (string) ($input['outcome'] ?? 'unknown');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'job_id' => $jobId,
            'recorded_at' => now()->toAtomString(),
            'outcome' => $outcome,
            'duration_ms' => (int) ($input['duration_ms'] ?? 0),
            'stop_gate_decision' => [
                'schema_version' => (string) ($input['stop_gate_decision']['schema_version'] ?? ''),
                'may_run' => (bool) ($input['stop_gate_decision']['may_run'] ?? false),
                'stop_reasons' => (array) ($input['stop_gate_decision']['stop_reasons'] ?? []),
            ],
            'payload_hash' => isset($input['payload_hash']) && is_string($input['payload_hash'])
                ? $input['payload_hash']
                : null,
            'failure_signature' => isset($input['failure_signature']) && is_string($input['failure_signature'])
                ? $input['failure_signature']
                : null,
            'provider_safe' => true,
        ];
    }

    private function write(array $envelope): void
    {
        $date = substr($envelope['recorded_at'], 0, 10);
        $dir = $this->storagePath.'/'.$date;
        File::ensureDirectoryExists($dir);

        $safeJobId = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $envelope['job_id']) ?? 'job';
        $filename = sprintf(
            '%s-%s.json',
            $safeJobId,
            (string) (microtime(true) * 1000),
        );
        File::put($dir.'/'.$filename, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
