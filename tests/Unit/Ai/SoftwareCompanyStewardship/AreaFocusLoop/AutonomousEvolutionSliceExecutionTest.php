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
        $plan['slices'][0]['allowed_files'] = [
            'app/Services/Ai/AgenticEngineeringOs/UnmaterializedRealityCompilerSlice.php',
            'tests/Unit/Ai/AgenticEngineeringOs/UnmaterializedRealityCompilerSliceTest.php',
        ];
        $method = new ReflectionMethod($this->sessionService(), 'firstSemanticSlice');
        $slice = $method->invoke($this->sessionService(), $plan);

        $this->assertIsArray($slice);
        $this->assertSame('semantic_step:contract', $slice['decomposition']);
        $this->assertSame(1, $slice['sequence']);
    }

    public function test_finding_is_rewritten_to_only_the_first_bounded_slice(): void
    {
        $plan = $this->planForBigFinding();
        $plan['slices'][0]['allowed_files'] = [
            'app/Services/Ai/AgenticEngineeringOs/UnmaterializedRealityCompilerSlice.php',
            'tests/Unit/Ai/AgenticEngineeringOs/UnmaterializedRealityCompilerSliceTest.php',
        ];
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

    public function test_slice_progression_advances_to_the_next_pending_slice(): void
    {
        // AP-806 slice-progression: with slice 1 (contract) already merged, the next
        // cycle picks slice 2 (skeleton) — never re-doing step 1.
        $plan = $this->planForBigFinding();
        $session = $this->sessionService();
        $slices = array_values(array_filter((array) ($plan['slices'] ?? []), 'is_array'));
        $this->assertGreaterThanOrEqual(2, count($slices), 'a big finding must decompose into >=2 ordered slices');
        $slice1Id = (string) $slices[0]['slice_id'];

        $next = (new ReflectionMethod($session, 'firstSemanticSlice'))->invoke($session, $plan, [$slice1Id => true]);
        $this->assertIsArray($next);
        $this->assertSame(2, $next['sequence']);
        $this->assertNotSame($slice1Id, (string) $next['slice_id']);
    }

    public function test_no_pending_slice_remains_when_all_slices_merged(): void
    {
        // When every semantic slice merged, firstSemanticSlice returns null so
        // runCycle finalizes (review-locks) the parent instead of re-running it.
        $plan = $this->planForBigFinding();
        $session = $this->sessionService();
        $completed = [];
        foreach ((array) ($plan['slices'] ?? []) as $slice) {
            if (is_array($slice) && str_starts_with((string) ($slice['decomposition'] ?? ''), 'semantic_step:')) {
                $completed[(string) $slice['slice_id']] = true;
            }
        }
        $next = (new ReflectionMethod($session, 'firstSemanticSlice'))->invoke($session, $plan, $completed);
        $this->assertNull($next);
    }

    public function test_completed_semantic_slice_ids_are_read_from_the_durable_record(): void
    {
        // A prior cycle that MERGED slice 1 must be recoverable across cycles/runs
        // from the session record so the next cycle advances. Failed/blocked slices
        // must NOT count as completed.
        $dir = sys_get_temp_dir().'/atlas_ap806_slicerec_'.uniqid('', true);
        $session = $this->sessionService();
        $session->setStorageDirForTesting($dir);
        $path = $session->recordPath('agentic_engineering_os');
        @mkdir(dirname($path), 0777, true);
        $record = [
            'area_id' => 'agentic_engineering_os',
            'cycles' => [
                ['final_status' => 'cycle_completed', 'selected_finding' => ['finding_id' => 'p', 'active_slice_id' => 'mas_done_1']],
                ['final_status' => 'blocked', 'selected_finding' => ['finding_id' => 'p', 'active_slice_id' => 'mas_failed_2']],
            ],
        ];
        file_put_contents($path, json_encode($record).PHP_EOL);

        $completed = (new ReflectionMethod($session, 'completedSemanticSliceIds'))->invoke($session, 'agentic_engineering_os');
        $this->assertArrayHasKey('mas_done_1', $completed);
        $this->assertArrayNotHasKey('mas_failed_2', $completed, 'a failed slice is not completed and must be retried');

        @unlink($path);
        @rmdir(dirname($path));
    }

    public function test_finding_keys_are_slice_scoped_so_a_completed_slice_does_not_lock_the_parent(): void
    {
        $session = $this->sessionService();
        $sliceKeys = (new ReflectionMethod($session, 'findingKeys'))->invoke($session, [
            'finding_id' => 'afdf_reality_compiler',
            'finding_hash' => 'sha256:afdf_reality_compiler',
            'title' => 'Introduce Reality Compiler slices for intent-to-system execution',
            'active_slice_id' => 'mas_slice_xyz',
        ]);
        $this->assertSame(['mas_slice_xyz'], $sliceKeys, 'a sliced finding locks ONLY its slice');

        // The parent (no active_slice_id) keeps its own keys, so it stays selectable.
        $parentKeys = (new ReflectionMethod($session, 'findingKeys'))->invoke($session, [
            'finding_id' => 'afdf_reality_compiler',
            'finding_hash' => 'sha256:afdf_reality_compiler',
            'title' => 'Introduce Reality Compiler slices for intent-to-system execution',
        ]);
        $this->assertNotContains('mas_slice_xyz', $parentKeys);
        $this->assertContains('afdf_reality_compiler', $parentKeys);
    }
}
