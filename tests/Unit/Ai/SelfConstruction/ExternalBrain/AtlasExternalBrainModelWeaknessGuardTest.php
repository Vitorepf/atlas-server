<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelWeaknessGuard;
use Tests\TestCase;

final class AtlasExternalBrainModelWeaknessGuardTest extends TestCase
{
    private function guard(): AtlasExternalBrainModelWeaknessGuard
    {
        return new AtlasExternalBrainModelWeaknessGuard();
    }

    private function cleanCandidate(): array
    {
        return [
            'task_id'            => 'task-001',
            'objective'          => 'Implement AtlasFooService so it computes bar.',
            'allowed_files'      => ['app/Services/Foo/AtlasFooService.php'],
            'acceptance_criteria' => [
                'The AtlasFooService::compute() method must return an array with schema key.',
                'Running ./vendor/bin/phpunit tests/Unit/Foo/AtlasFooServiceTest.php produces green output.',
            ],
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->guard()->guard([]);

        $this->assertSame(AtlasExternalBrainModelWeaknessGuard::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->guard()->guard([]);

        foreach (['schema', 'passed', 'weakness_findings', 'repair_steps', 'blocked_until_fixed'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── clean candidate passes ────────────────────────────────────────────────

    public function test_passes_when_no_weaknesses(): void
    {
        $result = $this->guard()->guard(['candidate' => $this->cleanCandidate()]);

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['weakness_findings']);
        $this->assertFalse($result['blocked_until_fixed']);
    }

    // ── shallow_duplication (high) ────────────────────────────────────────────

    public function test_flags_shallow_duplication_when_file_in_queued_targets(): void
    {
        $result = $this->guard()->guard([
            'candidate'      => $this->cleanCandidate(),
            'queued_targets' => ['app/Services/Foo/AtlasFooService.php'],
        ]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_SHALLOW_DUPLICATION, $ids);
    }

    public function test_shallow_duplication_is_high_severity(): void
    {
        $result = $this->guard()->guard([
            'candidate'      => $this->cleanCandidate(),
            'queued_targets' => ['app/Services/Foo/AtlasFooService.php'],
        ]);

        $finding = current(array_filter($result['weakness_findings'], fn ($f) => $f['weakness_id'] === AtlasExternalBrainModelWeaknessGuard::WEAKNESS_SHALLOW_DUPLICATION));
        $this->assertSame(AtlasExternalBrainModelWeaknessGuard::SEVERITY_HIGH, $finding['severity']);
    }

    public function test_shallow_duplication_blocks(): void
    {
        $result = $this->guard()->guard([
            'candidate'      => $this->cleanCandidate(),
            'queued_targets' => ['app/Services/Foo/AtlasFooService.php'],
        ]);

        $this->assertTrue($result['blocked_until_fixed']);
    }

    public function test_flags_duplication_when_file_in_done_targets(): void
    {
        $result = $this->guard()->guard([
            'candidate'    => $this->cleanCandidate(),
            'done_targets' => ['app/Services/Foo/AtlasFooService.php'],
        ]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_SHALLOW_DUPLICATION, $ids);
    }

    // ── template_farming (high) ───────────────────────────────────────────────

    public function test_flags_template_farming_when_all_criteria_generic(): void
    {
        $result = $this->guard()->guard([
            'candidate' => [
                'task_id'            => 'task-tpl',
                'objective'          => 'Implement AtlasBarService.',
                'allowed_files'      => ['app/Services/Bar/AtlasBarService.php'],
                'acceptance_criteria' => [
                    'The service must run.',
                    'The tests must pass.',
                    'The output must be valid.',
                ],
            ],
        ]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_TEMPLATE_FARMING, $ids);
    }

    public function test_no_template_farming_when_criteria_has_method_reference(): void
    {
        $result = $this->guard()->guard([
            'candidate' => [
                'task_id'            => 'task-ok',
                'objective'          => 'Implement AtlasBazService.',
                'allowed_files'      => ['app/Services/Baz/AtlasBazService.php'],
                'acceptance_criteria' => [
                    'The AtlasBazService::compute() method must return an array.',
                    'The tests must pass.',
                ],
            ],
        ]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertNotContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_TEMPLATE_FARMING, $ids);
    }

    // ── missing_code_search (medium) ──────────────────────────────────────────

    public function test_flags_missing_code_search_when_no_proof_in_criteria(): void
    {
        $result = $this->guard()->guard([
            'candidate' => [
                'task_id'            => 'task-nc',
                'objective'          => 'Implement AtlasQuxService so it works.',
                'allowed_files'      => ['app/Services/Qux/AtlasQuxService.php'],
                'acceptance_criteria' => [
                    'The AtlasQuxService::go() method must return true or false.',
                    'The service must handle edge cases.',
                ],
            ],
        ]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_MISSING_CODE_SEARCH, $ids);
    }

    public function test_no_missing_code_search_when_phpunit_referenced(): void
    {
        $result = $this->guard()->guard(['candidate' => $this->cleanCandidate()]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertNotContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_MISSING_CODE_SEARCH, $ids);
    }

    // ── weak_acceptance (medium) ──────────────────────────────────────────────

    public function test_flags_weak_acceptance_for_should_work_phrase(): void
    {
        $candidate = $this->cleanCandidate();
        $candidate['acceptance_criteria'][] = 'The integration should work correctly.';

        $result = $this->guard()->guard(['candidate' => $candidate]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_WEAK_ACCEPTANCE, $ids);
    }

    public function test_flags_weak_acceptance_for_must_pass_alone(): void
    {
        $result = $this->guard()->guard([
            'candidate' => [
                'task_id'            => 'task-weak',
                'objective'          => 'Add something.',
                'allowed_files'      => ['app/Foo.php'],
                'acceptance_criteria' => [
                    'AtlasQux::run() must return an array schema.',
                    './vendor/bin/phpunit tests/Unit/FooTest.php must pass.',
                ],
            ],
        ]);

        // "must pass" is a weak phrase → should be flagged
        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_WEAK_ACCEPTANCE, $ids);
    }

    // ── over_broad_scope (medium) ─────────────────────────────────────────────

    public function test_flags_over_broad_scope_when_too_many_files(): void
    {
        $candidate             = $this->cleanCandidate();
        $candidate['allowed_files'] = [
            'app/A.php', 'app/B.php', 'app/C.php', 'app/D.php', 'app/E.php', 'app/F.php',
        ];

        $result = $this->guard()->guard(['candidate' => $candidate]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_OVER_BROAD_SCOPE, $ids);
    }

    public function test_no_over_broad_scope_within_limit(): void
    {
        $result = $this->guard()->guard(['candidate' => $this->cleanCandidate()]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertNotContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_OVER_BROAD_SCOPE, $ids);
    }

    public function test_custom_max_files_respected(): void
    {
        $candidate             = $this->cleanCandidate();
        $candidate['allowed_files'] = ['app/A.php', 'app/B.php', 'app/C.php'];

        $result = $this->guard()->guard([
            'candidate'         => $candidate,
            'max_files_per_task' => 2,
        ]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_OVER_BROAD_SCOPE, $ids);
    }

    // ── fake_confidence (low) ─────────────────────────────────────────────────

    public function test_flags_fake_confidence_in_objective(): void
    {
        $candidate             = $this->cleanCandidate();
        $candidate['objective'] = 'Ensure that the service computes bar correctly.';

        $result = $this->guard()->guard(['candidate' => $candidate]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_FAKE_CONFIDENCE, $ids);
    }

    public function test_fake_confidence_is_low_severity(): void
    {
        $candidate             = $this->cleanCandidate();
        $candidate['objective'] = 'Verify that the service computes bar correctly.';

        $result = $this->guard()->guard(['candidate' => $candidate]);

        $finding = current(array_filter($result['weakness_findings'], fn ($f) => $f['weakness_id'] === AtlasExternalBrainModelWeaknessGuard::WEAKNESS_FAKE_CONFIDENCE));
        $this->assertSame(AtlasExternalBrainModelWeaknessGuard::SEVERITY_LOW, $finding['severity']);
    }

    public function test_fake_confidence_alone_does_not_block(): void
    {
        $candidate             = $this->cleanCandidate();
        $candidate['objective'] = 'Verify that the service computes bar.';

        $result = $this->guard()->guard(['candidate' => $candidate]);

        // fake_confidence is low → should not block even if it fires
        // (only high severity blocks)
        $highFindings = array_filter($result['weakness_findings'], fn ($f) => $f['severity'] === AtlasExternalBrainModelWeaknessGuard::SEVERITY_HIGH);
        if ($highFindings === []) {
            $this->assertFalse($result['blocked_until_fixed']);
        } else {
            $this->assertTrue($result['blocked_until_fixed']);
        }
    }

    // ── repair_steps ──────────────────────────────────────────────────────────

    public function test_repair_steps_non_empty_when_findings_present(): void
    {
        $result = $this->guard()->guard([
            'candidate'      => $this->cleanCandidate(),
            'queued_targets' => ['app/Services/Foo/AtlasFooService.php'],
        ]);

        $this->assertNotEmpty($result['repair_steps']);
    }

    public function test_repair_steps_empty_when_passed(): void
    {
        $result = $this->guard()->guard(['candidate' => $this->cleanCandidate()]);

        $this->assertSame([], $result['repair_steps']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'candidate'      => $this->cleanCandidate(),
            'queued_targets' => [],
        ];

        $this->assertSame($this->guard()->guard($input), $this->guard()->guard($input));
    }

    // ── contradiction_miss (high) ───────────────────────────────────────────

    public function test_flags_contradiction_when_single_criterion_self_contradicts(): void
    {
        $result = $this->guard()->guard([
            'candidate' => [
                'task_id'             => 'task-contra',
                'objective'           => 'Implement AtlasContraService.',
                'allowed_files'       => ['app/Services/AtlasContraService.php'],
                'acceptance_criteria' => [
                    'AtlasContraService::run() must return true and must not return true.',
                ],
            ],
        ]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_CONTRADICTION_MISS, $ids);
        $this->assertTrue($result['blocked_until_fixed']);
    }

    public function test_flags_contradiction_across_two_negated_criteria(): void
    {
        $result = $this->guard()->guard([
            'candidate' => [
                'task_id'             => 'task-contra-2',
                'objective'           => 'Implement AtlasContraService.',
                'allowed_files'       => ['app/Services/AtlasContraService.php'],
                'acceptance_criteria' => [
                    'AtlasContraService::compute() returns the schema array.',
                    'AtlasContraService::compute() does not return the schema array.',
                ],
            ],
        ]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_CONTRADICTION_MISS, $ids);
    }

    public function test_no_contradiction_for_clean_candidate(): void
    {
        $result = $this->guard()->guard(['candidate' => $this->cleanCandidate()]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertNotContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_CONTRADICTION_MISS, $ids);
    }

    // ── proxy_output (medium) ───────────────────────────────────────────────

    public function test_flags_proxy_output_when_acceptance_measures_line_count(): void
    {
        $result = $this->guard()->guard([
            'candidate' => [
                'task_id'             => 'task-proxy',
                'objective'           => 'Implement AtlasProxyService.',
                'allowed_files'       => ['app/Services/AtlasProxyService.php'],
                'acceptance_criteria' => [
                    'AtlasProxyService.php must have at least 50 lines of code.',
                    './vendor/bin/phpunit tests/Unit/AtlasProxyServiceTest.php produces green output.',
                ],
            ],
        ]);

        $ids = array_column($result['weakness_findings'], 'weakness_id');
        $this->assertContains(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_PROXY_OUTPUT, $ids);
    }

    // ── escalation policy output fields ─────────────────────────────────────

    public function test_output_includes_model_tier_weakness_decision_and_rationale(): void
    {
        $result = $this->guard()->guard([
            'candidate'  => $this->cleanCandidate(),
            'model_tier' => 'small',
        ]);

        foreach (['model_tier', 'weakness', 'decision', 'escalation_rationale'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame('small', $result['model_tier']);
        $this->assertSame(AtlasExternalBrainModelWeaknessGuard::DECISION_ALLOW, $result['decision']);
        $this->assertNull($result['weakness']);
    }

    public function test_high_severity_weakness_without_recovery_evidence_decides_block(): void
    {
        $result = $this->guard()->guard([
            'candidate'      => $this->cleanCandidate(),
            'queued_targets' => ['app/Services/Foo/AtlasFooService.php'],
            'model_tier'     => 'small',
        ]);

        $this->assertSame(AtlasExternalBrainModelWeaknessGuard::DECISION_BLOCK, $result['decision']);
        $this->assertSame(AtlasExternalBrainModelWeaknessGuard::WEAKNESS_SHALLOW_DUPLICATION, $result['weakness']);
        $this->assertTrue($result['blocked_until_fixed']);
        $this->assertNotEmpty($result['escalation_rationale']);
    }

    public function test_high_severity_weakness_with_reliable_recovery_evidence_allows_scaffolded_execution(): void
    {
        $result = $this->guard()->guard([
            'candidate'         => $this->cleanCandidate(),
            'queued_targets'    => ['app/Services/Foo/AtlasFooService.php'],
            'model_tier'        => 'small',
            'recovery_evidence' => [
                AtlasExternalBrainModelWeaknessGuard::WEAKNESS_SHALLOW_DUPLICATION => 0.85,
            ],
        ]);

        $this->assertSame(AtlasExternalBrainModelWeaknessGuard::DECISION_ALLOW_SCAFFOLDED, $result['decision']);
        $this->assertFalse($result['blocked_until_fixed'], 'reliable recovery evidence must lift the block even though severity is high');
        $this->assertStringContainsString('scaffold', $result['escalation_rationale']);
    }

    public function test_medium_severity_weakness_without_recovery_evidence_decides_escalate(): void
    {
        $result = $this->guard()->guard([
            'candidate'  => [
                'task_id'             => 'task-medium',
                'objective'           => 'Implement AtlasQuxService so it works.',
                'allowed_files'       => ['app/Services/Qux/AtlasQuxService.php'],
                'acceptance_criteria' => [
                    'The AtlasQuxService::go() method must return true or false.',
                    'The service must handle edge cases.',
                ],
            ],
            'model_tier' => 'small',
        ]);

        $this->assertSame(AtlasExternalBrainModelWeaknessGuard::DECISION_ESCALATE, $result['decision']);
        $this->assertFalse($result['blocked_until_fixed']);
    }

    public function test_medium_severity_weakness_with_reliable_recovery_evidence_allows_scaffolded_execution(): void
    {
        $result = $this->guard()->guard([
            'candidate'  => [
                'task_id'             => 'task-medium-2',
                'objective'           => 'Implement AtlasQuxService so it works.',
                'allowed_files'       => ['app/Services/Qux/AtlasQuxService.php'],
                'acceptance_criteria' => [
                    'The AtlasQuxService::go() method must return true or false.',
                    'The service must handle edge cases.',
                ],
            ],
            'model_tier'        => 'small',
            'recovery_evidence' => [
                ['weakness_id' => AtlasExternalBrainModelWeaknessGuard::WEAKNESS_MISSING_CODE_SEARCH, 'recovery_rate' => 0.9],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainModelWeaknessGuard::DECISION_ALLOW_SCAFFOLDED, $result['decision']);
    }
}
