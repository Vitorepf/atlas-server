<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseCoverageReporter;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Proves the hard-case coverage reporter: capabilities_with_zero_cases surfaces the blind spots; the JSON
 * output carries NO score/grade/percent key; identical inputs yield byte-identical output.
 */
final class AtlasLoopHardCaseCoverageReporterTest extends TestCase
{
    private string $tmpDir;

    private AtlasLoopHardCaseDatasetRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas_hardcase_cov_'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0775, true);
        $this->registry = new AtlasLoopHardCaseDatasetRegistry($this->tmpDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir.'/registry.json');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function seedRegistry(): void
    {
        // Case 1 — give_back ⇒ originate
        $this->registry->register([
            'case_id' => 'hc-a', 'slug' => 'a', 'captured_at' => 't', 'source' => 'give_back',
            'scope_root' => 'app/Foo', 'failure_signature' => 'sigA',
            'original_attempt_ledger_digest' => 'l1', 'minimal_repro_seed' => [], 'expected_failure_mode' => 'sigA',
        ]);
        // Case 2 — judge_reject ⇒ certify
        $this->registry->register([
            'case_id' => 'hc-b', 'slug' => 'b', 'captured_at' => 't', 'source' => 'judge_reject',
            'scope_root' => 'app/Bar', 'failure_signature' => 'sigB',
            'original_attempt_ledger_digest' => 'l2', 'minimal_repro_seed' => [], 'expected_failure_mode' => 'sigB',
        ]);
        // Case 3 — give_back ⇒ originate (second case for the same capability)
        $this->registry->register([
            'case_id' => 'hc-c', 'slug' => 'c', 'captured_at' => 't', 'source' => 'give_back',
            'scope_root' => 'app/Baz', 'failure_signature' => 'sigC',
            'original_attempt_ledger_digest' => 'l3', 'minimal_repro_seed' => [], 'expected_failure_mode' => 'sigC',
        ]);
    }

    private function reporter(): AtlasLoopHardCaseCoverageReporter
    {
        return new AtlasLoopHardCaseCoverageReporter(
            registry: $this->registry,
            capabilitiesSource: static fn (): array => ['originate', 'decompose', 'certify', 'merge', 'regression-net'],
            lastRunOutcomeProvider: static fn (string $caseId): ?string => 'pass',
        );
    }

    public function test_capabilities_with_zero_cases_lists_decompose_and_merge_when_only_originate_and_certify_covered(): void
    {
        $this->seedRegistry();
        $report = $this->reporter()->report();

        $this->assertSame(['decompose', 'merge', 'regression-net'], $report['capabilities_with_zero_cases']);
        $this->assertSame(['hc-a', 'hc-c'], $report['per_capability']['originate']['case_ids']);
        $this->assertSame(['hc-b'], $report['per_capability']['certify']['case_ids']);
    }

    public function test_report_json_carries_no_score_or_grade_or_percent_key(): void
    {
        $this->seedRegistry();
        $report = $this->reporter()->report();

        $walk = function (array $data) use (&$walk): void {
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $this->assertDoesNotMatchRegularExpression('/score|grade|percent/i', $key, "key {$key} is Goodhart-prone");
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($report);
    }

    public function test_two_identical_runs_produce_byte_identical_json(): void
    {
        $this->seedRegistry();
        $reporter = $this->reporter();

        $a = json_encode($reporter->report(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $b = json_encode($reporter->report(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertSame($a, $b);
    }

    public function test_case_ids_are_sorted_byte_stably_per_capability(): void
    {
        // Register out-of-order ids to verify sort.
        $this->registry->register([
            'case_id' => 'hc-zzz', 'slug' => 'z', 'captured_at' => 't', 'source' => 'give_back',
            'scope_root' => 'app/Z', 'failure_signature' => 'sigZ',
            'original_attempt_ledger_digest' => 'l', 'minimal_repro_seed' => [], 'expected_failure_mode' => 'sigZ',
        ]);
        $this->registry->register([
            'case_id' => 'hc-aaa', 'slug' => 'a', 'captured_at' => 't', 'source' => 'give_back',
            'scope_root' => 'app/A', 'failure_signature' => 'sigA',
            'original_attempt_ledger_digest' => 'l', 'minimal_repro_seed' => [], 'expected_failure_mode' => 'sigA',
        ]);

        $report = $this->reporter()->report();
        $this->assertSame(['hc-aaa', 'hc-zzz'], $report['per_capability']['originate']['case_ids']);
    }

    public function test_render_text_is_human_readable_and_deterministic(): void
    {
        $this->seedRegistry();
        $reporter = $this->reporter();
        $text = $reporter->renderText();

        $this->assertStringContainsString('capability | covered | case_ids | last_outcomes', $text);
        $this->assertStringContainsString('originate | yes', $text);
        $this->assertStringContainsString('decompose | no', $text);
        $this->assertSame($text, $reporter->renderText(), 'deterministic across calls');
    }

    public function test_custom_capability_mapper_overrides_default_heuristic(): void
    {
        $this->seedRegistry();
        $reporter = new AtlasLoopHardCaseCoverageReporter(
            registry: $this->registry,
            capabilitiesSource: static fn (): array => ['originate', 'decompose', 'certify', 'merge'],
            lastRunOutcomeProvider: static fn (): ?string => null,
            capabilityMapper: static fn (array $case): array => ['merge'], // everything maps to merge
        );

        $report = $reporter->report();
        $this->assertCount(3, $report['per_capability']['merge']['case_ids']);
        $this->assertContains('originate', $report['capabilities_with_zero_cases']);
    }
}
