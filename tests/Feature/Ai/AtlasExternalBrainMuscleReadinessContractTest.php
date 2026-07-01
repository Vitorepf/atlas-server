<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleReadinessContract;
use Tests\TestCase;

final class AtlasExternalBrainMuscleReadinessContractTest extends TestCase
{
    private function fullyScopedSpec(): array
    {
        return [
            'task_packet_id' => 'task-1',
            'objective' => 'Wire AtlasFooBridge::translate() into the live command flow',
            'allowed_files' => ['app/Services/AtlasFooBridge.php', 'tests/Unit/AtlasFooBridgeTest.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit/AtlasFooBridgeTest.php exits 0'],
            'required_evidence' => ['tests_or_gates_result'],
            'risk_level' => 'low',
            'give_back_on_blocked' => true,
        ];
    }

    public function test_fully_scoped_impl_plus_test_packet_with_all_signals_is_ready(): void
    {
        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($this->fullyScopedSpec());

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['blocking_deficiencies']);
    }

    public function test_test_only_packet_is_not_ready(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['allowed_files'] = ['tests/Unit/AtlasFooBridgeTest.php'];

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertFalse($result['ready']);
        $this->assertContains('no_test_only_packet', $result['blocking_deficiencies']);
    }

    public function test_bare_directory_is_not_ready(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['allowed_files'] = ['app/Services'];

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertFalse($result['ready']);
        $this->assertContains('scoped_files', $result['blocking_deficiencies']);
    }

    public function test_wildcard_path_is_not_ready(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['allowed_files'] = ['app/Services/*.php', 'tests/Unit/AtlasFooBridgeTest.php'];

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertFalse($result['ready']);
        $this->assertContains('scoped_files', $result['blocking_deficiencies']);
    }

    public function test_path_traversal_is_not_ready(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['allowed_files'] = ['../etc/passwd', 'tests/Unit/AtlasFooBridgeTest.php'];

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertFalse($result['ready']);
        $this->assertContains('scoped_files', $result['blocking_deficiencies']);
    }

    public function test_vague_objective_is_not_ready(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['objective'] = 'make it better';

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertFalse($result['ready']);
        $this->assertContains('enough_context', $result['blocking_deficiencies']);
    }

    public function test_contradictory_acceptance_is_not_ready(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['acceptance_criteria'] = ['result must pass and must not pass the same test'];

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertFalse($result['ready']);
        $this->assertContains('acceptance_contradiction_risk', $result['blocking_deficiencies']);
    }

    public function test_critical_operator_only_risk_is_not_ready(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['risk_level'] = 'critical';

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertFalse($result['ready']);
        $this->assertContains('bounded_risk', $result['blocking_deficiencies']);
        $this->assertSame(AtlasExternalBrainMuscleReadinessContract::CATEGORY_OPERATOR_ONLY, $result['failure_category']);
    }

    public function test_active_claim_collision_is_not_ready(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['active_claims'] = [
            ['worker_id' => 'worker-2', 'claimed_files' => ['app/Services/AtlasFooBridge.php']],
        ];

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertFalse($result['ready']);
        $this->assertContains('no_collision', $result['blocking_deficiencies']);
    }

    public function test_mismatched_worker_capabilities_increase_blocking_deficiencies_and_risk_score(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['task_family'] = 'wiring';
        $spec['risk_level'] = 'medium';
        $spec['model_tier'] = 'frontier';
        $spec['worker_capabilities'] = [
            ['task_families' => ['refactor'], 'risk_levels' => ['low'], 'model_tiers' => ['small']],
        ];

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertFalse($result['ready']);
        $this->assertContains('worker_capability_fit', $result['blocking_deficiencies']);
        $this->assertGreaterThan(0.0, $result['give_back_risk_score']);
    }

    public function test_matching_worker_capabilities_do_not_block(): void
    {
        $spec = $this->fullyScopedSpec();
        $spec['task_family'] = 'wiring';
        $spec['risk_level'] = 'low';
        $spec['model_tier'] = 'small';
        $spec['worker_capabilities'] = [
            ['task_families' => ['wiring'], 'risk_levels' => ['low'], 'model_tiers' => ['small']],
        ];

        $result = (new AtlasExternalBrainMuscleReadinessContract)->check($spec);

        $this->assertTrue($result['ready']);
    }
}
