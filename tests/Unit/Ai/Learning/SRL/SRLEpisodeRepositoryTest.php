<?php

namespace Tests\Unit\Ai\Cognitive\SRL;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognitive\SRL\SRLEpisodeRepository;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class SRLEpisodeRepositoryTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = ['2026_05_07_170000_create_srl_episodes_table.php'];

    private const LEDGER_COMPANION_TABLES = ['srl_preferences', 'srl_episodes'];

    public function test_repository_records_three_srl_phases_and_ledger_events(): void
    {
        $repository = app(SRLEpisodeRepository::class);

        $episode = $repository->start([
            'domain' => 'learning',
            'target_flow' => 'learning.worked_example',
            'objective' => 'Entender filas Laravel.',
            'expected_difficulty' => 4,
            'strategy_chosen' => 'compare',
        ]);
        $episode = $repository->observe($episode['id'], [
            'cognitive_load' => 'medium',
            'self_rating' => 3,
        ]);
        $episode = $repository->reflect($episode['id'], [
            'what_worked' => 'worked example',
            'what_didnt' => 'faltou pratica',
            'adjustment_for_next' => 'fazer drill',
        ]);

        $this->assertSame('complete', $episode['completion_status']);
        $this->assertCount(1, $episode['performance_observations']);
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::SrlForethoughtRecorded->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::SrlPerformanceObservation->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::SrlReflectionRecorded->value)->count());
    }
}
