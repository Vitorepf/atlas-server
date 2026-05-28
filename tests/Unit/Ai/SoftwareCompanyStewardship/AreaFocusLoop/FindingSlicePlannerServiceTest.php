<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use Tests\TestCase;

final class FindingSlicePlannerServiceTest extends TestCase
{
    private function planner(): FindingSlicePlannerService
    {
        return new FindingSlicePlannerService();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function finding(array $overrides = []): array
    {
        return array_merge([
            'finding_id' => 'afdf_runtime_gap',
            'finding_hash' => 'sha256:afdf_runtime_gap',
            'title' => 'Harden AP-786 selection refill loop',
            'detail' => 'The selection refill path can starve; add a bounded next-action.',
            'why_it_matters' => 'Throughput of the autonomous loop matters.',
            'proposed_next_action' => 'Implement a bounded refill in the selection path.',
            'kind' => 'bug',
            'severity' => 'medium',
            'owner_candidate' => 'atlas_dev',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'],
            'affected_docs' => [],
            'evidence_refs' => ['expected_test:AutonomousEvolutionSessionServiceTest.php'],
            'origin_type' => 'runtime_gap',
            'priority_score' => 950,
            'spec_seed' => [
                'candidate_id' => 'afdf_runtime_gap',
                'tests_required' => ['tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function plan(array $overrides = [], string $scope = FindingSlicePlannerService::SCOPE_FACTORY_MAX): array
    {
        return $this->planner()->plan([
            'finding' => $this->finding($overrides),
            'mode' => FindingSlicePlannerService::MODE_RECORD,
            'scope_profile' => $scope,
        ]);
    }

    public function test_plan_hash_is_deterministic_for_identical_input(): void
    {
        $input = [
            'finding' => $this->finding(),
            'mode' => FindingSlicePlannerService::MODE_RECORD,
            'scope_profile' => FindingSlicePlannerService::SCOPE_FACTORY_MAX,
        ];

        $first = $this->planner()->plan($input);
        $second = $this->planner()->plan($input);

        $this->assertSame(FindingSlicePlannerService::PLAN_SCHEMA, $first['schema_version']);
        $this->assertNotSame('', (string) $first['plan_hash']);
        $this->assertSame($first['plan_hash'], $second['plan_hash']);
        $this->assertSame($first, $second);
    }

    public function test_narrow_missing_test_finding_is_accepted_as_atlas_dev_slice(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_missing_test',
            'finding_hash' => 'sha256:afdf_missing_test',
            'kind' => 'test',
            'origin_type' => 'missing_test',
            'title' => 'Pin AP-756 materializer with a focused test',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php'],
            'evidence_refs' => ['expected_test:AreaFocusBranchSandboxMaterializerServiceTest.php'],
            'spec_seed' => ['candidate_id' => 'afdf_missing_test'],
        ]);

        $this->assertSame(FindingSlicePlannerService::STATUS_SLICED, $plan['decomposition_status']);
        $this->assertCount(1, $plan['slices']);
        $slice = $plan['slices'][0];
        $this->assertSame(FindingSlicePlannerService::OWNER_ATLAS_DEV, $slice['owner']);
        $this->assertSame(FindingSlicePlannerService::SHAPE_SERVICE_AND_TEST, $slice['expected_diff_shape']);
        $this->assertContains(
            'php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerServiceTest.php',
            $slice['validation_commands'],
        );
    }

    public function test_broad_finding_with_multiple_targets_is_decomposed_into_bounded_slices(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_broad_multi',
            'finding_hash' => 'sha256:afdf_broad_multi',
            'title' => 'Strengthen three AreaFocusLoop runtimes',
            'affected_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineService.php',
            ],
            'evidence_refs' => [],
            'spec_seed' => [
                'candidate_id' => 'afdf_broad_multi',
                'tests_required' => [
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineServiceTest.php',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDeepFindingEngineServiceTest.php',
                ],
            ],
        ]);

        $this->assertSame(FindingSlicePlannerService::STATUS_SLICED, $plan['decomposition_status']);
        $this->assertCount(3, $plan['slices']);
        // Each decomposed slice is bounded and ordered.
        foreach ($plan['slices'] as $index => $slice) {
            $this->assertSame($index + 1, $slice['sequence']);
            $this->assertLessThanOrEqual(FindingSlicePlannerService::MAX_FILES_PER_SLICE, count($slice['allowed_files']));
            $this->assertNotEmpty($slice['allowed_files']);
            $this->assertNotEmpty($slice['validation_commands']);
        }
        // Slices target distinct sources -> distinct ids.
        $ids = array_column($plan['slices'], 'slice_id');
        $this->assertCount(3, array_unique($ids));
    }

    public function test_broad_self_referential_finding_with_no_scope_is_blocked(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_generic',
            'finding_hash' => 'sha256:afdf_generic',
            'title' => 'Make Atlas better',
            'detail' => 'Improve the factory overall.',
            'why_it_matters' => 'We want Atlas to be better.',
            'proposed_next_action' => 'Make everything better.',
            'kind' => 'improvement',
            'affected_files' => [],
            'affected_docs' => [],
            'evidence_refs' => [],
            'spec_seed' => ['candidate_id' => 'afdf_generic'],
        ]);

        $this->assertSame(FindingSlicePlannerService::STATUS_OPERATOR_REVIEW, $plan['decomposition_status']);
        $this->assertSame([], $plan['slices']);
        $this->assertContains(
            FindingSlicePlannerService::BLOCKER_OPERATOR_OR_ARCHITECT_SPEC_REQUIRED,
            $plan['blockers'],
        );
    }

    public function test_generic_make_atlas_better_prompt_is_rejected_even_with_priority(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_make_better',
            'finding_hash' => 'sha256:afdf_make_better',
            'title' => 'make the factory better',
            'detail' => 'make the factory better',
            'why_it_matters' => 'make the factory better',
            'proposed_next_action' => 'make the factory better',
            'affected_files' => [],
            'evidence_refs' => [],
            'priority_score' => 999,
            'spec_seed' => ['candidate_id' => 'afdf_make_better'],
        ]);

        $this->assertNotSame(FindingSlicePlannerService::STATUS_SLICED, $plan['decomposition_status']);
        $this->assertContains(
            FindingSlicePlannerService::BLOCKER_OPERATOR_OR_ARCHITECT_SPEC_REQUIRED,
            $plan['blockers'],
        );
    }

    public function test_factory_max_rejects_docs_only_churn_without_runtime_unlock(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_docs',
            'finding_hash' => 'sha256:afdf_docs',
            'title' => 'Tidy a guide doc',
            'detail' => 'Polish prose in a guide.',
            'why_it_matters' => 'Reads a little nicer.',
            'proposed_next_action' => 'Edit the doc prose.',
            'kind' => 'doc',
            'affected_files' => [],
            'affected_docs' => ['docs/engineering-knowledge-base/some-guide.md'],
            'evidence_refs' => [],
            'spec_seed' => ['candidate_id' => 'afdf_docs'],
        ], FindingSlicePlannerService::SCOPE_FACTORY_MAX);

        $this->assertSame(FindingSlicePlannerService::STATUS_BLOCKED, $plan['decomposition_status']);
        $this->assertContains(
            FindingSlicePlannerService::BLOCKER_FACTORY_MAX_DOCS_ONLY_CHURN,
            $plan['blockers'],
        );
    }

    public function test_docs_only_is_allowed_when_it_unblocks_runtime_or_certification(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_docs_unlock',
            'finding_hash' => 'sha256:afdf_docs_unlock',
            'title' => 'Correct contract drift that blocks certification',
            'detail' => 'The doc contradicts runtime and must be corrected to unblock certification.',
            'why_it_matters' => 'Stops future agents from lying and unblocks the runtime certification.',
            'proposed_next_action' => 'Correct the canonical contract doc to unblock certification.',
            'kind' => 'doc',
            'affected_files' => [],
            'affected_docs' => ['docs/ap/AP-786-autonomous-evolution-session-contract.md'],
            'evidence_refs' => [],
            'spec_seed' => ['candidate_id' => 'afdf_docs_unlock'],
        ], FindingSlicePlannerService::SCOPE_FACTORY_MAX);

        $this->assertSame(FindingSlicePlannerService::STATUS_SLICED, $plan['decomposition_status']);
        $this->assertCount(1, $plan['slices']);
        $slice = $plan['slices'][0];
        $this->assertSame(FindingSlicePlannerService::OWNER_STEWARDSHIP, $slice['owner']);
        $this->assertSame(FindingSlicePlannerService::SHAPE_DOCS_ONLY, $slice['expected_diff_shape']);
    }

    public function test_runtime_bottleneck_becomes_an_implementable_slice(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_runtime_bottleneck',
            'finding_hash' => 'sha256:afdf_runtime_bottleneck',
            'kind' => 'runtime',
            'origin_type' => 'runtime_bottleneck',
            'title' => 'Replace unbounded blocking in the merge governor hot path',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php'],
            'evidence_refs' => ['expected_test:StewardshipBranchMergeGovernorServiceTest.php'],
            'spec_seed' => ['candidate_id' => 'afdf_runtime_bottleneck'],
        ]);

        $this->assertSame(FindingSlicePlannerService::STATUS_SLICED, $plan['decomposition_status']);
        $this->assertCount(1, $plan['slices']);
        $slice = $plan['slices'][0];
        $this->assertSame(FindingSlicePlannerService::OWNER_ATLAS_DEV, $slice['owner']);
        $this->assertSame(FindingSlicePlannerService::SHAPE_SERVICE_AND_TEST, $slice['expected_diff_shape']);
        $this->assertNotSame('', $slice['success_condition']);
    }

    public function test_blocks_when_no_validation_command_can_be_derived(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_no_test',
            'finding_hash' => 'sha256:afdf_no_test',
            'kind' => 'runtime',
            'title' => 'Improve a service with no test target',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php'],
            'evidence_refs' => [],
            'spec_seed' => ['candidate_id' => 'afdf_no_test'],
        ], FindingSlicePlannerService::SCOPE_BALANCED);

        $this->assertSame(FindingSlicePlannerService::STATUS_BLOCKED, $plan['decomposition_status']);
        $this->assertContains(
            FindingSlicePlannerService::BLOCKER_VALIDATION_COMMAND_MISSING,
            $plan['blockers'],
        );
    }

    public function test_blocks_when_allowed_files_are_unbounded(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_broad_scope',
            'finding_hash' => 'sha256:afdf_broad_scope',
            'title' => 'Refactor the whole AreaFocusLoop subsystem',
            'detail' => 'A wide-reaching structural change across a directory.',
            'why_it_matters' => 'Structural cohesion of the subsystem.',
            'proposed_next_action' => 'Rework the directory.',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'],
            'evidence_refs' => [],
            'spec_seed' => ['candidate_id' => 'afdf_broad_scope'],
        ]);

        $this->assertSame(FindingSlicePlannerService::STATUS_BLOCKED, $plan['decomposition_status']);
        $this->assertContains(
            FindingSlicePlannerService::BLOCKER_ALLOWED_FILES_TOO_BROAD,
            $plan['blockers'],
        );
    }

    public function test_forge_slice_without_authority_is_owner_runtime_not_ready(): void
    {
        $plan = $this->planner()->plan([
            'finding' => $this->finding([
                'finding_id' => 'afdf_forge',
                'finding_hash' => 'sha256:afdf_forge',
                'owner_candidate' => 'forge',
                'title' => 'Build a cross-runtime Obra change',
                'affected_files' => ['app/Services/Ai/AtlasForge/AtlasForgeRuntimeService.php'],
                'evidence_refs' => ['expected_test:AtlasForgeRuntimeServiceTest.php'],
                'spec_seed' => [
                    'candidate_id' => 'afdf_forge',
                    'tests_required' => ['tests/Unit/Ai/AtlasForge/AtlasForgeRuntimeServiceTest.php'],
                ],
            ]),
            'scope_profile' => FindingSlicePlannerService::SCOPE_FACTORY_MAX,
            'context' => ['forge_authority' => false],
        ]);

        $this->assertSame(FindingSlicePlannerService::STATUS_BLOCKED, $plan['decomposition_status']);
        $this->assertContains(
            FindingSlicePlannerService::BLOCKER_OWNER_RUNTIME_NOT_READY,
            $plan['blockers'],
        );
    }

    public function test_forge_slice_with_live_authority_is_sliced(): void
    {
        $plan = $this->planner()->plan([
            'finding' => $this->finding([
                'finding_id' => 'afdf_forge_ok',
                'finding_hash' => 'sha256:afdf_forge_ok',
                'owner_candidate' => 'forge',
                'title' => 'Build a cross-runtime Obra change',
                'affected_files' => ['app/Services/Ai/AtlasForge/AtlasForgeRuntimeService.php'],
                'evidence_refs' => ['expected_test:AtlasForgeRuntimeServiceTest.php'],
                'spec_seed' => [
                    'candidate_id' => 'afdf_forge_ok',
                    'tests_required' => ['tests/Unit/Ai/AtlasForge/AtlasForgeRuntimeServiceTest.php'],
                ],
            ]),
            'scope_profile' => FindingSlicePlannerService::SCOPE_FACTORY_MAX,
            'context' => ['forge_authority' => ['status' => 'live']],
        ]);

        $this->assertSame(FindingSlicePlannerService::STATUS_SLICED, $plan['decomposition_status']);
        $this->assertSame(FindingSlicePlannerService::OWNER_FORGE, $plan['slices'][0]['owner']);
        $this->assertSame(FindingSlicePlannerService::MERGE_NEVER_AUTO, $plan['slices'][0]['merge_policy']);
    }

    public function test_provider_fit_unknown_for_unresolvable_owner(): void
    {
        $plan = $this->plan([
            'finding_id' => 'afdf_unknown_owner',
            'finding_hash' => 'sha256:afdf_unknown_owner',
            'owner_candidate' => 'external_robot',
            'title' => 'Change a runtime with an unmappable owner',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'],
            'evidence_refs' => ['expected_test:AutonomousEvolutionSessionServiceTest.php'],
            'spec_seed' => ['candidate_id' => 'afdf_unknown_owner'],
        ]);

        $this->assertSame(FindingSlicePlannerService::STATUS_BLOCKED, $plan['decomposition_status']);
        $this->assertContains(
            FindingSlicePlannerService::BLOCKER_PROVIDER_FIT_UNKNOWN,
            $plan['blockers'],
        );
    }

    public function test_blocks_evidence_obligations_missing_without_finding_identity(): void
    {
        $plan = $this->plan([
            'finding_id' => '',
            'finding_hash' => '',
            'title' => 'Change a runtime with no stable identity',
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'],
            'evidence_refs' => ['expected_test:AutonomousEvolutionSessionServiceTest.php'],
            'spec_seed' => [],
        ]);

        $this->assertSame(FindingSlicePlannerService::STATUS_BLOCKED, $plan['decomposition_status']);
        $this->assertContains(
            FindingSlicePlannerService::BLOCKER_EVIDENCE_OBLIGATIONS_MISSING,
            $plan['blockers'],
        );
    }

    public function test_slice_carries_all_required_executable_slice_fields(): void
    {
        $plan = $this->plan();

        $this->assertSame(FindingSlicePlannerService::STATUS_SLICED, $plan['decomposition_status']);
        $slice = $plan['slices'][0];

        foreach ([
            'schema_version', 'slice_id', 'sequence', 'owner', 'risk_level', 'objective',
            'allowed_files', 'forbidden_files', 'expected_diff_shape', 'validation_commands',
            'evidence_obligations', 'provider_fit', 'max_runtime_seconds', 'retry_policy',
            'merge_policy', 'success_condition',
        ] as $field) {
            $this->assertArrayHasKey($field, $slice, "slice missing field: {$field}");
        }

        $this->assertSame(FindingSlicePlannerService::SLICE_SCHEMA, $slice['schema_version']);
        $this->assertContains('.env', $slice['forbidden_files']);
        $this->assertContains('vendor/', $slice['forbidden_files']);
        $this->assertIsInt($slice['max_runtime_seconds']);
        $this->assertGreaterThan(0, $slice['max_runtime_seconds']);
        $this->assertIsArray($slice['provider_fit']);
        $this->assertSame('atlas_decide', $slice['provider_fit']['source']);
    }

    public function test_balanced_scope_does_not_apply_docs_only_churn_block(): void
    {
        // The docs-only churn rejection is a factory_max policy; in balanced scope
        // a docs slice that has a docs-health validation is allowed.
        $plan = $this->plan([
            'finding_id' => 'afdf_docs_balanced',
            'finding_hash' => 'sha256:afdf_docs_balanced',
            'title' => 'Tidy a guide doc',
            'kind' => 'doc',
            'affected_files' => [],
            'affected_docs' => ['docs/engineering-knowledge-base/some-guide.md'],
            'evidence_refs' => [],
            'spec_seed' => ['candidate_id' => 'afdf_docs_balanced'],
        ], FindingSlicePlannerService::SCOPE_BALANCED);

        $this->assertSame(FindingSlicePlannerService::STATUS_SLICED, $plan['decomposition_status']);
        $this->assertSame(FindingSlicePlannerService::OWNER_STEWARDSHIP, $plan['slices'][0]['owner']);
    }
}
