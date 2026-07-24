<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\AaeosScorecardProjector;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Models\AtlasLedgerEvent;
use Mockery;
use PHPUnit\Framework\TestCase;

final class AaeosLedgerMeasurementReaderTest extends TestCase
{
    public function test_empty_measurement_sample_is_explicitly_unknown_not_a_static_nine_point_score(): void
    {
        $card = (new AaeosScorecardProjector)->project([]);

        $this->assertSame('unknown', $card['measurement_status']);
        $this->assertSame(
            ['operate_path_wiring', 'spine_enforced', 'antifragile_loop'],
            $card['unknown_dimensions'],
        );
        $this->assertSame([], $card['measurement_sources']);
        $this->assertNull($card['composite']);
        $this->assertFalse($card['god_sota']);
    }

    public function test_caller_supplied_scores_and_sources_cannot_mint_measured_god_sota(): void
    {
        $card = (new AaeosScorecardProjector)->project([
            'operate_path_wiring' => 9.9,
            'spine_enforced' => 9.9,
            'antifragile_loop' => 9.9,
            'measurement_sources' => ['caller_supplied'],
        ]);

        $this->assertSame('unknown', $card['measurement_status']);
        $this->assertNull($card['composite']);
        $this->assertFalse($card['god_sota']);
    }

    public function test_integrity_valid_ledger_measurement_contract_is_the_only_positive_measurement_path(): void
    {
        $event = new AtlasLedgerEvent;
        $event->setAttribute('event_id', 'aaeos-scorecard-proof');
        $event->setAttribute('event_type', LedgerEventType::AaeosCycleRecorded->value);
        $event->setAttribute('payload', [
            'schema' => 'atlas.aaeos.scorecard_measurement.v1',
            'measurements' => [
                'operate_path_wiring' => 9.2,
                'spine_enforced' => 9.2,
                'antifragile_loop' => 9.0,
            ],
            'measurement_sources' => ['ledger:aaeos-scorecard-proof'],
        ]);
        $ledger = Mockery::mock(AtlasEvidenceLedger::class);
        $ledger->shouldReceive('eventById')->once()->with('aaeos-scorecard-proof')->andReturn($event);
        $ledger->shouldReceive('eventIntegrityValid')->once()->with($event)->andReturnTrue();

        $card = (new AaeosScorecardProjector(ledger: $ledger))->project([
            'measurement_event_id' => 'aaeos-scorecard-proof',
        ]);

        $this->assertSame('measured', $card['measurement_status']);
        $this->assertSame(9.13, $card['composite']);
        $this->assertTrue($card['god_sota']);
        $this->assertSame(['ledger:aaeos-scorecard-proof'], $card['measurement_sources']);
    }
}
