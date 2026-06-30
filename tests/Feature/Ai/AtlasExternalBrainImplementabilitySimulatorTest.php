<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImplementabilitySimulator;
use Tests\TestCase;

final class AtlasExternalBrainImplementabilitySimulatorTest extends TestCase
{
    private AtlasExternalBrainImplementabilitySimulator $sim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sim = new AtlasExternalBrainImplementabilitySimulator;
    }

    private function strongCandidate(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id'      => 'feat-task-01',
            'objective'           => 'Implement AtlasExternalBrainOrganHealthScorer to score organ health',
            'allowed_files'       => [
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorer.php',
                'tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorerTest.php',
            ],
            'acceptance_criteria' => [
                'php artisan test tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorerTest.php exits 0',
                'AtlasExternalBrainOrganHealthScorer::score() returns health_score between 0 and 1',
            ],
        ], $overrides);
    }

    // ── AC1: missing impl file, test-only scope, forbidden target → reject with reasons

    public function test_ac1_test_only_scope_with_missing_impl_is_rejected_with_reasons(): void
    {
        $result = $this->sim->simulate(
            $this->strongCandidate([
                'allowed_files' => ['tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorerTest.php'],
            ]),
            [
                'missing_prod_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorer.php'],
            ],
        );

        $this->assertNotSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_ENQUEUEABLE, $result['verdict']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertTrue(
            array_reduce($result['reasons'], fn (bool $c, string $r) => $c || str_contains($r, 'test_without_impl'), false),
            'reasons must mention test_without_impl'
        );
    }

    public function test_ac1_forbidden_target_is_rejected_with_reasons(): void
    {
        $forbiddenFile = 'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorer.php';

        $result = $this->sim->simulate(
            $this->strongCandidate(),
            ['forbidden_files' => [$forbiddenFile]],
        );

        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_CONTRADICTORY, $result['verdict']);
        $this->assertNotEmpty($result['reasons']);
        $forbiddenReasons = array_filter($result['reasons'], fn ($r) => str_contains($r, 'forbidden_target'));
        $this->assertNotEmpty($forbiddenReasons);
    }

    public function test_ac1_multiple_forbidden_targets_each_appear_in_reasons(): void
    {
        $files = $this->strongCandidate()['allowed_files'];

        $result = $this->sim->simulate(
            $this->strongCandidate(),
            ['forbidden_files' => $files],
        );

        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_CONTRADICTORY, $result['verdict']);
        foreach ($files as $file) {
            $hit = array_filter($result['reasons'], fn ($r) => str_contains($r, $file));
            $this->assertNotEmpty($hit, "Forbidden target {$file} must appear in reasons");
        }
    }

    public function test_ac1_result_always_includes_reasons_array(): void
    {
        $result = $this->sim->simulate(
            $this->strongCandidate([
                'allowed_files' => ['tests/Unit/SomeTest.php'],
            ]),
            ['missing_prod_files' => ['app/Services/Foo.php']],
        );

        $this->assertArrayHasKey('reasons', $result);
        $this->assertIsArray($result['reasons']);
        $this->assertNotEmpty($result['reasons']);
    }

    // ── AC2: strong candidate with impl+test files and runnable acceptance passes

    public function test_ac2_strong_candidate_is_enqueueable(): void
    {
        $result = $this->sim->simulate($this->strongCandidate());

        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_ENQUEUEABLE, $result['verdict']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_ac2_candidate_with_impl_and_test_file_passes_in_clean_context(): void
    {
        $result = $this->sim->simulate(
            $this->strongCandidate(),
            [
                'implemented_files'   => [],
                'active_allowed_files' => [],
                'pending_task_ids'    => [],
                'forbidden_files'     => [],
            ],
        );

        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_ENQUEUEABLE, $result['verdict']);
    }

    public function test_ac2_output_has_schema_verdict_reasons_candidate_id(): void
    {
        $result = $this->sim->simulate($this->strongCandidate());

        $this->assertArrayHasKey('schema',       $result);
        $this->assertArrayHasKey('verdict',      $result);
        $this->assertArrayHasKey('reasons',      $result);
        $this->assertArrayHasKey('candidate_id', $result);
        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::SCHEMA, $result['schema']);
        $this->assertSame('feat-task-01', $result['candidate_id']);
    }

    // ── AC3: high dependency or collision risk → repair_required, not false approval

    public function test_ac3_high_collision_risk_produces_repair_required(): void
    {
        $result = $this->sim->simulate(
            $this->strongCandidate([
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasA.php',
                    'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasB.php',
                    'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasC.php',
                ],
            ]),
            [
                'active_allowed_files' => [
                    'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasA.php',
                    'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasB.php',
                    'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasC.php',
                ],
            ],
        );

        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_REPAIR_REQUIRED, $result['verdict']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertContains('multi_file_collision_risk', $result['reasons']);
    }

    public function test_ac3_high_dependency_risk_produces_repair_required(): void
    {
        $result = $this->sim->simulate(
            $this->strongCandidate(['unblocked_by' => ['task-A', 'task-B', 'task-C']]),
            ['pending_task_ids' => ['task-A', 'task-B', 'task-C']],
        );

        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_REPAIR_REQUIRED, $result['verdict']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertContains('high_dependency_risk', $result['reasons']);
    }

    public function test_ac3_single_collision_is_still_collision_not_repair_required(): void
    {
        $result = $this->sim->simulate(
            $this->strongCandidate(),
            [
                'active_allowed_files' => [
                    'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorer.php',
                ],
            ],
        );

        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_COLLISION, $result['verdict']);
    }

    public function test_ac3_repair_required_reasons_list_all_problematic_files(): void
    {
        $files = [
            'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasA.php',
            'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasB.php',
            'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasC.php',
        ];

        $result = $this->sim->simulate(
            $this->strongCandidate(['allowed_files' => $files]),
            ['active_allowed_files' => $files],
        );

        $this->assertSame(AtlasExternalBrainImplementabilitySimulator::VERDICT_REPAIR_REQUIRED, $result['verdict']);
        foreach ($files as $file) {
            $hit = array_filter($result['reasons'], fn ($r) => str_contains($r, $file));
            $this->assertNotEmpty($hit, "Colliding file {$file} must appear in reasons");
        }
    }

    // ── AC4: pure and deterministic

    public function test_ac4_same_input_produces_identical_output(): void
    {
        $candidate = $this->strongCandidate();
        $context   = ['forbidden_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasBanned.php']];

        $this->assertSame(
            $this->sim->simulate($candidate, $context),
            $this->sim->simulate($candidate, $context),
        );
    }
}
