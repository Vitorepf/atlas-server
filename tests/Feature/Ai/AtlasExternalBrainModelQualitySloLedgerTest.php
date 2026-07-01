<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelQualitySloLedger;
use Tests\TestCase;

final class AtlasExternalBrainModelQualitySloLedgerTest extends TestCase
{
    private function ledger(): AtlasExternalBrainModelQualitySloLedger
    {
        return new AtlasExternalBrainModelQualitySloLedger;
    }

    public function test_segment_below_minimum_evidence_is_insufficient_evidence_and_blocked_not_green(): void
    {
        $result = $this->ledger()->compute([
            'outcome_rows' => [
                [
                    'model_tier' => 'tier-a', 'scaffold_variant' => 'variant-a', 'task_class' => 'class-a',
                    'sample_size' => 3,
                    'commit_success_rate' => 1.0, 'give_back_rate' => 0.0, 'value_proof_rate' => 1.0,
                    'duplicate_rate' => 0.0, 'evidence_strength' => 1.0,
                ],
            ],
        ]);

        $row = $result['slo_rows'][0];
        $this->assertSame('insufficient_evidence', $row['status']);
        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::ENFORCEMENT_BLOCK, $row['enforcement_action']);
        $this->assertNotSame('green', $row['status']);
        $this->assertCount(1, $result['insufficient_segments']);
    }

    public function test_red_segment_scaffolds_or_escalates_based_on_failing_slo_count_and_threshold(): void
    {
        $result = $this->ledger()->compute([
            'outcome_rows' => [
                [
                    'model_tier' => 'tier-scaffold', 'scaffold_variant' => 'v', 'task_class' => 'c',
                    'sample_size' => 20,
                    'commit_success_rate' => 0.60, // 1 failing SLO
                    'give_back_rate' => 0.05, 'value_proof_rate' => 0.90,
                    'duplicate_rate' => 0.02, 'evidence_strength' => 0.90,
                    'escalation_threshold' => 3,
                ],
                [
                    'model_tier' => 'tier-escalate', 'scaffold_variant' => 'v', 'task_class' => 'c',
                    'sample_size' => 20,
                    'commit_success_rate' => 0.10, 'give_back_rate' => 0.90, 'value_proof_rate' => 0.10, // 3 failing SLOs
                    'duplicate_rate' => 0.02, 'evidence_strength' => 0.90,
                    'escalation_threshold' => 3,
                ],
            ],
        ]);

        $byTier = [];
        foreach ($result['slo_rows'] as $row) {
            $byTier[$row['model_tier']] = $row;
        }

        $this->assertSame('red', $byTier['tier-scaffold']['status']);
        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::ENFORCEMENT_SCAFFOLD, $byTier['tier-scaffold']['enforcement_action']);

        $this->assertSame('red', $byTier['tier-escalate']['status']);
        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::ENFORCEMENT_ESCALATE, $byTier['tier-escalate']['enforcement_action']);

        $this->assertContains("tier-scaffold:v:c", $result['failing_segments']);
        $this->assertContains("tier-escalate:v:c", $result['failing_segments']);
    }

    public function test_provider_name_only_quality_claims_are_rejected_regardless_of_otherwise_green_metrics(): void
    {
        $result = $this->ledger()->compute([
            'outcome_rows' => [
                [
                    'model_tier' => 'tier-provider-only', 'scaffold_variant' => 'v', 'task_class' => 'c',
                    'sample_size' => 20,
                    'commit_success_rate' => 1.0, 'give_back_rate' => 0.0, 'value_proof_rate' => 1.0,
                    'duplicate_rate' => 0.0, 'evidence_strength' => 1.0,
                    'quality_claim_basis' => 'provider_reputation_only',
                ],
            ],
        ]);

        $row = $result['slo_rows'][0];
        $this->assertSame('green', $row['status']);
        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::ENFORCEMENT_BLOCK, $row['enforcement_action']);
        $this->assertCount(1, $result['rejected_provider_name_only_claims']);
        $this->assertSame('provider_reputation_only', $result['rejected_provider_name_only_claims'][0]['quality_claim_basis']);
    }

    public function test_accepted_claim_bases_are_not_rejected(): void
    {
        foreach (['evidence', 'measured_outcomes', 'benchmark', 'slo_metrics'] as $basis) {
            $result = $this->ledger()->compute([
                'outcome_rows' => [
                    [
                        'model_tier' => 'tier-'.$basis, 'scaffold_variant' => 'v', 'task_class' => 'c',
                        'sample_size' => 20,
                        'commit_success_rate' => 1.0, 'give_back_rate' => 0.0, 'value_proof_rate' => 1.0,
                        'duplicate_rate' => 0.0, 'evidence_strength' => 1.0,
                        'quality_claim_basis' => $basis,
                    ],
                ],
            ]);

            $this->assertSame([], $result['rejected_provider_name_only_claims'], "basis {$basis} should not be rejected");
            $this->assertNull($result['slo_rows'][0]['enforcement_action']);
        }
    }
}
