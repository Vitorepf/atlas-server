<?php

namespace Tests\Unit\Ai\Learning\SRL;

use App\Services\Ai\Learning\SRL\SRLOrchestrator;
use App\Services\Ai\Learning\SRL\SRLPreferenceService;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class SRLOrchestratorTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = ['2026_05_07_170000_create_srl_episodes_table.php'];

    private const LEDGER_COMPANION_TABLES = ['srl_preferences', 'srl_episodes'];

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
