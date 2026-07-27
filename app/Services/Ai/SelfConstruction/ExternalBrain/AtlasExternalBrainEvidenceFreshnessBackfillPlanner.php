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

    /**
     * Every entry here named an artisan command that does not exist, so the whole
     * catalogue pointed at nothing: atlas:task:queue-health-snapshot,
     * atlas:task:report and atlas:self-construction:smoke were never registered,
     * and index-code / sync are ACTIONS of atlas:engineering:knowledge, not
     * segments of a command name. Checked against Artisan::all().
     *
     * The proof commands also carried --dry-run, which only atlas:engineering:
     * knowledge accepts; the others take --json for the same read-only intent.
     *
     * @var array<string, array{capture_task:string, proof_command:string}>
     */
    private const KNOWN_STREAMS = [
        'queue_health' => [
            'capture_task' => 'atlas:task:health',
            'proof_command' => '/opt/homebrew/bin/php artisan atlas:task:health --json',
        ],
        'muscle_outcomes' => [
            'capture_task' => 'atlas:task report',
            'proof_command' => '/opt/homebrew/bin/php artisan atlas:task report --json',
        ],
        'runtime_receipts' => [
            'capture_task' => 'atlas:self-construction:final-smoke',
            'proof_command' => '/opt/homebrew/bin/php artisan atlas:self-construction:final-smoke --json',
        ],
        'code_facts' => [
            'capture_task' => 'atlas:engineering:knowledge index-code',
            'proof_command' => '/opt/homebrew/bin/php artisan atlas:engineering:knowledge index-code --dry-run',
        ],
    ];

    /** Lower rank = higher priority. Missing outranks stale; contradictory/never_captured are unsafe. */
    private const REASON_RANK = [
        'missing' => 0,
        'contradictory' => 1,
        'never_captured' => 1,
        'stale' => 2,
    ];

    /**
     * @param  array<string,mixed>  $audit
     * @return array<string,mixed>
     */
    public function plan(array $audit): array
    {
        $streams = (array) ($audit['evidence_streams'] ?? []);
        $nowUnix = max(0, (int) ($audit['now_unix'] ?? 0));

        $backfillTasks = [];

        foreach ($streams as $stream) {
            if (! is_array($stream) || ! isset($stream['stream_id'])) {
                continue;
            }

            $streamId = (string) $stream['stream_id'];
            $hasEvidence = (bool) ($stream['has_evidence'] ?? false);
            $lastAt = max(0, (int) ($stream['last_captured_at_unix'] ?? 0));
            $threshold = max(1, (int) ($stream['freshness_threshold_seconds'] ?? self::DEFAULT_FRESHNESS_THRESHOLD));
            $contradictory = (bool) ($stream['contradictory'] ?? false);

            $reason = null;

            if (! $hasEvidence) {
                $reason = 'missing';
            } elseif ($contradictory) {
                $reason = 'contradictory';
            } elseif ($lastAt === 0) {
                // claimed evidence but never actually captured — unsafe regardless of staleness window.
                $reason = 'never_captured';
            } elseif ($nowUnix > 0 && ($nowUnix - $lastAt) > $threshold) {
                $reason = 'stale';
            }

            if ($reason === null) {
                continue;
            }

            [$captureTask, $proofCommand] = $this->catalogueLookup($streamId);

            // Leverage impact: how much brain autonomy depends on this stream.
            // Known streams have higher leverage than unknown ones.
            $leverageImpact = isset(self::KNOWN_STREAMS[$streamId]) ? 0.8 : 0.3;
            $customLeverage = (float) ($stream['leverage_impact'] ?? 0.0);
            if ($customLeverage > 0.0) {
                $leverageImpact = $customLeverage;
            }

            // Freshness risk: how stale the evidence is (0=fresh, 1=very stale/missing).
            $freshnessRisk = match ($reason) {
                'missing' => 1.0,
                'contradictory' => 0.9,
                'never_captured' => 0.9,
                'stale' => 0.5,
                default => 0.0,
            };

            // Capture cost: how expensive is the capture (0=cheap, 1=expensive).
            $captureCost = (float) ($stream['capture_cost'] ?? 0.5);

            $backfillTasks[] = [
                'stream_id' => $streamId,
                'capture_task' => $captureTask,
                'freshness_threshold_seconds' => $threshold,
                'proof_command' => $proofCommand,
                'reason' => $reason,
                'priority' => $reason === 'missing' ? 'high' : (in_array($reason, ['contradictory', 'never_captured'], true) ? 'high' : 'medium'),
                'leverage_impact' => round($leverageImpact, 4),
                'freshness_risk' => round($freshnessRisk, 4),
                'capture_cost' => round($captureCost, 4),
            ];
        }

        // Sort priority: reason rank first, then leverage impact (desc),
        // then freshness risk (desc), then capture cost (asc).
        usort($backfillTasks, static function (array $a, array $b): int {
            $reasonCmp = self::REASON_RANK[$a['reason']] <=> self::REASON_RANK[$b['reason']];
            if ($reasonCmp !== 0) {
                return $reasonCmp;
            }
            // Higher leverage impact first.
            $levCmp = ($b['leverage_impact'] ?? 0.0) <=> ($a['leverage_impact'] ?? 0.0);
            if ($levCmp !== 0) {
                return $levCmp;
            }
            // Higher freshness risk first.
            $riskCmp = ($b['freshness_risk'] ?? 0.0) <=> ($a['freshness_risk'] ?? 0.0);
            if ($riskCmp !== 0) {
                return $riskCmp;
            }

            // Lower capture cost first.
            return ($a['capture_cost'] ?? 0.0) <=> ($b['capture_cost'] ?? 0.0);
        });

        $groupedByReason = [];
        foreach ($backfillTasks as $task) {
            $groupedByReason[$task['reason']][] = $task['stream_id'];
        }

        $isNeeded = $backfillTasks !== [];
        $nextProofCommand = $isNeeded ? $backfillTasks[0]['proof_command'] : 'none';
        $priorityOrder = array_column($backfillTasks, 'stream_id');

        $freshnessSummary = [
            'total_streams' => count($streams),
            'needs_backfill_count' => count($backfillTasks),
            'missing_count' => count($groupedByReason['missing'] ?? []),
            'stale_count' => count($groupedByReason['stale'] ?? []),
            'contradictory_count' => count($groupedByReason['contradictory'] ?? []),
            'never_captured_count' => count($groupedByReason['never_captured'] ?? []),
            'is_backfill_needed' => $isNeeded,
        ];

        return [
            'schema' => self::SCHEMA,
            'backfill_tasks' => $backfillTasks,
            'grouped_by_reason' => $groupedByReason,
            'priority_order' => $priorityOrder,
            'is_backfill_needed' => $isNeeded,
            'next_proof_command' => $nextProofCommand,
            'freshness_summary' => $freshnessSummary,
        ];
    }

    /** @return array{string, string}  [capture_task, proof_command] */
    private function catalogueLookup(string $streamId): array
    {
        if (isset(self::KNOWN_STREAMS[$streamId])) {
            $entry = self::KNOWN_STREAMS[$streamId];

            return [$entry['capture_task'], $entry['proof_command']];
        }

        $safe = (string) preg_replace('/[^A-Za-z0-9_.:-]/', '', $streamId);
        $safe = $safe !== '' ? $safe : 'unknown';

        return [
            'capture:'.$safe,
            '/opt/homebrew/bin/php artisan atlas:brain:seed --stream='.$safe.' --dry-run',
        ];
    }
}
