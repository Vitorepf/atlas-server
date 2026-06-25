<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerCapabilityRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Proves the AtlasNativeWorkerCapabilityRegistry contract: deterministic ordering; 7 Atlas-native final
 * capabilities; external_provider_worker + human_operator marked bootstrap_only; no duplicate
 * capability_id across the union.
 */
final class AtlasNativeWorkerCapabilityRegistryTest extends TestCase
{
    public function test_capabilities_returns_the_seven_canonical_atlas_native_rows_in_fixed_order(): void
    {
        $rows = (new AtlasNativeWorkerCapabilityRegistry)->capabilities();
        $ids = array_column($rows, 'capability_id');

        $this->assertSame([
            'inspect_task_packet',
            'prepare_patch_plan',
            'apply_scoped_patch',
            'run_gates',
            'write_evidence',
            'request_rollback',
            'learn_from_receipt',
        ], $ids, 'deterministic ordering required');
    }

    public function test_two_invocations_return_byte_identical_json(): void
    {
        $r = new AtlasNativeWorkerCapabilityRegistry;
        $a = json_encode($r->capabilities(), JSON_UNESCAPED_SLASHES);
        $b = json_encode($r->capabilities(), JSON_UNESCAPED_SLASHES);
        $this->assertSame($a, $b);
    }

    public function test_each_capability_row_carries_the_required_fact_keys(): void
    {
        foreach ((new AtlasNativeWorkerCapabilityRegistry)->capabilities() as $row) {
            foreach (['capability_id', 'autonomy_level', 'required_inputs', 'outputs', 'forbidden_side_effects', 'readiness_requirements'] as $key) {
                $this->assertArrayHasKey($key, $row, "row missing {$key}");
            }
            $this->assertIsArray($row['required_inputs']);
            $this->assertIsArray($row['outputs']);
            $this->assertIsArray($row['forbidden_side_effects']);
            $this->assertIsArray($row['readiness_requirements']);
        }
    }

    public function test_atlas_native_final_capabilities_use_native_autonomy_levels_only(): void
    {
        $allowed = [
            AtlasNativeWorkerCapabilityRegistry::AUTONOMY_NATIVE_AUTONOMOUS,
            AtlasNativeWorkerCapabilityRegistry::AUTONOMY_NATIVE_SUPERVISED,
        ];
        foreach ((new AtlasNativeWorkerCapabilityRegistry)->capabilities() as $row) {
            $this->assertContains($row['autonomy_level'], $allowed, 'final capability must be native_autonomous or native_supervised');
        }
    }

    public function test_external_provider_worker_and_human_operator_are_bootstrap_only(): void
    {
        $bootstrap = (new AtlasNativeWorkerCapabilityRegistry)->bootstrapOwners();
        $byId = [];
        foreach ($bootstrap as $row) {
            $byId[$row['capability_id']] = $row;
        }
        $this->assertArrayHasKey('external_provider_worker', $byId);
        $this->assertArrayHasKey('human_operator', $byId);
        $this->assertSame(AtlasNativeWorkerCapabilityRegistry::AUTONOMY_BOOTSTRAP_ONLY, $byId['external_provider_worker']['autonomy_level']);
        $this->assertTrue($byId['external_provider_worker']['bootstrap_only'] ?? false);
        $this->assertTrue($byId['human_operator']['bootstrap_only'] ?? false);
    }

    public function test_no_duplicate_capability_id_across_final_and_bootstrap(): void
    {
        $ids = (new AtlasNativeWorkerCapabilityRegistry)->allIds();
        $this->assertCount(count(array_unique($ids)), $ids, 'no duplicate capability_id across the union');
    }

    public function test_no_scalar_score_or_percent_field_in_any_row(): void
    {
        $r = new AtlasNativeWorkerCapabilityRegistry;
        $json = (string) json_encode(array_merge($r->capabilities(), $r->bootstrapOwners()));
        $this->assertDoesNotMatchRegularExpression('/"(score|grade|percent|readiness_score)"/i', $json);
    }
}
