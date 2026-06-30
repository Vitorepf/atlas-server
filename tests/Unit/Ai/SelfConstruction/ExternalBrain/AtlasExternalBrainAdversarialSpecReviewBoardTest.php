<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdversarialSpecReviewBoard;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAdversarialSpecReviewBoardTest extends TestCase
{
    private AtlasExternalBrainAdversarialSpecReviewBoard $board;

    protected function setUp(): void
    {
        $this->board = new AtlasExternalBrainAdversarialSpecReviewBoard;
    }

    private function strongSpec(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'test-task-01',
            'objective' => 'Implement AtlasContractDriftDetector to detect interface implementation drift at CI time',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AtlasContractDriftDetector.php',
                'tests/Unit/Ai/SelfConstruction/AtlasContractDriftDetectorTest.php',
            ],
            'acceptance_criteria' => [
                'The runnable test proves AtlasContractDriftDetector::detect() returns drift entries when interfaces diverge',
                'php artisan atlas:ci:contract-check exits 1 when drift exists',
            ],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
        ], $overrides);
    }

    // ── happy path: strong spec passes all 5 lenses ───────────────────────────

    public function test_strong_spec_is_approved_with_all_five_lenses_passing(): void
    {
        $r = $this->board->review($this->strongSpec());

        $this->assertSame(AtlasExternalBrainAdversarialSpecReviewBoard::SCHEMA, $r['schema']);
        $this->assertTrue($r['approved'], 'strong spec must be approved');
        $this->assertCount(5, $r['lens_results']);
        foreach ($r['lens_results'] as $lens) {
            $this->assertTrue($lens['passed'], "lens {$lens['lens']} must pass for a strong spec");
            $this->assertSame([], $lens['reasons']);
        }
        $this->assertSame([], $r['repair_hints']);
    }

    public function test_output_always_has_five_named_lenses(): void
    {
        $r = $this->board->review($this->strongSpec());
        $lensNames = array_column($r['lens_results'], 'lens');

        $this->assertContains(AtlasExternalBrainAdversarialSpecReviewBoard::LENS_IMPLEMENTABILITY, $lensNames);
        $this->assertContains(AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE, $lensNames);
        $this->assertContains(AtlasExternalBrainAdversarialSpecReviewBoard::LENS_ANTI_PROXY, $lensNames);
        $this->assertContains(AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY, $lensNames);
        $this->assertContains(AtlasExternalBrainAdversarialSpecReviewBoard::LENS_STEADY_STATE_AUTONOMY, $lensNames);
    }

    // ── lens 1: implementability ──────────────────────────────────────────────

    public function test_implementability_fails_when_allowed_files_empty(): void
    {
        $r = $this->board->review($this->strongSpec(['allowed_files' => []]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_IMPLEMENTABILITY);
        $this->assertFalse($lens['passed']);
        $this->assertContains('allowed_files_empty', $lens['reasons']);
        $this->assertFalse($r['approved']);
        $this->assertNotEmpty($r['repair_hints']);
    }

    public function test_implementability_fails_when_acceptance_criteria_empty(): void
    {
        $r = $this->board->review($this->strongSpec(['acceptance_criteria' => []]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_IMPLEMENTABILITY);
        $this->assertFalse($lens['passed']);
        $this->assertContains('acceptance_criteria_empty', $lens['reasons']);
    }

    public function test_implementability_fails_when_required_evidence_empty(): void
    {
        $r = $this->board->review($this->strongSpec(['required_evidence' => []]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_IMPLEMENTABILITY);
        $this->assertFalse($lens['passed']);
        $this->assertContains('required_evidence_empty', $lens['reasons']);
    }

    public function test_implementability_fails_for_bare_directory_in_allowed_files(): void
    {
        $r = $this->board->review($this->strongSpec([
            'allowed_files' => ['app/Services/Ai/SelfConstruction'],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_IMPLEMENTABILITY);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_starts_with($r, 'bare_directory')));
    }

    // ── lens 2: leverage ──────────────────────────────────────────────────────

    public function test_leverage_fails_when_objective_is_cosmetic(): void
    {
        $r = $this->board->review($this->strongSpec(['objective' => 'Fix typo in the README file']));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_contains($r, 'cosmetic_proxy')));
    }

    public function test_leverage_fails_when_objective_has_no_capability_verb(): void
    {
        $r = $this->board->review($this->strongSpec([
            'objective' => 'The contract drift situation in the codebase is concerning',
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);
        $this->assertFalse($lens['passed']);
        $this->assertContains('objective_lacks_capability_verb', $lens['reasons']);
    }

    public function test_leverage_fails_when_criteria_lack_measurable_outcome(): void
    {
        $r = $this->board->review($this->strongSpec([
            'acceptance_criteria' => ['The feature should make Atlas better overall'],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);
        $this->assertFalse($lens['passed']);
        $this->assertContains('acceptance_criteria_lack_measurable_outcome', $lens['reasons']);
    }

    // ── lens 3: anti-proxy ────────────────────────────────────────────────────

    public function test_anti_proxy_fails_when_objective_is_metric_report(): void
    {
        $r = $this->board->review($this->strongSpec([
            'objective' => 'Count how many drift events exist in the codebase',
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_ANTI_PROXY);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_contains($r, 'metric_proxy')));
    }

    public function test_anti_proxy_fails_when_allowed_files_are_only_tests(): void
    {
        $r = $this->board->review($this->strongSpec([
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/AtlasFooTest.php'],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_ANTI_PROXY);
        $this->assertFalse($lens['passed']);
        $this->assertContains('allowed_files_contain_only_test_files_no_impl', $lens['reasons']);
    }

    public function test_anti_proxy_fails_when_criteria_demand_cleanup(): void
    {
        $r = $this->board->review($this->strongSpec([
            'acceptance_criteria' => ['Remove dead code from the drift detector module'],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_ANTI_PROXY);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_contains($r, 'cleanup_proxy')));
    }

    // ── lens 4: collision safety ──────────────────────────────────────────────

    public function test_collision_safety_fails_for_wildcard_in_allowed_files(): void
    {
        $r = $this->board->review($this->strongSpec([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/*.php'],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_contains($r, 'wildcard')));
    }

    public function test_collision_safety_fails_for_path_traversal(): void
    {
        $r = $this->board->review($this->strongSpec([
            'allowed_files' => ['app/../config/atlas.php'],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_contains($r, 'path_traversal')));
    }

    public function test_collision_safety_fails_for_high_contention_file(): void
    {
        $r = $this->board->review($this->strongSpec([
            'allowed_files' => ['app/Providers/AppServiceProvider.php'],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_contains($r, 'high_contention')));
    }

    // ── lens 5: steady-state autonomy ─────────────────────────────────────────

    public function test_autonomy_fails_when_objective_requires_operator_approval(): void
    {
        $r = $this->board->review($this->strongSpec([
            'objective' => 'Implement drift detector but requires approval before merging',
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_STEADY_STATE_AUTONOMY);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_contains($r, 'human_intervention')));
    }

    public function test_autonomy_fails_when_criteria_require_human_review(): void
    {
        $r = $this->board->review($this->strongSpec([
            'acceptance_criteria' => ['Human review confirms the drift report is correct'],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_STEADY_STATE_AUTONOMY);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_contains($r, 'human_step')));
    }

    public function test_autonomy_fails_when_objective_has_external_api_dependency(): void
    {
        $r = $this->board->review($this->strongSpec([
            'objective' => 'Implement drift detector that calls external api to verify contracts',
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_STEADY_STATE_AUTONOMY);
        $this->assertFalse($lens['passed']);
        $this->assertNotEmpty(array_filter($lens['reasons'], fn ($r) => str_contains($r, 'external_provider')));
    }

    // ── repair hints aggregated ───────────────────────────────────────────────

    public function test_repair_hints_aggregate_from_all_failed_lenses(): void
    {
        // Trigger implementability AND leverage failures simultaneously.
        $r = $this->board->review($this->strongSpec([
            'allowed_files' => [],
            'acceptance_criteria' => [],
        ]));

        $this->assertFalse($r['approved']);
        $this->assertGreaterThan(1, count($r['repair_hints']), 'hints from multiple lenses must aggregate');
        foreach ($r['repair_hints'] as $hint) {
            $this->assertIsString($hint);
            $this->assertNotEmpty($hint);
        }
    }

    public function test_repair_hints_are_deduplicated(): void
    {
        // allowed_files=[] triggers implementability hint; same hint should appear once even if lens fires twice.
        $r = $this->board->review($this->strongSpec(['allowed_files' => []]));

        $uniqueHints = array_unique($r['repair_hints']);
        $this->assertSame(count($uniqueHints), count($r['repair_hints']), 'repair_hints must not contain duplicates');
    }

    // ── each lens result has required keys ───────────────────────────────────

    public function test_each_lens_result_has_required_keys(): void
    {
        $r = $this->board->review($this->strongSpec());

        foreach ($r['lens_results'] as $lens) {
            $this->assertArrayHasKey('lens', $lens);
            $this->assertArrayHasKey('passed', $lens);
            $this->assertArrayHasKey('reasons', $lens);
            $this->assertArrayHasKey('repair_hints', $lens);
            $this->assertIsBool($lens['passed']);
            $this->assertIsArray($lens['reasons']);
            $this->assertIsArray($lens['repair_hints']);
        }
    }

    // ── AC1: collision_safety fails when file already live in queue ───────────

    public function test_collision_safety_fails_when_allowed_file_is_already_live(): void
    {
        $liveFile = 'app/Services/Ai/SelfConstruction/AtlasContractDriftDetector.php';
        $r = $this->board->review($this->strongSpec([
            'live_queued_targets' => [$liveFile],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);
        $this->assertFalse($lens['passed']);
        $liveReasons = array_filter($lens['reasons'], fn ($r) => str_contains($r, 'already_live_in_queue'));
        $this->assertNotEmpty($liveReasons);
        $this->assertFalse($r['approved']);
    }

    public function test_collision_safety_passes_when_no_file_is_live(): void
    {
        $r = $this->board->review($this->strongSpec([
            'live_queued_targets' => ['app/Services/Ai/SelfConstruction/SomeOtherService.php'],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);
        $this->assertTrue($lens['passed']);
    }

    public function test_collision_safety_passes_when_live_queued_targets_absent(): void
    {
        $r = $this->board->review($this->strongSpec());

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);
        $this->assertTrue($lens['passed']);
    }

    public function test_collision_safety_reports_all_colliding_files(): void
    {
        $r = $this->board->review($this->strongSpec([
            'live_queued_targets' => [
                'app/Services/Ai/SelfConstruction/AtlasContractDriftDetector.php',
                'tests/Unit/Ai/SelfConstruction/AtlasContractDriftDetectorTest.php',
            ],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_COLLISION_SAFETY);
        $liveReasons = array_filter($lens['reasons'], fn ($r) => str_contains($r, 'already_live_in_queue'));
        $this->assertCount(2, $liveReasons);
    }

    // ── AC2: leverage fails when runnable criteria lack target/outcome ─────────

    public function test_leverage_fails_when_runnable_criteria_lack_capability_outcome(): void
    {
        // criteria with --filter= but no capability words, no class name mention
        $r = $this->board->review($this->strongSpec([
            'acceptance_criteria' => [
                'php artisan test --filter=AtlasFooTest',
                'php artisan test --filter=AtlasFooBarTest',
            ],
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AtlasFoo.php',
                'tests/Unit/Ai/SelfConstruction/AtlasFooTest.php',
            ],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);
        $this->assertFalse($lens['passed']);
        $liveReasons = array_filter($lens['reasons'], fn ($r) => str_contains($r, 'runnable_criteria'));
        $this->assertNotEmpty($liveReasons);
    }

    public function test_leverage_passes_when_runnable_criteria_include_capability_statement(): void
    {
        $r = $this->board->review($this->strongSpec([
            'acceptance_criteria' => [
                'AtlasFoo::detect() returns drift entries when interfaces diverge',
                'php artisan test --filter=AtlasFooTest',
            ],
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AtlasFoo.php',
                'tests/Unit/Ai/SelfConstruction/AtlasFooTest.php',
            ],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);
        $capabilityReasons = array_filter($lens['reasons'], fn ($r) => str_contains($r, 'runnable_criteria'));
        $this->assertEmpty($capabilityReasons);
    }

    public function test_leverage_passes_when_criteria_have_no_filter_at_all(): void
    {
        // No --filter= → AC2 check never fires regardless of content
        $r = $this->board->review($this->strongSpec([
            'acceptance_criteria' => [
                'AtlasFoo::detect() returns drift entries when interfaces diverge',
            ],
        ]));

        $lens = $this->findLens($r, AtlasExternalBrainAdversarialSpecReviewBoard::LENS_LEVERAGE);
        $capabilityReasons = array_filter($lens['reasons'], fn ($r) => str_contains($r, 'runnable_criteria'));
        $this->assertEmpty($capabilityReasons);
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
