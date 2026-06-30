<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAcceptanceReplayCoverageMatrix;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAcceptanceReplayCoverageMatrixTest extends TestCase
{
    private function matrix(): AtlasExternalBrainAcceptanceReplayCoverageMatrix
    {
        return new AtlasExternalBrainAcceptanceReplayCoverageMatrix;
    }

    /** Minimal passing spec — runnable command + impl file, no high-leverage claims. */
    private function passingSpec(array $overrides = []): array
    {
        return array_merge([
            'objective'           => 'Implement a deterministic score calculator.',
            'acceptance_criteria' => ['Runnable: /opt/homebrew/bin/php artisan test --filter=ScoreCalculatorTest'],
            'allowed_files'       => ['app/Services/ScoreCalculator.php', 'tests/Unit/ScoreCalculatorTest.php'],
            'required_evidence'   => [],
            'evidence_refs'       => [],
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->matrix()->audit($this->passingSpec());

        foreach (['schema', 'verdict', 'coverage_flags', 'rejections', 'claimed_leverage_gaps'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::SCHEMA, $r['schema']);
    }

    // ── AC3: concrete spec with impl scope + evidence → no false rejection ────

    public function test_concrete_spec_with_impl_file_and_runnable_command_is_accepted(): void
    {
        $r = $this->matrix()->audit($this->passingSpec());

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_ACCEPTED, $r['verdict']);
        $this->assertSame([], $r['rejections']);
    }

    public function test_spec_with_evidence_backed_acceptance_and_matching_refs_is_accepted(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'objective'         => 'Implement outcome learning pipeline.',
            'acceptance_criteria' => [
                '/opt/homebrew/bin/php artisan test --filter=LearningLoopTest',
                'Outcome learning is recorded in the ledger.',
            ],
            'required_evidence' => ['learning_outcome'],
            'evidence_refs'     => ['outcome_learning:cycle_1'],
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_ACCEPTED, $r['verdict']);
        $this->assertSame([], $r['claimed_leverage_gaps']);
    }

    // ── AC1: claimed_leverage_gaps populated for uncovered leverage claims ────

    public function test_claimed_outcome_learning_without_evidence_ref_produces_gap(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'objective'         => 'Enable outcome learning closed-loop in the brain.',
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=BrainTest'],
            'evidence_refs'     => [],
        ]));

        $this->assertContains('learning_loop', $r['claimed_leverage_gaps']);
    }

    public function test_claimed_anti_goodhart_without_evidence_ref_produces_gap(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'objective'         => 'Add anti-Goodhart proxy detection to the audit.',
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=AuditTest'],
            'evidence_refs'     => [],
        ]));

        $this->assertContains('anti_goodhart', $r['claimed_leverage_gaps']);
    }

    public function test_claimed_autonomy_without_evidence_ref_produces_gap(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'objective'         => 'Achieve steady-state autonomy with no human dependency.',
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=AutonomyTest'],
            'evidence_refs'     => [],
        ]));

        $this->assertContains('autonomy', $r['claimed_leverage_gaps']);
    }

    public function test_no_leverage_claim_means_empty_gaps(): void
    {
        $r = $this->matrix()->audit($this->passingSpec());

        $this->assertSame([], $r['claimed_leverage_gaps']);
    }

    // ── AC2: verdict rejects leverage claims without runnable replay coverage ──

    public function test_claimed_autonomy_without_coverage_causes_rejection(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'objective'     => 'Achieve autonomy steady-state with no human dependency.',
            'evidence_refs' => [],
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED, $r['verdict']);
        $rejectionDims = array_column($r['rejections'], 'dimension');
        $this->assertContains(AtlasExternalBrainAcceptanceReplayCoverageMatrix::DIM_CLAIMED_LEVERAGE, $rejectionDims);
    }

    public function test_claimed_outcome_learning_without_coverage_causes_rejection(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'objective'     => 'Improve outcome learning closed loop in the brain.',
            'evidence_refs' => [],
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED, $r['verdict']);
        $rejectionDims = array_column($r['rejections'], 'dimension');
        $this->assertContains(AtlasExternalBrainAcceptanceReplayCoverageMatrix::DIM_CLAIMED_LEVERAGE, $rejectionDims);
    }

    public function test_claimed_anti_goodhart_without_coverage_causes_rejection(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'objective'     => 'Add anti-Goodhart audit pass to the gate.',
            'evidence_refs' => [],
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED, $r['verdict']);
        $this->assertContains('claimed_leverage_without_coverage', array_column($r['rejections'], 'reason'));
    }

    public function test_claimed_leverage_with_matching_evidence_is_not_rejected(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'objective'         => 'Achieve autonomy steady-state.',
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=AutonomyTest'],
            'evidence_refs'     => ['autonomy_proof:cycle_5'],
        ]));

        $rejectionDims = array_column($r['rejections'], 'dimension');
        $this->assertNotContains(AtlasExternalBrainAcceptanceReplayCoverageMatrix::DIM_CLAIMED_LEVERAGE, $rejectionDims);
        $this->assertSame([], $r['claimed_leverage_gaps']);
    }

    // ── Existing blocking rejections still work ───────────────────────────────

    public function test_no_runnable_command_causes_rejection(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'acceptance_criteria' => ['All tests pass.'],  // no artisan/phpunit
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED, $r['verdict']);
        $this->assertContains('no_runnable_command', array_column($r['rejections'], 'reason'));
    }

    public function test_only_test_files_in_allowed_files_causes_rejection(): void
    {
        $r = $this->matrix()->audit($this->passingSpec([
            'allowed_files' => ['tests/Unit/FooTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED, $r['verdict']);
        $this->assertContains('no_impl_file_coverage', array_column($r['rejections'], 'reason'));
    }

    // ── AC4: deterministic (no I/O side effects) ──────────────────────────────

    public function test_audit_is_deterministic(): void
    {
        $spec = $this->passingSpec([
            'objective'     => 'Enable outcome learning in the brain.',
            'evidence_refs' => [],
        ]);
        $a = $this->matrix()->audit($spec);
        $b = $this->matrix()->audit($spec);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
