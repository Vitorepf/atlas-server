<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\SelfExpanding;

use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\NewAreaProposalGateService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingSoftwareCompanyService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use Tests\TestCase;

class SelfExpandingSoftwareCompanyServiceTest extends TestCase
{
    private function gate(?StewardshipEvolutionDecisionLedgerService &$ledger = null): NewAreaProposalGateService
    {
        $ledger = app(StewardshipEvolutionDecisionLedgerService::class);
        $ledger->setStorageRootForTesting(sys_get_temp_dir().'/atlas-ap738-'.bin2hex(random_bytes(4)));

        return new NewAreaProposalGateService(
            app(StewardshipEvolutionReadModelService::class),
            $ledger,
        );
    }

    private function service(?NewAreaProposalGateService $gate = null): SelfExpandingSoftwareCompanyService
    {
        return new SelfExpandingSoftwareCompanyService($gate ?? $this->gate());
    }

    public function test_projects_self_expanding_v0_as_proposal_only_ceiling(): void
    {
        $report = $this->service()->project([
            'observed_gaps' => [
                ['gap_id' => 'gap_replay', 'summary' => 'Replay evidence lacks a steward', 'candidate_area' => 'replay_evidence'],
            ],
        ]);

        $this->assertSame(SelfExpandingSoftwareCompanyService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(SelfExpandingSoftwareCompanyService::STATUS_READY, $report['status']);
        $this->assertSame('AP-738', $report['ap_contract']);
        $this->assertSame('Self-Expanding Software Company', $report['layer']);
        $this->assertSame('v0_proposal_only', $report['mode']);
        $this->assertTrue($report['promotion_boundary']['self_expansion_can_propose']);
        $this->assertFalse($report['promotion_boundary']['self_expansion_can_create_area']);
        $this->assertSame(1, $report['expansion_summary']['new_domain_candidates']);
    }

    public function test_existing_capabilities_are_handoffs_not_new_domains(): void
    {
        $report = $this->service()->project([
            'observed_gaps' => [
                ['gap_id' => 'gap_dev', 'summary' => 'Atlas Dev needs stewardship', 'candidate_area' => 'atlas_dev'],
            ],
        ]);

        $this->assertSame(0, $report['expansion_summary']['new_domain_candidates']);
        $this->assertSame(1, $report['expansion_summary']['existing_capability_handoffs']);
        $this->assertSame('area_stewardship_existing_capability_handoff', $report['operator_inbox']['items'][0]['review_route']);
        $this->assertSame('route_existing_capability_to_area_stewardship_review', $report['operator_inbox']['items'][0]['recommended_operator_action']);
    }

    public function test_sensitive_candidates_are_separated_for_review(): void
    {
        $report = $this->service()->project([
            'observed_gaps' => [
                ['gap_id' => 'gap_trading', 'summary' => 'Trading strategy backtest lacks owner', 'candidate_area' => 'trading_research'],
            ],
        ]);

        $this->assertSame(1, $report['expansion_summary']['new_domain_candidates']);
        $this->assertSame(1, $report['expansion_summary']['sensitive_candidates']);
        $this->assertSame('resolve_gate_blockers_before_accept', $report['operator_inbox']['items'][0]['recommended_operator_action']);
    }

    public function test_accepted_new_domain_candidate_is_ready_for_domain_gate(): void
    {
        $gate = $this->gate($ledger);
        $service = $this->service($gate);
        $input = [
            'observed_gaps' => [
                ['gap_id' => 'gap_telemetry', 'summary' => 'Telemetry quality lacks owner', 'candidate_area' => 'telemetry_quality'],
            ],
        ];
        $proposalId = (string) $gate->evaluate($input)['gate_items'][0]['proposal_id'];
        $gate->decide($input + [
            'proposal_id' => $proposalId,
            'operator_actor' => 'operator_test',
            'decision' => 'accept',
            'rationale' => 'approved for AP-737 to Domain Runtime Creation Gate handoff only',
        ]);

        $report = $service->project($input);

        $this->assertSame(1, $report['expansion_summary']['ready_for_domain_runtime_creation_gate']);
        $this->assertSame('prepare_domain_runtime_creation_gate_review_packet', $report['operator_inbox']['items'][0]['recommended_operator_action']);
        $this->assertSame(1, $ledger->listDecisions()['decision_count']);
    }

    public function test_report_hash_is_deterministic_for_same_input(): void
    {
        $input = [
            'observed_gaps' => [
                ['gap_id' => 'gap_replay', 'summary' => 'Replay evidence lacks a steward', 'candidate_area' => 'replay_evidence'],
            ],
        ];

        $service = $this->service();

        $a = $service->project($input);
        $b = $service->project($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertStringStartsWith('sha256:', $a['report_hash']);
    }

    public function test_claim_policy_never_allows_creation_or_mutation(): void
    {
        $policy = $this->service()->project()['claim_policy'];

        $this->assertTrue($policy['proposal_only']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['dev_invoked']);
        $this->assertFalse($policy['forge_invoked']);
        $this->assertFalse($policy['branch_created']);
        $this->assertFalse($policy['worktree_created']);
        $this->assertFalse($policy['domain_runtime_created']);
        $this->assertFalse($policy['department_created']);
        $this->assertFalse($policy['new_os_created']);
        $this->assertFalse($policy['parallel_runtime_created']);
        $this->assertFalse($policy['auto_promotion']);
    }
}
