<?php

namespace Tests\Unit\Ai\Cognitive\Dreyfus;

use App\Services\Ai\Cognitive\Dreyfus\DreyfusPedagogyResolver;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class DreyfusPedagogyResolverTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = ['2026_05_07_120000_create_dreyfus_overlays_table.php'];

    private const LEDGER_COMPANION_TABLES = ['dreyfus_overlays'];

    public function test_resolver_maps_all_five_levels_to_distinct_pedagogy_modes(): void
    {
        $resolver = app(DreyfusPedagogyResolver::class);

        $expected = [
            1 => 'novato',
            2 => 'iniciante_avancado',
            3 => 'competente',
            4 => 'expert',
            5 => 'master',
        ];

        foreach ($expected as $stage => $mode) {
            foreach (['learning.plan', 'learning.practice', 'learning.review'] as $flow) {
                $resolution = $resolver->resolve([
                    'topic' => 'topic-'.$stage.'-'.$flow,
                    'domain' => 'learning',
                    'flow' => $flow,
                    'dreyfus_stage_target' => $stage,
                ]);

                $this->assertSame($stage, $resolution['dreyfus_stage_resolved']);
                $this->assertSame($mode, $resolution['pedagogy_mode_resolved']);
                $this->assertSame('operator_requested', $resolution['source']);
            }
        }
    }

    public function test_aggregator_uses_ledger_mastery_and_transfer_evidence(): void
    {
        $nodeId = '00000000-0000-5000-8000-000000000163';

        foreach (range(1, 8) as $i) {
            app(AtlasEvidenceLedger::class)->record(LedgerEventType::OperationCompleted, [
                'domain' => 'learning',
                'knowledge_node_id' => $nodeId,
                'mastery_evidence' => [
                    'reviewed' => true,
                    'transfer_proof' => $i <= 2,
                ],
            ], [
                'tenant_id' => 'test',
                'operator_id' => 'vitor',
                'envelope_id' => 'env_dreyfus_'.$i,
                'correlation_id' => 'dreyfus',
            ]);
        }

        $resolution = app(DreyfusPedagogyResolver::class)->resolve([
            'knowledge_node_id' => $nodeId,
            'topic' => 'Laravel queues',
            'domain' => 'learning',
            'flow' => 'learning.practice',
        ]);

        $this->assertSame('evidence_aggregate_fallback', $resolution['source']);
        $this->assertSame(5, $resolution['dreyfus_stage_resolved']);
        $this->assertSame('master', $resolution['pedagogy_mode_resolved']);
        $this->assertSame(8, data_get($resolution, 'evidence_aggregate.signals.reviewed_mastery_evidence'));
    }
}
