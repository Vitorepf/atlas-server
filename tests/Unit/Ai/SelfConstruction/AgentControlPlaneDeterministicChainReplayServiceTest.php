<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

final class AgentControlPlaneDeterministicChainReplayServiceTest extends TestCase
{
    public function test_payload_names_volatile_fields_excluded_from_deterministic_hash(): void
    {
        $replay = $this->newService()->replay();

        $this->assertSame(
            AgentControlPlaneDeterministicChainReplayService::DETERMINISTIC_HASH_EXCLUDED_FIELDS,
            data_get($replay, 'deterministic_hash_exclusions'),
        );
        $this->assertContains('generated_at', $replay['deterministic_hash_exclusions']);
        $this->assertContains('replay_id', $replay['deterministic_hash_exclusions']);
    }

    public function test_two_replays_with_same_override_inputs_produce_identical_deterministic_hash(): void
    {
        $options = [
            'override_chain_integrity' => ['status' => 'available'],
        ];

        $first = $this->newService()->replay($options);
        $second = $this->newService()->replay($options);

        $this->assertSame($first['deterministic_replay_hash'], $second['deterministic_replay_hash']);
        $this->assertNotSame($first['replay_id'], $second['replay_id']);
    }

    public function test_replay_includes_minimum_proof_bundle_requirements(): void
    {
        $replay = $this->newService()->replay();

        $this->assertArrayHasKey('minimum_proof_bundle_requirements', $replay);
        foreach (['slices', 'edges', 'proof_bundle', 'runtime_safety', 'cycle_integrity', 'terminal_horizon'] as $component) {
            $this->assertArrayHasKey($component, $replay['minimum_proof_bundle_requirements'], "missing minimum proof bundle component: {$component}");
        }
    }

    public function test_readiness_claim_allowed_is_false_when_proof_bundle_excluded(): void
    {
        $replay = $this->newService()->replay(['include_proof_bundle' => false]);

        $this->assertFalse($replay['minimum_proof_bundle_requirements']['proof_bundle']);
        $this->assertFalse($replay['readiness_claim_allowed']);
    }

    public function test_readiness_claim_allowed_is_false_when_slices_excluded(): void
    {
        $replay = $this->newService()->replay(['include_slices' => false]);

        $this->assertFalse($replay['minimum_proof_bundle_requirements']['slices']);
        $this->assertFalse($replay['readiness_claim_allowed']);
    }

    public function test_deterministic_replay_hash_changes_when_proof_content_changes(): void
    {
        $first = $this->newService()->replay(['override_chain_integrity' => ['status' => 'available']]);
        $second = $this->newService()->replay(['override_chain_integrity' => ['status' => 'blocked']]);

        $this->assertNotSame($first['deterministic_replay_hash'], $second['deterministic_replay_hash']);
    }

    private function newService(): AgentControlPlaneDeterministicChainReplayService
    {
        $audit = new AgentControlPlaneChainIntegrityAuditService($this->readiness());

        return new AgentControlPlaneDeterministicChainReplayService($audit, $this->readiness());
    }

    private function readiness(): AtlasSelfConstructionReadinessService
    {
        return app(AtlasSelfConstructionReadinessService::class);
    }
}
