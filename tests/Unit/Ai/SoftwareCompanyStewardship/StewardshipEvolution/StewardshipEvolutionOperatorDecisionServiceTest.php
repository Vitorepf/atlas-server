<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionOperatorDecisionService;
use InvalidArgumentException;
use Tests\TestCase;

class StewardshipEvolutionOperatorDecisionServiceTest extends TestCase
{
    private function service(): StewardshipEvolutionOperatorDecisionService
    {
        return app(StewardshipEvolutionOperatorDecisionService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'target_type' => 'autonomous_executive',
            'target_id' => 'exec_1234567890abcdef',
            'target_hash' => 'sha256:'.hash('sha256', 'executive-target'),
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'risk' => 'medium',
            'rationale' => 'approve next governed slice',
        ], $overrides);
    }

    public function test_builds_full_operator_decision_receipt(): void
    {
        $receipt = $this->service()->decide($this->input());

        $this->assertSame(StewardshipEvolutionOperatorDecisionService::RECEIPT_SCHEMA, $receipt['schema_version']);
        $this->assertSame('AP-731', $receipt['ap_contract']);
        $this->assertStringStartsWith('seod_', $receipt['decision_id']);
        $this->assertStringStartsWith('sha256:', $receipt['decision_hash']);
        $this->assertSame('autonomous_executive', $receipt['target_type']);
        $this->assertSame('accept', $receipt['decision']);
        $this->assertTrue($receipt['operator_owned']);
        $this->assertTrue($receipt['requires_owner_execution']);
        $this->assertFalse($receipt['executed']);
        $this->assertFalse($receipt['atlas_auto_decided']);
        $this->assertFalse($receipt['autoapproval_allowed']);
        $this->assertFalse($receipt['autoimplementation_allowed']);
        $this->assertFalse($receipt['branch_created']);
        $this->assertFalse($receipt['provider_invoked']);
        $this->assertFalse($receipt['dev_invoked']);
        $this->assertFalse($receipt['forge_invoked']);
        $this->assertFalse($receipt['mutates_target_repo']);
        $this->assertFalse($receipt['parallel_registry_created']);
        $this->assertFalse($receipt['new_os_created']);
        $this->assertFalse($receipt['auto_promotion']);
    }

    public function test_decision_id_and_hash_are_deterministic(): void
    {
        $a = $this->service()->decide($this->input());
        $b = $this->service()->decide($this->input());

        $this->assertSame($a['decision_id'], $b['decision_id']);
        $this->assertSame($a['decision_hash'], $b['decision_hash']);
    }

    public function test_all_target_types_can_be_reviewed(): void
    {
        foreach (StewardshipEvolutionOperatorDecisionService::TARGET_TYPES as $targetType) {
            $receipt = $this->service()->decide($this->input([
                'target_type' => $targetType,
                'target_id' => $targetType.'_target',
                'target_hash' => '',
            ]));

            $this->assertSame($targetType, $receipt['target_type']);
            $this->assertStringStartsWith('sha256:', $receipt['target_hash']);
            $this->assertFalse($receipt['executed']);
        }
    }

    public function test_all_decisions_build_distinct_next_actions(): void
    {
        $actions = [];
        foreach (StewardshipEvolutionOperatorDecisionService::DECISIONS as $decision) {
            $receipt = $this->service()->decide($this->input([
                'decision' => $decision,
                'rationale' => $decision === 'accept' ? 'approved' : '',
            ]));
            $actions[$decision] = $receipt['next_allowed_action'];
        }

        $this->assertCount(4, array_unique($actions));
    }

    public function test_target_payload_can_derive_anchor_without_persisting_execution(): void
    {
        $receipt = $this->service()->decide($this->input([
            'target_type' => 'new_area_proposal',
            'target_id' => '',
            'target_hash' => '',
            'target_payload' => [
                'proposal_id' => 'new_area_replay',
                'candidate_area' => 'replay_evidence',
            ],
        ]));

        $this->assertSame('new_area_replay', $receipt['target_id']);
        $this->assertStringStartsWith('sha256:', $receipt['target_hash']);
        $this->assertStringContainsString('domain_runtime_creation_gate', $receipt['next_allowed_action']);
        $this->assertFalse($receipt['auto_promotion']);
    }

    public function test_blocks_empty_actor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/operator_actor_required/');

        $this->service()->decide($this->input(['operator_actor' => '']));
    }

    public function test_blocks_invalid_decision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid_decision/');

        $this->service()->decide($this->input(['decision' => 'approve']));
    }

    public function test_blocks_invalid_target_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid_target_type/');

        $this->service()->decide($this->input(['target_type' => 'new_runtime_os']));
    }

    public function test_blocks_target_without_anchor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/target_without_anchor/');

        $this->service()->decide($this->input(['target_id' => '', 'target_hash' => '']));
    }

    public function test_high_risk_accept_requires_rationale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/rationale_required_for_high_risk_accept/');

        $this->service()->decide($this->input(['risk' => 'critical', 'rationale' => '']));
    }
}
