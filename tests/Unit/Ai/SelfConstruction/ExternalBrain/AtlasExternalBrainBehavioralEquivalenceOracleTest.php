<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBehavioralEquivalenceOracle;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainBehavioralEquivalenceOracleTest extends TestCase
{
    private function oracle(): AtlasExternalBrainBehavioralEquivalenceOracle
    {
        return new AtlasExternalBrainBehavioralEquivalenceOracle;
    }

    private function contract(array $overrides = []): array
    {
        return array_merge([
            'outputs' => ['status' => 'ok'],
            'errors' => ['InvalidArgumentException'],
            'side_effects' => ['writes_db'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    public function test_equivalent_contract_case(): void
    {
        $r = $this->oracle()->evaluate([
            'before' => $this->contract(),
            'after' => $this->contract(),
        ]);

        $this->assertSame('equivalent', $r['verdict']);
        $this->assertTrue($r['merge_approved']);
        $this->assertSame([], $r['divergences']);
    }

    public function test_divergence_fail_closed_case_output_mismatch(): void
    {
        $r = $this->oracle()->evaluate([
            'before' => $this->contract(),
            'after' => $this->contract(['outputs' => ['status' => 'different']]),
        ]);

        $this->assertSame('not_equivalent', $r['verdict']);
        $this->assertFalse($r['merge_approved']);
        $this->assertContains('output_divergence', $r['divergences']);
    }

    public function test_missing_before_contract_fails_closed(): void
    {
        $r = $this->oracle()->evaluate(['after' => $this->contract()]);

        $this->assertSame('not_equivalent', $r['verdict']);
        $this->assertFalse($r['merge_approved']);
        $this->assertContains('missing_before_contract', $r['divergences']);
    }

    public function test_missing_after_contract_fails_closed(): void
    {
        $r = $this->oracle()->evaluate(['before' => $this->contract()]);

        $this->assertSame('not_equivalent', $r['verdict']);
        $this->assertContains('missing_after_contract', $r['divergences']);
    }

    public function test_unknown_side_effect_fails_closed(): void
    {
        $r = $this->oracle()->evaluate([
            'before' => $this->contract(),
            'after' => $this->contract(['side_effects' => ['writes_db', 'unknown']]),
        ]);

        $this->assertSame('not_equivalent', $r['verdict']);
        $this->assertContains('unknown_side_effect', $r['divergences']);
    }

    public function test_error_contract_divergence_fails_closed(): void
    {
        $r = $this->oracle()->evaluate([
            'before' => $this->contract(),
            'after' => $this->contract(['errors' => ['RuntimeException']]),
        ]);

        $this->assertSame('not_equivalent', $r['verdict']);
        $this->assertContains('error_contract_divergence', $r['divergences']);
    }

    public function test_side_effect_set_order_does_not_matter(): void
    {
        $r = $this->oracle()->evaluate([
            'before' => $this->contract(['side_effects' => ['writes_db', 'emits_event']]),
            'after' => $this->contract(['side_effects' => ['emits_event', 'writes_db']]),
        ]);

        $this->assertSame('equivalent', $r['verdict']);
    }

    public function test_required_evidence_divergence_fails_closed(): void
    {
        $r = $this->oracle()->evaluate([
            'before' => $this->contract(),
            'after' => $this->contract(['required_evidence' => []]),
        ]);

        $this->assertSame('not_equivalent', $r['verdict']);
        $this->assertContains('required_evidence_divergence', $r['divergences']);
    }

    public function test_schema_present(): void
    {
        $r = $this->oracle()->evaluate([
            'before' => $this->contract(),
            'after' => $this->contract(),
        ]);

        $this->assertSame(AtlasExternalBrainBehavioralEquivalenceOracle::SCHEMA, $r['schema']);
    }
}
