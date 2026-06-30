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

    public function test_route_returns_matched_candidates_ordered_autonomous_before_supervised(): void
    {
        $r = new AtlasNativeWorkerCapabilityRegistry;

        // 'apply_scoped_patch' is native_supervised; 'inspect_task_packet' is native_autonomous
        $result = $r->route(['required_capabilities' => ['apply_scoped_patch', 'inspect_task_packet']]);

        $this->assertSame([], $result['unsupported_gap']);
        $ids = array_column($result['candidates'], 'capability_id');
        // autonomous must come before supervised regardless of input order
        $this->assertSame('inspect_task_packet', $ids[0], 'autonomous capability must be first');
        $this->assertSame('apply_scoped_patch',  $ids[1], 'supervised capability must follow');
    }

    public function test_route_returns_unsupported_gap_for_unknown_capability_id(): void
    {
        $r = new AtlasNativeWorkerCapabilityRegistry;

        $result = $r->route(['required_capabilities' => ['inspect_task_packet', 'non_existent_capability']]);

        $this->assertContains('non_existent_capability', $result['unsupported_gap']);
        $this->assertNotEmpty($result['candidates']);
        $this->assertSame('inspect_task_packet', $result['candidates'][0]['capability_id']);
    }

    public function test_route_excludes_supervised_capabilities_when_ceiling_is_autonomous(): void
    {
        $r = new AtlasNativeWorkerCapabilityRegistry;

        // 'apply_scoped_patch' and 'request_rollback' are native_supervised — above autonomous ceiling
        $result = $r->route([
            'required_capabilities' => ['inspect_task_packet', 'apply_scoped_patch', 'run_gates'],
            'risk_ceiling'          => AtlasNativeWorkerCapabilityRegistry::AUTONOMY_NATIVE_AUTONOMOUS,
        ]);

        $ids = array_column($result['candidates'], 'capability_id');
        $this->assertContains('inspect_task_packet', $ids);
        $this->assertContains('run_gates',           $ids);
        $this->assertNotContains('apply_scoped_patch', $ids, 'supervised capability must not appear when ceiling is autonomous');
        $this->assertContains('apply_scoped_patch', $result['unsupported_gap'], 'supervised capability must appear in unsupported_gap');
    }

    public function test_route_with_empty_requirements_returns_empty_candidates_and_no_gap(): void
    {
        $r      = new AtlasNativeWorkerCapabilityRegistry;
        $result = $r->route(['required_capabilities' => []]);

        $this->assertSame([], $result['candidates']);
        $this->assertSame([], $result['unsupported_gap']);
    }

    public function test_route_is_deterministic_across_two_calls(): void
    {
        $r      = new AtlasNativeWorkerCapabilityRegistry;
        $needs  = ['required_capabilities' => ['run_gates', 'write_evidence', 'inspect_task_packet']];
        $first  = $r->route($needs);
        $second = $r->route($needs);

        $this->assertSame(
            json_encode($first,  JSON_UNESCAPED_SLASHES),
            json_encode($second, JSON_UNESCAPED_SLASHES),
            'route() must be deterministic'
        );
    }

    public function test_no_scalar_score_or_percent_field_in_any_row(): void
    {
        $r = new AtlasNativeWorkerCapabilityRegistry;
        $json = (string) json_encode(array_merge($r->capabilities(), $r->bootstrapOwners()));
        $this->assertDoesNotMatchRegularExpression('/"(score|grade|percent|readiness_score)"/i', $json);
    }
}
