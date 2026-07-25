<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticWorkcell\Support;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellDesignArtifactsSupport as Support;
use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellRoleContractSupport;
use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellTopologyPolicySupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Pure Support peel for AAWR design artifacts residual — no I/O, no host service, no DB.
 *
 * Explicit path proof: AtlasAgenticWorkcellRuntimeService imports Support and no
 * longer declares the peeled private design/admission/artifact methods.
 */
final class AgenticWorkcellDesignArtifactsSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/AgenticWorkcell/Support/AgenticWorkcellDesignArtifactsSupport.php';

    private const HOST_PATH = 'app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php';

    /** @var list<string> */
    private const PEELED = [
        'orgDesign',
        'pressureLayerAdvisoryRoster',
        'taskGraph',
        'contextPacks',
        'executionSchedule',
        'verificationPlan',
        'evidenceLedger',
        'memoryPacket',
        'counterfactualReplay',
        'learningPolicy',
        'controlPlaneSummary',
        'workcellAdmission',
        'learningCandidates',
        'blocked',
        'average',
        'countsBy',
        'numericOrNull',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellDesignArtifactsSupport;',
            $hostSrc,
            'Host must import AgenticWorkcellDesignArtifactsSupport',
        );

        foreach ([
            'AgenticWorkcellDesignArtifactsSupport::orgDesign',
            'AgenticWorkcellDesignArtifactsSupport::pressureLayerAdvisoryRoster',
            'AgenticWorkcellDesignArtifactsSupport::taskGraph',
            'AgenticWorkcellDesignArtifactsSupport::contextPacks',
            'AgenticWorkcellDesignArtifactsSupport::executionSchedule',
            'AgenticWorkcellDesignArtifactsSupport::verificationPlan',
            'AgenticWorkcellDesignArtifactsSupport::evidenceLedger',
            'AgenticWorkcellDesignArtifactsSupport::memoryPacket',
            'AgenticWorkcellDesignArtifactsSupport::counterfactualReplay',
            'AgenticWorkcellDesignArtifactsSupport::learningPolicy',
            'AgenticWorkcellDesignArtifactsSupport::controlPlaneSummary',
            'AgenticWorkcellDesignArtifactsSupport::workcellAdmission',
            'AgenticWorkcellDesignArtifactsSupport::learningCandidates',
            'AgenticWorkcellDesignArtifactsSupport::blocked',
            'AgenticWorkcellDesignArtifactsSupport::average',
            'AgenticWorkcellDesignArtifactsSupport::countsBy',
            'AgenticWorkcellDesignArtifactsSupport::numericOrNull',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        foreach (self::PEELED as $method) {
            $this->assertStringNotContainsString(
                'private function '.$method,
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
            $this->assertStringNotContainsString(
                'private static function '.$method,
                $hostSrc,
                "Peeled static residual on host: {$method}",
            );
        }

        // Host must keep IO/DI residual only.
        foreach (['aregDecision', 'compileOrgPattern', 'workcell'] as $kept) {
            $this->assertStringContainsString(
                'private function '.$kept,
                $hostSrc,
                "Host must keep IO residual private: {$kept}",
            );
        }
    }

    #[Test]
    public function pure_support_is_static_and_final_with_no_instance_state(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->getConstructor()?->isPrivate() ?? false);

        foreach (self::PEELED as $method) {
            $m = new ReflectionMethod(Support::class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function org_design_and_verification_plan_are_domain_and_risk_sensitive(): void
    {
        $design = Support::orgDesign('solo_agent', 'finance', 'atlas_finance', 4, 8, ['source' => 'test']);
        $this->assertSame('atlas.agentic_workcell.org_design.v1', $design['schema_version']);
        $this->assertTrue($design['operator_review_required']);
        $this->assertSame(8, $design['risk_score']);
        $this->assertNotEmpty($design['org_design_hash']);

        $low = Support::verificationPlan('solo_agent', 'conversation', 'atlas_conversation', 3, [['role_id' => 'a']]);
        $this->assertTrue($low['independent_verifier_required']);
        $this->assertFalse($low['completion_allowed_without_evidence']);
        $this->assertContains('response_shape_check', $low['checks']);
        $this->assertNotContains('policy_gate', $low['checks']);

        $high = Support::verificationPlan('lead_workers', 'programming', 'atlas_dev', 9, [
            ['role_id' => 'a'], ['role_id' => 'b'], ['role_id' => 'c'], ['role_id' => 'd'],
        ]);
        $this->assertTrue($high['critic_required']);
        $this->assertTrue($high['evidence_auditor_required']);
        $this->assertContains('focused_tests', $high['checks']);
        $this->assertContains('policy_gate', $high['checks']);
        $this->assertContains('adversarial_verification', $high['checks']);
    }

    #[Test]
    public function task_graph_context_packs_and_schedule_compose_from_role_roster(): void
    {
        $roles = AgenticWorkcellRoleContractSupport::roleRoster('solo_agent', 'conversation', 'atlas_conversation', 3);
        $this->assertNotEmpty($roles);

        $graph = Support::taskGraph('obj', 'solo_agent', 'conversation', 'atlas_conversation', $roles, []);
        $this->assertSame('atlas.agentic_workcell.task_graph.v1', $graph['schema_version']);
        $this->assertCount(count($roles), $graph['tasks']);
        $this->assertSame(
            'task_01_'.(string) $roles[0]['role_id'],
            $graph['tasks'][0]['task_id'],
        );
        $this->assertTrue($graph['conflict_policy']['reviewers_are_read_only']);

        $packs = Support::contextPacks('obj', 'conversation', 'atlas_conversation', $roles, ['ctx:a', 'ctx:b'], ['ev:1'], []);
        $this->assertSame(count($roles), $packs['pack_count']);
        $this->assertTrue($packs['context_isolation_required']);
        $this->assertSame(['ctx:a', 'ctx:b'], $packs['packs'][0]['included_context_refs']);

        $serial = Support::executionSchedule('solo_agent', $roles, $graph, 3);
        $this->assertFalse($serial['parallelism_allowed']);
        $this->assertSame(1, $serial['max_parallel_agents']);
        $this->assertSame('standard', $serial['risk_mode']);

        $parallel = Support::executionSchedule('lead_workers', $roles, $graph, 9);
        $this->assertTrue($parallel['parallelism_allowed']);
        $this->assertSame('strict', $parallel['risk_mode']);
        $this->assertGreaterThanOrEqual(2, $parallel['max_parallel_agents']);
    }

    #[Test]
    public function evidence_memory_learning_policy_and_control_summary_are_deterministic(): void
    {
        $roles = [
            ['role_id' => 'lead_synthesizer'],
            ['role_id' => 'qa_testing'],
        ];
        $taskGraph = ['tasks' => [
            ['task_id' => 'task_01_lead_synthesizer'],
            ['task_id' => 'task_02_qa_testing'],
        ]];
        $verification = ['checks' => ['a', 'b']];

        $ledger = Support::evidenceLedger('ship it', $roles, $taskGraph, ['ev:x']);
        $this->assertSame(['ev:x'], $ledger['initial_evidence_refs']);
        $this->assertSame(2, $ledger['task_count']);
        $this->assertArrayHasKey('lead_synthesizer', $ledger['role_receipt_requirements']);

        $memory = Support::memoryPacket('ship it', 'programming', 'atlas_dev', 'lead_workers', $roles);
        $this->assertSame('programming:atlas_dev', $memory['scope']);
        $this->assertSame(['lead_synthesizer', 'qa_testing'], $memory['role_ids']);

        $policy = Support::learningPolicy('programming', 'atlas_dev', 'lead_workers', 9);
        $this->assertTrue($policy['records_outcome']);
        $this->assertTrue($policy['anti_false_learning_gate']['operator_review_required_when_risk_high']);
        $this->assertSame('programming:atlas_dev:lead_workers', $policy['scope']);

        $summary = Support::controlPlaneSummary('lead_workers', $roles, $taskGraph, $verification, AtlasAgenticWorkcellRuntimeService::STATUS_READY);
        $this->assertSame(2, $summary['role_count']);
        $this->assertSame(2, $summary['task_count']);
        $this->assertSame(2, $summary['verification_check_count']);
        $this->assertTrue($summary['independent_verification_required']);
    }

    #[Test]
    public function counterfactual_replay_scores_all_topologies(): void
    {
        $replay = Support::counterfactualReplay('obj', 'research', 'atlas_research', 'mapreduce_research', 6, 4);
        $this->assertSame('mapreduce_research', $replay['selected_topology']);
        $this->assertCount(count(AgenticWorkcellTopologyPolicySupport::TOPOLOGIES), $replay['candidates']);
        $this->assertNotEmpty($replay['winning_topology']);
        $this->assertSame(
            $replay['candidates'][0]['topology'],
            $replay['winning_topology'],
        );
    }

    #[Test]
    public function workcell_admission_legacy_block_and_unsupported_topology(): void
    {
        $roles = [['role_id' => 'lead_synthesizer']];

        $legacy = Support::workcellAdmission([], 'solo_agent', $roles, 3, []);
        $this->assertSame('legacy_planning_only', $legacy['status']);
        $this->assertTrue($legacy['requires_execution_order_before_execution']);

        $unsupported = Support::workcellAdmission(['topology' => 'not_a_real_topology'], 'solo_agent', $roles, 3, ['e']);
        $this->assertSame('blocked', $unsupported['status']);
        $this->assertSame('unsupported_workcell_topology', $unsupported['reason']);

        $invalidOrder = Support::workcellAdmission(['execution_order' => 'nope'], 'solo_agent', $roles, 3, ['e']);
        $this->assertSame('blocked', $invalidOrder['status']);
        $this->assertSame('execution_order_invalid', $invalidOrder['reason']);
    }

    #[Test]
    public function learning_candidates_blocked_envelope_average_and_counts_by(): void
    {
        $promote = Support::learningCandidates('solo_agent', 0.9, 0.8, []);
        $this->assertSame([['kind' => 'promote_org_pattern_candidate', 'reason' => 'quality_and_roi_acceptable']], $promote);

        $lowQ = Support::learningCandidates('lead_workers', 0.4, 0.9, []);
        $this->assertSame('change_topology', $lowQ[0]['kind']);
        $this->assertSame('lead_workers', $lowQ[0]['current_topology']);

        $multi = Support::learningCandidates('solo_agent', 0.4, 0.2, ['verification_failed' => true]);
        $kinds = array_column($multi, 'kind');
        $this->assertContains('change_topology', $kinds);
        $this->assertContains('reduce_agent_count_or_context', $kinds);
        $this->assertContains('strengthen_verifier_or_red_team', $kinds);

        $blocked = Support::blocked('atlas.test.v1', 'missing', 'need workcell');
        $this->assertSame(AtlasAgenticWorkcellRuntimeService::STATUS_BLOCKED, $blocked['status']);
        $this->assertSame('missing', $blocked['blocker']['reason']);
        $this->assertTrue($blocked['claim_policy']['planning_only']);

        $this->assertNull(Support::numericOrNull(null));
        $this->assertNull(Support::numericOrNull('nope'));
        $this->assertSame(1.5, Support::numericOrNull(1.5));

        $this->assertNull(Support::average(collect(), 'quality_score'));
        $a = new stdClass;
        $a->quality_score = 0.5;
        $b = new stdClass;
        $b->quality_score = 0.7;
        $this->assertSame(0.6, Support::average(collect([$a, $b]), 'quality_score'));

        $r1 = new stdClass;
        $r1->topology = 'solo_agent';
        $r2 = new stdClass;
        $r2->topology = 'solo_agent';
        $r3 = new stdClass;
        $r3->topology = 'lead_workers';
        $counts = Support::countsBy(collect([$r1, $r2, $r3]), 'topology');
        $this->assertSame(['lead_workers' => 1, 'solo_agent' => 2], $counts);
    }

    #[Test]
    public function pressure_layer_advisory_roster_is_read_only_and_not_execution(): void
    {
        $roster = Support::pressureLayerAdvisoryRoster('programming', 'atlas_dev');
        $this->assertNotEmpty($roster);
        foreach ($roster as $guard) {
            $this->assertTrue($guard['advisory']);
            $this->assertTrue($guard['read_only']);
            $this->assertSame('programming', $guard['domain']);
            $this->assertSame('atlas_dev', $guard['flow_id']);
            $this->assertContains('mutate_files', $guard['forbidden_actions']);
            $this->assertNotEmpty($guard['role_id']);
        }
    }
}
