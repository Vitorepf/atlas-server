<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityDriftWorkProposer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCapabilityDriftWorkProposerTest extends TestCase
{
    private function finding(array $overrides = []): array
    {
        return array_merge([
            'area_id' => 'routing',
            'drift_type' => 'outcome_regression',
            'impact_level' => 'high',
            'confidence' => 'high',
            'evidence_needed' => ['frozen_route', 'current_outcome'],
            'repair_action' => 'revalidate_route',
            'task_fabric_advice' => 'proceed_with_caution_pending_repair',
        ], $overrides);
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'findings' => [$this->finding()],
            'frozen_facts' => ['route_version' => 'r1', 'world_hash' => 'w1', 'claim_version' => 'c1'],
            'current_facts' => ['route_version' => 'r2', 'world_hash' => 'w2', 'claim_version' => 'c1', 'outcome' => 'failure'],
            'target_paths' => ['routing' => 'app/Services/Ai/AtlasRoutingService.php'],
            'existing_proposals' => [],
            'active_claims' => [],
            'active_reservations' => [],
        ], $overrides);
    }

    public function test_material_drift_emits_one_bridge_ready_governed_proposal(): void
    {
        $result = (new AtlasExternalBrainCapabilityDriftWorkProposer)->propose($this->input());

        $this->assertTrue($result['has_material_drift']);
        $this->assertCount(1, $result['proposals']);
        $proposal = $result['proposals'][0];
        $this->assertSame('task_fabric_proposal', $proposal['destination']);
        foreach (['finding', 'baseline', 'expected_structural_delta', 'red_behavior', 'green_acceptance', 'rollback', 'outcome_metric'] as $field) {
            $this->assertNotEmpty($proposal[$field]);
        }
        $this->assertSame('app/Services/Ai/AtlasRoutingService.php', $proposal['allowed_files'][0]);
        $this->assertNotEmpty($proposal['evidence_refs']);
    }

    public function test_unknown_drift_stays_unknown_and_emits_no_work(): void
    {
        $result = (new AtlasExternalBrainCapabilityDriftWorkProposer)->propose($this->input([
            'current_facts' => [],
        ]));

        $this->assertFalse($result['has_material_drift']);
        $this->assertSame([], $result['proposals']);
        $this->assertSame('unknown', $result['findings'][0]['classification']);
    }

    public function test_active_claim_or_reservation_blocks_without_mutating_it(): void
    {
        $result = (new AtlasExternalBrainCapabilityDriftWorkProposer)->propose($this->input([
            'active_claims' => [['area_id' => 'routing']],
            'active_reservations' => [['area_id' => 'other']],
        ]));

        $this->assertSame([], $result['proposals']);
        $this->assertSame('active_claim', $result['findings'][0]['blocked_reason']);
        $this->assertSame([['area_id' => 'routing']], $result['active_claims']);
    }

    public function test_recurring_same_fingerprint_is_deduplicated(): void
    {
        $result = (new AtlasExternalBrainCapabilityDriftWorkProposer)->propose($this->input([
            'existing_proposals' => [['fingerprint' => 'routing:outcome_regression:r1:r2:w1:w2:c1:c1']],
        ]));

        $this->assertSame([], $result['proposals']);
        $this->assertSame('duplicate_fingerprint', $result['findings'][0]['blocked_reason']);
    }

    public function test_expected_drift_is_observed_without_originating_repair_work(): void
    {
        $result = (new AtlasExternalBrainCapabilityDriftWorkProposer)->propose($this->input([
            'findings' => [$this->finding(['expected_drift' => true])],
        ]));

        $this->assertFalse($result['has_material_drift']);
        $this->assertSame('expected_drift', $result['findings'][0]['classification']);
        $this->assertSame('expected_drift', $result['findings'][0]['blocked_reason']);
    }

    public function test_model_provider_tool_and_harness_regressions_share_the_same_governed_path(): void
    {
        $findings = array_map(
            fn (string $source): array => $this->finding([
                'area_id' => $source,
                'drift_type' => $source.'_drift',
                'material_regression' => true,
            ]),
            ['model', 'provider', 'tool', 'harness'],
        );
        $input = $this->input([
            'findings' => $findings,
            'target_paths' => [
                'model' => 'app/Services/Ai/ModelRoute.php',
                'provider' => 'app/Services/Ai/ProviderPort.php',
                'tool' => 'app/Services/Ai/ToolGateway.php',
                'harness' => 'app/Services/Ai/HarnessContract.php',
            ],
        ]);

        $result = (new AtlasExternalBrainCapabilityDriftWorkProposer)->propose($input);

        $this->assertCount(4, $result['proposals']);
        $this->assertSame(
            ['model', 'provider', 'tool', 'harness'],
            array_column($result['findings'], 'area_id'),
        );
        $this->assertSame(
            ['task_fabric_proposal', 'task_fabric_proposal', 'task_fabric_proposal', 'task_fabric_proposal'],
            array_column($result['proposals'], 'destination'),
        );
    }

    public function test_calibration_decline_and_benchmark_frontier_movement_emit_governed_work(): void
    {
        $result = (new AtlasExternalBrainCapabilityDriftWorkProposer)->propose($this->input([
            'findings' => [
                $this->finding(['area_id' => 'calibration', 'drift_type' => 'declining_calibration', 'material_regression' => true]),
                $this->finding(['area_id' => 'frontier', 'drift_type' => 'benchmark_frontier_movement', 'material_regression' => true]),
            ],
            'target_paths' => ['calibration' => 'app/Services/Ai/Calibration.php', 'frontier' => 'app/Services/Ai/Frontier.php'],
        ]));

        self::assertCount(2, $result['proposals']);
        self::assertSame(['calibration', 'frontier'], array_column($result['proposals'], 'destination') === [] ? [] : array_column($result['findings'], 'area_id'));
    }

    public function test_expired_claim_does_not_block_repair_but_active_claim_does(): void
    {
        $service = new AtlasExternalBrainCapabilityDriftWorkProposer;
        $expired = $service->propose($this->input(['active_claims' => [['area_id' => 'routing', 'expires_at' => '2020-01-01T00:00:00Z']]]));
        $active = $service->propose($this->input(['active_claims' => [['area_id' => 'routing', 'expires_at' => '2099-01-01T00:00:00Z']]]));

        self::assertCount(1, $expired['proposals']);
        self::assertSame('active_claim', $active['findings'][0]['blocked_reason']);
    }

    public function test_alert_storm_duplicates_collapse_to_one_proposal_per_fingerprint(): void
    {
        $finding = $this->finding(['material_regression' => true]);
        $result = (new AtlasExternalBrainCapabilityDriftWorkProposer)->propose($this->input(['findings' => [$finding, $finding]]));

        self::assertCount(1, $result['proposals']);
        self::assertSame('duplicate_fingerprint', $result['findings'][1]['blocked_reason']);
    }
}
