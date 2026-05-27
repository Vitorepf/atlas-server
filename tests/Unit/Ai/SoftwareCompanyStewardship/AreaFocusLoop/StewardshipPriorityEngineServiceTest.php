<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityEngineService;
use Tests\TestCase;

final class StewardshipPriorityEngineServiceTest extends TestCase
{
    private function service(): StewardshipPriorityEngineService
    {
        return app(StewardshipPriorityEngineService::class);
    }

    public function test_orders_ap783_above_cosmetic_ui(): void
    {
        $report = $this->service()->rank([
            'candidates' => [
                [
                    'id' => 'cosmetic_ui',
                    'title' => 'Polish cockpit button spacing',
                    'type' => 'ui_cosmetic',
                    'changed_files' => ['resources/js/Components/Button.vue'],
                    'operator_touchpoints_reduced' => 0,
                    'dependency_unlocks' => [],
                ],
                [
                    'id' => 'AP-783',
                    'title' => 'AP-783 integration lane promotion',
                    'ap_contract' => 'AP-783',
                    'type' => 'integration_lane_promotion',
                    'evidence_refs' => ['ap782_receipt', 'ap780_packet'],
                    'dependency_unlocks' => ['merge_review', 'owner_runtime', '24h_loop_truth'],
                    'operator_touchpoints_reduced' => 4,
                    'requires_main_dirty' => false,
                    'requires_provider_without_sandbox' => false,
                ],
            ],
        ]);

        $this->assertSame(StewardshipPriorityEngineService::STATUS_READY, $report['status']);
        $this->assertSame('AP-785', $report['ap_contract']);
        $this->assertSame('AP-783', $report['top_candidate']['item_id']);
        $this->assertSame('now', $report['top_candidate']['lane']);
        $this->assertGreaterThan(
            $report['ranked_items'][1]['final_priority_score'],
            $report['ranked_items'][0]['final_priority_score'],
        );
    }

    public function test_penalizes_main_dirty_or_provider_without_sandbox(): void
    {
        $report = $this->service()->rank([
            'candidates' => [
                [
                    'id' => 'provider_no_sandbox',
                    'title' => 'Provider patch directly on main',
                    'type' => 'provider_execution',
                    'requires_main_dirty' => true,
                    'requires_provider_without_sandbox' => true,
                    'sensitive_data' => false,
                ],
                [
                    'id' => 'safe_docs_test',
                    'title' => 'Document and test existing owner boundary',
                    'type' => 'safety_robustness_unlock',
                    'changed_files' => ['docs/ap/AP-785-stewardship-priority-engine-contract.md', 'tests/Unit/Ai/FooTest.php'],
                    'evidence_refs' => ['test_plan'],
                    'dependency_unlocks' => ['operator_review'],
                ],
            ],
        ]);

        $risky = $this->byId($report, 'provider_no_sandbox');

        $this->assertSame('blocked', $risky['lane']);
        $this->assertGreaterThanOrEqual(60, $risky['risk_penalty']);
        $this->assertContains('main_dirty_required', $risky['reason_machine']);
        $this->assertContains('provider_without_sandbox', $risky['reason_machine']);
        $this->assertSame('safe_docs_test', $report['top_candidate']['item_id']);
    }

    public function test_promotes_safety_and_robustness_unlock(): void
    {
        $report = $this->service()->rank([
            'candidates' => [
                [
                    'id' => 'robustness_unlock',
                    'title' => 'Add branch collision gate and regression tests',
                    'type' => 'safety_robustness_unlock',
                    'evidence_refs' => ['unit_test', 'branch_cert'],
                    'dependency_unlocks' => ['24h_scheduler', 'merge_queue', 'operator_confidence'],
                    'operator_touchpoints_reduced' => 3,
                    'changed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Gate.php', 'tests/Unit/Ai/GateTest.php'],
                ],
                [
                    'id' => 'later_provider_routing',
                    'title' => 'Provider routing Opus Sonnet Gemini Codex',
                    'type' => 'provider_routing',
                    'requires_owner_runtime_boundary' => true,
                    'requires_provider_without_sandbox' => true,
                ],
            ],
        ]);

        $top = $report['top_candidate'];

        $this->assertSame('robustness_unlock', $top['item_id']);
        $this->assertSame('now', $top['lane']);
        $this->assertGreaterThanOrEqual(80, $top['robustness_score']);
        $this->assertGreaterThanOrEqual(60, $top['dependency_unlock_score']);
    }

    public function test_blocks_sensitive_item_without_gates(): void
    {
        $report = $this->service()->rank([
            'candidates' => [[
                'id' => 'sensitive_without_gates',
                'title' => 'Run data migration touching sensitive customer content',
                'type' => 'data_migration',
                'sensitive_data' => true,
                'required_gates' => [],
            ]],
        ]);

        $item = $report['top_candidate'];

        $this->assertSame('blocked', $item['lane']);
        $this->assertContains('sensitive_without_required_gates', $item['reason_machine']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
        $this->assertFalse($report['claim_policy']['mutates_target_repo']);
    }

    public function test_output_is_deterministic(): void
    {
        $input = [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'candidates' => [
                ['id' => 'b', 'type' => 'ui_cosmetic'],
                ['id' => 'a', 'type' => 'safety_robustness_unlock', 'dependency_unlocks' => ['x']],
            ],
        ];

        $first = $this->service()->rank($input);
        $second = $this->service()->rank($input);

        $this->assertSame($first['priority_hash'], $second['priority_hash']);
        $this->assertSame($first['ranked_items'], $second['ranked_items']);
    }

    public function test_command_smoke_json_uses_canonical_seed_priorities(): void
    {
        $this->artisan('atlas:software-company-stewardship:priority-engine', [
            '--area' => 'agentic_engineering_os',
            '--focus' => 'dev_forge',
            '--json' => true,
        ])->assertExitCode(0);
    }

    /**
     * @return array<string,mixed>
     */
    private function byId(array $report, string $id): array
    {
        foreach ($report['ranked_items'] as $item) {
            if (($item['item_id'] ?? '') === $id) {
                return $item;
            }
        }

        $this->fail("Priority item {$id} not found.");
    }
}
