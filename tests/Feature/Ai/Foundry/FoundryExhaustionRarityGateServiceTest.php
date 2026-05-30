<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Foundry;

use App\Services\Ai\Foundry\FoundryExhaustionRarityGateService;
use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopResourceGovernorService;
use Tests\TestCase;

final class FoundryExhaustionRarityGateServiceTest extends TestCase
{
    private function service(): FoundryExhaustionRarityGateService
    {
        return app(FoundryExhaustionRarityGateService::class);
    }

    /** Budget leg report: under cap on both metrics, status=ok. */
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

    /** Backlog leg: scarce (below_floor). */
    private function backlogBelowFloor(int $packets = 1): array
    {
        return ['status' => BacklogDepthGovernorService::STATUS_BELOW_FLOOR, 'packets_count' => $packets];
    }

    /** Backlog leg: backlog exists (ok). */
    private function backlogOk(int $packets = 12): array
    {
        return ['status' => BacklogDepthGovernorService::STATUS_OK, 'packets_count' => $packets];
    }

    /** N positively-measured zero-admissible non-transient cycles. */
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

    public function test_flag_off_returns_skipped_with_deterministic_hash_and_no_reads(): void
    {
        $svc = $this->service();
        // If a read were acted upon, this poison override would change the result.
        $svc->setBudgetReportForTesting(['status' => LoopResourceGovernorService::STATUS_STOP, 'resource_summary' => []]);
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $out = $svc->decide([]); // flag off (config default false, no override)

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_SKIPPED, $out['status']);
        $this->assertTrue($out['fallback_is_honest_stop']);
        $this->assertSame('frontier_flag_off', $out['drop_reason']);
        $this->assertSame('not_evaluated', $out['budget_leg']);
        $this->assertSame('not_evaluated', $out['rarity_leg']);

        $again = $this->service()->decide([]);
        $this->assertSame($out['gate_hash'], $again['gate_hash']);
    }

    public function test_backlog_available_via_governor_ok_returns_not_eligible(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogOk());

        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_NOT_ELIGIBLE, $out['status']);
        $this->assertSame('backlog_available', $out['drop_reason']);
        $this->assertSame('available', $out['rarity_leg']);
    }

    public function test_rarity_polarity_below_floor_eligible_ok_not_eligible(): void
    {
        // below_floor + all legs => eligible
        $a = $this->service();
        $a->setBudgetReportForTesting($this->budgetOk());
        $a->setBacklogReportForTesting($this->backlogBelowFloor());
        $eligible = $a->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));
        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_ELIGIBLE, $eligible['status']);

        // ok (all else equal) => not_eligible
        $b = $this->service();
        $b->setBudgetReportForTesting($this->budgetOk());
        $b->setBacklogReportForTesting($this->backlogOk());
        $notEligible = $b->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));
        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_NOT_ELIGIBLE, $notEligible['status']);
    }

    public function test_measured_exhaustion_returns_eligible(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor(1));

        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_ELIGIBLE, $out['status']);
        $this->assertTrue($out['stable_metrics']);
        $this->assertSame('ok', $out['budget_leg']);
        $this->assertSame('below_floor', $out['rarity_leg']);
        $this->assertSame(3, $out['consecutive_admissible_zero_cycles']);
        $this->assertNull($out['drop_reason']);
        $this->assertTrue($out['fallback_is_honest_stop']);
    }

    public function test_premium_over_ceiling_blocks_even_when_exhausted_token_only(): void
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
        $this->assertSame('over_cap', $out['budget_leg']);
    }

    public function test_premium_over_ceiling_provider_calls_only_blocks(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting([
            'status' => LoopResourceGovernorService::STATUS_OK,
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

    public function test_fewer_than_window_n_records_returns_evidence_insufficient(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(2)]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_NOT_ELIGIBLE, $out['status']);
        $this->assertSame('evidence_insufficient', $out['drop_reason']);
    }

    public function test_only_last_cycle_backlog_exhausted_with_prior_inconclusive_is_not_eligible(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        // Most-recent-last ordering: the last cycle is the only positive measurement;
        // prior cycles are inconclusive (blocked by a non-backlog reason, no count).
        $records = [
            ['outcome' => 'blocked', 'blockers' => ['validation_failed']],
            ['outcome' => 'blocked', 'blockers' => ['validation_failed']],
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted']],
        ];

        $out = $svc->decide($this->enabled(['ledger_records' => $records]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_NOT_ELIGIBLE, $out['status']);
        $this->assertSame('evidence_insufficient', $out['drop_reason']);
        $this->assertSame(1, $out['consecutive_admissible_zero_cycles']);
    }

    public function test_window_with_admissible_work_is_not_eligible(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $records = [
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0],
            ['outcome' => 'blocked', 'blockers' => [], 'admissible_packet_count' => 3],
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0],
        ];

        $out = $svc->decide($this->enabled(['ledger_records' => $records]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_NOT_ELIGIBLE, $out['status']);
        $this->assertSame('backlog_available', $out['drop_reason']);
    }

    public function test_merge_or_progress_in_window_is_not_eligible(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $records = $this->zeroAdmissibleRecords(2);
        $records[] = ['outcome' => 'merged', 'blockers' => []];

        $out = $svc->decide($this->enabled(['ledger_records' => $records]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_NOT_ELIGIBLE, $out['status']);
        $this->assertSame('backlog_available', $out['drop_reason']);
    }

    public function test_transient_blocked_cycle_does_not_break_or_count_streak(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        // A transient-infra blocked cycle interleaved: must be skipped (neither
        // counted nor breaking), so 3 real measured cycles still yield eligible.
        $records = [
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0],
            ['outcome' => 'blocked', 'blockers' => ['owner_runtime_provider_timeout']],
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0],
            ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0],
        ];

        $out = $svc->decide($this->enabled(['ledger_records' => $records]));

        $this->assertSame(FoundryExhaustionRarityGateService::STATUS_ELIGIBLE, $out['status']);
    }

    public function test_gate_emits_zero_generation_and_read_only_policy(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());

        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));

        $this->assertTrue($out['claim_policy']['read_only']);
        $this->assertFalse($out['claim_policy']['writes_state']);
        $this->assertFalse($out['claim_policy']['generates_code']);
        $this->assertFalse($out['claim_policy']['provider_invoked']);
        $this->assertFalse($out['claim_policy']['canonical_doc_write_allowed']);
        $this->assertFalse($out['claim_policy']['ledger_record_invoked']);
        // Decide-only: no proposal / no generation trigger key anywhere in the shape.
        $this->assertArrayNotHasKey('proposal', $out);
        $this->assertArrayNotHasKey('generation_trigger', $out);
        $this->assertContains($out['status'], [
            FoundryExhaustionRarityGateService::STATUS_NOT_ELIGIBLE,
            FoundryExhaustionRarityGateService::STATUS_ELIGIBLE,
            FoundryExhaustionRarityGateService::STATUS_BLOCKED,
            FoundryExhaustionRarityGateService::STATUS_SKIPPED,
        ]);
    }

    public function test_identical_input_identical_hash(): void
    {
        $input = $this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]);

        $a = $this->service();
        $a->setBudgetReportForTesting($this->budgetOk());
        $a->setBacklogReportForTesting($this->backlogBelowFloor());

        $b = $this->service();
        $b->setBudgetReportForTesting($this->budgetOk());
        $b->setBacklogReportForTesting($this->backlogBelowFloor());

        $this->assertSame($a->decide($input)['gate_hash'], $b->decide($input)['gate_hash']);
    }

    public function test_validate_shape_valid_with_no_unexpected_keys(): void
    {
        $svc = $this->service();
        $svc->setBudgetReportForTesting($this->budgetOk());
        $svc->setBacklogReportForTesting($this->backlogBelowFloor());
        $out = $svc->decide($this->enabled(['ledger_records' => $this->zeroAdmissibleRecords(3)]));

        $this->assertCount(16, $out);
        $validation = FoundrySchemas::validateShape(FoundrySchemas::GATE_VERDICT, $out);
        $this->assertTrue($validation['valid']);
        $this->assertSame([], $validation['unexpected_keys']);

        // Skipped shape also satisfies the schema.
        $skipped = $this->service()->decide([]);
        $skippedValidation = FoundrySchemas::validateShape(FoundrySchemas::GATE_VERDICT, $skipped);
        $this->assertTrue($skippedValidation['valid']);
        $this->assertSame([], $skippedValidation['unexpected_keys']);
    }
}
