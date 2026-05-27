<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfDirectedEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use RuntimeException;
use Tests\TestCase;

/**
 * Read-only contract tests for the Self-Directed Evolution Gap Read Model
 * (v0.1, AP-708).
 *
 * The reused owners are `final`, so data is injected through the service's
 * `$input` overrides (which short-circuit before any owner method is called),
 * keeping every projection side-effect free. Source-unavailability is exercised
 * by overriding the read-only fetch seams on the (non-final) read model itself.
 */
class SelfDirectedEvolutionGapReadModelServiceTest extends TestCase
{
    private function service(): SelfDirectedEvolutionGapReadModelService
    {
        return app(SelfDirectedEvolutionGapReadModelService::class);
    }

    public function test_emits_report_schema_and_full_envelope(): void
    {
        $report = $this->service()->project();

        $this->assertSame(SelfDirectedEvolutionGapReadModelService::REPORT_SCHEMA, $report['schema_version']);
        foreach (['status', 'generated_at', 'source_summary', 'candidates', 'blockers', 'owner_reuse_matrix', 'claim_policy', 'report_hash', 'candidate_count'] as $key) {
            $this->assertArrayHasKey($key, $report, "missing report key {$key}");
        }
        $this->assertContains($report['status'], ['ready', 'partial', 'blocked']);
        $this->assertStringStartsWith('sha256:', $report['report_hash']);
        foreach (['self_construction', 'self_improvement', 'aael'] as $source) {
            $this->assertArrayHasKey($source, $report['owner_reuse_matrix']);
        }
    }

    public function test_normalizes_self_construction_gaps_to_candidate(): void
    {
        $report = $this->service()->project([
            'gaps' => [
                ['kind' => 'missing_service_class', 'subsystem_acronym' => 'ABC', 'subsystem_name' => 'Alpha Beta', 'group' => 'cognitive_immune', 'rationale' => 'Service class not found.'],
            ],
            'self_improvement_backlog' => ['proposals' => []],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 0, 'blocked' => 0]],
        ]);

        $this->assertSame(1, $report['candidate_count']);
        $candidate = $report['candidates'][0];
        $this->assertSame(SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA, $candidate['schema_version']);
        $this->assertSame('self_construction', $candidate['source_owner']);
        $this->assertSame('missing_service_class', $candidate['gap_kind']);
        $this->assertSame('high', $candidate['risk_level']);
        $this->assertSame(AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA, $candidate['source_schema_version']);
        $this->assertStringStartsWith('gapc_', $candidate['candidate_id']);
        $this->assertStringStartsWith('sha256:', $candidate['candidate_hash']);
        $this->assertSame(AtlasSelfConstructionSubsystemBuilderService::class, $candidate['duplicate_authority_guard']['owner_service']);
        $this->assertFalse($candidate['duplicate_authority_guard']['parallel_authority_created']);
        $this->assertContains('propose', $candidate['duplicate_authority_guard']['not_invoked_methods']);
    }

    public function test_normalizes_self_improvement_backlog_via_input_override(): void
    {
        $report = $this->service()->project([
            'gaps' => [],
            'self_improvement_backlog' => [
                'proposals' => [
                    ['proposal_id' => 'prop_X', 'title' => 'Improve router', 'status' => 'pending_human_review', 'risk_level' => 'high', 'target_capability' => 'router', 'summary' => 'router needs work', 'evidence_refs' => ['ev1']],
                ],
            ],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 0, 'blocked' => 0]],
        ]);

        $this->assertSame(1, $report['candidate_count']);
        $candidate = $report['candidates'][0];
        $this->assertSame('self_improvement', $candidate['source_owner']);
        $this->assertSame('self_improvement_backlog_item', $candidate['gap_kind']);
        $this->assertSame('self_improvement:prop_X', $candidate['source_ref']);
        $this->assertSame('high', $candidate['risk_level']);
        $this->assertSame(AtlasSelfImprovementProposalBacklogService::ITEM_SCHEMA_VERSION, $candidate['source_schema_version']);
        $this->assertContains('ev1', $candidate['evidence_refs']);
    }

    public function test_self_improvement_terminal_states_are_not_surfaced(): void
    {
        $report = $this->service()->project([
            'gaps' => [],
            'self_improvement_backlog' => [
                'proposals' => [
                    ['proposal_id' => 'prop_done', 'title' => 'done', 'status' => 'archived'],
                    ['proposal_id' => 'prop_rej', 'title' => 'rejected', 'status' => 'rejected'],
                    ['proposal_id' => 'prop_live', 'title' => 'live', 'status' => 'draft'],
                ],
            ],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 0, 'blocked' => 0]],
        ]);

        $this->assertSame(1, $report['candidate_count']);
        $this->assertSame('self_improvement:prop_live', $report['candidates'][0]['source_ref']);
    }

    public function test_normalizes_aael_control_plane_via_input_override(): void
    {
        $report = $this->service()->project([
            'gaps' => [],
            'self_improvement_backlog' => ['proposals' => []],
            'aael_control_plane' => [
                'summary' => ['operator_review_required' => 2, 'blocked' => 1],
                'window' => ['hours' => 24],
            ],
        ]);

        $kinds = array_map(static fn (array $c): string => $c['gap_kind'], $report['candidates']);
        $this->assertContains('aael_operator_review_required', $kinds);
        $this->assertContains('aael_promotion_blocked', $kinds);

        $blocked = array_values(array_filter($report['candidates'], static fn (array $c): bool => $c['gap_kind'] === 'aael_promotion_blocked'))[0];
        $this->assertSame('high', $blocked['risk_level']);
        $this->assertSame(AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA, $blocked['source_schema_version']);
    }

    public function test_dedupe_is_deterministic(): void
    {
        $duplicateGap = ['kind' => 'partial_canon', 'subsystem_acronym' => 'DUP', 'subsystem_name' => 'Dup', 'group' => 'memory_core', 'rationale' => 'same'];
        $report = $this->service()->project([
            'gaps' => [$duplicateGap, $duplicateGap, $duplicateGap],
            'self_improvement_backlog' => ['proposals' => []],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 0, 'blocked' => 0]],
        ]);

        $this->assertSame(1, $report['candidate_count']);
    }

    public function test_all_candidates_require_operator_curation_with_side_effects_off(): void
    {
        $report = $this->service()->project([
            'gaps' => [['kind' => 'pipeline_not_proven', 'subsystem_acronym' => 'PNP', 'subsystem_name' => 'Pipe', 'group' => 'teos', 'rationale' => 'x']],
            'self_improvement_backlog' => ['proposals' => [['proposal_id' => 'p1', 'title' => 't', 'status' => 'draft']]],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 1, 'blocked' => 1], 'window' => ['hours' => 24]],
        ]);

        $this->assertGreaterThanOrEqual(4, $report['candidate_count']);
        foreach ($report['candidates'] as $candidate) {
            $this->assertTrue($candidate['requires_operator_curation']);
            $this->assertFalse($candidate['autoapproval_allowed']);
            $this->assertFalse($candidate['external_side_effect_allowed']);
            $this->assertFalse($candidate['duplicate_authority_guard']['parallel_authority_created']);
        }
    }

    public function test_claim_policy_enforces_read_only_invariants(): void
    {
        $policy = $this->service()->project()['claim_policy'];

        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['writes_state']);
        $this->assertFalse($policy['invokes_propose']);
        $this->assertFalse($policy['invokes_approve']);
        $this->assertFalse($policy['creates_backlog_proposal']);
        $this->assertFalse($policy['creates_aael_cycle']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['autoapproval_allowed']);
        $this->assertFalse($policy['external_side_effect_allowed']);
        $this->assertFalse($policy['parallel_authority_created']);
        $this->assertTrue($policy['operator_curation_required']);
    }

    public function test_does_not_call_propose_or_approve(): void
    {
        // Real Subsystem Builder, but its append-only proposal/approval logs are
        // redirected to a temp dir. propose()/approve() are the only writers of
        // those files; if neither file exists after projection, neither was called.
        $tmp = sys_get_temp_dir().'/atlas_sde_'.uniqid('', true);
        @mkdir($tmp, 0775, true);
        $builder = app(AtlasSelfConstructionSubsystemBuilderService::class);
        $builder->setProposalsLogPathForTesting($tmp.'/proposals.jsonl');
        $builder->setApprovalsLogPathForTesting($tmp.'/approvals.jsonl');

        $svc = new SelfDirectedEvolutionGapReadModelService(
            $builder,
            app(AtlasSelfImprovementProposalBacklogService::class),
            app(AtlasAutonomousEvolutionLoopService::class),
        );

        $svc->project([
            'gaps' => [['kind' => 'operator_request', 'subsystem_acronym' => 'OPR', 'subsystem_name' => 'Op', 'group' => 'self_construction', 'rationale' => 'x']],
            'self_improvement_backlog' => ['proposals' => []],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 0, 'blocked' => 0]],
        ]);

        $this->assertFileDoesNotExist($tmp.'/proposals.jsonl', 'read model must not call propose()');
        $this->assertFileDoesNotExist($tmp.'/approvals.jsonl', 'read model must not call approve()');

        @rmdir($tmp);
    }

    public function test_report_hash_is_deterministic_for_same_input(): void
    {
        $input = [
            'gaps' => [['kind' => 'missing_service_class', 'subsystem_acronym' => 'AAA', 'subsystem_name' => 'A', 'group' => 'memory_core', 'rationale' => 'r']],
            'self_improvement_backlog' => ['proposals' => [['proposal_id' => 'p9', 'title' => 't9', 'status' => 'draft', 'risk_level' => 'low']]],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 1, 'blocked' => 0], 'window' => ['hours' => 24]],
        ];

        $a = $this->service()->project($input);
        $b = $this->service()->project($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertSame($a['candidates'], $b['candidates']);
    }

    public function test_source_unavailable_becomes_blocker_without_breaking_report(): void
    {
        $svc = new class(
            app(AtlasSelfConstructionSubsystemBuilderService::class),
            app(AtlasSelfImprovementProposalBacklogService::class),
            app(AtlasAutonomousEvolutionLoopService::class),
        ) extends SelfDirectedEvolutionGapReadModelService
        {
            protected function fetchGaps(): array
            {
                throw new RuntimeException('scorecard unavailable');
            }
        };

        $report = $svc->project([
            'self_improvement_backlog' => ['proposals' => [['proposal_id' => 'p', 'title' => 't', 'status' => 'draft']]],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 0, 'blocked' => 0]],
        ]);

        $this->assertSame('partial', $report['status']);
        $this->assertFalse($report['source_summary']['self_construction']['available']);
        $reasons = array_map(static fn (array $b): string => $b['reason'], $report['blockers']);
        $this->assertContains('source_unavailable', $reasons);
        $this->assertSame(1, $report['candidate_count']);
    }

    public function test_blocked_status_when_all_sources_unavailable(): void
    {
        $svc = new class(
            app(AtlasSelfConstructionSubsystemBuilderService::class),
            app(AtlasSelfImprovementProposalBacklogService::class),
            app(AtlasAutonomousEvolutionLoopService::class),
        ) extends SelfDirectedEvolutionGapReadModelService
        {
            protected function fetchGaps(): array
            {
                throw new RuntimeException('down');
            }

            protected function fetchBacklog(array $filters): array
            {
                throw new RuntimeException('down');
            }

            protected function fetchControlPlane(int $hours): array
            {
                throw new RuntimeException('down');
            }
        };

        $report = $svc->project();

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(0, $report['candidate_count']);
        $this->assertCount(3, $report['blockers']);
    }

    public function test_candidates_sorted_by_priority_then_risk(): void
    {
        $report = $this->service()->project([
            'gaps' => [
                ['kind' => 'partial_canon', 'subsystem_acronym' => 'MED', 'subsystem_name' => 'Med', 'group' => 'memory_core', 'rationale' => 'medium risk'],
                ['kind' => 'missing_service_class', 'subsystem_acronym' => 'HIG', 'subsystem_name' => 'High', 'group' => 'memory_core', 'rationale' => 'high risk'],
            ],
            'self_improvement_backlog' => ['proposals' => []],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 0, 'blocked' => 0]],
        ]);

        $this->assertSame('high', $report['candidates'][0]['risk_level']);
        $this->assertGreaterThanOrEqual(
            $report['candidates'][1]['priority_score'],
            $report['candidates'][0]['priority_score'],
        );
    }

    public function test_limit_caps_candidate_count(): void
    {
        $gaps = [];
        foreach (['AA', 'BB', 'CC', 'DD'] as $a) {
            $gaps[] = ['kind' => 'partial_canon', 'subsystem_acronym' => $a, 'subsystem_name' => $a, 'group' => 'memory_core', 'rationale' => 'r'];
        }
        $report = $this->service()->project([
            'gaps' => $gaps,
            'self_improvement_backlog' => ['proposals' => []],
            'aael_control_plane' => ['summary' => ['operator_review_required' => 0, 'blocked' => 0]],
            'limit' => 2,
        ]);

        $this->assertSame(2, $report['candidate_count']);
    }
}
