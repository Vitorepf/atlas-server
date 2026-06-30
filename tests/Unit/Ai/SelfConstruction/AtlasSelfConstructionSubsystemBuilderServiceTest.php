<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Tests\TestCase;

class AtlasSelfConstructionSubsystemBuilderServiceTest extends TestCase
{
    private string $tmpRoot;

    private AtlasSelfConstructionSubsystemBuilderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_self_construction_'.uniqid('', true);
        @mkdir($this->tmpRoot, 0775, true);
        $this->svc = new AtlasSelfConstructionSubsystemBuilderService(new AtlasCognitionScoreCardService);
        $this->svc->setProposalsLogPathForTesting($this->tmpRoot.'/proposals.jsonl');
        $this->svc->setApprovalsLogPathForTesting($this->tmpRoot.'/approvals.jsonl');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpRoot.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_detect_gaps_returns_list(): void
    {
        $gaps = $this->svc->detectGaps();
        $this->assertIsArray($gaps);
        foreach ($gaps as $g) {
            $this->assertArrayHasKey('kind', $g);
            $this->assertArrayHasKey('subsystem_acronym', $g);
        }
    }

    public function test_propose_builds_canonical_envelope(): void
    {
        $p = $this->svc->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'NEWX',
            'subsystem_name' => 'New Subsystem',
            'group' => 'cognitive_immune',
            'rationale' => 'test gap',
        ]);
        $this->assertSame(AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA, $p['schema_version']);
        $this->assertStringStartsWith('prop_', $p['proposal_id']);
        $this->assertStringStartsWith('sha256:', $p['proposal_hash']);
        $this->assertSame('NEWX', $p['proposed_subsystem']['acronym']);
        $this->assertSame('cognitive_immune', $p['proposed_subsystem']['group']);
        $this->assertTrue($p['requires_human_approval']);
        $this->assertTrue($p['checks']['claim_policy_compliant']);
        $this->assertFalse($p['checks']['external_rivals_certification_touched']);
        $this->assertStringContainsString('App\\Services\\Ai\\Aemor', $p['proposed_subsystem']['service_class']);
        $this->assertStringContainsString('namespace App\\Services\\Ai\\Aemor', $p['scaffold']['service_skeleton']);
    }

    public function test_propose_persists_in_jsonl(): void
    {
        $this->svc->propose([
            'gap_kind' => 'operator_request',
            'subsystem_acronym' => 'XYZ',
            'group' => 'self_construction',
        ]);
        $list = $this->svc->listProposals();
        $this->assertCount(1, $list);
        $this->assertSame('XYZ', $list[0]['proposed_subsystem']['acronym']);
    }

    public function test_propose_hash_is_deterministic_for_same_gap(): void
    {
        $p1 = $this->svc->propose([
            'gap_kind' => 'operator_request',
            'subsystem_acronym' => 'AAA',
            'group' => 'self_construction',
        ]);
        $p2 = $this->svc->propose([
            'gap_kind' => 'operator_request',
            'subsystem_acronym' => 'AAA',
            'group' => 'self_construction',
        ]);
        $this->assertSame($p1['proposal_hash'], $p2['proposal_hash']);
        $this->assertSame($p1['proposal_id'], $p2['proposal_id']);
    }

    public function test_unknown_gap_kind_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->propose([
            'gap_kind' => 'invalid',
            'subsystem_acronym' => 'X',
        ]);
    }

    public function test_unknown_group_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->propose([
            'gap_kind' => 'operator_request',
            'subsystem_acronym' => 'X',
            'group' => 'not_a_group',
        ]);
    }

    public function test_acronym_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->propose([
            'gap_kind' => 'operator_request',
            'subsystem_acronym' => '',
        ]);
    }

    public function test_approve_appends_receipt(): void
    {
        $p = $this->svc->propose([
            'gap_kind' => 'operator_request',
            'subsystem_acronym' => 'AAA',
            'group' => 'self_construction',
        ]);
        $r = $this->svc->approve([
            'proposal_id' => $p['proposal_id'],
            'proposal_hash' => $p['proposal_hash'],
            'action' => 'approve',
            'rationale' => 'looks good',
        ]);
        $this->assertSame(AtlasSelfConstructionSubsystemBuilderService::APPROVAL_SCHEMA, $r['schema_version']);
        $this->assertSame('approve', $r['action']);
        $this->assertCount(1, $this->svc->listApprovals());
    }

    public function test_reject_works(): void
    {
        $p = $this->svc->propose([
            'gap_kind' => 'operator_request',
            'subsystem_acronym' => 'BBB',
            'group' => 'self_construction',
        ]);
        $r = $this->svc->approve([
            'proposal_id' => $p['proposal_id'],
            'proposal_hash' => $p['proposal_hash'],
            'action' => 'reject',
        ]);
        $this->assertSame('reject', $r['action']);
    }

    public function test_approve_requires_proposal_id_and_hash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->approve([
            'proposal_id' => '',
            'proposal_hash' => '',
            'action' => 'approve',
        ]);
    }

    public function test_approve_rejects_unknown_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->approve([
            'proposal_id' => 'x',
            'proposal_hash' => 'y',
            'action' => 'whatever',
        ]);
    }

    public function test_proposal_contains_task_fabric_contract_with_required_fields(): void
    {
        $p = $this->svc->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'NEWX',
            'group' => 'self_construction',
        ]);
        $this->assertArrayHasKey('task_fabric_contract', $p);
        $c = $p['task_fabric_contract'];
        $this->assertArrayHasKey('allowed_files', $c);
        $this->assertArrayHasKey('doc_path', $c);
        $this->assertArrayHasKey('acceptance_criteria', $c);
        $this->assertArrayHasKey('required_evidence', $c);
        $this->assertContains('tests_or_gates_result', $c['required_evidence']);
        $this->assertContains('implementation_notes', $c['required_evidence']);
    }

    public function test_task_fabric_contract_allowed_files_include_impl_and_test_paths(): void
    {
        $p = $this->svc->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'NEWX',
            'group' => 'self_construction',
        ]);
        $files = $p['task_fabric_contract']['allowed_files'];
        $this->assertCount(2, $files);
        $this->assertStringEndsWith('.php', $files[0]);
        $this->assertStringEndsWith('Test.php', $files[1]);
        $this->assertStringStartsWith('app/', $files[0]);
        $this->assertStringStartsWith('tests/', $files[1]);
    }

    public function test_task_fabric_contract_acceptance_criteria_contains_artisan_test_command(): void
    {
        $p = $this->svc->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'NEWX',
            'group' => 'self_construction',
        ]);
        $criteria = implode(' ', $p['task_fabric_contract']['acceptance_criteria']);
        $this->assertStringContainsString('/opt/homebrew/bin/php artisan test', $criteria);
    }

    public function test_task_fabric_contract_marks_scaffold_not_directly_enqueueable(): void
    {
        $p = $this->svc->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'NEWX',
            'group' => 'self_construction',
        ]);
        $c = $p['task_fabric_contract'];
        $this->assertTrue($c['scaffold_only']);
        $this->assertFalse($c['directly_enqueueable']);
        $this->assertNotEmpty($c['enqueue_blocker']);
    }
}
