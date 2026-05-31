<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\BuildPlanDecomposerService;
use PHPUnit\Framework\TestCase;

final class BuildPlanDecomposerAtomicBacklogScopeTest extends TestCase
{
    /**
     * @param  list<array{0:string,1:string,2:string,3:string}>  $rows
     */
    private function markdown(array $rows): string
    {
        $table = "| Slice | Entrega | Aceite | Guarda |\n| --- | --- | --- | --- |\n";
        foreach ($rows as $row) {
            $table .= "| {$row[0]} | {$row[1]} | {$row[2]} | {$row[3]} |\n";
        }

        return "---\nid: PLAN-ATOMIC-SCOPE\ntitle: Atomic Scope\n---\n\n"
            ."## 6. Decomposicao em slices ordenados\n\n{$table}\n"
            ."## 10. Sequenciamento\n\nS49\n";
    }

    /**
     * @param  list<array<string,mixed>>  $captured
     */
    private function service(array &$captured): BuildPlanDecomposerService
    {
        $service = new BuildPlanDecomposerService;
        $service->setSlicePlannerCallableForTesting(static function (array $input) use (&$captured): array {
            $captured[] = $input;
            $allowedFiles = array_values((array) ($input['finding']['affected_files'] ?? []));

            return [
                'schema_version' => FindingSlicePlannerService::PLAN_SCHEMA,
                'finding_id' => (string) ($input['finding']['finding_id'] ?? ''),
                'decomposition_status' => FindingSlicePlannerService::STATUS_SLICED,
                'slices' => [[
                    'schema_version' => FindingSlicePlannerService::SLICE_SCHEMA,
                    'slice_id' => 'fake_slice_1',
                    'sequence' => 1,
                    'allowed_files' => $allowedFiles,
                    'owner' => FindingSlicePlannerService::OWNER_ATLAS_DEV,
                ]],
                'blockers' => [],
            ];
        });

        return $service;
    }

    public function test_new_class_slice_ignores_existing_src_provenance_when_deriving_scope(): void
    {
        $captured = [];
        $delivery = "Create a new PHP class SpecCompletenessScorer at app/Services/Ai/Aaeos/Cores/SpecCompletenessScorer.php with ONE method score(array \$spec): array; self-contained, no edits to existing code [area=aaeos route=atlas_dev status=ready src=AtlasMemoryGovernanceService.php:233]";
        $acceptance = 'New unit test SpecCompletenessScorerTest passes with computed assertions.';

        $this->service($captured)->decompose([
            'build_plan_md' => $this->markdown([['S49', $delivery, $acceptance, 'atlas_dev']]),
        ]);

        $this->assertSame([
            'app/Services/Ai/Aaeos/Cores/SpecCompletenessScorer.php',
            'tests/Unit/Ai/Aaeos/Cores/SpecCompletenessScorerTest.php',
        ], $captured[0]['finding']['affected_files']);
    }

    public function test_new_class_slice_uses_explicit_paired_test_without_adding_duplicate_conventional_test(): void
    {
        $captured = [];
        $delivery = "Create a new PHP class FooDecision at app/Services/Ai/Aaeos/FooDecision.php with ONE method decide(array \$input): array; self-contained [area=aaeos route=atlas_dev status=ready src=SomeExistingService.php:42]";
        $acceptance = 'Create tests/Unit/Services/Ai/Aaeos/FooDecisionTest.php with five computed assertions.';

        $this->service($captured)->decompose([
            'build_plan_md' => $this->markdown([['S49', $delivery, $acceptance, 'atlas_dev']]),
        ]);

        $this->assertSame([
            'app/Services/Ai/Aaeos/FooDecision.php',
            'tests/Unit/Services/Ai/Aaeos/FooDecisionTest.php',
        ], $captured[0]['finding']['affected_files']);
        $this->assertNotContains('tests/Unit/Ai/Aaeos/FooDecisionTest.php', $captured[0]['finding']['affected_files']);
    }

    public function test_new_final_class_slice_ignores_named_existing_contract_dependencies(): void
    {
        $captured = [];
        $delivery = "Create a new PHP class ProviderProofAttributionScorer (final) at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Attribution/ProviderProofAttributionScorer.php, namespace App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\Attribution, computed over ProviderAndModelAttributionPerLaneContract::LANE_ROLES; no edits to existing code [area=aaeos route=atlas_dev status=ready src=ProviderAndModelAttributionPerLaneContract.php:18]";
        $acceptance = 'New test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Attribution/ProviderProofAttributionScorerTest.php passes.';

        $this->service($captured)->decompose([
            'build_plan_md' => $this->markdown([['S49', $delivery, $acceptance, 'atlas_dev']]),
        ]);

        $this->assertSame([
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Attribution/ProviderProofAttributionScorer.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Attribution/ProviderProofAttributionScorerTest.php',
        ], $captured[0]['finding']['affected_files']);
        $this->assertNotContains(
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ProviderAndModelAttributionPerLaneContract.php',
            $captured[0]['finding']['affected_files'],
        );
    }

    public function test_route_metadata_wins_over_canonical_words_in_delivery_text(): void
    {
        $captured = [];
        $delivery = "Create a new PHP class DestructiveTestCoverageRemovalContract at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DestructiveTestCoverageRemovalContract.php returning quality_bar_matrix_canonical:string; no docs-only work [area=aaeos route=atlas_dev status=ready src=AtlasMinimaxFirstWorkerService.php:407]";
        $acceptance = 'New paired test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DestructiveTestCoverageRemovalContractTest.php passes.';

        $plan = $this->service($captured)->decompose([
            'build_plan_md' => $this->markdown([['S262', $delivery, $acceptance, 'atlas_dev']]),
        ]);

        $this->assertSame('atlas_dev', $plan['slices'][0]['owner']);
        $this->assertSame('atlas_dev', $captured[0]['finding']['owner_candidate']);
    }
}
