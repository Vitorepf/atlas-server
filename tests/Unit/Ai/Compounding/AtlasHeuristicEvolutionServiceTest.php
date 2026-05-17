<?php

namespace Tests\Unit\Ai\Compounding;

use App\Services\Ai\Compounding\AtlasHeuristicEvolutionService;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasHeuristicEvolutionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php',
        ]);
    }

    public function test_requires_before_after_evidence_rollback_and_tests(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('heuristic_update_requires_before_state');

        app(AtlasHeuristicEvolutionService::class)->propose([
            'heuristic_key' => 'router.debug',
        ]);
    }

    public function test_creates_auditable_and_reversible_heuristic_update(): void
    {
        $update = app(AtlasHeuristicEvolutionService::class)->propose([
            'heuristic_key' => 'router.debug',
            'flow_id' => 'atlas_debug',
            'before_state' => ['weight' => 0.4],
            'after_state' => ['weight' => 0.65],
            'evidence_refs' => ['outcome:1'],
            'rollback_plan' => ['weight' => 0.4],
            'test_refs' => ['tests/Unit/Ai/Compounding/AtlasHeuristicEvolutionServiceTest.php'],
            'apply' => true,
        ]);

        $this->assertSame('applied', $update->status);
        $this->assertNotEmpty($update->receipt_hash);

        $rolledBack = app(AtlasHeuristicEvolutionService::class)->rollBack($update);
        $this->assertSame('rolled_back', $rolledBack->status);
        $this->assertNotNull($rolledBack->rolled_back_at);
    }
}
