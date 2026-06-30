<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdversarialSpecReviewBoard;
use Tests\TestCase;

final class AtlasExternalBrainAdversarialSpecReviewBoardTest extends TestCase
{
    private AtlasExternalBrainAdversarialSpecReviewBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->board = new AtlasExternalBrainAdversarialSpecReviewBoard;
    }

    private function concreteSpec(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'feat-task-01',
            'objective'      => 'Implement AtlasExternalBrainOrganHealthScorer to score organ health and emit actionable degradation alerts',
            'allowed_files'  => [
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorer.php',
                'tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorerTest.php',
            ],
            'acceptance_criteria' => [
                'The runnable test proves AtlasExternalBrainOrganHealthScorer::score() returns a numeric health_score and alert list',
                'php artisan test tests/Unit/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainOrganHealthScorerTest.php exits 0',
            ],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
        ], $overrides);
    }

    // ── AC1: low-evidence specs are rejected with lens_results and repair_hints

    public function test_ac1_spec_without_required_evidence_is_rejected_with_repair_hints(): void
    {
        $result = $this->board->review($this->concreteSpec(['required_evidence' => []]));

        $this->assertFalse($result['approved']);
        $this->assertNotEmpty($result['lens_results']);
        $this->assertNotEmpty($result['repair_hints']);

        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_IMPLEMENTABILITY);
        $this->assertFalse($lens['passed']);
        $this->assertContains('required_evidence_empty', $lens['reasons']);
        $this->assertNotEmpty($lens['repair_hints']);
    }

    public function test_ac1_spec_with_weak_acceptance_criteria_is_rejected_with_repair_hints(): void
    {
        $result = $this->board->review($this->concreteSpec([
            'acceptance_criteria' => ['The feature should be good and make Atlas better overall'],
        ]));

        $this->assertFalse($result['approved']);
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);
        $this->assertFalse($lens['passed']);
        $this->assertContains('acceptance_criteria_lack_measurable_outcome', $lens['reasons']);
        $this->assertNotEmpty($lens['repair_hints']);
        $this->assertNotEmpty($result['repair_hints']);
    }

    public function test_ac1_template_like_spec_is_rejected_with_repair_hints(): void
    {
        $result = $this->board->review($this->concreteSpec([
            'objective' => 'Implement [service_name] to add capability to the Atlas system',
        ]));

        $this->assertFalse($result['approved']);
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);
        $this->assertFalse($lens['passed']);
        $templateReasons = array_filter($lens['reasons'], fn ($r) => str_contains($r, 'template_placeholder'));
        $this->assertNotEmpty($templateReasons);
        $this->assertNotEmpty($lens['repair_hints']);
        $this->assertNotEmpty($result['repair_hints']);
    }

    public function test_ac1_duplicate_objective_spec_is_rejected_with_repair_hints(): void
    {
        $existingObjective = 'Implement AtlasExternalBrainOrganHealthScorer to score organ health and emit actionable degradation alerts';

        $result = $this->board->review($this->concreteSpec([
            'known_spec_objectives' => [$existingObjective],
        ]));

        $this->assertFalse($result['approved']);
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);
        $this->assertFalse($lens['passed']);
        $this->assertContains('duplicate_objective_already_in_queue', $lens['reasons']);
        $this->assertNotEmpty($lens['repair_hints']);
        $this->assertNotEmpty($result['repair_hints']);
    }

    public function test_ac1_template_placeholder_in_curly_braces_is_rejected(): void
    {
        $result = $this->board->review($this->concreteSpec([
            'objective' => 'Implement {{ service_class }} to add new Atlas capability',
        ]));

        $this->assertFalse($result['approved']);
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);
        $templateReasons = array_filter($lens['reasons'], fn ($r) => str_contains($r, 'template_placeholder'));
        $this->assertNotEmpty($templateReasons);
    }

    // ── AC2: concrete high-leverage spec is approved

    public function test_ac2_concrete_high_leverage_spec_with_impl_and_test_scope_is_approved(): void
    {
        $result = $this->board->review($this->concreteSpec());

        $this->assertTrue($result['approved']);
        $this->assertSame([], $result['repair_hints']);

        foreach ($result['lens_results'] as $lens) {
            $this->assertTrue($lens['passed'], "Lens {$lens['lens']} must pass for a concrete spec");
        }
    }

    public function test_ac2_approved_spec_has_schema_and_task_packet_id_in_output(): void
    {
        $result = $this->board->review($this->concreteSpec());

        $this->assertSame(AtlasExternalBrainAdversarialSpecReviewBoard::SCHEMA, $result['schema']);
        $this->assertSame('feat-task-01', $result['task_packet_id']);
        $this->assertTrue($result['approved']);
    }

    public function test_ac2_duplicate_check_does_not_fire_when_known_specs_differ(): void
    {
        $result = $this->board->review($this->concreteSpec([
            'known_spec_objectives' => ['Implement a completely different AtlasThing to do something else'],
        ]));

        $this->assertTrue($result['approved']);
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);
        $this->assertTrue($lens['passed']);
    }

    // ── AC3: every failed lens returns actionable (non-generic) repair hints

    public function test_ac3_implementability_failure_returns_actionable_hints(): void
    {
        $result = $this->board->review($this->concreteSpec(['allowed_files' => []]));
        $lens   = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_IMPLEMENTABILITY);

        $this->assertFalse($lens['passed']);
        foreach ($lens['repair_hints'] as $hint) {
            $this->assertStringNotContainsString('generic_rejection', $hint);
            $this->assertGreaterThan(10, strlen($hint), "Hint '{$hint}' is too short to be actionable");
        }
    }

    public function test_ac3_leverage_failure_returns_actionable_hints(): void
    {
        $result = $this->board->review($this->concreteSpec([
            'objective' => 'The codebase needs improvement',
        ]));
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);

        $this->assertFalse($lens['passed']);
        foreach ($lens['repair_hints'] as $hint) {
            $this->assertStringNotContainsString('generic_rejection', $hint);
            $this->assertGreaterThan(10, strlen($hint));
        }
    }

    public function test_ac3_anti_proxy_failure_returns_actionable_hints(): void
    {
        $result = $this->board->review($this->concreteSpec([
            'objective' => 'Count how many orphan organs exist in the ExternalBrain module',
        ]));
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_ANTI_PROXY);

        $this->assertFalse($lens['passed']);
        foreach ($lens['repair_hints'] as $hint) {
            $this->assertStringNotContainsString('generic_rejection', $hint);
            $this->assertGreaterThan(10, strlen($hint));
        }
    }

    public function test_ac3_collision_safety_failure_returns_actionable_hints(): void
    {
        $result = $this->board->review($this->concreteSpec([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/*.php'],
        ]));
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);

        $this->assertFalse($lens['passed']);
        foreach ($lens['repair_hints'] as $hint) {
            $this->assertStringNotContainsString('generic_rejection', $hint);
            $this->assertGreaterThan(10, strlen($hint));
        }
    }

    public function test_ac3_autonomy_failure_returns_actionable_hints(): void
    {
        $result = $this->board->review($this->concreteSpec([
            'objective' => 'Implement organ scorer but requires approval before merging to main',
        ]));
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_STEADY_STATE_AUTONOMY);

        $this->assertFalse($lens['passed']);
        foreach ($lens['repair_hints'] as $hint) {
            $this->assertStringNotContainsString('generic_rejection', $hint);
            $this->assertGreaterThan(10, strlen($hint));
        }
    }

    public function test_ac3_template_failure_returns_concrete_replacement_hint(): void
    {
        $result = $this->board->review($this->concreteSpec([
            'objective' => 'Implement [class_name] to add capability',
        ]));
        $lens = $this->findLens($result, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);

        $this->assertFalse($lens['passed']);
        $templateHints = array_filter($lens['repair_hints'], fn ($h) => str_contains($h, 'template_placeholder'));
        $this->assertNotEmpty($templateHints);
    }

    // ── AC4: pure and deterministic — no side effects

    public function test_ac4_same_input_produces_identical_output(): void
    {
        $spec = $this->concreteSpec();

        $this->assertSame(
            $this->board->review($spec),
            $this->board->review($spec),
        );
    }

    public function test_ac4_calling_twice_does_not_mutate_state(): void
    {
        $approved   = $this->concreteSpec();
        $rejected   = $this->concreteSpec(['required_evidence' => []]);

        $r1 = $this->board->review($approved);
        $r2 = $this->board->review($rejected);
        $r3 = $this->board->review($approved);

        $this->assertTrue($r1['approved']);
        $this->assertFalse($r2['approved']);
        $this->assertTrue($r3['approved'], 'second approved review must not be tainted by rejected review');
    }

    // ── helper ────────────────────────────────────────────────────────────────

    private function findLens(array $result, string $lensName): array
    {
        foreach ($result['lens_results'] as $lens) {
            if ($lens['lens'] === $lensName) {
                return $lens;
            }
        }
        $this->fail("Lens '{$lensName}' not found in result");
    }
}
