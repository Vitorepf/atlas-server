<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\NightShift;

use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use RuntimeException;
use Tests\TestCase;

/**
 * Read-only contract tests for the Night Shift Area Focus Loop read model
 * (slice 1, AP-712 inside the Atlas Software Company Stewardship Stack).
 *
 * Determinism: the Area Contract comes from the canonical registry, and the
 * canonical gap owner (Self-Directed Evolution) is reused but never executed —
 * data is injected via the `gap_read_model` input override, owner-doc presence
 * via `owner_doc_status`. Source-unavailability is exercised by overriding the
 * read-only `fetchGapReadModel()` seam.
 */
class AreaFocusLoopReadModelServiceTest extends TestCase
{
    private function service(): AreaFocusLoopReadModelService
    {
        return app(AreaFocusLoopReadModelService::class);
    }

    /**
     * @param  array<string,bool>|null  $override
     * @return array<string,bool>
     */
    private function ownerDocsPresent(?array $override = null): array
    {
        $docs = (new AtlasNightShiftAreaFocusContractRegistry())
            ->resolve(AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS)['area_owner_docs'];
        $map = [];
        foreach ($docs as $doc) {
            $map[$doc] = true;
        }

        return array_merge($map, $override ?? []);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function gapReport(array $candidates): array
    {
        return [
            'schema_version' => SelfDirectedEvolutionGapReadModelService::REPORT_SCHEMA,
            'status' => 'ready',
            'candidates' => $candidates,
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA,
            'source_owner' => 'self_construction',
            'source_ref' => 'self_construction:subsystem:ABC:partial_canon',
            'gap_kind' => 'partial_canon',
            'title' => 'Self-Construction gap · partial_canon · ABC',
            'risk_level' => 'medium',
            'capability' => 'Alpha Beta',
            'evidence_refs' => ['acos_scorecard:ABC'],
            'owner_doc_refs' => ['docs/engineering-knowledge-base/atlas-ai-self-construction-os.md'],
            'candidate_hash' => 'sha256:'.str_repeat('a', 64),
        ], $overrides);
    }

    /**
     * Anonymous subclass that throws from the gap seam, to exercise SDE outage.
     */
    private function serviceWithGapOutage(): AreaFocusLoopReadModelService
    {
        return new class(
            app(SelfDirectedEvolutionGapReadModelService::class),
            app(AtlasNightShiftAreaFocusContractRegistry::class),
        ) extends AreaFocusLoopReadModelService {
            protected function fetchGapReadModel(array $input): array
            {
                throw new RuntimeException('gap read model down');
            }
        };
    }

    public function test_emits_report_schema_and_full_envelope(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([$this->candidate()]),
        ]);

        $this->assertSame(AreaFocusLoopReadModelService::REPORT_SCHEMA, $report['schema_version']);
        foreach ([
            'status', 'mode', 'ap_contract', 'stewardship_stack_note', 'area_id', 'area', 'area_map',
            'finding_count', 'findings', 'routing_summary', 'governance', 'budget_state',
            'evidence_requirement', 'morning_inbox', 'source_summary', 'blockers',
            'owner_reuse_matrix', 'claim_policy', 'report_hash', 'generated_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $report, "missing report key {$key}");
        }
        $this->assertSame('read_only', $report['mode']);
        $this->assertSame('AP-712', $report['ap_contract']);
        $this->assertContains($report['status'], ['ready', 'partial', 'blocked']);
        $this->assertStringStartsWith('sha256:', $report['report_hash']);
        $this->assertStringContainsString('não OS novo', $report['stewardship_stack_note']);
    }

    public function test_area_contract_comes_from_registry_with_ap712_fields(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([]),
        ]);

        $area = $report['area'];
        $this->assertSame('agentic_engineering_os', $area['area_id']);
        foreach (['area_id', 'area_name', 'area_owner_docs', 'repo_scope', 'autonomy_tier', 'dev_budget', 'forge_budget', 'wip_limit', 'risk_policy', 'stop_conditions', 'inbox_destination'] as $field) {
            $this->assertArrayHasKey($field, $area, "missing AP-712 area field {$field}");
        }
        $this->assertCount(5, $area['area_owner_docs']);
        $this->assertSame(0, $area['autonomy_tier']);
        $this->assertContains('Night Shift', $area['owned_systems']);
    }

    public function test_unknown_area_is_blocked_with_supported_areas(): void
    {
        $report = $this->service()->project(['area_id' => 'marketing_company']);

        $this->assertSame('blocked', $report['status']);
        $this->assertNull($report['area']);
        $this->assertSame(0, $report['finding_count']);
        $blocker = $report['blockers'][0];
        $this->assertSame('unknown_area', $blocker['reason']);
        $this->assertContains('agentic_engineering_os', $blocker['supported_areas']);
    }

    public function test_self_construction_partial_canon_routes_to_sde(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([$this->candidate(['risk_level' => 'medium'])]),
        ]);

        $this->assertSame(1, $report['finding_count']);
        $finding = $report['findings'][0];
        $this->assertSame(AreaFocusLoopReadModelService::FINDING_SCHEMA, $finding['schema_version']);
        $this->assertSame('self_directed_evolution', $finding['source']);
        $this->assertSame('self_directed_evolution', $finding['route']);
        $this->assertSame('needs_spec_proposal_first', $finding['route_reason']);
        $this->assertSame('branch_allowed', $finding['triage_class']);
        $this->assertFalse($finding['safe_to_autofix']);
        $this->assertFalse($finding['dispatched']);
        $this->assertTrue($finding['requires_operator_review']);
        $this->assertStringStartsWith('nsf_', $finding['finding_id']);
        $this->assertStringStartsWith('sha256:', $finding['finding_hash']);
    }

    public function test_aael_gap_routes_to_forge(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([$this->candidate([
                'source_owner' => 'aael',
                'source_ref' => 'aael:control_plane:aael_operator_review_required',
                'gap_kind' => 'aael_operator_review_required',
                'title' => 'AAEL portfolio review pending',
                'risk_level' => 'medium',
            ])]),
        ]);

        $finding = $report['findings'][0];
        $this->assertSame('atlas_forge', $finding['route']);
        $this->assertSame('long_horizon_portfolio_to_forge', $finding['route_reason']);
        $this->assertTrue($finding['requires_branch_isolation']);
        $this->assertTrue($finding['operator_decision_required']);
        $this->assertSame(1, $report['routing_summary']['atlas_forge']);
    }

    public function test_small_self_improvement_routes_to_atlas_dev(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([$this->candidate([
                'source_owner' => 'self_improvement',
                'source_ref' => 'self_improvement:prop_small',
                'gap_kind' => 'self_improvement_backlog_item',
                'title' => 'Tidy a helper',
                'risk_level' => 'low',
            ])]),
        ]);

        $finding = $report['findings'][0];
        $this->assertSame('atlas_dev', $finding['route']);
        $this->assertSame('small_scoped_local_to_dev', $finding['route_reason']);
        $this->assertSame(1, $report['routing_summary']['atlas_dev']);
    }

    public function test_critical_gap_is_triaged_inbox_only(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([$this->candidate([
                'source_ref' => 'self_construction:subsystem:CRT:missing_service_class',
                'gap_kind' => 'missing_service_class',
                'risk_level' => 'critical',
            ])]),
        ]);

        $finding = $report['findings'][0];
        $this->assertSame('inbox_only', $finding['triage_class']);
        $this->assertSame('inbox_only', $finding['route']);
        $this->assertSame('wide', $finding['blast_radius']);
    }

    public function test_sensitive_keyword_forces_inbox_only(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([$this->candidate([
                'source_ref' => 'self_construction:subsystem:AUTH:partial_canon',
                'title' => 'Gap in auth token rotation',
                'risk_level' => 'medium',
            ])]),
        ]);

        $this->assertSame('inbox_only', $report['findings'][0]['route']);
    }

    public function test_wip_limit_queues_execution_overflow(): void
    {
        $candidates = [];
        foreach (range(0, 4) as $i) {
            $candidates[] = $this->candidate([
                'source_owner' => 'self_improvement',
                'source_ref' => "self_improvement:prop_{$i}",
                'gap_kind' => 'self_improvement_backlog_item',
                'title' => "Small dev task {$i}",
                'risk_level' => 'medium',
                'candidate_hash' => 'sha256:'.str_repeat((string) $i, 64),
            ]);
        }

        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport($candidates),
        ]);

        // execution_wip_limit derives from wip_limit.max_branches (3).
        $this->assertSame(3, $report['budget_state']['execution_wip_limit']);
        $this->assertSame(3, $report['routing_summary']['atlas_dev']);
        $this->assertSame(2, $report['routing_summary']['queued']);
    }

    public function test_missing_owner_doc_becomes_finding_and_partial_status(): void
    {
        $missingDoc = 'docs/engineering-knowledge-base/atlas-forge-operating-system.md';
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent([$missingDoc => false]),
            'gap_read_model' => $this->gapReport([]),
        ]);

        $this->assertSame('partial', $report['status']);
        $docFindings = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['source'] === 'area_owner_docs',
        ));
        $this->assertCount(1, $docFindings);
        $this->assertSame('missing_owner_doc', $docFindings[0]['gap_kind']);
        $this->assertSame('inbox_only', $docFindings[0]['route']);
        $this->assertSame('missing_owner_doc_requires_operator', $docFindings[0]['route_reason']);
        $this->assertSame(1, $report['area_map']['owner_docs_missing']);
    }

    public function test_ready_when_docs_present_and_sde_available(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([$this->candidate()]),
        ]);

        $this->assertSame('ready', $report['status']);
        $this->assertSame(0, $report['area_map']['owner_docs_missing']);
        $this->assertSame(5, $report['area_map']['owner_docs_present']);
    }

    public function test_morning_inbox_collects_operator_decisions(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([
                $this->candidate(['risk_level' => 'critical', 'source_ref' => 'sc:crit', 'candidate_hash' => 'sha256:'.str_repeat('c', 64)]),
                $this->candidate([
                    'source_owner' => 'self_improvement',
                    'gap_kind' => 'self_improvement_backlog_item',
                    'title' => 'small dev task',
                    'risk_level' => 'low',
                    'source_ref' => 'si:1',
                    'candidate_hash' => 'sha256:'.str_repeat('d', 64),
                ]),
            ]),
        ]);

        $inbox = $report['morning_inbox'];
        $this->assertSame(AreaFocusLoopReadModelService::MORNING_INBOX_SCHEMA, $inbox['schema_version']);
        $this->assertSame('morning_inbox', $inbox['destination']);
        // critical (inbox_only) + atlas_dev (execution route) both need a decision.
        $this->assertSame(2, $inbox['decision_count']);
        $this->assertContains('approve', $inbox['items'][0]['operator_actions']);
    }

    public function test_budget_state_is_honest_read_only(): void
    {
        $budget = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([$this->candidate()]),
        ])['budget_state'];

        $this->assertFalse($budget['budget_consumed']);
        $this->assertFalse($budget['execution_executed']);
        $this->assertSame(3, $budget['execution_wip_limit']);
        $this->assertArrayHasKey('dev_budget', $budget);
        $this->assertArrayHasKey('forge_budget', $budget);
    }

    public function test_governance_envelope_declares_hard_gates(): void
    {
        $gov = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([]),
        ])['governance'];

        $this->assertSame('read_only', $gov['mode']);
        $this->assertSame('max_governed', $gov['governed_mode']);
        $this->assertFalse($gov['executes_work']);
        $this->assertFalse($gov['opens_branch']);
        $this->assertFalse($gov['execution_enabled']);
        $this->assertTrue($gov['no_merge_without_operator']);
        $this->assertTrue($gov['no_deploy_without_operator']);
        $this->assertTrue($gov['no_secrets']);
        $this->assertTrue($gov['no_destructive_change']);
        $this->assertTrue($gov['branch_isolation_required']);
        $this->assertTrue($gov['kill_switch_required']);
        $this->assertTrue($gov['evidence_pack_required']);
        $this->assertTrue($gov['morning_inbox_required']);
    }

    public function test_claim_policy_enforces_read_only_and_no_new_os(): void
    {
        $policy = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([]),
        ])['claim_policy'];

        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['writes_state']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['executes_work']);
        $this->assertFalse($policy['opens_branch']);
        $this->assertFalse($policy['dispatches_to_dev_or_forge']);
        $this->assertFalse($policy['creates_spec']);
        $this->assertFalse($policy['autoapproval_allowed']);
        $this->assertFalse($policy['external_side_effect_allowed']);
        $this->assertFalse($policy['parallel_runtime_created']);
        $this->assertFalse($policy['is_new_os']);
        $this->assertTrue($policy['reuses_self_directed_evolution_for_gaps']);
        $this->assertTrue($policy['operator_review_required']);
    }

    public function test_report_hash_is_deterministic_for_same_input(): void
    {
        $input = [
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([
                $this->candidate(),
                $this->candidate([
                    'source_ref' => 'self_construction:subsystem:XYZ:missing_service_class',
                    'gap_kind' => 'missing_service_class',
                    'risk_level' => 'high',
                    'candidate_hash' => 'sha256:'.str_repeat('b', 64),
                ]),
            ]),
        ];

        $a = $this->service()->project($input);
        $b = $this->service()->project($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertSame($a['findings'], $b['findings']);
    }

    public function test_routing_summary_counts_routes(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([
                $this->candidate(['risk_level' => 'low']),
                $this->candidate([
                    'source_ref' => 'self_construction:subsystem:CRT:x',
                    'risk_level' => 'critical',
                    'candidate_hash' => 'sha256:'.str_repeat('c', 64),
                ]),
            ]),
        ]);

        $summary = $report['routing_summary'];
        $this->assertSame(1, $summary['self_directed_evolution']);
        $this->assertSame(1, $summary['inbox_only']);
        $this->assertSame(0, $summary['atlas_dev']);
        $this->assertSame(0, $summary['atlas_forge']);
    }

    public function test_limit_caps_finding_count(): void
    {
        $candidates = [];
        foreach (['AA', 'BB', 'CC', 'DD'] as $i => $acr) {
            $candidates[] = $this->candidate([
                'source_ref' => "self_construction:subsystem:{$acr}:partial_canon",
                'candidate_hash' => 'sha256:'.str_repeat((string) $i, 64),
            ]);
        }
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport($candidates),
            'limit' => 2,
        ]);

        $this->assertSame(2, $report['finding_count']);
    }

    public function test_findings_sorted_by_priority_then_risk(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->gapReport([
                $this->candidate(['risk_level' => 'low', 'candidate_hash' => 'sha256:'.str_repeat('1', 64), 'source_ref' => 'sc:low']),
                $this->candidate(['risk_level' => 'high', 'candidate_hash' => 'sha256:'.str_repeat('2', 64), 'source_ref' => 'sc:high']),
            ]),
        ]);

        $this->assertGreaterThanOrEqual(
            self::riskRank($report['findings'][1]['severity']),
            self::riskRank($report['findings'][0]['severity']),
        );
    }

    public function test_sde_source_unavailable_becomes_blocker_without_breaking_report(): void
    {
        $report = $this->serviceWithGapOutage()->project(['owner_doc_status' => $this->ownerDocsPresent()]);

        $this->assertSame('partial', $report['status']);
        $this->assertFalse($report['source_summary']['self_directed_evolution']['available']);
        $reasons = array_map(static fn (array $b): string => $b['reason'], $report['blockers']);
        $this->assertContains('source_unavailable', $reasons);
        // owner-doc source still produced a valid area map.
        $this->assertSame(5, $report['area_map']['owner_docs_present']);
    }

    public function test_blocked_when_sde_unavailable_and_owner_docs_missing(): void
    {
        $report = $this->serviceWithGapOutage()->project([
            'owner_doc_status' => $this->ownerDocsPresent([
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os.md' => false,
                'docs/engineering-knowledge-base/atlas-forge-operating-system.md' => false,
            ]),
        ]);

        $this->assertSame('blocked', $report['status']);
    }

    private static function riskRank(string $severity): int
    {
        return [
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            'unknown' => 0,
        ][$severity] ?? 0;
    }
}
