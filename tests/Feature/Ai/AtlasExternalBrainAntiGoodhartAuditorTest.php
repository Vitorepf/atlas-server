<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAntiGoodhartAuditor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAntiGoodhartAuditorTest extends TestCase
{
    private function auditor(): AtlasExternalBrainAntiGoodhartAuditor
    {
        return new AtlasExternalBrainAntiGoodhartAuditor;
    }

    /** Minimal valid task with distinct category/value_mechanism/files. */
    private function task(string $id, array $overrides = []): array
    {
        return array_merge([
            'label'                  => $id,
            'category'               => 'architecture_unlock',
            'value_mechanism'        => "unlocks_{$id}_autonomous_recovery",
            'allowed_files'          => ["app/Services/{$id}/{$id}Service.php"],
            'final_score'            => 0.85,
            'runnable_acceptance'    => "php artisan test --filter={$id}",
            'implementation_proof'   => "{$id}_test_green",
            'already_satisfied'      => false,
        ], $overrides);
    }

    // ── AC1: same-template batch with shallow renamed targets → flagged ────────

    public function test_renamed_noun_clones_are_flagged_as_template_similarity_farm(): void
    {
        // All tasks differ only in the Atlas{X}Service name — same structural fingerprint.
        $batch = [];
        foreach (['Foo', 'Bar', 'Baz', 'Qux'] as $noun) {
            $batch[] = [
                'label'           => "add-Atlas{$noun}Service",
                'category'        => 'architecture_unlock',
                'value_mechanism' => 'adds_service_capability',
                'allowed_files'   => ["app/Services/Atlas{$noun}Service.php"],
                'objective'       => "Add Atlas{$noun}Service with the standard interface",
                'final_score'     => 0.82,
            ];
        }

        $r = $this->auditor()->audit($batch);

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $r['verdict']);
        $findingNames = array_column($r['findings'], 'finding');
        $this->assertContains('template_similarity_farm', $findingNames);
    }

    public function test_same_category_same_dir_exceeding_half_is_template_farm(): void
    {
        // 4 of 5 tasks: same category + same top dir prefix
        $batch = [
            $this->task('a', ['category' => 'bug_fix', 'allowed_files' => ['app/Services/Foo/FooService.php']]),
            $this->task('b', ['category' => 'bug_fix', 'allowed_files' => ['app/Services/Foo/FooRepo.php']]),
            $this->task('c', ['category' => 'bug_fix', 'allowed_files' => ['app/Services/Foo/FooGate.php']]),
            $this->task('d', ['category' => 'bug_fix', 'allowed_files' => ['app/Services/Foo/FooJob.php']]),
            $this->task('e', ['category' => 'runtime_continuity', 'allowed_files' => ['app/Services/Bar/BarService.php']]),
        ];

        $r = $this->auditor()->audit($batch);

        $this->assertNotSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS, $r['verdict']);
        $findingNames = array_column($r['findings'], 'finding');
        $this->assertContains('template_farm', $findingNames);
    }

    public function test_template_farm_finding_includes_repair_hint(): void
    {
        $batch = array_fill(0, 4, [
            'label'           => 'clone',
            'category'        => 'bug_fix',
            'value_mechanism' => 'fixes_clone_bug',
            'allowed_files'   => ['app/Services/Dup/DupService.php'],
            'objective'       => 'Fix clone DupService issue',
            'final_score'     => 0.70,
        ]);

        $r    = $this->auditor()->audit($batch);
        $farm = current(array_filter($r['findings'], fn ($f) => str_contains($f['finding'], 'farm')));

        $this->assertNotEmpty($farm['repair_hint']);
    }

    // ── AC2: structurally diverse high-evidence batches pass with explicit reasons ─

    public function test_diverse_high_evidence_batch_passes(): void
    {
        $batch = [
            $this->task('auth',    ['category' => 'architecture_unlock']),
            $this->task('health',  ['category' => 'runtime_continuity']),
            $this->task('gate',    ['category' => 'bug_fix']),
            $this->task('harvest', ['category' => 'learning_loop']),
            $this->task('merge',   ['category' => 'task_quality_repair']),
        ];

        $r = $this->auditor()->audit($batch);

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS, $r['verdict']);
        $this->assertTrue($r['passed']);
        $this->assertSame([], $r['findings']);
    }

    public function test_pass_result_includes_checks_run_for_explicit_evidence(): void
    {
        $r = $this->auditor()->audit([$this->task('organ')]);

        $this->assertArrayHasKey('checks_run', $r);
        $this->assertContains('template_farm',         $r['checks_run']);
        $this->assertContains('template_similarity_farm', $r['checks_run']);
        $this->assertContains('test_count_padding',    $r['checks_run']);
    }

    public function test_diverse_batch_pass_checks_run_lists_all_nine_checks(): void
    {
        $r = $this->auditor()->audit([$this->task('x')]);

        $this->assertCount(9, $r['checks_run']);
    }

    // ── AC3: test-count inflation vs real capability lift ─────────────────────

    public function test_thin_test_gate_majority_is_flagged_as_test_count_padding(): void
    {
        // 4 thin test-gate tasks + 1 real task = 80% thin → flagged
        $thin = static fn (string $id): array => [
            'label'           => "test-{$id}",
            'category'        => 'test_gate',
            'value_mechanism' => 'adds_test_coverage',
            'allowed_files'   => ["tests/Feature/{$id}Test.php"],  // single file
            'final_score'     => 0.55,
        ];

        $batch = [
            $thin('A'), $thin('B'), $thin('C'), $thin('D'),
            $this->task('real-feature', ['category' => 'architecture_unlock']),
        ];

        $r            = $this->auditor()->audit($batch);
        $findingNames = array_column($r['findings'], 'finding');

        $this->assertContains('test_count_padding', $findingNames);
    }

    public function test_real_capability_lift_with_proof_passes_test_padding_check(): void
    {
        // Tasks that have test files but ALSO have impl files and proof — not thin.
        $batch = [
            $this->task('cap-a', [
                'category'     => 'architecture_unlock',
                'allowed_files' => ['app/Services/CapA/CapAService.php', 'tests/Feature/CapATest.php'],
            ]),
            $this->task('cap-b', [
                'category'     => 'runtime_continuity',
                'allowed_files' => ['app/Services/CapB/CapBService.php', 'tests/Feature/CapBTest.php'],
            ]),
            $this->task('cap-c', [
                'category'     => 'bug_fix',
                'allowed_files' => ['app/Services/CapC/CapCService.php', 'tests/Feature/CapCTest.php'],
            ]),
        ];

        $r            = $this->auditor()->audit($batch);
        $findingNames = array_column($r['findings'], 'finding');

        $this->assertNotContains('test_count_padding', $findingNames);
    }

    public function test_high_score_task_without_proof_flagged_not_real_lift(): void
    {
        $batch = [
            $this->task('a', ['final_score' => 0.95, 'runnable_acceptance' => '', 'implementation_proof' => '']),
            $this->task('b', ['final_score' => 0.90, 'runnable_acceptance' => '', 'implementation_proof' => '']),
        ];

        $r            = $this->auditor()->audit($batch);
        $findingNames = array_column($r['findings'], 'finding');

        $this->assertContains('high_score_missing_proof', $findingNames);
    }

    public function test_high_score_task_with_proof_is_not_flagged(): void
    {
        $batch = [$this->task('proven', ['final_score' => 0.92])];

        $r            = $this->auditor()->audit($batch);
        $findingNames = array_column($r['findings'], 'finding');

        $this->assertNotContains('high_score_missing_proof', $findingNames);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_audit_is_deterministic(): void
    {
        $batch = [
            $this->task('x', ['category' => 'architecture_unlock']),
            $this->task('y', ['category' => 'bug_fix']),
            $this->task('z', ['category' => 'learning_loop']),
        ];

        $a = $this->auditor()->audit($batch);
        $b = $this->auditor()->audit($batch);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_empty_batch_returns_pass(): void
    {
        $r = $this->auditor()->audit([]);

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS, $r['verdict']);
        $this->assertSame([], $r['findings']);
    }
}
