<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use Tests\TestCase;

class AtlasArchitectureOperationsCatalogTest extends TestCase
{
    public function test_catalog_exposes_canonical_architecture_operations(): void
    {
        $catalog = app(AtlasArchitectureOperationsCatalog::class);
        $summary = $catalog->summary();

        $this->assertSame('arquitetura_mae', $catalog->sectionKey());
        $this->assertSame('atlas.architecture_operations.v1', $summary['schema_version']);
        $this->assertSame('arquitetura_mae', $summary['section']);
        $this->assertSame(22, $summary['command_count']);
        $this->assertSame($catalog->commands(), $summary['commands']);
        $this->assertSame([
            'architecture_operations',
            'architecture_validate',
            'documentation_health',
            'knowledge_sync',
            'code_intelligence_index',
            'kernel_slo_report',
            'kernel_pipeline_report',
            'repair_report',
            'provider_performance_report',
            'dynamic_compute_market_report',
            'provider_cost_rates_missing',
            'provider_cost_rates_upsert',
            'qualitative_levels_report',
            'rivals_strategy_report',
            'rivals_strategy_due_reviews',
            'rivals_strategy_record_review',
            'strategic_decision_review',
            'decision_receipt_report',
            'ledger_replay',
            'ledger_projection_worker',
            'self_improvement_schedule_report',
            'inbox_action_report',
        ], $summary['operation_ids']);

        $commands = array_column($summary['commands'], 'command');

        $this->assertContains('atlas ai architecture-operations --json', $commands);
        $this->assertContains('atlas ai architecture-validate', $commands);
        $this->assertContains('atlas engineering knowledge docs-health --json', $commands);
        $this->assertContains('atlas engineering knowledge sync --prune --json', $commands);
        $this->assertContains('atlas engineering knowledge index-code --prune --json', $commands);
        $this->assertContains('atlas ai slo --hours=24 --json', $commands);
        $this->assertContains('atlas ai kernel-pipeline-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai repair-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai provider-performance --hours=24 --json', $commands);
        $this->assertContains('atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', $commands);
        $this->assertContains('atlas ai telemetry cost-rates --missing --hours=168 --json', $commands);
        $this->assertContains('atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', $commands);
        $this->assertContains('atlas ai qualitative-levels --hours=720 --json', $commands);
        $this->assertContains('atlas ai rivals-strategy report --hours=8760 --json', $commands);
        $this->assertContains('atlas ai rivals-strategy due-reviews --due-days=30 --json', $commands);
        $this->assertContains('atlas ai rivals-strategy record-review --review-id=<id> --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json', $commands);
        $this->assertContains('atlas ai strategic-decision review --json', $commands);
        $this->assertContains('atlas ai decision-receipt-report --envelope=<id> --json', $commands);
        $this->assertContains('atlas ledger replay --envelope=<id> --json', $commands);
        $this->assertContains('atlas ai ledger-project --limit=500 --json', $commands);
        $this->assertContains('atlas ai self-improvement-schedule-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai inbox-action-report --hours=24 --json', $commands);
        $this->assertSame('architecture_operations', data_get($summary, 'commands.0.id'));
        $this->assertSame('catalog', data_get($summary, 'commands.0.kind'));
        $this->assertSame('cli', data_get($summary, 'commands.0.surface'));
        $this->assertSame('json', data_get($summary, 'commands.0.output'));
        $this->assertSame('validation', data_get($summary, 'commands.1.kind'));
        $this->assertSame('validation', data_get($summary, 'commands.2.kind'));
        $this->assertSame('maintenance', data_get($summary, 'commands.3.kind'));
        $this->assertSame('maintenance', data_get($summary, 'commands.4.kind'));
        $this->assertSame('evidence_report', data_get($summary, 'commands.9.kind'));
        $this->assertSame('evidence_report', data_get($summary, 'commands.10.kind'));
        $this->assertSame('review_action', data_get($summary, 'commands.11.kind'));
        $this->assertSame('maturity_report', data_get($summary, 'commands.12.kind'));
        $this->assertSame('maturity_report', data_get($summary, 'commands.13.kind'));
        $this->assertSame('review_queue', data_get($summary, 'commands.14.kind'));
        $this->assertSame('review_action', data_get($summary, 'commands.15.kind'));
        $this->assertSame('planning_surface', data_get($summary, 'commands.16.kind'));
        $this->assertSame('evidence_report', data_get($summary, 'commands.17.kind'));
        $this->assertSame('evidence_report', data_get($summary, 'commands.18.kind'));
        $this->assertSame('maintenance', data_get($summary, 'commands.19.kind'));
        $this->assertSame('evidence_report', data_get($summary, 'commands.20.kind'));
        $this->assertSame('evidence_report', data_get($summary, 'commands.21.kind'));
    }

    public function test_catalog_filters_architecture_operations_by_id_and_kind(): void
    {
        $catalog = app(AtlasArchitectureOperationsCatalog::class);

        $byId = $catalog->summary(['id' => 'provider_performance_report']);
        $byKind = $catalog->summary(['kind' => 'evidence_report']);

        $this->assertSame(['id' => 'provider_performance_report'], $byId['filters']);
        $this->assertSame(1, $byId['command_count']);
        $this->assertSame(['provider_performance_report'], $byId['operation_ids']);
        $this->assertSame('atlas ai provider-performance --hours=24 --json', data_get($byId, 'commands.0.command'));

        $byValidation = $catalog->summary(['kind' => 'validation']);
        $this->assertSame(2, $byValidation['command_count']);
        $this->assertSame(['architecture_validate', 'documentation_health'], $byValidation['operation_ids']);

        $byMaintenance = $catalog->summary(['kind' => 'maintenance']);
        $this->assertSame(3, $byMaintenance['command_count']);
        $this->assertSame(['knowledge_sync', 'code_intelligence_index', 'ledger_projection_worker'], $byMaintenance['operation_ids']);

        $this->assertSame(['kind' => 'evidence_report'], $byKind['filters']);
        $this->assertSame(10, $byKind['command_count']);
        $this->assertNotContains('architecture_operations', $byKind['operation_ids']);
        $this->assertContains('kernel_slo_report', $byKind['operation_ids']);
        $this->assertContains('dynamic_compute_market_report', $byKind['operation_ids']);
        $this->assertContains('provider_cost_rates_missing', $byKind['operation_ids']);
        $this->assertContains('decision_receipt_report', $byKind['operation_ids']);
        $this->assertContains('ledger_replay', $byKind['operation_ids']);
        $this->assertContains('inbox_action_report', $byKind['operation_ids']);

        $byPlanning = $catalog->summary(['kind' => 'planning_surface']);
        $this->assertSame(1, $byPlanning['command_count']);
        $this->assertSame(['strategic_decision_review'], $byPlanning['operation_ids']);
        $this->assertSame('atlas ai strategic-decision review --json', data_get($byPlanning, 'commands.0.command'));

        $byMaturity = $catalog->summary(['kind' => 'maturity_report']);
        $this->assertSame(2, $byMaturity['command_count']);
        $this->assertSame(['qualitative_levels_report', 'rivals_strategy_report'], $byMaturity['operation_ids']);
        $this->assertSame('atlas ai qualitative-levels --hours=720 --json', data_get($byMaturity, 'commands.0.command'));
        $this->assertSame('atlas ai rivals-strategy report --hours=8760 --json', data_get($byMaturity, 'commands.1.command'));

        $byReviewQueue = $catalog->summary(['kind' => 'review_queue']);
        $this->assertSame(1, $byReviewQueue['command_count']);
        $this->assertSame(['rivals_strategy_due_reviews'], $byReviewQueue['operation_ids']);

        $byReviewAction = $catalog->summary(['kind' => 'review_action']);
        $this->assertSame(2, $byReviewAction['command_count']);
        $this->assertSame(['provider_cost_rates_upsert', 'rivals_strategy_record_review'], $byReviewAction['operation_ids']);

        $byCostRateAction = $catalog->summary(['id' => 'provider_cost_rates_upsert']);
        $this->assertSame(1, $byCostRateAction['command_count']);
        $this->assertSame('atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json', data_get($byCostRateAction, 'commands.0.command'));

        $byDynamicComputeMarket = $catalog->summary(['id' => 'dynamic_compute_market_report']);
        $this->assertSame(1, $byDynamicComputeMarket['command_count']);
        $this->assertSame('atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', data_get($byDynamicComputeMarket, 'commands.0.command'));
    }
}
