<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationMutationProbe;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationMutationProbeTest extends TestCase
{
    private function probe(): AtlasExternalBrainSimplificationMutationProbe
    {
        return new AtlasExternalBrainSimplificationMutationProbe;
    }

    // ── AC: ready_with_mutation_guards_case ────────────────────────────────────

    public function test_ready_with_mutation_guards_case_when_all_three_covered(): void
    {
        $r = $this->probe()->probe(['mutation_test_coverage' => [
            'removed_branch' => true,
            'changed_output' => true,
            'missing_consumer' => true,
        ]]);

        $this->assertTrue($r['ready']);
        $this->assertSame([], $r['missing_mutation_guards']);
    }

    // ── AC: missing_guard_case — exact missing guard names ────────────────────

    public function test_missing_guard_case_names_removed_branch(): void
    {
        $r = $this->probe()->probe(['mutation_test_coverage' => [
            'removed_branch' => false,
            'changed_output' => true,
            'missing_consumer' => true,
        ]]);

        $this->assertFalse($r['ready']);
        $this->assertSame(['removed_branch'], $r['missing_mutation_guards']);
    }

    public function test_missing_guard_case_names_changed_output(): void
    {
        $r = $this->probe()->probe(['mutation_test_coverage' => [
            'removed_branch' => true,
            'changed_output' => false,
            'missing_consumer' => true,
        ]]);

        $this->assertFalse($r['ready']);
        $this->assertSame(['changed_output'], $r['missing_mutation_guards']);
    }

    public function test_missing_guard_case_names_missing_consumer(): void
    {
        $r = $this->probe()->probe(['mutation_test_coverage' => [
            'removed_branch' => true,
            'changed_output' => true,
            'missing_consumer' => false,
        ]]);

        $this->assertFalse($r['ready']);
        $this->assertSame(['missing_consumer'], $r['missing_mutation_guards']);
    }

    public function test_missing_guard_case_names_all_when_none_covered(): void
    {
        $r = $this->probe()->probe([]);

        $this->assertFalse($r['ready']);
        $this->assertSame(['removed_branch', 'changed_output', 'missing_consumer'], $r['missing_mutation_guards']);
    }

    public function test_partial_coverage_names_only_the_missing_two(): void
    {
        $r = $this->probe()->probe(['mutation_test_coverage' => ['removed_branch' => true]]);

        $this->assertFalse($r['ready']);
        $this->assertSame(['changed_output', 'missing_consumer'], $r['missing_mutation_guards']);
    }

    // ── Determinism ────────────────────────────────────────────────────────────

    public function test_probe_is_deterministic(): void
    {
        $facts = ['mutation_test_coverage' => ['removed_branch' => true, 'changed_output' => false]];
        $a = $this->probe()->probe($facts);
        $b = $this->probe()->probe($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->probe()->probe([]);
        $this->assertSame(AtlasExternalBrainSimplificationMutationProbe::SCHEMA, $r['schema']);
    }
}
