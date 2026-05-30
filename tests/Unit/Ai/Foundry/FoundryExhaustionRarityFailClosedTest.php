<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry;

use App\Services\Ai\Foundry\FoundryExhaustionRarityGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopResourceGovernorService;
use Tests\TestCase;

/**
 * P2-EXHAUSTION-FAILCLOSED · Findings 19 & 20.
 *
 * (19) An under-determined budget summary (no usable headroom on either metric,
 *      status not explicit-go) must fail closed (blocked + budget_signal_unavailable),
 *      never be read as under-cap. Existing over-cap detections still win.
 * (20) consecutiveMeasuredZeroAdmissible must BOUND transient skips: positive-zero
 *      cycles separated by more than max_transient_skips transient cycles no longer
 *      stitch into a "consecutive" streak => not_eligible (evidence_insufficient).
 *
 * Both findings make `eligible` strictly HARDER to reach; nothing relaxed.
 */
final class FoundryExhaustionRarityFailClosedTest extends TestCase
{
    private function service(): FoundryExhaustionRarityGateService
    {
        return app(FoundryExhaustionRarityGateService::class);
    }

    private function budgetOk(int $ceiling = 6000000): array
    {
        return [
            'status' => LoopResourceGovernorService::STATUS_OK,
            'resource_summary' => [
                'provider_calls' => 10,
                'token_estimate' => 1000,
                'headroom' => [
                    'provider_calls' => ['value' => 10, 'hard_ceiling' => $ceiling, 'remaining_to_hard' => $ceiling - 10],
                    'token_estimate' => ['value' => 1000, 'hard_ceiling' => $ceiling, 'remaining_to_hard' => $ceiling - 1000],
                ],
            ],
        ];
    }

    private function backlogBelowFloor(int $packets = 1): array
    {
        return ['status' => BacklogDepthGovernorService::STATUS_BELOW_FLOOR, 'packets_count' => $packets];
    }

    private function zeroAdmissibleRecords(int $n): array
    {
        $records = [];
        for ($i = 0; $i < $n; $i++) {
            $records[] = ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0];
        }

        return $records;
    }

    private function enabled(array $extra = []): array
    {
        return array_merge(['exhaustion_rarity_gate_enabled' => true, 'window_n' => 3], $extra);
    }

    // ---------------------------------------------------------------- Finding 19

    /** No usable headroom for either metric + status not go => fail-closed blocked. */
    public function test_under_determined_budget_signal_fails_closed_to_blocked(): void
    {
        $svc = $this->service();
        // status is NOT ok and headroom is absent/garbled => unreadable.
        $svc->setBudgetReportForTesting([
            'status' => 'unknown',
            'resource_summary' => ['provider_calls' => 0, 'token_estimate' => 0, 'headroom' => []],
        ]);
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_BLOCKED, $out['status']);
        $this->assertSame('budget_signal_unavailable', $out['drop_reason']);
        $this->assertSame('over_cap', $out['budget_leg']);
    }

    /** A partial-but-usable headroom on ONE metric + status ok is still readable. */
    public function test_one_usable_metric_under_cap_is_not_fail_closed(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting([
            'status' => LoopResourceGovernorService::STATUS_OK,
            'resource_summary' => [
                'provider_calls' => 5,
                'token_estimate' => 10,
                'headroom' => [
                    // provider_calls usable (value+hard); token_estimate absent.
                    'provider_calls' => ['value' => 5, 'hard_ceiling' => 100, 'remaining_to_hard' => 95],
                ],
            ],
        ]);
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_ELIGIBLE, $out['status']);
        $this->assertSame('ok', $out['budget_leg']);
    }

    /** Existing STATUS_STOP over-cap detection still wins over the fail-closed branch. */
    public function test_explicit_stop_still_wins_with_premium_spend_over_ceiling(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting([
            'status' => LoopResourceGovernorService::STATUS_STOP,
            'resource_summary' => [
                'provider_calls' => 5,
                'token_estimate' => 9999,
                'headroom' => [
                    'provider_calls' => ['value' => 5, 'hard_ceiling' => 100, 'remaining_to_hard' => 95],
                    'token_estimate' => ['value' => 9999, 'hard_ceiling' => 100, 'remaining_to_hard' => -9899],
                ],
            ],
        ]);
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_BLOCKED, $out['status']);
        $this->assertSame('premium_spend_over_ceiling', $out['drop_reason']);
    }

    /** Existing negative-headroom (value>hard) over-cap detection still wins. */
    public function test_negative_headroom_still_wins_over_fail_closed(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting([
            'status' => 'unknown', // also under-determined-looking, but headroom is negative.
            'resource_summary' => [
                'provider_calls' => 500,
                'token_estimate' => 10,
                'headroom' => [
                    'provider_calls' => ['value' => 500, 'hard_ceiling' => 100, 'remaining_to_hard' => -400],
                    'token_estimate' => ['value' => 10, 'hard_ceiling' => 100, 'remaining_to_hard' => 90],
                ],
            ],
        ]);
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_BLOCKED, $out['status']);
        $this->assertSame('premium_spend_over_ceiling', $out['drop_reason']);
    }

    // ---------------------------------------------------------------- Finding 20

    /** Measured-zero cycles separated by more transient cycles than allowed => not_eligible. */
    public function test_transient_skips_over_bound_yields_not_eligible(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        // window_n=2, max_transient_skips=1. Two measured-zero cycles separated by
        // 2 transient cycles => over bound; cannot be called a consecutive streak.
        $records = [
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0],
            ['outcome' => 'blocked', 'blockers' => ['owner_runtime_provider_timeout']],
            ['outcome' => 'blocked', 'blockers' => ['owner_runtime_provider_timeout']],
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0],
        ];

        $out = $svc->decide($this->enabled([
            'window_n' => 2,
            'max_transient_skips' => 1,
            'ledger_records' => $records,
        ]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_NOT_ELIGIBLE, $out['status']);
        $this->assertSame('evidence_insufficient', $out['drop_reason']);
    }

    /** Within the bound, transient skips still don't break a genuine streak. */
    public function test_transient_skips_within_bound_still_reaches_eligible(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        // window_n=2, max_transient_skips=1: exactly one transient skip tolerated.
        $records = [
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0],
            ['outcome' => 'blocked', 'blockers' => ['owner_runtime_provider_timeout']],
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0],
        ];

        $out = $svc->decide($this->enabled([
            'window_n' => 2,
            'max_transient_skips' => 1,
            'ledger_records' => $records,
        ]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_ELIGIBLE, $out['status']);
    }

    /** A genuinely consecutive measured-zero window still reaches eligible (no regression). */
    public function test_genuinely_consecutive_window_still_eligible(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_ELIGIBLE, $out['status']);
        $this->assertSame(3, $out['consecutive_admissible_zero_cycles']);
        $this->assertNull($out['drop_reason']);
    }

    // ---------------------------------------------------------------- invariant guards

    /** The gate is no weaker: shape unchanged (16 keys), determinism preserved. */
    public function test_shape_and_determinism_unchanged(): void
    {
        $input = $this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]);

        $a = $this->service();
        $a->setBudgetReportForTesting($this->budgetOk());
        $a->setBacklogReportForTesting($this->backlogBelowFloor());

        $b = $this->service();
        $b->setBudgetReportForTesting($this->budgetOk());
        $b->setBacklogReportForTesting($this->backlogBelowFloor());

        $outA = $a->decide($input);
        $outB = $b->decide($input);

        $this->assertCount(16, $outA);
        $this->assertSame($outA['gate_hash'], $outB['gate_hash']);
        // Read-only / zero-generation invariant intact.
        $this->assertTrue($outA['claim_policy']['read_only']);
        $this->assertFalse($outA['claim_policy']['generates_code']);
        $this->assertTrue($outA['fallback_is_honest_stop']);
    }
}
