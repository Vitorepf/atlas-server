<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignFileStore;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the campaign supervisor status surface is live at the operator surface: seeded heartbeat/ledger files
 * are read back as deterministic facts (with a pinned clock for a stable heartbeat age); an unknown campaign
 * reports an empty status.
 */
final class AtlasLoopCampaignSupervisorStatusCommandTest extends TestCase
{
    private string $root = '';

    private string $campaign = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-campaign-status-'.bin2hex(random_bytes(5));
        $this->campaign = 'camp-'.bin2hex(random_bytes(3));

        $supervisor = app(AtlasLoopCampaignSupervisor::class);
        $supervisor->setStorageRootForTesting($this->root);
        $supervisor->setClockForTesting(fn (): int => 1000);
        $this->app->instance(AtlasLoopCampaignSupervisor::class, $supervisor);
    }

    protected function tearDown(): void
    {
        $dir = AtlasLoopCampaignFileStore::storageDir($this->campaign, $this->root);
        @unlink($dir.'/heartbeat');
        @unlink(AtlasLoopCampaignFileStore::ledgerPath($this->campaign, $this->root));
        @rmdir($dir);
        @rmdir($this->root);
        parent::tearDown();
    }

    private function runStatus(string $campaign): array
    {
        $exit = Artisan::call('atlas:loop:campaign-supervisor-status', ['--campaign' => $campaign, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_seeded_heartbeat_and_ledger_are_reported(): void
    {
        AtlasLoopCampaignFileStore::ensureStorage($this->campaign, $this->root);
        $dir = AtlasLoopCampaignFileStore::storageDir($this->campaign, $this->root);
        file_put_contents($dir.'/heartbeat', '900'); // clock pinned at 1000 ⇒ age 100
        file_put_contents(
            AtlasLoopCampaignFileStore::ledgerPath($this->campaign, $this->root),
            json_encode(['event' => 'a'])."\n".json_encode(['event' => 'b'])."\n",
        );

        ['exit' => $exit, 'd' => $d] = $this->runStatus($this->campaign);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.campaign_supervisor_status.v1', $d['schema']);
        $this->assertSame(900, $d['heartbeat']['heartbeat_at'], (string) json_encode($d));
        $this->assertSame(100, $d['heartbeat']['age_seconds']);
        $this->assertFalse($d['lock']['locked']);
        $this->assertSame(2, $d['ledger_count']);
    }

    public function test_unknown_campaign_is_empty_status(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->runStatus('nope-'.bin2hex(random_bytes(3)));

        $this->assertSame(0, $exit);
        $this->assertNull($d['heartbeat']['heartbeat_at']);
        $this->assertFalse($d['lock']['locked']);
        $this->assertSame(0, $d['ledger_count']);
        $this->assertSame([], $d['ledger_tail']);
    }

    public function test_missing_campaign_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:campaign-supervisor-status', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
