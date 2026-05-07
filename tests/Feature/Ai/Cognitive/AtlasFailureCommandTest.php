<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasFailureCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_160000_create_failure_signatures_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('failure_repetition_alerts');
        Schema::dropIfExists('failure_diversity_metrics');
        Schema::dropIfExists('failure_signatures');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_failure_command_records_lists_computes_diversity_and_acknowledges_alert(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Artisan::call('atlas:failure', [
                'action' => 'record',
                'subject' => 'runtime failed while executing provider',
                '--domain' => 'programming',
                '--json' => true,
            ]);
        }

        $recorded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('recorded', $recorded['status']);
        $this->assertSame('triggered', data_get($recorded, 'alert.status'));
        $this->assertSame(3, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::FailureSignatureRecorded->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::FailureRepetitionAlert->value)->count());

        Artisan::call('atlas:failure', ['action' => 'recent', '--domain' => 'programming', '--json' => true]);
        $recent = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $recent['signatures']);

        Artisan::call('atlas:failure', ['action' => 'diversity', '--domain' => 'programming', '--json' => true]);
        $diversity = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('computed', $diversity['status']);
        $this->assertSame(1, $diversity['unique_signatures']);

        Artisan::call('atlas:failure', ['action' => 'alerts', '--json' => true]);
        $alerts = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $alerts['alerts']);

        Artisan::call('atlas:failure', [
            'action' => 'ack',
            'subject' => (string) $alerts['alerts'][0]['id'],
            '--reflection' => 'Vou revisar o runtime antes de repetir.',
            '--json' => true,
        ]);
        $ack = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('acknowledged', data_get($ack, 'alert.alert_status'));
    }
}
