<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

/**
 * MULTK-08 — Decision Receipt failure-pattern miner (report-only, [MEDIDOR]).
 *
 * Reads Decision Receipts v2 (MAXK-04) joined with real outcomes (OUTC-01) by
 * `decision_id` and groups them by a coarse-scope key {task_category × basis
 * × provider}. Publishes {pattern, failure_rate, n} for every group whose
 * denominator clears the pinned floor AND whose failure_rate exceeds the
 * pinned ceiling.
 *
 * Frontier plan §2407-2411 pétreos:
 *   - v1 receipts without basis are EXCLUDED — the miner waits for MAXK-04-
 *     shaped envelopes (basis-carrying); this refuses to guess the basis
 *     from adjacent fields (§2409 anti-mining-v1).
 *   - Floor `n_min` and ceiling `failure_rate_max` are PINNED BEFORE data
 *     is seen (ELEV-03 freeze on `atlas.decide.receipt_failure_patterns.v1`);
 *     patterns with `n < n_min` are ABSENT from the report (not tail-of-queue;
 *     honest silence anti-Goodhart).
 *   - Report-only: `promotes_selection=false`, `blocker=false`,
 *     `emits_bias_carimbado=true` — the miner NEVER changes routing;
 *     selection consumption requires a separate ELEV-26 promotion after 2
 *     windows (ELEV-31 alternatives-compared applies to that flip).
 *   - Coarse scope: task_category is the join key, NOT the free-text
 *     `flow_id` (§2408 lesson from MAXK-01 — grouping by fine framework
 *     inflates the multiple-comparisons space).
 *
 * FULLY PURE. Zero I/O. Provider-safe (takes already-provider-safe receipt
 * arrays from callers). Never writes to any ledger — the caller layer is
 * responsible for {freeze, series persistence, digest injection}.
 */
final class DecisionReceiptFailurePatternMiner
{
    public const SCHEMA_VERSION = 'atlas.decide.receipt_failure_patterns.v1';

    /**
     * §2410 acceptance: floor is pinned in this artefact (freeze on-write is
     * the caller's job — this class refuses to accept caller-provided floors
     * below the pin, so an honest report cannot be softened by argument).
     */
    public const N_MIN_PINNED = 10;

    public const FAILURE_RATE_CEILING_PINNED = 0.25;

    /**
     * @param  list<array<string,mixed>>  $receipts  Decision Receipt v2 rows,
     *   each expected to carry `decision_id`, `task_category`, `basis` (any
     *   non-empty string), `provider`. Rows without `basis` are dropped as
     *   `dropped_v1_no_basis`.
     * @param  list<array<string,mixed>>  $outcomes  outcome rows, each with
     *   `decision_id` and either `verified_basis` in {server_verified,
     *   gates_passed, absent, claimed} + `proven_real` (bool). Rows whose
     *   verified_basis is `absent`/`claimed` are counted as zero-weight
     *   witnesses (§ESP-05); they DO count into the denominator only when
     *   `count_zero_weight_in_denominator=true`, but they NEVER contribute a
     *   failure. Default behaviour (mirror of ESP-05 wiring): only verified
     *   witnesses count.
     * @return array<string,mixed>
     */
    public function mine(
        array $receipts,
        array $outcomes,
        ?int $nMin = null,
        ?float $failureRateCeiling = null,
    ): array {
        $nMin = max(self::N_MIN_PINNED, $nMin ?? self::N_MIN_PINNED);
        $ceiling = $failureRateCeiling ?? self::FAILURE_RATE_CEILING_PINNED;
        if ($ceiling < self::FAILURE_RATE_CEILING_PINNED) {
            $ceiling = self::FAILURE_RATE_CEILING_PINNED;
        }

        $droppedV1 = 0;
        $indexedReceipts = [];
        foreach ($receipts as $receipt) {
            $basis = isset($receipt['basis']) ? trim((string) $receipt['basis']) : '';
            if ($basis === '') {
                $droppedV1++;

                continue;
            }
            $decisionId = isset($receipt['decision_id']) ? (string) $receipt['decision_id'] : '';
            if ($decisionId === '') {
                continue;
            }
            $indexedReceipts[$decisionId] = [
                'task_category' => (string) ($receipt['task_category'] ?? 'unknown'),
                'basis' => $basis,
                'provider' => (string) ($receipt['provider'] ?? 'unknown'),
            ];
        }

        $verifiedOutcomes = 0;
        $unverifiedOutcomes = 0;
        $groups = [];
        foreach ($outcomes as $outcome) {
            $decisionId = isset($outcome['decision_id']) ? (string) $outcome['decision_id'] : '';
            if ($decisionId === '' || ! isset($indexedReceipts[$decisionId])) {
                continue;
            }
            $verifiedBasis = strtolower((string) ($outcome['verified_basis'] ?? 'absent'));
            $isVerifiedWitness = in_array($verifiedBasis, ['server_verified', 'gates_passed'], true);
            if (! $isVerifiedWitness) {
                $unverifiedOutcomes++;

                continue;
            }
            $verifiedOutcomes++;
            $receipt = $indexedReceipts[$decisionId];
            $groupKey = $this->groupKey($receipt);
            $groups[$groupKey] = $groups[$groupKey] ?? [
                'task_category' => $receipt['task_category'],
                'basis' => $receipt['basis'],
                'provider' => $receipt['provider'],
                'n' => 0,
                'failures' => 0,
            ];
            $groups[$groupKey]['n']++;
            if (($outcome['proven_real'] ?? false) === false) {
                $groups[$groupKey]['failures']++;
            }
        }

        $patterns = [];
        $suppressedByFloor = 0;
        $suppressedByCeiling = 0;
        foreach ($groups as $group) {
            $n = $group['n'];
            if ($n < $nMin) {
                $suppressedByFloor++;

                continue;
            }
            $rate = $n === 0 ? 0.0 : $group['failures'] / $n;
            if ($rate <= $ceiling) {
                $suppressedByCeiling++;

                continue;
            }
            $patterns[] = [
                'task_category' => $group['task_category'],
                'basis' => $group['basis'],
                'provider' => $group['provider'],
                'n' => $n,
                'failures' => $group['failures'],
                'failure_rate' => round($rate, 4),
                'exceeds_ceiling_by' => round($rate - $ceiling, 4),
            ];
        }

        usort($patterns, static function (array $a, array $b): int {
            $cmp = $b['failure_rate'] <=> $a['failure_rate'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['n'] <=> $a['n'];
        });

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $patterns === [] ? 'insufficient_signal' : 'ok',
            'thresholds' => [
                'n_min' => $nMin,
                'failure_rate_ceiling' => $ceiling,
                'n_min_pinned' => self::N_MIN_PINNED,
                'failure_rate_ceiling_pinned' => self::FAILURE_RATE_CEILING_PINNED,
            ],
            'source' => [
                'read_only' => true,
                'promotes_selection' => false,
                'blocker' => false,
                'emits_bias_carimbado' => true,
                'group_key' => 'task_category × basis × provider',
                'excludes_v1_no_basis' => true,
                'verified_witnesses_only' => true,
            ],
            'denominators' => [
                'receipts_indexed' => count($indexedReceipts),
                'dropped_v1_no_basis' => $droppedV1,
                'verified_outcomes' => $verifiedOutcomes,
                'unverified_outcomes' => $unverifiedOutcomes,
                'groups_total' => count($groups),
                'suppressed_by_floor' => $suppressedByFloor,
                'suppressed_by_ceiling' => $suppressedByCeiling,
            ],
            'patterns' => $patterns,
        ];
    }

    /**
     * @param  array{task_category:string,basis:string,provider:string}  $receipt
     */
    private function groupKey(array $receipt): string
    {
        return $receipt['task_category'].'||'.$receipt['basis'].'||'.$receipt['provider'];
    }
}
