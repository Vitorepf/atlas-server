<?php

namespace Tests\Unit\Ai\Cognitive\SRL;

use App\Services\Ai\Cognitive\SRL\SRLOrchestrator;
use App\Services\Ai\Cognitive\SRL\SRLPreferenceService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SRLOrchestratorTest extends TestCase
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

    public function test_orchestrator_respects_opt_in_toggle(): void
    {
        $orchestrator = app(SRLOrchestrator::class);

        $this->assertSame('skipped', $orchestrator->beginIfEnabled('learning.worked_example', ['domain' => 'learning'])['status']);

        app(SRLPreferenceService::class)->set(true, 'learning');

        $started = $orchestrator->beginIfEnabled('learning.worked_example', [
            'domain' => 'learning',
            'objective' => 'Treinar filas.',
        ]);

        $this->assertSame('started', $started['status']);
        $this->assertSame('learning.worked_example', data_get($started, 'episode.target_flow'));
    }
}
