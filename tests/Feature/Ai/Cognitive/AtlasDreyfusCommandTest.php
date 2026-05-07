<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasDreyfusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_120000_create_dreyfus_overlays_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('dreyfus_overlays');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_command_can_upsert_show_list_and_dispute_overlay(): void
    {
        $exit = Artisan::call('atlas:dreyfus', [
            'node' => 'laravel-queues',
            '--domain' => 'programming',
            '--level' => 4,
            '--confidence' => 0.87,
            '--specialist' => 'programming.backend_api',
            '--json' => true,
        ]);
        $updated = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('updated', $updated['status']);
        $this->assertSame(4, data_get($updated, 'overlay.current_level'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => LedgerEventType::DreyfusLevelDeltaRecorded->value,
            'emitter_stage' => 'atlas.dreyfus.cli',
        ]);

        Artisan::call('atlas:dreyfus', [
            'node' => data_get($updated, 'overlay.knowledge_node_id'),
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $shown = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('show', $shown['mode']);
        $this->assertSame(4, data_get($shown, 'overlay.current_level'));

        Artisan::call('atlas:dreyfus', [
            '--all' => true,
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $all = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $all['overlays']);

        Artisan::call('atlas:dreyfus', [
            'node' => 'all',
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $allAlias = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('all', $allAlias['mode']);
        $this->assertCount(1, $allAlias['overlays']);

        Artisan::call('atlas:dreyfus', [
            'node' => data_get($updated, 'overlay.knowledge_node_id'),
            '--domain' => 'programming',
            '--dispute' => 'Tenho mais evidencia de expert do que o overlay mostra.',
            '--json' => true,
        ]);
        $dispute = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('opened', $dispute['status']);
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::DreyfusDisputeOpened->value)->count());

        Artisan::call('atlas:dreyfus', [
            'node' => data_get($updated, 'overlay.knowledge_node_id'),
            '--domain' => 'programming',
            '--resolve-dispute' => 'Curator reviewed external evidence and kept level 4.',
            '--json' => true,
        ]);
        $resolved = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::DreyfusDisputeResolved->value)->count());
    }
}
