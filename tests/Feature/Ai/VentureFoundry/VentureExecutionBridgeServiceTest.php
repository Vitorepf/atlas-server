<?php

namespace Tests\Feature\Ai\VentureFoundry;

use App\Models\AiMission;
use App\Models\AiVentureStrategyReview;
use App\Services\Ai\VentureFoundry\VentureExecutionBridgeService;
use App\Services\Ai\VentureFoundry\VentureIdeationService;
use App\Services\Ai\VentureFoundry\VentureRegistryService;
use App\Services\Ai\VentureFoundry\VentureStrategistService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureExecutionBridgeServiceTest extends TestCase
{
    use CreatesMissionFoundationTables;
    use CreatesStrategyRuntimeTables;
    use CreatesVentureFoundryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
        $this->createStrategyRuntimeTables();
        $this->createVentureFoundryTables();

        // Mirror migration 2026_06_08_140000: the proactive columns the bridge
        // stamps for provenance (the shared concern predates them).
        if (! \Illuminate\Support\Facades\Schema::hasColumn('ai_missions', 'proactive_origin')) {
            \Illuminate\Support\Facades\Schema::table('ai_missions', function ($table): void {
                $table->json('proactive_origin')->nullable();
                $table->string('proactive_status', 24)->nullable();
            });
        }
    }

    protected function tearDown(): void
    {
        $this->dropVentureFoundryTables();
        $this->dropStrategyRuntimeTables();
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    private function ventureWithReview(): array
    {
        $idea = app(VentureIdeationService::class)->register([
            'title' => 'Empresa ponte',
            'problem' => 'p', 'icp' => 'i', 'pain' => 'd',
        ]);
        $venture = app(VentureRegistryService::class)->promoteIdea($idea);

        // New venture -> review has S1 gaps (opportunity + business rules).
        $packet = app(VentureStrategistService::class)->review($venture);

        return [$venture, $packet['review']];
    }

    public function test_bridges_gaps_into_draft_suggest_missions_never_auto_executing(): void
    {
        [$venture, $review] = $this->ventureWithReview();

        $result = app(VentureExecutionBridgeService::class)->bridge($venture, $review);

        $this->assertSame('bridged', $result['bridge_status']);
        $this->assertGreaterThanOrEqual(2, $result['created']);

        foreach ($result['missions'] as $bridged) {
            $mission = AiMission::query()->findOrFail($bridged['mission_id']);
            $this->assertSame('draft', $mission->status);
            $this->assertSame('suggest', $mission->autonomy_level);
            $this->assertSame('proposed', $mission->proactive_status);
            $this->assertSame($venture->venture_id, $mission->proactive_origin['venture_id']);
            $this->assertSame((string) $review->uuid, $mission->proactive_origin['review_uuid']);
        }

        $this->assertCount($result['created'], (array) $review->refresh()->bridged_mission_ids);
    }

    public function test_bridge_is_idempotent_per_review(): void
    {
        [$venture, $review] = $this->ventureWithReview();
        $bridge = app(VentureExecutionBridgeService::class);

        $first = $bridge->bridge($venture, $review);
        $missionCount = AiMission::query()->count();

        $second = $bridge->bridge($venture, $review->refresh());

        $this->assertSame('already_bridged', $second['bridge_status']);
        $this->assertSame(0, $second['created']);
        $this->assertSame($missionCount, AiMission::query()->count());
        $this->assertSame(array_map(fn ($m) => $m['mission_id'], $first['missions']), $second['missions']);
    }

    public function test_bridge_respects_mission_cap(): void
    {
        config()->set('atlas_venture_foundry.bridge_max_missions_per_review', 1);
        [$venture, $review] = $this->ventureWithReview();

        $result = app(VentureExecutionBridgeService::class)->bridge($venture, $review);

        $this->assertSame(1, $result['created']);
        $this->assertGreaterThanOrEqual(1, $result['skipped_over_cap']);
        $this->assertSame(1, AiMission::query()->count());
    }

    public function test_bridge_latest_uses_most_recent_review_and_command_action_works(): void
    {
        [$venture] = $this->ventureWithReview();

        $exit = $this->artisan('atlas:venture', [
            'action' => 'bridge-execution',
            '--venture' => $venture->venture_id,
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
        $this->assertGreaterThan(0, AiMission::query()->count());
        $this->assertNotNull(AiVentureStrategyReview::query()->firstOrFail()->bridged_mission_ids);
    }
}
