<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainBacklogCostModel is wired into
 * AtlasExternalBrainAutonomyGovernorCommand via an optional backlog_cost
 * input section, mirroring how AtlasExternalBrainTaskFamilyYieldModel is
 * already wired at the same site.
 */
final class AtlasExternalBrainBacklogCostModelWiringWiredTest extends TestCase
{
    private function callCommand(array $payload): array
    {
        $path = tempnam(sys_get_temp_dir(), 'autonomy_governor_backlog_cost_').'.json';
        file_put_contents($path, (string) json_encode($payload));

        try {
            Artisan::call('atlas:external-brain:autonomy-governor', ['--input' => $path]);

            return (array) json_decode(trim(Artisan::output()), true);
        } finally {
            @unlink($path);
        }
    }

    public function test_backlog_cost_key_present_and_recommends_action(): void
    {
        $decoded = $this->callCommand([
            'backlog_cost' => [
                'backlog_size' => 40,
                'claimable_depth' => 30,
                'blocked_count' => 15,
            ],
        ]);

        $this->assertArrayHasKey('backlog_cost', $decoded);
        $backlogCost = $decoded['backlog_cost'];
        $this->assertSame('atlas.external_brain.backlog_cost_model.v1', $backlogCost['schema']);
        $this->assertSame('unblock', $backlogCost['preferred_action']);
    }

    public function test_missing_backlog_cost_defaults_to_empty_input(): void
    {
        $decoded = $this->callCommand([]);

        $this->assertArrayHasKey('backlog_cost', $decoded);
        $this->assertSame('seed', $decoded['backlog_cost']['preferred_action']);
    }
}
