<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionScopeSlicer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionScopeSlicerTest extends TestCase
{
    private function slicer(): AtlasExternalBrainCompressionScopeSlicer
    {
        return new AtlasExternalBrainCompressionScopeSlicer;
    }

    // ── AC: bounded_slice_case ──────────────────────────────────────────────

    public function test_bounded_slice_case_produces_slice_with_primary_target_tests_and_dependency_order(): void
    {
        $r = $this->slicer()->slice(['plan' => ['targets' => [
            [
                'target' => 'FooService',
                'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
                'tests' => ['FooTest'],
                'depends_on' => ['BarService'],
            ],
        ]]]);

        $this->assertCount(1, $r['slices']);
        $slice = $r['slices'][0];
        $this->assertSame('FooService', $slice['primary_target']);
        $this->assertSame(['app/Foo.php', 'tests/FooTest.php'], $slice['allowed_files']);
        $this->assertSame(['FooTest'], $slice['tests']);
        $this->assertSame(['BarService'], $slice['dependency_order']);
        $this->assertSame([], $r['rejected']);
    }

    // ── AC: broad_scope_rejection_case ──────────────────────────────────────

    public function test_broad_scope_rejection_case_when_allowed_files_exceed_bound(): void
    {
        $r = $this->slicer()->slice(['plan' => [
            'max_files_per_slice' => 2,
            'targets' => [[
                'target' => 'BigTarget',
                'allowed_files' => ['a.php', 'b.php', 'c.php'],
                'tests' => ['BigTest'],
            ]],
        ]]);

        $this->assertSame([], $r['slices']);
        $this->assertCount(1, $r['rejected']);
        $this->assertSame('BigTarget', $r['rejected'][0]['target']);
        $this->assertContains('allowed_files_exceeds_bound', $r['rejected'][0]['split_reasons']);
    }

    public function test_missing_tests_rejects_slice(): void
    {
        $r = $this->slicer()->slice(['plan' => ['targets' => [[
            'target' => 'NoTestsTarget',
            'allowed_files' => ['a.php'],
        ]]]]);

        $this->assertContains('missing_tests', $r['rejected'][0]['split_reasons']);
    }

    public function test_missing_allowed_files_rejects_slice(): void
    {
        $r = $this->slicer()->slice(['plan' => ['targets' => [[
            'target' => 'NoFilesTarget',
            'tests' => ['T1'],
        ]]]]);

        $this->assertContains('missing_allowed_files', $r['rejected'][0]['split_reasons']);
    }

    public function test_multiple_split_reasons_all_named(): void
    {
        $r = $this->slicer()->slice(['plan' => ['targets' => [[
            'target' => 'BadTarget',
        ]]]]);

        $this->assertCount(2, $r['rejected'][0]['split_reasons']);
    }

    // ── default bound ─────────────────────────────────────────────────────────

    public function test_default_max_files_per_slice_is_five(): void
    {
        $r = $this->slicer()->slice(['plan' => ['targets' => [[
            'target' => 'FiveFiles',
            'allowed_files' => ['1.php', '2.php', '3.php', '4.php', '5.php'],
            'tests' => ['T1'],
        ]]]]);

        $this->assertCount(1, $r['slices']);
    }

    public function test_six_files_exceeds_default_bound(): void
    {
        $r = $this->slicer()->slice(['plan' => ['targets' => [[
            'target' => 'SixFiles',
            'allowed_files' => ['1.php', '2.php', '3.php', '4.php', '5.php', '6.php'],
            'tests' => ['T1'],
        ]]]]);

        $this->assertContains('allowed_files_exceeds_bound', $r['rejected'][0]['split_reasons']);
    }

    // ── multiple targets ────────────────────────────────────────────────────

    public function test_multiple_targets_split_independently(): void
    {
        $r = $this->slicer()->slice(['plan' => ['targets' => [
            ['target' => 'Good', 'allowed_files' => ['a.php'], 'tests' => ['T1']],
            ['target' => 'Bad', 'allowed_files' => [], 'tests' => []],
        ]]]);

        $this->assertCount(1, $r['slices']);
        $this->assertCount(1, $r['rejected']);
        $this->assertSame('Good', $r['slices'][0]['primary_target']);
        $this->assertSame('Bad', $r['rejected'][0]['target']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_slice_is_deterministic(): void
    {
        $facts = ['plan' => ['targets' => [['target' => 'X', 'allowed_files' => ['a.php'], 'tests' => ['T1']]]]];
        $a = $this->slicer()->slice($facts);
        $b = $this->slicer()->slice($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->slicer()->slice([]);
        $this->assertSame(AtlasExternalBrainCompressionScopeSlicer::SCHEMA, $r['schema']);
    }
}
