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
        $this->assertSame(11, $summary['command_count']);
        $this->assertSame($catalog->commands(), $summary['commands']);
        $this->assertSame([
            'architecture_operations',
            'architecture_validate',
            'kernel_slo_report',
            'kernel_pipeline_report',
            'repair_report',
            'provider_performance_report',
            'decision_receipt_report',
            'ledger_replay',
            'ledger_projection_worker',
            'self_improvement_schedule_report',
            'inbox_action_report',
        ], $summary['operation_ids']);

        $commands = array_column($summary['commands'], 'command');

        $this->assertContains('atlas ai architecture-operations --json', $commands);
        $this->assertContains('atlas ai architecture-validate', $commands);
        $this->assertContains('atlas ai slo --hours=24 --json', $commands);
        $this->assertContains('atlas ai kernel-pipeline-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai repair-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai provider-performance --hours=24 --json', $commands);
        $this->assertContains('atlas ai decision-receipt-report --envelope=<id> --json', $commands);
        $this->assertContains('atlas ledger replay --envelope=<id> --json', $commands);
        $this->assertContains('atlas ai ledger-project --limit=500 --json', $commands);
        $this->assertContains('atlas ai self-improvement-schedule-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai inbox-action-report --hours=24 --json', $commands);
        $this->assertSame('architecture_operations', data_get($summary, 'commands.0.id'));
        $this->assertSame('catalog', data_get($summary, 'commands.0.kind'));
        $this->assertSame('cli', data_get($summary, 'commands.0.surface'));
        $this->assertSame('json', data_get($summary, 'commands.0.output'));
        $this->assertSame('evidence_report', data_get($summary, 'commands.7.kind'));
        $this->assertSame('maintenance', data_get($summary, 'commands.8.kind'));
        $this->assertSame('evidence_report', data_get($summary, 'commands.9.kind'));
        $this->assertSame('evidence_report', data_get($summary, 'commands.10.kind'));
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

        $this->assertSame(['kind' => 'evidence_report'], $byKind['filters']);
        $this->assertSame(8, $byKind['command_count']);
        $this->assertNotContains('architecture_operations', $byKind['operation_ids']);
        $this->assertContains('kernel_slo_report', $byKind['operation_ids']);
        $this->assertContains('decision_receipt_report', $byKind['operation_ids']);
        $this->assertContains('ledger_replay', $byKind['operation_ids']);
        $this->assertContains('inbox_action_report', $byKind['operation_ids']);
    }
}
