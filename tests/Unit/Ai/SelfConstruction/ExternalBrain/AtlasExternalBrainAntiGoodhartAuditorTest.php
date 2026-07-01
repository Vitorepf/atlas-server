<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAntiGoodhartAuditor;
use Tests\TestCase;

final class AtlasExternalBrainAntiGoodhartAuditorTest extends TestCase
{
    private function auditor(): AtlasExternalBrainAntiGoodhartAuditor
    {
        return new AtlasExternalBrainAntiGoodhartAuditor;
    }

    private function task(string $label, string $category = 'bug_fix', string $dir = 'app/Services/Ai', float $score = 0.7): array
    {
        return [
            'label'           => $label,
            'category'        => $category,
            'allowed_files'   => [$dir.'/'.$label.'.php', $dir.'/'.$label.'Test.php'],
            'value_mechanism' => 'fixes:'.$label,
            'final_score'     => $score,
        ];
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.anti_goodhart_auditor.v1',
            AtlasExternalBrainAntiGoodhartAuditor::SCHEMA,
        );
    }

    public function test_high_count_low_variety_batch_is_rejected(): void
    {
        // 10 tasks, all bug_fix, all same directory — triggers template_farm (critical).
        $batch = array_map(
            fn (int $i): array => $this->task("task-{$i}", 'bug_fix', 'app/Services/Ai'),
            range(1, 10),
        );

        $result = $this->auditor()->audit($batch);

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict']);
        $this->assertFalse($result['passed']);
        $this->assertGreaterThan(0, $result['finding_count']);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('template_farm', $findingNames);
    }

    public function test_high_leverage_diverse_batch_passes(): void
    {
        // Each task uses a distinct top-3-segment dir prefix so no concentration finding fires.
        $batch = [
            $this->task('arch-1',    'architecture_unlock', 'app/Core/Arch'),
            $this->task('bug-1',     'bug_fix',             'app/Queue/Bugs'),
            $this->task('runtime-1', 'runtime_continuity',  'app/Runtime/Svc'),
            $this->task('docs-1',    'docs_sync',           'docs/engineering'),
        ];

        $result = $this->auditor()->audit($batch);

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS, $result['verdict']);
        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['finding_count']);
        $this->assertSame([], $result['findings']);
    }

    public function test_findings_include_concrete_repair_hints(): void
    {
        // All bug_fix, same dir → template_farm finding.
        $batch = array_map(
            fn (int $i): array => $this->task("t-{$i}", 'bug_fix', 'app/Services/Ai'),
            range(1, 6),
        );

        $result = $this->auditor()->audit($batch);

        $this->assertNotEmpty($result['findings']);
        foreach ($result['findings'] as $finding) {
            $this->assertArrayHasKey('finding',     $finding);
            $this->assertArrayHasKey('severity',    $finding);
            $this->assertArrayHasKey('affected',    $finding);
            $this->assertArrayHasKey('fraction',    $finding);
            $this->assertArrayHasKey('repair_hint', $finding);
            $this->assertNotEmpty($finding['repair_hint'], 'repair_hint must be a non-empty string');
            $this->assertIsString($finding['repair_hint']);
        }
    }

    public function test_low_variety_is_critical_and_causes_reject(): void
    {
        // 5 tasks, all same category → low_variety.
        $batch = array_map(
            fn (int $i): array => $this->task("lv-{$i}", 'bug_fix', 'app/Services/A'.$i),
            range(1, 5),
        );

        $result = $this->auditor()->audit($batch);

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict']);
        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('low_variety', $findingNames);
    }

    public function test_test_count_padding_triggers_repair_required(): void
    {
        // 5 tasks: 3 thin test_gate + 2 diverse non-thin → test_padding, but no critical.
        $batch = [
            ['label' => 'tg-1', 'category' => 'test_gate', 'allowed_files' => ['app/A.php'], 'value_mechanism' => 'gate:a', 'final_score' => 0.4],
            ['label' => 'tg-2', 'category' => 'test_gate', 'allowed_files' => ['app/B.php'], 'value_mechanism' => 'gate:b', 'final_score' => 0.4],
            ['label' => 'tg-3', 'category' => 'test_gate', 'allowed_files' => ['app/C.php'], 'value_mechanism' => 'gate:c', 'final_score' => 0.4],
            $this->task('bug-x', 'bug_fix',             'app/Services/Queue'),
            $this->task('arch-x', 'architecture_unlock', 'app/Services/Core'),
        ];

        $result = $this->auditor()->audit($batch);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('test_count_padding', $findingNames);

        // Not critical → repair_required (unless template_farm/low_variety also triggered).
        $hasCritical = array_intersect($findingNames, ['template_farm', 'low_variety']) !== [];
        if (! $hasCritical && $result['finding_count'] < 3) {
            $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REPAIR_REQUIRED, $result['verdict']);
        }
    }

    public function test_unverifiable_value_claims_triggers_finding(): void
    {
        $batch = [
            ['label' => 'bad-1', 'category' => 'bug_fix', 'allowed_files' => ['app/A.php'], 'value_mechanism' => '',        'final_score' => 0.5],
            ['label' => 'bad-2', 'category' => 'bug_fix', 'allowed_files' => ['app/B.php'], 'value_mechanism' => 'general',  'final_score' => 0.5],
            ['label' => 'bad-3', 'category' => 'bug_fix', 'allowed_files' => ['app/C.php'], 'value_mechanism' => 'misc',     'final_score' => 0.5],
            ['label' => 'bad-4', 'category' => 'bug_fix', 'allowed_files' => ['app/D.php'], 'value_mechanism' => '',        'final_score' => 0.5],
            $this->task('good-1', 'architecture_unlock', 'app/Services/Core'),
            $this->task('good-2', 'runtime_continuity',  'app/Services/Runtime'),
        ];

        $result = $this->auditor()->audit($batch);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('unverifiable_value_claim', $findingNames);

        $hint = '';
        foreach ($result['findings'] as $f) {
            if ($f['finding'] === 'unverifiable_value_claim') {
                $hint = $f['repair_hint'];
                break;
            }
        }
        $this->assertStringContainsString('value_mechanism', $hint);
    }

    public function test_already_satisfied_work_triggers_finding(): void
    {
        $batch = [
            array_merge($this->task('sat-1', 'bug_fix', 'app/Services/A'), ['already_satisfied' => true]),
            $this->task('ok-1', 'architecture_unlock', 'app/Services/B'),
            $this->task('ok-2', 'bug_fix',             'app/Services/C'),
        ];

        $result = $this->auditor()->audit($batch);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('already_satisfied_work', $findingNames);

        foreach ($result['findings'] as $f) {
            if ($f['finding'] === 'already_satisfied_work') {
                $this->assertSame(1, $f['affected']);
                $this->assertStringContainsString('sat-1', $f['repair_hint']);
                break;
            }
        }
    }

    public function test_empty_batch_passes(): void
    {
        $result = $this->auditor()->audit([]);

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS, $result['verdict']);
        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['finding_count']);
    }

    public function test_three_or_more_findings_trigger_reject(): void
    {
        // Craft a batch that hits test_padding + unverifiable + file_overconcentration.
        $batch = array_merge(
            // 3 thin test-gate tasks
            array_map(fn (int $i): array => [
                'label'           => "tg-{$i}",
                'category'        => 'test_gate',
                'allowed_files'   => ["app/Services/Same/T{$i}.php"],
                'value_mechanism' => '',
                'final_score'     => 0.3,
            ], range(1, 3)),
            // 2 regular tasks also targeting same dir with generic value
            array_map(fn (int $i): array => [
                'label'           => "bug-{$i}",
                'category'        => 'bug_fix',
                'allowed_files'   => ["app/Services/Same/B{$i}.php", "tests/B{$i}Test.php"],
                'value_mechanism' => 'general',
                'final_score'     => 0.4,
            ], range(1, 2)),
        );

        $result = $this->auditor()->audit($batch);

        $this->assertGreaterThanOrEqual(3, $result['finding_count']);
        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict']);
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->auditor()->audit([$this->task('key-check')]);

        foreach (['schema', 'verdict', 'passed', 'total_audited', 'finding_count', 'findings'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::SCHEMA, $result['schema']);
        $this->assertIsArray($result['findings']);
    }

    // ── template_similarity_farm ──────────────────────────────────────────────

    /**
     * Task with a CamelCase class name — same structural objective, different noun.
     * Each uses a distinct dir so the existing template_farm check stays silent.
     */
    private function renamedNounTask(string $className, string $dirSegment): array
    {
        return [
            'label'           => "codex-meta-implement-{$className}-20260630",
            'objective'       => "Implement {$className} to extend Atlas capability for autonomous wiring",
            'category'        => 'architecture_unlock',
            'allowed_files'   => [
                "app/Services/Ai/SelfConstruction/Adapters/{$dirSegment}/{$className}.php",
                "tests/Unit/Ai/SelfConstruction/Adapters/{$dirSegment}/{$className}Test.php",
            ],
            'value_mechanism' => "new_capability:adapter_wiring_{$className}",
            'final_score'     => 0.75,
        ];
    }

    public function test_similarity_farm_detected_for_renamed_noun_batch(): void
    {
        // 6 tasks in distinct dirs (template_farm is silent) but structurally identical.
        $batch = [
            $this->renamedNounTask('FooServiceAdapter', 'foo'),
            $this->renamedNounTask('BarServiceAdapter', 'bar'),
            $this->renamedNounTask('BazServiceAdapter', 'baz'),
            $this->renamedNounTask('QuxServiceAdapter', 'qux'),
            $this->renamedNounTask('FrobServiceAdapter', 'frob'),
            $this->renamedNounTask('NorfServiceAdapter', 'norf'),
        ];

        $result = $this->auditor()->audit($batch);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('template_similarity_farm', $findingNames,
            'renamed-noun template farm must be detected even across distinct directories');

        foreach ($result['findings'] as $f) {
            if ($f['finding'] === 'template_similarity_farm') {
                $this->assertSame('critical', $f['severity']);
                $this->assertGreaterThanOrEqual(3, $f['affected']);
                $this->assertNotEmpty($f['repair_hint']);
                break;
            }
        }

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict'],
            'critical similarity farm must produce reject verdict');
    }

    // ── AC1: high_score_missing_proof ────────────────────────────────────────

    public function test_high_score_task_lacking_proof_triggers_finding(): void
    {
        $batch = [
            ['label' => 'h1', 'category' => 'architecture_unlock', 'allowed_files' => ['app/A.php', 'tests/ATest.php'], 'value_mechanism' => 'unlocks:capability_X', 'final_score' => 0.90],
            ['label' => 'h2', 'category' => 'bug_fix',             'allowed_files' => ['app/B.php', 'tests/BTest.php'], 'value_mechanism' => 'fixes:critical_Y',    'final_score' => 0.85],
        ];

        $result = $this->auditor()->audit($batch);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('high_score_missing_proof', $findingNames);

        foreach ($result['findings'] as $f) {
            if ($f['finding'] === 'high_score_missing_proof') {
                $this->assertSame(2, $f['affected']);
                $this->assertStringContainsString('proof', $f['repair_hint']);
                break;
            }
        }
    }

    public function test_high_score_task_with_both_proof_fields_does_not_trigger(): void
    {
        $batch = [[
            'label'                => 'h1',
            'category'             => 'architecture_unlock',
            'allowed_files'        => ['app/A.php', 'tests/ATest.php'],
            'value_mechanism'      => 'unlocks:X',
            'final_score'          => 0.92,
            'runnable_acceptance'  => 'phpunit AtlasATest',
            'implementation_proof' => 'commit:abc123',
        ]];

        $result = $this->auditor()->audit($batch);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertNotContains('high_score_missing_proof', $findingNames);
    }

    // ── AC2: value_mechanism_clone ────────────────────────────────────────────

    public function test_value_mechanism_clone_causes_reject(): void
    {
        // 6 tasks — distinct names and files, but all share identical value_mechanism.
        $batch = array_map(static fn (int $i): array => [
            'label'           => "task-{$i}",
            'category'        => 'architecture_unlock',
            'allowed_files'   => ["app/Services/Distinct{$i}/{$i}.php", "tests/Distinct{$i}Test.php"],
            'value_mechanism' => 'new_capability:same_thing_every_time',
            'final_score'     => 0.75,
        ], range(1, 6));

        $result = $this->auditor()->audit($batch);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('value_mechanism_clone', $findingNames);
        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict']);

        foreach ($result['findings'] as $f) {
            if ($f['finding'] === 'value_mechanism_clone') {
                $this->assertSame('critical', $f['severity']);
                $this->assertGreaterThan(0.50, $f['fraction']);
                $this->assertStringContainsString('same_thing_every_time', $f['repair_hint']);
                break;
            }
        }
    }

    public function test_diverse_batch_has_no_similarity_farm(): void
    {
        $batch = [
            [
                'label'           => 'fix-async-timeout',
                'objective'       => 'Fix async task timeout so lease recovery never stalls',
                'category'        => 'bug_fix',
                'allowed_files'   => ['app/Services/Task/AtlasLeaseRecovery.php', 'tests/Unit/Task/AtlasLeaseRecoveryTest.php'],
                'value_mechanism' => 'closes_runtime_gap:lease_timeout',
                'final_score'     => 0.8,
            ],
            [
                'label'           => 'add-brain-saturation-meter',
                'objective'       => 'Add surface saturation detection so the brain rotates mining surfaces autonomously',
                'category'        => 'architecture_unlock',
                'allowed_files'   => ['app/Services/Ai/ExternalBrain/SaturationMeter.php', 'tests/Unit/ExternalBrain/SaturationMeterTest.php'],
                'value_mechanism' => 'new_capability:surface_saturation_detection',
                'final_score'     => 0.9,
            ],
            [
                'label'           => 'update-loop-docs',
                'objective'       => 'Update loop canonical definition docs to reflect the autonomy tier split',
                'category'        => 'docs_sync',
                'allowed_files'   => ['docs/loop-canonical-definition.md'],
                'value_mechanism' => 'docs_accuracy:loop_definition',
                'final_score'     => 0.6,
            ],
            [
                'label'           => 'add-evidence-intake-gate',
                'objective'       => 'Add evidence intake validation gate to reject chat_memory stream at ingest time',
                'category'        => 'runtime_continuity',
                'allowed_files'   => ['app/Services/Ai/ExternalBrain/EvidenceIntakeGate.php', 'tests/Unit/ExternalBrain/EvidenceIntakeGateTest.php'],
                'value_mechanism' => 'closes_runtime_gap:evidence_stream_validation',
                'final_score'     => 0.85,
            ],
        ];

        $result = $this->auditor()->audit($batch);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertNotContains('template_similarity_farm', $findingNames,
            'genuinely diverse batch must not trigger similarity farm');
    }

    // ── new AC: countermetric_floor + required_repairs output keys ────────────

    public function test_output_includes_countermetric_floor_and_required_repairs(): void
    {
        $result = $this->auditor()->audit([$this->task('key-check-2')]);

        $this->assertArrayHasKey('countermetric_floor', $result);
        $this->assertArrayHasKey('required_repairs', $result);
        $this->assertIsFloat($result['countermetric_floor']);
        $this->assertIsArray($result['required_repairs']);
    }

    public function test_countermetric_floor_is_one_when_no_findings(): void
    {
        $batch = [
            $this->task('arch-2',    'architecture_unlock', 'app/Core/Arch'),
            $this->task('bug-2',     'bug_fix',             'app/Queue/Bugs'),
            $this->task('runtime-2', 'runtime_continuity',  'app/Runtime/Svc'),
            $this->task('docs-2',    'docs_sync',           'docs/engineering'),
        ];

        $result = $this->auditor()->audit($batch);

        $this->assertSame(1.0, $result['countermetric_floor']);
        $this->assertSame([], $result['required_repairs']);
    }

    public function test_required_repairs_lists_repair_hints_when_findings_present(): void
    {
        $batch = array_map(static fn (int $i): array => [
            'label'           => "task-{$i}",
            'category'        => 'architecture_unlock',
            'allowed_files'   => ["app/Services/Distinct{$i}/{$i}.php", "tests/Distinct{$i}Test.php"],
            'value_mechanism' => 'new_capability:same_thing_every_time',
            'final_score'     => 0.75,
        ], range(1, 6));

        $result = $this->auditor()->audit($batch);

        $this->assertNotEmpty($result['required_repairs']);
        $this->assertLessThan(1.0, $result['countermetric_floor']);
    }

    // ── new AC: template_farm/low_variety/value_mechanism_clone reject even with high task count ──

    public function test_template_farm_rejects_even_with_high_task_count(): void
    {
        $batch = array_map(
            static fn (int $i) => [
                'label'           => "tplfarm-{$i}",
                'category'        => 'bug_fix',
                'allowed_files'   => ["app/Services/Ai/SelfConstruction/Tpl{$i}.php"],
                'value_mechanism' => "fixes:thing_{$i}",
                'final_score'     => 0.6,
            ],
            range(1, 20),
        );

        $result = $this->auditor()->audit($batch);

        $this->assertSame(20, $result['total_audited']);
        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict']);
        $this->assertContains('template_farm', array_column($result['findings'], 'finding'));
    }

    public function test_low_variety_rejects_even_with_high_task_count(): void
    {
        $batch = array_map(
            static fn (int $i) => [
                'label'           => "lowvar-{$i}",
                'category'        => 'bug_fix',
                'allowed_files'   => ["app/Services/Ai/SelfConstruction/Distinct{$i}/Lowvar{$i}.php"],
                'value_mechanism' => "fixes:distinct_thing_{$i}",
                'final_score'     => 0.6,
            ],
            range(1, 25),
        );

        $result = $this->auditor()->audit($batch);

        $this->assertSame(25, $result['total_audited']);
        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict']);
        $this->assertContains('low_variety', array_column($result['findings'], 'finding'));
    }

    public function test_value_mechanism_clone_rejects_even_with_high_task_count(): void
    {
        $batch = array_map(static fn (int $i): array => [
            'label'           => "vmclone-{$i}",
            'category'        => 'architecture_unlock',
            'allowed_files'   => ["app/Services/Distinct{$i}/{$i}.php", "tests/Distinct{$i}Test.php"],
            'value_mechanism' => 'new_capability:same_thing_every_time',
            'final_score'     => 0.75,
        ], range(1, 30));

        $result = $this->auditor()->audit($batch);

        $this->assertSame(30, $result['total_audited']);
        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict']);
        $this->assertContains('value_mechanism_clone', array_column($result['findings'], 'finding'));
    }

    // ── new AC: high final_score without proof fields → repair_required, not pass ──

    public function test_high_score_without_proof_yields_repair_required_not_pass(): void
    {
        $batch = [
            [
                'label'           => 'no-proof-1',
                'category'        => 'bug_fix',
                'allowed_files'   => ['app/Services/Ai/SelfConstruction/NoProof1.php'],
                'value_mechanism' => 'closes_runtime_gap:no_proof_1',
                'final_score'     => 0.90,
                // no runnable_acceptance / implementation_proof
            ],
            [
                'label'           => 'has-proof-2',
                'category'        => 'architecture_unlock',
                'allowed_files'   => ['app/Services/Ai/SelfConstruction/HasProof2.php'],
                'value_mechanism' => 'new_capability:has_proof_2',
                'final_score'     => 0.85,
                'runnable_acceptance'  => 'php artisan test tests/Unit/HasProof2Test.php',
                'implementation_proof' => 'commit abc123',
                'implementation_evidence' => 'commit abc123',
            ],
        ];

        $result = $this->auditor()->audit($batch);

        $this->assertNotSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS, $result['verdict']);
        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('high_score_missing_proof', $findingNames);
        $this->assertContains('high_score_missing_delivery_evidence', $findingNames);
    }

    // ── AntiGoodhart circuit: template_farm + high_score_missing_proof + value_mechanism_clone
    // must all survive aggregation into a single fail-closed verdict, never collapse to a
    // weaker one. ──

    public function test_simultaneous_template_farm_high_score_missing_proof_and_value_mechanism_clone_all_survive(): void
    {
        $batch = array_map(static fn (int $i): array => [
            'label'           => "clone-{$i}",
            'category'        => 'bug_fix',
            'allowed_files'   => ["app/Services/Ai/SelfConstruction/Clone{$i}.php"],
            'value_mechanism' => 'shared_mechanism',
            'final_score'     => 0.90,
            // no runnable_acceptance / implementation_proof — high score but self-declared.
        ], range(1, 4));

        $result = $this->auditor()->audit($batch);

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict']);
        $this->assertFalse($result['passed']);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('template_farm', $findingNames);
        $this->assertContains('high_score_missing_proof', $findingNames);
        $this->assertContains('value_mechanism_clone', $findingNames);

        foreach ($result['findings'] as $finding) {
            $this->assertNotSame('', $finding['repair_hint']);
            // Percent formatting is consistent: fraction is a 0..1 float, and any
            // percentage embedded in the repair hint text matches round(fraction*100).
            if (preg_match('/(\d+)%/', $finding['repair_hint'], $m) === 1) {
                $this->assertSame((string) round($finding['fraction'] * 100), $m[1]);
            }
        }

        // Deterministic priority: same input audited twice yields the identical verdict and
        // finding order — aggregation never silently drops or reorders findings.
        $again = $this->auditor()->audit($batch);
        $this->assertSame($result['verdict'], $again['verdict']);
        $this->assertSame(array_column($result['findings'], 'finding'), array_column($again['findings'], 'finding'));
    }

    public function test_genuinely_diverse_proof_backed_batch_still_passes(): void
    {
        $batch = [
            [
                'category'             => 'bug_fix',
                'allowed_files'        => ['app/Services/Ai/SelfConstruction/A.php'],
                'value_mechanism'      => 'closes_runtime_gap:a',
                'final_score'          => 0.90,
                'runnable_acceptance'  => 'php artisan test tests/Unit/ATest.php',
                'implementation_proof' => 'commit aaa111',
                'implementation_evidence' => 'commit aaa111',
            ],
            [
                'category'             => 'architecture_unlock',
                'allowed_files'        => ['app/Console/Commands/B.php'],
                'value_mechanism'      => 'new_capability:b',
                'final_score'          => 0.85,
                'runnable_acceptance'  => 'php artisan test tests/Feature/BTest.php',
                'implementation_proof' => 'commit bbb222',
                'implementation_evidence' => 'commit bbb222',
            ],
            [
                'category'             => 'test_gate',
                'allowed_files'        => ['tests/Unit/CTest.php', 'app/Models/C.php'],
                'value_mechanism'      => 'closes_runtime_gap:c',
                'final_score'          => 0.70,
                'runnable_acceptance'  => 'php artisan test tests/Unit/CTest.php',
                'implementation_proof' => 'commit ccc333',
                'implementation_evidence' => 'commit ccc333',
            ],
        ];

        $result = $this->auditor()->audit($batch);

        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_PASS, $result['verdict']);
        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['findings']);
        $this->assertEqualsWithDelta(1.0, $result['countermetric_floor'], 0.001);
    }

    // ── AC: high-score claims require claimable_conversion_evidence or implementation_evidence ──

    public function test_high_score_tasks_without_delivery_evidence_trigger_finding(): void
    {
        $batch = [
            [
                'label' => 'high-1',
                'category' => 'bug_fix',
                'allowed_files' => ['app/Services/Ai/A.php'],
                'value_mechanism' => 'closes_runtime_gap:a',
                'final_score' => 0.90,
                // no claimable_conversion_evidence / implementation_evidence
            ],
            [
                'label' => 'high-2',
                'category' => 'architecture_unlock',
                'allowed_files' => ['app/Console/Commands/B.php'],
                'value_mechanism' => 'new_capability:b',
                'final_score' => 0.85,
            ],
        ];

        $result = $this->auditor()->audit($batch);

        $this->assertContains('high_score_missing_delivery_evidence', array_column($result['findings'], 'finding'));
    }

    public function test_varied_high_value_tasks_with_delivery_evidence_pass_new_check(): void
    {
        $batch = [
            [
                'category' => 'bug_fix',
                'allowed_files' => ['app/Services/Ai/A.php'],
                'value_mechanism' => 'closes_runtime_gap:a',
                'final_score' => 0.90,
                'claimable_conversion_evidence' => 'converted into task-123',
            ],
            [
                'category' => 'architecture_unlock',
                'allowed_files' => ['app/Console/Commands/B.php'],
                'value_mechanism' => 'new_capability:b',
                'final_score' => 0.85,
                'implementation_evidence' => 'commit bbb222',
            ],
        ];

        $result = $this->auditor()->audit($batch);

        $this->assertNotContains('high_score_missing_delivery_evidence', array_column($result['findings'], 'finding'));
    }

    public function test_template_farm_and_low_variety_remain_critical_findings_with_new_check_present(): void
    {
        $batch = array_map(static fn (int $i): array => [
            'label' => "clone-{$i}",
            'category' => 'bug_fix',
            'allowed_files' => ["app/Services/Ai/SelfConstruction/Clone{$i}.php"],
            'value_mechanism' => "mechanism_{$i}",
            'final_score' => 0.90,
        ], range(1, 5));

        $result = $this->auditor()->audit($batch);

        $findingNames = array_column($result['findings'], 'finding');
        $this->assertContains('template_farm', $findingNames);
        $this->assertContains('low_variety', $findingNames);
        $this->assertSame(AtlasExternalBrainAntiGoodhartAuditor::VERDICT_REJECT, $result['verdict']);
    }
}
