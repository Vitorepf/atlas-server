<?php

namespace Tests\Feature\Ai\Cognitive;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasSRLCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_170000_create_srl_episodes_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('srl_preferences');
        Schema::dropIfExists('srl_episodes');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_srl_command_enables_starts_observes_reflects_and_lists_history(): void
    {
        Artisan::call('atlas:srl', ['action' => 'on', '--domain' => 'learning', '--json' => true]);
        $enabled = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue(data_get($enabled, 'preference.enabled'));

        Artisan::call('atlas:srl', [
            'action' => 'start',
            'subject' => 'learning.worked_example',
            '--domain' => 'learning',
            '--objective' => 'Treinar filas Laravel.',
            '--json' => true,
        ]);
        $started = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $episodeId = data_get($started, 'episode.id');
        $this->assertSame('started', $started['status']);

        Artisan::call('atlas:srl', ['action' => 'observe', 'subject' => (string) $episodeId, '--domain' => 'learning', '--json' => true]);
        $observed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('in_progress', $observed['completion_status']);

        Artisan::call('atlas:srl', [
            'action' => 'reflect',
            'subject' => (string) $episodeId,
            '--domain' => 'learning',
            '--worked' => 'comparei com exemplo',
            '--didnt' => 'faltou drill',
            '--adjustment' => 'fazer pratica curta',
            '--json' => true,
        ]);
        $reflected = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('complete', $reflected['completion_status']);

        Artisan::call('atlas:srl', ['action' => 'history', '--domain' => 'learning', '--json' => true]);
        $history = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $history['episodes']);
    }
}
