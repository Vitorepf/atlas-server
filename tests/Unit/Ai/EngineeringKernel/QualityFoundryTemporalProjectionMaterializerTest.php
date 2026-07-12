<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Models\AiRunOutcome;
use App\Services\Ai\Compounding\AtlasCompoundingOutcomeEvaluator;
use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryTemporalProjectionMaterializer;
use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryTemporalProjectionRebuilder;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use PHPUnit\Framework\TestCase;

final class QualityFoundryTemporalProjectionMaterializerTest extends TestCase
{
    public function test_missing_canonical_outcome_is_blocked_without_materializing_a_read_model_row(): void
    {
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $ledger->method('eventsForCorrelation')->willReturn([]);
        $evaluator = $this->createMock(AtlasCompoundingOutcomeEvaluator::class);
        $evaluator->expects(self::never())->method('evaluate');

        $result = (new QualityFoundryTemporalProjectionMaterializer(new QualityFoundryTemporalProjectionRebuilder, $evaluator))
            ->materialize($ledger, 'delivery-missing');

        self::assertSame('blocked', $result['status']);
        self::assertSame('temporal_outcome_missing', $result['reason']);
    }

    public function test_materialization_delegates_idempotency_to_the_canonical_evaluator_and_never_claims(): void
    {
        $events = [
            [
                'event_id' => 'outcome-event', 'event_hash' => hash('sha256', 'outcome-event'),
                'occurred_at' => '2026-07-10T00:00:00+00:00',
                'payload' => [
                    'event_name' => 'engineering.outcome.recorded',
                    'outcome' => [
                        'run_id' => 'run-1', 'delivery_id' => 'delivery-1', 'status' => 'released',
                        'outcome_hash' => hash('sha256', 'canonical-outcome'),
                        'correlated_hashes' => [],
                    ],
                ],
            ],
        ];
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $ledger->method('eventsForCorrelation')->with('delivery-1')->willReturn($events);
        $materialized = new AiRunOutcome(['outcome_hash' => hash('sha256', 'projection')]);
        $materialized->setAttribute('id', 'run-outcome-1');
        $evaluator = $this->createMock(AtlasCompoundingOutcomeEvaluator::class);
        $evaluator->expects(self::once())->method('evaluate')->with(self::callback(function (array $input): bool {
            return $input['flow_id'] === 'engineering_kernel_temporal'
                && $input['outcome_status'] === 'temporal_pending'
                && $input['learning_required'] === true
                && $input['flow_quality'] === 0
                && $input['payload']['delivery_id'] === 'delivery-1';
        }))->willReturn($materialized);

        $result = (new QualityFoundryTemporalProjectionMaterializer(new QualityFoundryTemporalProjectionRebuilder, $evaluator))
            ->materialize($ledger, 'delivery-1');

        self::assertSame('materialized', $result['status']);
        self::assertFalse($result['claim_eligible']);
        self::assertTrue($result['learning_required']);
    }
}
