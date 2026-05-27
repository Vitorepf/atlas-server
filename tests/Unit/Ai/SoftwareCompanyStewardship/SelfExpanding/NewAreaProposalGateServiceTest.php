<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\SelfExpanding;

use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\NewAreaProposalGateService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use Tests\TestCase;

class NewAreaProposalGateServiceTest extends TestCase
{
    private function service(?StewardshipEvolutionDecisionLedgerService &$ledger = null): NewAreaProposalGateService
    {
        $ledger = app(StewardshipEvolutionDecisionLedgerService::class);
        $ledger->setStorageRootForTesting(sys_get_temp_dir().'/atlas-ap737-'.bin2hex(random_bytes(4)));

        return new NewAreaProposalGateService(
            app(StewardshipEvolutionReadModelService::class),
            $ledger,
        );
    }

    public function test_projects_new_area_proposals_into_gate_items(): void
    {
        $gate = $this->service()->evaluate([
            'observed_gaps' => [
                ['gap_id' => 'gap_replay', 'summary' => 'Replay evidence lacks a steward', 'candidate_area' => 'replay_evidence'],
            ],
        ]);

        $this->assertSame(NewAreaProposalGateService::GATE_SCHEMA, $gate['schema_version']);
        $this->assertSame(NewAreaProposalGateService::STATUS_READY, $gate['status']);
        $this->assertSame('AP-737', $gate['ap_contract']);
        $this->assertSame(1, $gate['gate_item_count']);

        $item = $gate['gate_items'][0];
        $this->assertSame(NewAreaProposalGateService::ITEM_SCHEMA, $item['schema_version']);
        $this->assertSame('replay_evidence', $item['candidate_area']);
        $this->assertSame('awaiting_operator_review', $item['gate_status']);
        $this->assertSame(NewAreaProposalGateService::DOMAIN_PROPOSAL_SCHEMA, $item['domain_creation_proposal_draft']['schema']);
        $this->assertTrue($item['domain_creation_proposal_draft']['draft_only']);
        $this->assertFalse($item['claim_policy']['creates_domain_runtime']);
    }

    public function test_accept_decision_routes_to_domain_runtime_creation_gate_without_execution(): void
    {
        $service = $this->service($ledger);
        $input = [
            'observed_gaps' => [
                ['gap_id' => 'gap_memory_ops', 'summary' => 'Memory operations needs a steward', 'candidate_area' => 'memory_operations'],
            ],
        ];
        $proposalId = (string) $service->evaluate($input)['gate_items'][0]['proposal_id'];

        $receipt = $service->decide($input + [
            'proposal_id' => $proposalId,
            'operator_actor' => 'operator_test',
            'decision' => 'accept',
            'rationale' => 'approved for Domain Runtime Creation Gate review only',
        ]);
        $this->assertSame('new_area_proposal', $receipt['target_type']);
        $this->assertSame($proposalId, $receipt['target_id']);
        $this->assertStringContainsString('domain_runtime_creation_gate', $receipt['next_allowed_action']);
        $this->assertFalse($receipt['executed']);

        $gate = $service->evaluate($input);
        $this->assertSame('accepted_for_domain_runtime_creation_gate', $gate['gate_items'][0]['gate_status']);
        $this->assertSame(1, $ledger->listDecisions()['decision_count']);
    }

    public function test_sensitive_domain_gets_safety_sovereignty_block(): void
    {
        $gate = $this->service()->evaluate([
            'observed_gaps' => [
                ['gap_id' => 'gap_trading', 'summary' => 'Trading strategy backtest lacks owner', 'candidate_area' => 'trading_research'],
            ],
        ]);

        $item = $gate['gate_items'][0];
        $draft = $item['domain_creation_proposal_draft'];

        $this->assertSame('blocked_awaiting_operator_review', $item['gate_status']);
        $this->assertContains('sensitive_domain_requires_explicit_safety_sovereignty_review', $item['blockers']);
        $this->assertSame('sensitive', $draft['sovereignty_class']);
        $this->assertTrue($draft['safety_sovereignty_block']['required']);
        $this->assertTrue($draft['safety_sovereignty_block']['licensed_human_review_required']);
        $this->assertContains('jurisdiction_check', $draft['safety_sovereignty_block']['mandatory_gates']);
        $this->assertSame(100, $draft['promotion_policy']['replay_minimum_intents']);
    }

    public function test_existing_area_collision_blocks_promotion(): void
    {
        $gate = $this->service()->evaluate([
            'existing_area_ids' => ['replay_evidence'],
            'observed_gaps' => [
                ['gap_id' => 'gap_replay', 'summary' => 'Replay evidence lacks a steward', 'candidate_area' => 'replay_evidence'],
            ],
        ]);

        $this->assertSame('blocked_awaiting_operator_review', $gate['gate_items'][0]['gate_status']);
        $this->assertContains('candidate_area_already_exists', $gate['gate_items'][0]['blockers']);
    }

    public function test_known_capability_is_routed_to_area_stewardship_instead_of_new_domain(): void
    {
        $gate = $this->service()->evaluate([
            'observed_gaps' => [
                ['gap_id' => 'gap_dev', 'summary' => 'Atlas Dev needs stewardship', 'candidate_area' => 'atlas_dev'],
            ],
        ]);

        $item = $gate['gate_items'][0];

        $this->assertSame('blocked_awaiting_operator_review', $item['gate_status']);
        $this->assertContains('existing_capability_requires_area_stewardship_handoff_not_domain_creation', $item['blockers']);
        $this->assertSame('area_stewardship_existing_capability_handoff', $item['required_next_gate']['owner']);
        $this->assertFalse($item['domain_creation_proposal_draft']['creation_recommended']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md', $item['known_existing_owner']['owner_doc']);
    }

    public function test_proposal_filter_returns_no_proposals_for_unknown_id(): void
    {
        $gate = $this->service()->evaluate([
            'proposal_id' => 'new_area_missing',
            'observed_gaps' => [
                ['gap_id' => 'gap_replay', 'summary' => 'Replay evidence lacks a steward', 'candidate_area' => 'replay_evidence'],
            ],
        ]);

        $this->assertSame(NewAreaProposalGateService::STATUS_NO_PROPOSALS, $gate['status']);
        $this->assertSame(0, $gate['gate_item_count']);
    }

    public function test_claim_policy_never_allows_runtime_creation_or_mutation(): void
    {
        $policy = $this->service()->evaluate()['claim_policy'];

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
