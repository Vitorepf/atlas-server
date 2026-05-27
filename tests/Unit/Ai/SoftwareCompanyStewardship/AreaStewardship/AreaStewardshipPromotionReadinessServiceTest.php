<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipPromotionReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use Tests\TestCase;

class AreaStewardshipPromotionReadinessServiceTest extends TestCase
{
    private function service(): AreaStewardshipPromotionReadinessService
    {
        return app(AreaStewardshipPromotionReadinessService::class);
    }

    /**
     * @param  array<string,mixed>  $areaOverrides
     * @return array<string,mixed>
     */
    private function evolutionReport(array $areaOverrides = []): array
    {
        return [
            'schema_version' => StewardshipEvolutionReadModelService::REPORT_SCHEMA,
            'status' => StewardshipEvolutionReadModelService::STATUS_READY_PROPOSAL_ONLY,
            'area_stewardship' => array_merge([
                'schema_version' => StewardshipEvolutionReadModelService::AREA_STEWARD_SCHEMA,
                'status' => StewardshipEvolutionReadModelService::STATUS_READY_PROPOSAL_ONLY,
                'mode' => 'read_only',
                'area_id' => 'agentic_engineering_os',
                'area_name' => 'Agentic Engineering OS',
                'health_model' => [
                    'schema_version' => 'atlas.area.health_model.v1',
                    'score' => 91,
                    'band' => 'excellent',
                ],
                'roadmap_candidates' => [
                    ['candidate_id' => 'roadmap_1', 'summary' => 'Improve AP handoff', 'requires_operator_review' => true],
                ],
                'dev_forge_policy' => [
                    'small_local_work' => 'atlas_dev',
                    'cross_system_or_long_horizon_work' => 'forge',
                ],
                'operator_inbox' => [
                    'destination' => 'morning_inbox',
                    'auto_approval' => false,
                ],
            ], $areaOverrides),
            'area_focus_loop' => [
                'status' => 'ready',
                'evidence_refs' => ['docs/ap/AP-716-area-focus-loop-core-read-model-contract.md'],
            ],
            'claim_policy' => [
                'repo_mutation' => false,
                'merge_without_operator' => false,
                'deploy_without_operator' => false,
                'secret_access' => false,
            ],
        ];
    }

    public function test_ready_for_operator_review_when_only_acceptance_is_missing(): void
    {
        $report = $this->service()->assess([
            'area_id' => 'agentic_engineering_os',
            'evolution_report' => $this->evolutionReport(),
            'decision_ledger' => ['decisions' => []],
        ]);

        $this->assertSame(AreaStewardshipPromotionReadinessService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_OPERATOR_REVIEW, $report['status']);
        $this->assertSame(['operator_accept_decision_missing'], $report['blockers']);
        $this->assertSame('missing', $report['operator_acceptance']['status']);
        $this->assertFalse($report['claim_policy']['mutates_target_repo']);
    }

    public function test_ready_for_active_handoff_after_ap731_acceptance(): void
    {
        $initial = $this->service()->assess([
            'area_id' => 'agentic_engineering_os',
            'evolution_report' => $this->evolutionReport(),
            'decision_ledger' => ['decisions' => []],
        ]);

        $report = $this->service()->assess([
            'area_id' => 'agentic_engineering_os',
            'evolution_report' => $this->evolutionReport(),
            'decision_ledger' => [
                'decisions' => [[
                    'decision_id' => 'seod_area_accept',
                    'target_type' => 'area_stewardship',
                    'target_id' => 'agentic_engineering_os',
                    'target_hash' => $initial['target_hash'],
                    'decision' => 'accept',
                    'operator_actor' => 'vitor',
                    'recorded_at' => '2026-05-27T00:00:00+00:00',
                ]],
            ],
        ]);

        $this->assertSame(AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_ACTIVE_HANDOFF, $report['status']);
        $this->assertSame([], $report['blockers']);
        $this->assertSame('accepted', $report['operator_acceptance']['status']);
        $this->assertTrue($report['checks']['health_model_present']);
        $this->assertTrue($report['checks']['dev_forge_policy_present']);
    }

    public function test_blocks_when_required_stewardship_ingredients_are_missing(): void
    {
        $report = $this->service()->assess([
            'area_id' => 'agentic_engineering_os',
            'evolution_report' => $this->evolutionReport([
                'health_model' => [],
                'roadmap_candidates' => [],
            ]),
            'decision_ledger' => ['decisions' => []],
        ]);

        $this->assertSame(AreaStewardshipPromotionReadinessService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('health_model_present_missing_or_failed', $report['blockers']);
        $this->assertContains('roadmap_candidates_present_missing_or_failed', $report['blockers']);
    }

    public function test_blocks_when_ap730_is_blocked(): void
    {
        $report = $this->service()->assess([
            'evolution_report' => [
                'schema_version' => StewardshipEvolutionReadModelService::REPORT_SCHEMA,
                'status' => StewardshipEvolutionReadModelService::STATUS_BLOCKED,
            ],
        ]);

        $this->assertSame(AreaStewardshipPromotionReadinessService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('stewardship_evolution_not_ready', $report['reason']);
    }

    public function test_report_hash_is_deterministic_for_same_inputs(): void
    {
        $input = [
            'evolution_report' => $this->evolutionReport(),
            'decision_ledger' => ['decisions' => []],
        ];

        $a = $this->service()->assess($input);
        $b = $this->service()->assess($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertStringStartsWith('sha256:', $a['report_hash']);
    }
}
