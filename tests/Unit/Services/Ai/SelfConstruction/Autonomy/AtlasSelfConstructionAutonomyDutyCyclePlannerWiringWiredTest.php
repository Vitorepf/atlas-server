<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Autonomy;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasSelfConstructionAutonomyDutyCyclePlanner is no longer an orphan: it is invoked
 * from atlas:self-construction:autonomy-level's new `cycle` verb.
 */
final class AtlasSelfConstructionAutonomyDutyCyclePlannerWiringWiredTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_autonomy_cycle_facts_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_cycle_action_returns_originate_or_expand_for_healthy_queue(): void
    {
        $this->writeJson([
            'facts' => [
                'servable_depth' => 20,
                'malformed_rate' => 0.0,
                'give_back_rate' => 0.0,
                'congestion_score' => 0.1,
            ],
        ]);

        Artisan::call('atlas:self-construction:autonomy-level', [
            'action' => 'cycle',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('originate_or_expand', $payload['recommended_action']);
        $this->assertTrue($payload['ready_to_run']);
        $this->assertNotEmpty($payload['rationale']);
    }

    public function test_cycle_action_returns_self_heal_for_high_malformed_rate(): void
    {
        $this->writeJson([
            'facts' => [
                'servable_depth' => 20,
                'malformed_rate' => 0.9,
            ],
        ]);

        Artisan::call('atlas:self-construction:autonomy-level', [
            'action' => 'cycle',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('self_heal_queue', $payload['recommended_action']);
        $this->assertTrue($payload['ready_to_run']);
    }

    public function test_cycle_action_returns_drain_or_pause_for_high_congestion(): void
    {
        $this->writeJson([
            'facts' => [
                'servable_depth' => 20,
                'congestion_score' => 0.95,
            ],
        ]);

        Artisan::call('atlas:self-construction:autonomy-level', [
            'action' => 'cycle',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('drain_or_pause', $payload['recommended_action']);
        $this->assertFalse($payload['ready_to_run']);
    }

    public function test_cycle_action_without_facts_key_yields_usage_error(): void
    {
        $this->writeJson([]);

        Artisan::call('atlas:self-construction:autonomy-level', [
            'action' => 'cycle',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('usage_error', $payload['status']);
    }
}
