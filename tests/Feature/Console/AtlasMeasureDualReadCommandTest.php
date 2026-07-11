<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasMeasureDualReadCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateLedger();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_records_dual_read_to_evidence_ledger_and_is_readable(): void
    {
        $this->artisan('atlas:measure:dual-read', [
            'medidor' => 'TAXO-01',
            'valor_antigo' => 'score=63',
            'valor_novo' => 'score=71',
            'justificativa' => 're-taxonomia feedback neutral',
            '--commit' => 'abc1234',
            '--json' => true,
        ])->assertExitCode(0);

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::MeasureDualReadRecorded->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('measure', $event->scope_type);
        $this->assertSame('TAXO-01', $event->scope_id);
        $this->assertSame('atlas.measure', $event->emitter_stage);
        $this->assertSame('atlas.measure.dual_read.v1', data_get($event->payload, 'schema_version'));
        $this->assertSame('TAXO-01', data_get($event->payload, 'medidor'));
        $this->assertSame('score=63', data_get($event->payload, 'valor_antigo'));
        $this->assertSame('score=71', data_get($event->payload, 'valor_novo'));
        $this->assertSame('abc1234', data_get($event->payload, 'commit'));
        $this->assertSame('re-taxonomia feedback neutral', data_get($event->payload, 'justificativa'));
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }
}
