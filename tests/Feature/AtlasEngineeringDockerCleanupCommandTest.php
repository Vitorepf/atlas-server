<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasEngineeringDockerCleanupCommandTest extends TestCase
{
    public function test_cleanup_command_defaults_to_dry_run_json(): void
    {
        $exit = Artisan::call('atlas:engineering:docker-cleanup', [
            '--cache-retention-days' => 1,
            '--artifact-retention-days' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('completed', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertSame(1, $payload['cache_retention_days'] ?? null);
        $this->assertSame(1, $payload['artifact_retention_days'] ?? null);
    }
}
