<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionInvariantSynthesizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionInvariantSynthesizerTest extends TestCase
{
    private function synthesizer(): AtlasExternalBrainCompressionInvariantSynthesizer
    {
        return new AtlasExternalBrainCompressionInvariantSynthesizer;
    }

    public function test_invariants_for_action_case_delete(): void
    {
        $r = $this->synthesizer()->synthesize(['action' => 'delete']);

        $this->assertSame('invariants_required', $r['decision']);
        $this->assertContains('zero_active_consumers', $r['required_invariants']);
        $this->assertContains('behavior_lock_proof', $r['required_invariants']);
        $this->assertContains('rollback_evidence', $r['required_invariants']);
    }

    public function test_invariants_for_merge_action(): void
    {
        $r = $this->synthesizer()->synthesize(['action' => 'merge']);

        $this->assertSame('invariants_required', $r['decision']);
        $this->assertContains('io_contract_equivalence', $r['required_invariants']);
        $this->assertContains('no_duplicate_side_effects', $r['required_invariants']);
    }

    public function test_invariants_for_collapse_action(): void
    {
        $r = $this->synthesizer()->synthesize(['action' => 'collapse']);

        $this->assertSame('invariants_required', $r['decision']);
        $this->assertContains('call_path_preserved', $r['required_invariants']);
    }

    public function test_invariants_for_boundary_tightening_action(): void
    {
        $r = $this->synthesizer()->synthesize(['action' => 'boundary_tightening']);

        $this->assertSame('invariants_required', $r['decision']);
        $this->assertContains('no_external_caller_broken', $r['required_invariants']);
    }

    public function test_unknown_action_hold_case(): void
    {
        $r = $this->synthesizer()->synthesize(['action' => 'mutate_randomly']);

        $this->assertSame('hold_invariants', $r['decision']);
        $this->assertSame([], $r['required_invariants']);
    }

    public function test_missing_action_holds(): void
    {
        $r = $this->synthesizer()->synthesize([]);

        $this->assertSame('hold_invariants', $r['decision']);
    }

    public function test_schema_present(): void
    {
        $r = $this->synthesizer()->synthesize(['action' => 'delete']);

        $this->assertSame(AtlasExternalBrainCompressionInvariantSynthesizer::SCHEMA, $r['schema']);
    }
}
