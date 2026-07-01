<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

/**
 * FACT-only miner over {@see AtlasMaestroGiveBackRetryReceiptLedger} rows + downstream success
 * signals. Outputs raw {@see ReshapePatternFact} tuples — NEVER a score, rank, or quality number.
 *
 * The pattern fingerprint is sha256 over the STRUCTURAL delta:
 *   { forbidden_removed: list<string>, anchors_added: list<string>, scope_widened_bool: bool }
 *
 * Two ledgers that differ only in filenames but share the same structural delta produce the SAME
 * fingerprint, so they aggregate into ONE row.
 *
 * mineFailurePatterns() is a separate, independent grouping over generic retry rows (task_family,
 * worker_class, failure_reason, eventual_outcome) — it does not touch mine()'s structural-delta
 * pathway or output shape. It classifies each (task_family, worker_class, failure_reason) bucket as:
 *   poison_signature    — quarantine_rate >= POISON_QUARANTINE_RATE_FLOOR AND zero successes on a
 *                          sufficient sample: never worth retrying
 *   repairable_failure   — at least one observed success: worth retrying (with reshape)
 *   insufficient_sample  — below MIN_SAMPLE_FOR_FAILURE_PATTERN_CLASSIFICATION: no classification yet
 *   unclassified_failure — sufficient sample, zero successes, but quarantine rate below the poison
 *                          floor (mixed non-quarantine failures)
 * routing_action names the fact usable directly by a repeated-failure router or the give_back
 * reshape strategy: quarantine_do_not_retry / retry_with_reshape / hold_insufficient_sample / no_action.
 */
final class AtlasMaestroRetryEvidenceMiner
{
    public const FORBIDDEN_FIELDS = ['score', 'rank', 'rating', 'quality'];

    /** Minimum attempts needed before a pattern becomes actionable policy. */
    public const MIN_SAMPLE_FOR_POLICY = 5;

    /** Minimum attempts needed before a failure-family pattern gets a poison/repairable classification. */
    public const MIN_SAMPLE_FOR_FAILURE_PATTERN_CLASSIFICATION = 5;

    /** Quarantine rate at/above which a zero-success bucket is treated as a poison signature. */
    public const POISON_QUARANTINE_RATE_FLOOR = 0.8;

    /**
     * @param  list<array<string,mixed>>  $ledgerRows  raw rows from AtlasMaestroGiveBackRetryReceiptLedger::forTask()
     * @param  array<string,bool>  $successByTaskPacketId  truth signal: did the next attempt land green?
     * @return list<array<string,mixed>>
     */
    public function mine(array $ledgerRows, array $successByTaskPacketId): array
    {
        $buckets = [];
        foreach ($ledgerRows as $row) {
            $delta = $this->structuralDelta(
                array_values(array_map('strval', (array) ($row['original_allowed_files'] ?? []))),
                array_values(array_map('strval', (array) ($row['reshaped_allowed_files'] ?? []))),
                (array) ($row['structural_metadata'] ?? []),
            );
            $fingerprint = $this->fingerprint($delta);
            $buckets[$fingerprint] ??= [
                'delta' => $delta,
                'attempts' => 0,
                'successes' => 0,
                'failures' => 0,
                'last_seen_seq' => 0,
            ];
            $buckets[$fingerprint]['attempts']++;
            $taskPacketId = (string) ($row['task_packet_id'] ?? '');
            $success = (bool) ($successByTaskPacketId[$taskPacketId] ?? false);
            if ($success) {
                $buckets[$fingerprint]['successes']++;
            } else {
                $buckets[$fingerprint]['failures']++;
            }
            $seq = (int) ($row['seq'] ?? 0);
            if ($seq > $buckets[$fingerprint]['last_seen_seq']) {
                $buckets[$fingerprint]['last_seen_seq'] = $seq;
            }
        }

        $facts = [];
        foreach ($buckets as $fp => $bucket) {
            $insufficient = $bucket['attempts'] < self::MIN_SAMPLE_FOR_POLICY;
            $regression = ! $insufficient && $bucket['failures'] > $bucket['successes'];
            $rootCauseBucket = $this->rootCauseBucket($bucket['delta']);
            $outcome = $this->outcome($insufficient, $regression, $bucket['successes'], $bucket['failures']);
            $facts[] = [
                'reshape_pattern_fingerprint' => (string) $fp,
                'structural_delta' => $bucket['delta'],
                'root_cause_bucket' => $rootCauseBucket,
                'observed_attempts' => $bucket['attempts'],
                'observed_successes' => $bucket['successes'],
                'observed_failures' => $bucket['failures'],
                'last_seen_seq' => $bucket['last_seen_seq'],
                'regression_after_reshape' => $regression,
                'insufficient_sample' => $insufficient,
                'outcome' => $outcome,
                'policy_action' => $this->policyAction($outcome),
            ];
        }
        usort($facts, static fn (array $a, array $b): int => strcmp($a['reshape_pattern_fingerprint'], $b['reshape_pattern_fingerprint']));

        return $facts;
    }

    /**
     * @param  list<array<string,mixed>>  $rows  each: task_family, worker_class, failure_reason, eventual_outcome (success|quarantine|<other>)
     * @return list<array<string,mixed>>
     */
    public function mineFailurePatterns(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $taskFamily = (string) ($row['task_family'] ?? 'unknown');
            $workerClass = (string) ($row['worker_class'] ?? 'unknown');
            $failureReason = (string) ($row['failure_reason'] ?? 'unknown');
            $outcome = (string) ($row['eventual_outcome'] ?? 'failure');

            $key = implode('|', [$taskFamily, $workerClass, $failureReason]);
            $buckets[$key] ??= [
                'task_family' => $taskFamily,
                'worker_class' => $workerClass,
                'failure_reason' => $failureReason,
                'attempts' => 0,
                'successes' => 0,
                'quarantines' => 0,
                'other_failures' => 0,
            ];
            $buckets[$key]['attempts']++;
            match ($outcome) {
                'success' => $buckets[$key]['successes']++,
                'quarantine' => $buckets[$key]['quarantines']++,
                default => $buckets[$key]['other_failures']++,
            };
        }

        $facts = [];
        foreach ($buckets as $bucket) {
            $total = $bucket['attempts'];
            $insufficientSample = $total < self::MIN_SAMPLE_FOR_FAILURE_PATTERN_CLASSIFICATION;
            $quarantineRate = $total > 0 ? round($bucket['quarantines'] / $total, 4) : 0.0;
            $successRate = $total > 0 ? round($bucket['successes'] / $total, 4) : 0.0;

            $patternClass = match (true) {
                $insufficientSample => 'insufficient_sample',
                $bucket['successes'] > 0 => 'repairable_failure',
                $quarantineRate >= self::POISON_QUARANTINE_RATE_FLOOR => 'poison_signature',
                default => 'unclassified_failure',
            };

            $routingAction = match ($patternClass) {
                'poison_signature' => 'quarantine_do_not_retry',
                'repairable_failure' => 'retry_with_reshape',
                'insufficient_sample' => 'hold_insufficient_sample',
                default => 'no_action',
            };

            $facts[] = [
                'task_family' => $bucket['task_family'],
                'worker_class' => $bucket['worker_class'],
                'failure_reason' => $bucket['failure_reason'],
                'observed_attempts' => $total,
                'observed_successes' => $bucket['successes'],
                'observed_quarantines' => $bucket['quarantines'],
                'observed_other_failures' => $bucket['other_failures'],
                'success_rate' => $successRate,
                'quarantine_rate' => $quarantineRate,
                'insufficient_sample' => $insufficientSample,
                'pattern_class' => $patternClass,
                'routing_action' => $routingAction,
            ];
        }

        usort($facts, static fn (array $a, array $b): int => strcmp(
            $a['task_family'].'|'.$a['worker_class'].'|'.$a['failure_reason'],
            $b['task_family'].'|'.$b['worker_class'].'|'.$b['failure_reason'],
        ));

        return $facts;
    }

    /**
     * @param  list<string>  $original
     * @param  list<string>  $reshaped
     * @param  array<string,mixed>  $metadata
     * @return array{forbidden_removed:list<string>, anchors_added:list<string>, scope_widened_bool:bool}
     */
    private function structuralDelta(array $original, array $reshaped, array $metadata): array
    {
        // forbidden_removed and anchors_added are supplied verbatim by the caller (Maestro's
        // ReshapeProposal) and are CATEGORICAL — we strip filenames to a stable categorical
        // representation (sorted, deduplicated) to make the fingerprint filename-agnostic.
        $forbidden = array_values(array_unique(array_map('strval', (array) ($metadata['forbidden_removed'] ?? []))));
        $anchors = array_values(array_unique(array_map('strval', (array) ($metadata['anchors_added'] ?? []))));
        sort($forbidden, SORT_STRING);
        sort($anchors, SORT_STRING);

        return [
            'forbidden_removed' => $forbidden,
            'anchors_added' => $anchors,
            'scope_widened_bool' => count($reshaped) > count($original) || (bool) ($metadata['scope_widened_bool'] ?? false),
        ];
    }

    /**
     * Categorical root cause of the structural delta — deterministic, no scoring.
     *
     * @param  array<string,mixed>  $delta
     */
    private function rootCauseBucket(array $delta): string
    {
        if ((array) ($delta['forbidden_removed'] ?? []) !== []) {
            return 'forbidden_removed';
        }
        if ((bool) ($delta['scope_widened_bool'] ?? false)) {
            return 'scope_widened';
        }
        if ((array) ($delta['anchors_added'] ?? []) !== []) {
            return 'anchor_added';
        }

        return 'unclassified';
    }

    private function outcome(bool $insufficient, bool $regression, int $successes, int $failures): string
    {
        if ($insufficient) {
            return 'insufficient_sample';
        }
        if ($regression) {
            return 'regression';
        }
        if ($successes > $failures) {
            return 'repair_success';
        }

        return 'neutral';
    }

    /**
     * Recommended retry-policy action for this pattern fingerprint — stops infinite give_back
     * loops by telling the caller whether to keep retrying this exact structural reshape.
     */
    private function policyAction(string $outcome): string
    {
        return match ($outcome) {
            'regression' => 'block_reshape_pattern',
            'repair_success' => 'allow_reshape_pattern',
            'insufficient_sample' => 'no_action_insufficient_sample',
            default => 'no_action_neutral',
        };
    }

    /**
     * @param  array<string,mixed>  $delta
     */
    private function fingerprint(array $delta): string
    {
        ksort($delta);

        return hash('sha256', (string) json_encode($delta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
