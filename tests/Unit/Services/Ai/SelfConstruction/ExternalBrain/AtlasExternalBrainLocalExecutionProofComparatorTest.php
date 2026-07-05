<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalExecutionProofComparator;
use Tests\TestCase;

final class AtlasExternalBrainLocalExecutionProofComparatorTest extends TestCase
{
    private function comparator(): AtlasExternalBrainLocalExecutionProofComparator
    {
        return new AtlasExternalBrainLocalExecutionProofComparator;
    }

    // ── AC: mismatched test output produces distinct verdict ──

    public function test_mismatched_test_output_detected(): void
    {
        $result = $this->comparator()->compare([
            ['client_id' => 'a', 'test_output' => 'PASS', 'gate_output' => 'ok', 'outcome' => 'success'],
            ['client_id' => 'b', 'test_output' => 'FAIL', 'gate_output' => 'ok', 'outcome' => 'give_back'],
        ]);

        $this->assertSame('mismatched_test_output', $result['verdict']);
    }

    // ── AC: missing gate output produces distinct verdict ──

    public function test_missing_gate_output_detected(): void
    {
        $result = $this->comparator()->compare([
            ['client_id' => 'a', 'test_output' => 'PASS', 'gate_output' => 'ok', 'outcome' => 'success'],
            ['client_id' => 'b', 'test_output' => 'PASS', 'gate_output' => '', 'outcome' => 'success'],
        ]);

        $this->assertSame('missing_gate_output', $result['verdict']);
    }

    // ── AC: consistent proof produces distinct verdict ──

    public function test_consistent_proof_detected(): void
    {
        $result = $this->comparator()->compare([
            ['client_id' => 'a', 'test_output' => 'PASS', 'gate_output' => 'ok', 'outcome' => 'success'],
            ['client_id' => 'b', 'test_output' => 'PASS', 'gate_output' => 'ok', 'outcome' => 'success'],
        ]);

        $this->assertSame('consistent', $result['verdict']);
    }

    // ── fake green detection ──

    public function test_fake_green_detected(): void
    {
        $result = $this->comparator()->compare([
            ['client_id' => 'a', 'test_output' => 'PASS', 'gate_output' => 'ok', 'outcome' => 'success'],
            ['client_id' => 'b', 'test_output' => '', 'gate_output' => 'ok', 'outcome' => 'success'],
        ]);

        $this->assertSame('fake_green', $result['verdict']);
        $this->assertContains('b', $result['fake_green_clients']);
    }

    // ── single client → consistent ──

    public function test_single_client_consistent(): void
    {
        $result = $this->comparator()->compare([
            ['client_id' => 'a', 'test_output' => 'PASS', 'gate_output' => 'ok', 'outcome' => 'success'],
        ]);

        $this->assertSame('consistent', $result['verdict']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->comparator()->compare([]);

        $this->assertSame(AtlasExternalBrainLocalExecutionProofComparator::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('reasons', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $proofs = [
            ['client_id' => 'a', 'test_output' => 'PASS', 'gate_output' => 'ok', 'outcome' => 'success'],
            ['client_id' => 'b', 'test_output' => 'PASS', 'gate_output' => 'ok', 'outcome' => 'success'],
        ];

        $a = $this->comparator()->compare($proofs);
        $b = $this->comparator()->compare($proofs);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
