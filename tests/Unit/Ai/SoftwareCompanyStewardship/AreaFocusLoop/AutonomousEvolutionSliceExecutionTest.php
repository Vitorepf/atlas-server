<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AP-806: the loop must execute ONLY the first small semantic step, never the
 * big finding. These pin the runCycle override that narrows the owner-runtime
 * finding to the first decomposed slice.
 */
final class AutonomousEvolutionSliceExecutionTest extends TestCase
{
    private function sessionService(): AutonomousEvolutionSessionService
    {
        return app(AutonomousEvolutionSessionService::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function planForBigFinding(): array
    {
        return app(FindingSlicePlannerService::class)->plan([
            'finding' => [
                'finding_id' => 'afdf_reality_compiler',
                'finding_hash' => 'sha256:afdf_reality_compiler',
                'title' => 'Introduce Reality Compiler slices for intent-to-system execution',
                'detail' => 'Implement the Reality Compiler capability.',
                'kind' => 'feature',
                'origin_type' => 'structural',
                'severity' => 'medium',
                'owner_candidate' => 'atlas_dev',
                'affected_files' => ['app/Services/Ai/AgenticEngineeringOs/AutonomousWorkExecutionOs.php'],
                'evidence_refs' => ['expected_test:AutonomousWorkExecutionOsTest.php'],
                'spec_seed' => ['candidate_id' => 'afdf_reality_compiler'],
            ],
            'mode' => FindingSlicePlannerService::MODE_RECORD,
            'scope_profile' => FindingSlicePlannerService::SCOPE_FACTORY_MAX,
        ]);
    }

    public function test_first_semantic_slice_is_the_contract_step(): void
    {
        $plan = $this->planForBigFinding();
        $method = new ReflectionMethod($this->sessionService(), 'firstSemanticSlice');
        $slice = $method->invoke($this->sessionService(), $plan);

        $this->assertIsArray($slice);
        $this->assertSame('semantic_step:contract', $slice['decomposition']);
        $this->assertSame(1, $slice['sequence']);
    }

    public function test_finding_is_rewritten_to_only_the_first_bounded_slice(): void
    {
        $plan = $this->planForBigFinding();
        $session = $this->sessionService();
        $firstSlice = (new ReflectionMethod($session, 'firstSemanticSlice'))->invoke($session, $plan);

        $finding = [
            'finding_id' => 'afdf_reality_compiler',
            'finding_hash' => 'sha256:afdf_reality_compiler',
            'title' => 'Introduce Reality Compiler slices for intent-to-system execution',
            'detail' => 'Implement the whole Reality Compiler capability.',
            'proposed_next_action' => 'Implement everything.',
            'affected_files' => ['app/Services/Ai/AgenticEngineeringOs/AutonomousWorkExecutionOs.php'],
        ];
        $rewritten = (new ReflectionMethod($session, 'applySemanticSliceToFinding'))->invoke($session, $finding, $firstSlice);

        // Identity preserved (tracking/review-lock still work on the parent).
        $this->assertSame('afdf_reality_compiler', $rewritten['finding_id']);
        $this->assertSame('sha256:afdf_reality_compiler', $rewritten['finding_hash']);

        // The owner runtime now sees ONLY the bounded step, not the whole feature.
        $this->assertStringContainsString('execute ONLY this step', $rewritten['title']);
        $this->assertStringNotContainsString('whole Reality Compiler', $rewritten['detail']);
        $this->assertStringContainsString('STEP 1 of 3', $rewritten['detail']);
        $this->assertSame('', $rewritten['proposed_next_action']);
        $this->assertSame($firstSlice['allowed_files'], $rewritten['affected_files']);
        $this->assertSame($firstSlice['slice_id'], $rewritten['active_slice_id']);
    }

    public function test_override_is_inert_without_semantic_decomposition(): void
    {
        // A plan with no semantic step (e.g. a plain blocked plan) returns null,
        // so the loop keeps its existing finding unchanged.
        $session = $this->sessionService();
        $slice = (new ReflectionMethod($session, 'firstSemanticSlice'))->invoke($session, ['decomposition_status' => 'blocked', 'slices' => []]);
        $this->assertNull($slice);
    }
}
