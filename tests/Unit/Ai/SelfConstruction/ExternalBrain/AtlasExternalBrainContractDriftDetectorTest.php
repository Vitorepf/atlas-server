<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainContractDriftDetector;
use Tests\TestCase;

final class AtlasExternalBrainContractDriftDetectorTest extends TestCase
{
    private function detector(): AtlasExternalBrainContractDriftDetector
    {
        return new AtlasExternalBrainContractDriftDetector;
    }

    public function test_schema_present(): void
    {
        $r = $this->detector()->detect([]);
        $this->assertSame(AtlasExternalBrainContractDriftDetector::SCHEMA, $r['schema']);
    }

    // ── AC: no drift when all three sources agree → approved ─────────────────

    public function test_no_drift_yields_approved_compression(): void
    {
        $r = $this->detector()->detect([
            'documented_contracts' => ['return_type' => 'array', 'method' => 'process'],
            'test_contracts' => ['return_type' => 'array', 'method' => 'process'],
            'runtime_contracts' => ['return_type' => 'array', 'method' => 'process'],
        ]);

        $this->assertFalse($r['drift_detected']);
        $this->assertSame([], $r['drift_paths']);
        $this->assertSame(AtlasExternalBrainContractDriftDetector::APPROVAL_APPROVED, $r['compression_approval']);
    }

    public function test_empty_input_has_no_drift(): void
    {
        $r = $this->detector()->detect([]);
        $this->assertFalse($r['drift_detected']);
        $this->assertSame(AtlasExternalBrainContractDriftDetector::APPROVAL_APPROVED, $r['compression_approval']);
    }

    // ── AC: any drift blocks destructive compression ─────────────────────────

    public function test_value_mismatch_produces_drift_and_blocks_compression(): void
    {
        $r = $this->detector()->detect([
            'documented_contracts' => ['return_type' => 'array'],
            'test_contracts' => ['return_type' => 'array'],
            'runtime_contracts' => ['return_type' => 'Collection'],
        ]);

        $this->assertTrue($r['drift_detected']);
        $this->assertSame(AtlasExternalBrainContractDriftDetector::APPROVAL_BLOCKED, $r['compression_approval']);
        $this->assertSame('return_type', $r['drift_paths'][0]['path']);
        $this->assertSame('array', $r['drift_paths'][0]['documented']);
        $this->assertSame('array', $r['drift_paths'][0]['test']);
        $this->assertSame('Collection', $r['drift_paths'][0]['runtime']);
    }

    public function test_path_missing_from_one_source_is_drift(): void
    {
        $r = $this->detector()->detect([
            'documented_contracts' => ['field_x' => 'string'],
            'test_contracts' => ['field_x' => 'string'],
            'runtime_contracts' => [], // silent on field_x entirely
        ]);

        $this->assertTrue($r['drift_detected']);
        $drift = $r['drift_paths'][0];
        $this->assertSame('field_x', $drift['path']);
        $this->assertSame('string', $drift['documented']);
        $this->assertSame('string', $drift['test']);
        $this->assertStringContainsString('missing', $drift['runtime']);
    }

    // ── nested contracts compared via dot-notation paths ──────────────────────

    public function test_nested_contract_drift_reports_dot_path(): void
    {
        $r = $this->detector()->detect([
            'documented_contracts' => ['response' => ['fields' => ['id' => 'int']]],
            'test_contracts' => ['response' => ['fields' => ['id' => 'int']]],
            'runtime_contracts' => ['response' => ['fields' => ['id' => 'string']]],
        ]);

        $this->assertTrue($r['drift_detected']);
        $paths = array_column($r['drift_paths'], 'path');
        $this->assertContains('response.fields.id', $paths);
    }

    public function test_nested_contract_with_no_drift_is_approved(): void
    {
        $contract = ['response' => ['fields' => ['id' => 'int', 'name' => 'string']]];
        $r = $this->detector()->detect([
            'documented_contracts' => $contract,
            'test_contracts' => $contract,
            'runtime_contracts' => $contract,
        ]);

        $this->assertFalse($r['drift_detected']);
        $this->assertSame(AtlasExternalBrainContractDriftDetector::APPROVAL_APPROVED, $r['compression_approval']);
    }

    // ── multiple drift paths all reported, not just the first ────────────────

    public function test_multiple_drift_paths_all_reported(): void
    {
        $r = $this->detector()->detect([
            'documented_contracts' => ['a' => '1', 'b' => '2'],
            'test_contracts' => ['a' => '1', 'b' => '2'],
            'runtime_contracts' => ['a' => 'DIFFERENT', 'b' => 'ALSO_DIFFERENT'],
        ]);

        $this->assertCount(2, $r['drift_paths']);
        $paths = array_column($r['drift_paths'], 'path');
        $this->assertContains('a', $paths);
        $this->assertContains('b', $paths);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_detect_is_deterministic(): void
    {
        $input = [
            'documented_contracts' => ['x' => '1'],
            'test_contracts' => ['x' => '2'],
            'runtime_contracts' => ['x' => '3'],
        ];

        $this->assertSame(
            json_encode($this->detector()->detect($input)),
            json_encode($this->detector()->detect($input)),
        );
    }

    public function test_drift_paths_sorted_deterministically(): void
    {
        $r = $this->detector()->detect([
            'documented_contracts' => ['zeta' => '1', 'alpha' => '1'],
            'test_contracts' => ['zeta' => '2', 'alpha' => '2'],
            'runtime_contracts' => ['zeta' => '3', 'alpha' => '3'],
        ]);

        $paths = array_column($r['drift_paths'], 'path');
        $this->assertSame(['alpha', 'zeta'], $paths);
    }
}
