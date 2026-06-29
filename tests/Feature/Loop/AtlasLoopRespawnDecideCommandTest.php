<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopRespawnPolicy;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the respawn policy is live at the operator surface and emits deterministic facts: a dead-supervisor
 * campaign with budget and elapsed cooldown is admitted for respawn (master on), while the same campaign with
 * the master switch off is denied. A missing --campaign is a usage error. Decision only — nothing respawns.
 */
final class AtlasLoopRespawnDecideCommandTest extends TestCase
{
    private const DEAD_RUNNING_CAMPAIGN = [
        'campaign_id' => 'camp-1',
        'status' => 'running',
        'budget_remaining_s' => 100,
        'cooldown_elapsed_s' => 100,
        'supervisor_pid' => null, // dead
        'supervisor_hung' => false,
    ];

    public function test_requires_campaign(): void
    {
        $exit = Artisan::call('atlas:loop:respawn-decide', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_admits_respawn_for_dead_supervisor_with_master_on(): void
    {
        // master-on override + zero cooldown so the dead-supervisor campaign clears every gate
        $this->app->instance(AtlasLoopRespawnPolicy::class, new AtlasLoopRespawnPolicy(0, static fn (): bool => true));

        $decoded = $this->decide(self::DEAD_RUNNING_CAMPAIGN);

        $this->assertSame('atlas.loop.respawn_policy.v1', $decoded['schema_version']);
        $this->assertTrue($decoded['should_respawn']);
        $this->assertContains('admitted', $decoded['reasons']);
    }

    public function test_denies_respawn_when_master_off(): void
    {
        $this->app->instance(AtlasLoopRespawnPolicy::class, new AtlasLoopRespawnPolicy(0, static fn (): bool => false));

        $decoded = $this->decide(self::DEAD_RUNNING_CAMPAIGN);

        $this->assertFalse($decoded['should_respawn']);
        $this->assertContains('master_switch_off', $decoded['reasons']);
    }

    /**
     * @param  array<string,mixed>  $campaign
     * @return array<string,mixed>
     */
    private function decide(array $campaign): array
    {
        $exit = Artisan::call('atlas:loop:respawn-decide', [
            '--campaign' => json_encode($campaign),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
