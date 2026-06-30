<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns a brain audit with has_evidence=false or stale evidence into a concrete backfill plan:
 * names missing streams, assigns capture tasks, sets freshness thresholds, and picks the first
 * runnable proof command so Atlas can self-heal without a human or external provider.
 *
 * STREAMS REQUIRING BACKFILL when:
 *   - has_evidence = false                                           (reason = 'missing')
 *   - (now_unix - last_captured_at_unix) > freshness_threshold_secs (reason = 'stale')
 *
 * KNOWN STREAM CATALOGUE (defaults applied when stream_id matches):
 *   queue_health      — snapshot of task queue health metrics
 *   muscle_outcomes   — muscle worker task-outcome report
 *   runtime_receipts  — self-construction smoke run receipts
 *   code_facts        — code-intelligence index freshness
 *
 * For unknown stream ids, generic capture/proof commands are generated.
 *
 * INPUT:
 *   evidence_streams: list<{
 *     stream_id:                  string,
 *     has_evidence:               bool,
 *     last_captured_at_unix?:     int    (0 = never captured)
 *     freshness_threshold_seconds?: int  (default DEFAULT_FRESHNESS_THRESHOLD)
 *   }>
 *   now_unix?: int  (unix timestamp; 0 or absent = skip staleness check)
 *
 * OUTPUT:
 *   { schema, backfill_tasks, is_backfill_needed, next_proof_command }
 *
 *   backfill_tasks: list<{ stream_id, capture_task, freshness_threshold_seconds, proof_command, reason }>
 *   is_backfill_needed: bool
 *   next_proof_command: string  ('none' when no backfill needed)
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainEvidenceFreshnessBackfillPlanner
{
    public const SCHEMA = 'atlas.external_brain.evidence_freshness_backfill_planner.v1';

    public const DEFAULT_FRESHNESS_THRESHOLD = 86400;  // 24 hours

    /** @var array<string, array{capture_task:string, proof_command:string}> */
    private const KNOWN_STREAMS = [
        'queue_health' => [
            'capture_task' => 'atlas:task:queue-health-snapshot',
            'proof_command' => '/opt/homebrew/bin/php artisan atlas:task:queue-health-snapshot --dry-run',
        ],
        'muscle_outcomes' => [
            'capture_task' => 'atlas:task:report --outcome=probe',
            'proof_command' => '/opt/homebrew/bin/php artisan atlas:task:report --client=brain-audit --dry-run',
        ],
        'runtime_receipts' => [
            'capture_task' => 'atlas:self-construction:smoke',
            'proof_command' => '/opt/homebrew/bin/php artisan atlas:self-construction:smoke --dry-run',
        ],
        'code_facts' => [
            'capture_task' => 'atlas:engineering:knowledge:index-code',
            'proof_command' => '/opt/homebrew/bin/php artisan atlas:engineering:knowledge:index-code --dry-run',
        ],
    ];

    /**
     * @param  array<string,mixed>  $audit
     * @return array<string,mixed>
     */
    public function plan(array $audit): array
    {
        $streams    = (array) ($audit['evidence_streams'] ?? []);
        $nowUnix    = max(0, (int) ($audit['now_unix'] ?? 0));

        $backfillTasks = [];

        foreach ($streams as $stream) {
            if (! is_array($stream) || ! isset($stream['stream_id'])) {
                continue;
            }

            $streamId   = (string) $stream['stream_id'];
            $hasEvidence = (bool) ($stream['has_evidence'] ?? false);
            $lastAt      = max(0, (int) ($stream['last_captured_at_unix'] ?? 0));
            $threshold   = max(1, (int) ($stream['freshness_threshold_seconds'] ?? self::DEFAULT_FRESHNESS_THRESHOLD));

            $reason = null;

            if (! $hasEvidence) {
                $reason = 'missing';
            } elseif ($nowUnix > 0 && $lastAt > 0 && ($nowUnix - $lastAt) > $threshold) {
                $reason = 'stale';
            } elseif ($nowUnix > 0 && $lastAt === 0) {
                // never captured but has_evidence claimed — treat as stale
                $reason = 'stale';
            }

            if ($reason === null) {
                continue;
            }

            [$captureTask, $proofCommand] = $this->catalogueLookup($streamId);

            $backfillTasks[] = [
                'stream_id'                  => $streamId,
                'capture_task'               => $captureTask,
                'freshness_threshold_seconds' => $threshold,
                'proof_command'              => $proofCommand,
                'reason'                     => $reason,
            ];
        }

        $isNeeded        = $backfillTasks !== [];
        $nextProofCommand = $isNeeded ? $backfillTasks[0]['proof_command'] : 'none';

        return [
            'schema'              => self::SCHEMA,
            'backfill_tasks'      => $backfillTasks,
            'is_backfill_needed'  => $isNeeded,
            'next_proof_command'  => $nextProofCommand,
        ];
    }

    /** @return array{string, string}  [capture_task, proof_command] */
    private function catalogueLookup(string $streamId): array
    {
        if (isset(self::KNOWN_STREAMS[$streamId])) {
            $entry = self::KNOWN_STREAMS[$streamId];

            return [$entry['capture_task'], $entry['proof_command']];
        }

        return [
            'capture:'.$streamId,
            'atlas:brain:seed --stream='.$streamId,
        ];
    }
}
