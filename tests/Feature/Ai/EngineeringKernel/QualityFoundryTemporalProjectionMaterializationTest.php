<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Models\AiRunOutcome;
use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryTemporalProjectionMaterializer;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class QualityFoundryTemporalProjectionMaterializationTest extends TestCase
{
    use BootsCompoundingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_materializes_temporal_projection_and_replays_without_duplicate_read_model_rows(): void
    {
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $ledger->method('eventsForCorrelation')->with('delivery-1')->willReturn([$this->outcomeEvent()]);

        $materializer = app(QualityFoundryTemporalProjectionMaterializer::class);
        $first = $materializer->materialize($ledger, 'delivery-1');
        $second = $materializer->materialize($ledger, 'delivery-1');

        self::assertSame('materialized', $first['status']);
        self::assertSame('materialized', $second['status']);
        self::assertSame($first['outcome_hash'], $second['outcome_hash']);
        self::assertSame(1, AiRunOutcome::query()->count());
        self::assertSame('temporal_pending', AiRunOutcome::query()->value('outcome_status'));
        self::assertTrue((bool) AiRunOutcome::query()->value('learning_required'));
        self::assertSame(
            'atlas.quality_foundry.temporal_projection',
            data_get(AiRunOutcome::query()->first()->payload, 'payload.source'),
        );
    }

    /** @return array<string,mixed> */
    private function outcomeEvent(): array
    {
        return [
            'event_id' => 'outcome-event', 'event_hash' => hash('sha256', 'outcome-event'),
            'occurred_at' => '2026-07-10T00:00:00+00:00',
            'payload' => [
                'event_name' => 'engineering.outcome.recorded',
                'outcome' => [
                    'run_id' => 'run-1', 'delivery_id' => 'delivery-1', 'status' => 'released',
                    'outcome_hash' => hash('sha256', 'canonical-outcome'), 'correlated_hashes' => [],
                ],
            ],
        ];
    }
}
