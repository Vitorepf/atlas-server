<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The deterministic dead-code work-type WIRED into the live campaign supervisor (the supervisor-orchestrated
 * form: a campaign auto-produces certified removals at boot, provider-LESS). Pins the two guarantees: flag-OFF
 * is a byte-identical no-op, and flag-ON persists certified_for_review proposals to the campaign.
 */
final class AtlasLoopDeterministicDeadCodeSupplyWiringTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        $this->dir = sys_get_temp_dir().'/atlas-dcsw-'.bin2hex(random_bytes(5));
        @mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/Sample.php', <<<'PHP'
<?php

class Sample
{
    public function entry(): int
    {
        return $this->live();
    }

    private function deadHelper(): string
    {
        return 'never called';
    }

    private function live(): int
    {
        return 1;
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function campaign(): AtlasLoopCampaign
    {
        AtlasLoopProposal::$governedMergeInProgress = false;

        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'deadcode supply wiring',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    private function invoke(AtlasLoopCampaign $campaign): int
    {
        $supervisor = app(AtlasLoopCampaignSupervisor::class);
        // appendLedger writes under the campaign storage dir; set it up the way run() does before the hook.
        $ensure = new ReflectionMethod($supervisor, 'ensureStorage');
        $ensure->invoke($supervisor, $campaign->id);

        $m = new ReflectionMethod($supervisor, 'runDeterministicDeadCodeSupply');

        return (int) $m->invoke($supervisor, $campaign);
    }

    public function test_flag_off_is_a_byte_identical_noop(): void
    {
        config(['atlas.loop.deterministic_deadcode_supply_enabled' => false]);
        $campaign = $this->campaign();

        $this->assertSame(0, $this->invoke($campaign), 'flag OFF => no-op');
        $this->assertSame(0, AtlasLoopProposal::where('campaign_id', $campaign->id)->count(), 'no proposals written when OFF');
    }

    public function test_flag_on_persists_certified_removals_at_campaign_start_no_provider(): void
    {
        config([
            'atlas.loop.deterministic_deadcode_supply_enabled' => true,
            'atlas.loop.deterministic_deadcode_root' => $this->dir,
            'atlas.loop.deterministic_deadcode_scope' => '',
        ]);
        $campaign = $this->campaign();

        $persisted = $this->invoke($campaign);

        $this->assertGreaterThanOrEqual(1, $persisted, 'a campaign auto-certifies the dead member, provider-less');
        $proposal = AtlasLoopProposal::where('campaign_id', $campaign->id)->where('provider', 'deterministic')->first();
        $this->assertNotNull($proposal);
        $this->assertSame(AtlasLoopProposal::STATUS_CERTIFIED, $proposal->status, 'operator-review status, never auto-merge');
        $this->assertSame('Sample.php', $proposal->target_path);
    }
}
