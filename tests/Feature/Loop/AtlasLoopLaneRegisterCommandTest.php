<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the project-lane registry is live at the operator surface: a lane record registers into a sanitized
 * record carrying the normalized lane identity (project_id + repo_root) with secret/exec keys stripped; a
 * record missing project_id/repo_root is refused.
 */
final class AtlasLoopLaneRegisterCommandTest extends TestCase
{
    private function register(array $record): array
    {
        $exit = Artisan::call('atlas:loop:lane-register', [
            '--record' => (string) json_encode($record),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_lane_record_registers_with_normalized_identity(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->register([
            'project_id' => 'p1',
            'repo_root' => '/repo/p1',
            'allowed_scope_roots' => ['app/p1'],
            'provider_key' => 'should-be-stripped',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.lane_register.v1', $d['schema']);
        $this->assertSame('p1', $d['registration']['project_id'], (string) json_encode($d));
        $this->assertSame('/repo/p1', $d['registration']['repo_root']);
        // provider-safe: the secret key is stripped from the registration record
        $this->assertArrayNotHasKey('provider_key', $d['registration']);
    }

    public function test_record_missing_identity_is_refused(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->register(['repo_root' => '/repo/p1']); // no project_id

        $this->assertNotSame(0, $exit);
        $this->assertSame('register_refused', $d['reason']);
        $this->assertStringContainsString('project_id_and_repo_root_required', $d['message']);
    }

    public function test_missing_record_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:lane-register', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
