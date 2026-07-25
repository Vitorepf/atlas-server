<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticWorkcell\Support;

use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellRoleContractSupport as Support;
use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellTopologyPolicySupport;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure Support peel for AAWR role-contract residual — no I/O, no host service, no DB.
 *
 * Explicit path proof: AtlasAgenticWorkcellRuntimeService imports Support and no
 * longer declares the peeled private role-contract methods.
 */
final class AgenticWorkcellRoleContractSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/AgenticWorkcell/Support/AgenticWorkcellRoleContractSupport.php';

    private const HOST_PATH = 'app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php';

    /** @var list<string> */
    private const PEELED = [
        'depthProfile',
        'topologyAssignments',
        'roleContextScope',
        'roleOutputContract',
        'roleToolBoundary',
        'taskDependencies',
        'expectedArtifacts',
        'roleRoster',
    ];

    /** Host private method names before peel (must be gone). */
    /** @var list<string> */
    private const PEELED_HOST_PRIVATES = [
        'roleRoster',
        'depthProfile',
        'topologyAssignments',
        'roleContextScope',
        'roleOutputContract',
        'roleToolBoundary',
        'taskDependencies',
        'expectedArtifacts',
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
            'use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellRoleContractSupport;',
            $hostSrc,
            'Host must import AgenticWorkcellRoleContractSupport',
        );
        foreach ([
            'AgenticWorkcellRoleContractSupport::roleRoster',
            'AgenticWorkcellRoleContractSupport::taskDependencies',
            'AgenticWorkcellRoleContractSupport::expectedArtifacts',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        foreach (self::PEELED_HOST_PRIVATES as $method) {
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
    public function depth_profile_varies_by_risk_band_and_specializes_r4_security_roles(): void
    {
        $this->assertSame('minimal_evidence', Support::depthProfile('R0', 'backend'));
        $this->assertSame('light_independent_review', Support::depthProfile('R1', 'backend'));
        $this->assertSame('standard_contract_integration', Support::depthProfile('R2', 'backend'));
        $this->assertSame('multi_verifier_regression_compatibility', Support::depthProfile('R3', 'backend'));
        $this->assertSame('deep_independent_regression', Support::depthProfile('R4', 'backend'));
        $this->assertSame(
            'security_mutation_property_chaos_rollback',
            Support::depthProfile('R4', 'appsec_privacy'),
        );
        $this->assertSame(
            'security_mutation_property_chaos_rollback',
            Support::depthProfile('R4', 'devops_sre'),
        );
        $this->assertSame(
            'competing_candidates_different_family_disaster_drill',
            Support::depthProfile('R5', 'backend'),
        );
        $this->assertSame(
            'competing_candidates_different_family_disaster_drill',
            Support::depthProfile('unknown', 'backend'),
        );
    }

    #[Test]
    public function topology_assignments_cover_official_roster_with_forge_architecture_override(): void
    {
        $default = Support::topologyAssignments('lead_workers');
        $this->assertSame(count(EngineeringRoleRoster::OFFICIAL_ROLES), count($default));
        $this->assertSame('lead', $default['product_strategy']);
        $this->assertSame('coordination', $default['product_management']);
        $this->assertSame('design', $default['architecture']);
        $this->assertSame('independent_audit', $default['evidence_audit']);
        $this->assertSame('final_certification', $default['final_certification']);
        $this->assertSame('independent_verification', $default['qa_testing']);
        $this->assertSame('supporting_review', $default['backend']);

        $forge = Support::topologyAssignments('forge_milestone_crew');
        $this->assertSame('lead_architecture', $forge['architecture']);
        $this->assertSame('lead', $forge['product_strategy']);
    }

    #[Test]
    public function role_context_scope_and_output_contract_classify_verifier_worker_scout_defaults(): void
    {
        $this->assertSame('read_only_evidence_and_outputs', Support::roleContextScope('qa_testing'));
        $this->assertSame('read_only_evidence_and_outputs', Support::roleContextScope('lead_verifier'));
        $this->assertSame('read_only_evidence_and_outputs', Support::roleContextScope('outcome_analysis'));
        $this->assertSame('owned_work_packet_only', Support::roleContextScope('worker_backend'));
        $this->assertSame('owned_work_packet_only', Support::roleContextScope('tool_builder'));
        $this->assertSame('retrieval_and_mapping_only', Support::roleContextScope('scout_alpha'));
        $this->assertSame('retrieval_and_mapping_only', Support::roleContextScope('context_cartographer'));
        $this->assertSame('goal_and_coordination_context', Support::roleContextScope('product_strategy'));

        $verify = Support::roleOutputContract('qa_testing');
        $this->assertSame(['summary', 'evidence_refs', 'confidence', 'blockers', 'next_action'], $verify['must_return']);
        $this->assertSame('verification_report', $verify['role_specific_artifact']);
        $this->assertContains('unsupported_completion_claim', $verify['forbidden_output']);

        $build = Support::roleOutputContract('backend');
        $this->assertSame('work_product_or_findings', $build['role_specific_artifact']);
    }

    #[Test]
    public function role_tool_boundary_is_read_only_for_review_roles_and_gates_writes_by_risk(): void
    {
        $qa = Support::roleToolBoundary('qa_testing', 3);
        $this->assertTrue($qa['read_only']);
        $this->assertFalse($qa['writes_allowed']);
        $this->assertTrue($qa['requires_receipt']);
        $this->assertFalse($qa['external_side_effects_allowed']);

        $backendLow = Support::roleToolBoundary('backend', 5);
        $this->assertFalse($backendLow['read_only']);
        $this->assertTrue($backendLow['writes_allowed']);

        $backendHigh = Support::roleToolBoundary('backend', 9);
        $this->assertFalse($backendHigh['read_only']);
        $this->assertFalse($backendHigh['writes_allowed']);

        $scout = Support::roleToolBoundary('scout_1', 2);
        $this->assertTrue($scout['read_only']);
        $this->assertFalse($scout['writes_allowed']);
    }

    #[Test]
    public function task_dependencies_and_expected_artifacts_follow_role_contract_rules(): void
    {
        $this->assertSame([], Support::taskDependencies('product_strategy', 0, 'task_01_lead'));
        $this->assertSame([], Support::taskDependencies('backend', 3, 'task_01_lead'));
        $this->assertSame(['task_01_lead'], Support::taskDependencies('qa_testing', 5, 'task_01_lead'));
        $this->assertSame(['task_01_lead'], Support::taskDependencies('lead_verifier', 2, 'task_01_lead'));
        $this->assertSame(['task_01_lead'], Support::taskDependencies('evidence_audit', 4, 'task_01_lead'));
        $this->assertSame(['task_01_lead'], Support::taskDependencies('outcome_analysis', 6, 'task_01_lead'));

        $this->assertSame(
            ['verification_report', 'failed_or_passed_checks'],
            Support::expectedArtifacts('qa_testing'),
        );
        $this->assertSame(['risk_report', 'counterarguments'], Support::expectedArtifacts('red_team_critic'));
        $this->assertSame(['evidence_manifest', 'missing_evidence'], Support::expectedArtifacts('compliance_auditor'));
        $this->assertSame(
            ['work_product', 'changed_artifacts_or_plan'],
            Support::expectedArtifacts('backend_worker'),
        );
        $this->assertSame(['findings', 'handoff_packet'], Support::expectedArtifacts('product_strategy'));
    }

    #[Test]
    public function role_roster_keeps_official_22_membership_and_varies_depth_by_risk(): void
    {
        $low = Support::roleRoster('lead_workers', 'programming', 'atlas_dev', 2);
        $high = Support::roleRoster('lead_workers', 'programming', 'atlas_dev', 9);

        $this->assertCount(count(EngineeringRoleRoster::OFFICIAL_ROLES), $low);
        $this->assertSame(EngineeringRoleRoster::OFFICIAL_ROLES, array_column($low, 'role_id'));
        $this->assertSame(
            ['EngineeringRoleRoster::OFFICIAL_ROLES'],
            array_values(array_unique(array_column($low, 'membership_source'))),
        );

        $this->assertSame(AgenticWorkcellTopologyPolicySupport::riskBand(2), $low[0]['risk_band']);
        $this->assertSame(AgenticWorkcellTopologyPolicySupport::riskBand(9), $high[0]['risk_band']);
        $this->assertNotSame($low[0]['depth'], $high[0]['depth']);

        $byId = collect($low)->keyBy('role_id');
        $this->assertTrue($byId['qa_testing']['independent_context']);
        $this->assertTrue($byId['evidence_audit']['independent_context']);
        $this->assertTrue($byId['final_certification']['independent_context']);
        $this->assertFalse($byId['backend']['independent_context']);
        $this->assertSame(Support::FORBIDDEN_ACTIONS, $byId['backend']['forbidden_actions']);
        $this->assertSame('programming', $byId['backend']['domain']);
        $this->assertSame('atlas_dev', $byId['backend']['flow_id']);
        $this->assertSame(1, $low[0]['agent_index']);
        $this->assertSame(count(EngineeringRoleRoster::OFFICIAL_ROLES), $low[count($low) - 1]['agent_index']);

        $forge = Support::roleRoster('forge_milestone_crew', 'programming', 'atlas_forge', 5);
        $forgeById = collect($forge)->keyBy('role_id');
        $this->assertSame('lead_architecture', $forgeById['architecture']['topology_assignment']);
        $this->assertSame('independent_verification', $forgeById['qa_testing']['topology_assignment']);
        $this->assertSame('read_only_evidence_and_outputs', $forgeById['qa_testing']['context_scope']);
        $this->assertSame('verification_report', $forgeById['qa_testing']['output_contract']['role_specific_artifact']);
        $this->assertTrue($forgeById['qa_testing']['tool_boundary']['read_only']);
    }

    #[Test]
    public function support_source_has_no_io_or_di_seams(): void
    {
        $root = dirname(__DIR__, 5);
        $src = (string) file_get_contents($root.'/'.self::SUPPORT_PATH);

        foreach ([
            'app(',
            'base_path(',
            'config(',
            'file_exists(',
            'file_get_contents(',
            'Schema::',
            'DB::',
            'Storage::',
            'now(',
            'CarbonImmutable',
            'AtlasAgenticWorkcell::',
            'DatabaseTableAvailability',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $src,
                "Support must remain pure; found seam: {$needle}",
            );
        }
    }
}
