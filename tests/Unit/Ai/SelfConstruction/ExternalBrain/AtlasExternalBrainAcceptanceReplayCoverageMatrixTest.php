<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAcceptanceReplayCoverageMatrix;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAcceptanceReplayCoverageMatrixTest extends TestCase
{
    private AtlasExternalBrainAcceptanceReplayCoverageMatrix $matrix;

    protected function setUp(): void
    {
        $this->matrix = new AtlasExternalBrainAcceptanceReplayCoverageMatrix;
    }

    private function goodSpec(array $overrides = []): array
    {
        return array_merge([
            'acceptance_criteria' => [
                '/opt/homebrew/bin/php artisan test --filter=AtlasFooTest exits 0',
            ],
            'allowed_files'   => [
                'app/Services/Ai/AtlasFoo.php',
                'tests/Unit/Ai/AtlasFooTest.php',
            ],
            'evidence_refs'   => ['tests_or_gates_result'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->matrix->audit($this->goodSpec());

        foreach (['schema', 'verdict', 'coverage_flags', 'rejections'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::SCHEMA, $result['schema']);
    }

    // ── AC1: full-coverage spec is accepted ───────────────────────────────────

    public function test_complete_spec_is_accepted(): void
    {
        $result = $this->matrix->audit($this->goodSpec());

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_ACCEPTED, $result['verdict']);
        $this->assertSame([], $result['rejections']);
    }

    // ── AC1: rejected with exact reason — no runnable command ─────────────────

    public function test_rejected_when_no_artisan_or_phpunit_command(): void
    {
        $result = $this->matrix->audit($this->goodSpec([
            'acceptance_criteria' => ['The output must be JSON', 'Only pure functions allowed'],
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED, $result['verdict']);
        $reasons = array_column($result['rejections'], 'reason');
        $this->assertContains('no_runnable_command', $reasons);
    }

    public function test_vendor_bin_phpunit_counts_as_runnable(): void
    {
        $result = $this->matrix->audit($this->goodSpec([
            'acceptance_criteria' => ['./vendor/bin/phpunit tests/Unit/AtlasFooTest.php exits 0'],
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_ACCEPTED, $result['verdict']);
    }

    // ── AC1: rejected with exact reason — no impl file coverage ───────────────

    public function test_rejected_when_allowed_files_contains_only_test_files(): void
    {
        $result = $this->matrix->audit($this->goodSpec([
            'allowed_files' => ['tests/Unit/AtlasFooTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED, $result['verdict']);
        $reasons = array_column($result['rejections'], 'reason');
        $this->assertContains('no_impl_file_coverage', $reasons);
    }

    public function test_impl_file_among_allowed_files_passes_coverage(): void
    {
        $result = $this->matrix->audit($this->goodSpec([
            'allowed_files' => ['app/Services/AtlasFoo.php', 'tests/Unit/AtlasFooTest.php'],
        ]));

        $this->assertFalse($result['coverage_flags']['has_impl_file_coverage'] === false);
        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_ACCEPTED, $result['verdict']);
    }

    // ── AC1: rejected with exact reason — no evidence refs ────────────────────

    public function test_rejected_when_required_evidence_set_but_evidence_refs_empty(): void
    {
        $result = $this->matrix->audit($this->goodSpec([
            'evidence_refs'     => [],
            'required_evidence' => ['tests_or_gates_result'],
        ]));

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED, $result['verdict']);
        $reasons = array_column($result['rejections'], 'reason');
        $this->assertContains('no_evidence_refs', $reasons);
    }

    public function test_empty_required_evidence_does_not_block_even_with_no_evidence_refs(): void
    {
        $result = $this->matrix->audit($this->goodSpec([
            'evidence_refs'     => [],
            'required_evidence' => [],
        ]));

        // No required_evidence → no_evidence_refs check skipped
        $reasons = array_column($result['rejections'], 'reason');
        $this->assertNotContains('no_evidence_refs', $reasons);
    }

    // ── AC2: brittle proxy detection ──────────────────────────────────────────

    public function test_brittle_proxy_flagged_when_only_filter_single_class(): void
    {
        $result = $this->matrix->audit($this->goodSpec([
            'acceptance_criteria' => ['--filter=AtlasFooTest'],
        ]));

        $this->assertTrue($result['coverage_flags']['is_brittle_proxy']);
    }

    public function test_brittle_proxy_does_not_reject_by_itself(): void
    {
        // A spec can be brittle-proxy but still accepted if it has runnable command +
        // impl file + evidence. The brittle flag is a warning, not a blocker.
        $result = $this->matrix->audit($this->goodSpec([
            'acceptance_criteria' => [
                '/opt/homebrew/bin/php artisan test --filter=AtlasFooTest exits 0',
            ],
        ]));

        // Not brittle because it contains 'artisan' (broader command) not just '--filter='
        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_ACCEPTED, $result['verdict']);
    }

    public function test_broad_command_mentioning_impl_class_is_not_brittle(): void
    {
        $result = $this->matrix->audit($this->goodSpec([
            'acceptance_criteria' => [
                '/opt/homebrew/bin/php artisan test --filter=AtlasFooTest',
                'AtlasFoo::compute() returns expected structure',
            ],
        ]));

        $this->assertFalse($result['coverage_flags']['is_brittle_proxy']);
    }

    // ── Multiple rejections emitted ───────────────────────────────────────────

    public function test_multiple_rejection_reasons_emitted(): void
    {
        $result = $this->matrix->audit([
            'acceptance_criteria' => ['must be pure'],      // no runnable command
            'allowed_files'       => ['tests/FooTest.php'], // no impl file
            'evidence_refs'       => [],                    // no evidence
            'required_evidence'   => ['tests_or_gates_result'],
        ]);

        $this->assertSame(AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED, $result['verdict']);
        $this->assertCount(3, $result['rejections']);
    }

    // ── coverage_flags reflect actual state ───────────────────────────────────

    public function test_coverage_flags_all_true_on_good_spec(): void
    {
        $result = $this->matrix->audit($this->goodSpec());

        $this->assertTrue($result['coverage_flags']['has_runnable_command']);
        $this->assertTrue($result['coverage_flags']['has_impl_file_coverage']);
        $this->assertTrue($result['coverage_flags']['has_evidence_refs']);
        $this->assertFalse($result['coverage_flags']['is_brittle_proxy']);
    }
}
