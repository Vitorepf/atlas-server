<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasEliteCompactionCommandTest extends TestCase
{
    public function test_status_command_emits_schema(): void
    {
        $code = Artisan::call('atlas:elite:compaction', ['action' => 'status', '--json' => true]);
        $this->assertSame(0, $code);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('atlas.elite_compaction.status.v1', $payload['schema'] ?? null);
        $this->assertTrue((bool) ($payload['freeze_active'] ?? false));
    }

    public function test_scorecard_dual_emits_v4_modules(): void
    {
        $code = Artisan::call('atlas:cognition:scorecard', ['--json' => true]);
        $this->assertSame(0, $code);
        $payload = json_decode(Artisan::output(), true);
        $report = $payload['report'] ?? [];
        $this->assertArrayHasKey('v4', $report);
        $this->assertSame('atlas.cognition.scorecard.v4', $report['v4']['schema_version'] ?? null);
        $this->assertGreaterThanOrEqual(10, (int) ($report['v4']['module_count'] ?? 0));
    }

    public function test_loop_commands_are_hard_deleted(): void
    {
        $this->assertSame([], glob(base_path('app/Console/Commands/AtlasLoop*.php') ?: []));
    }

    public function test_freeze_blocks_brain_next_when_active(): void
    {
        config()->set('atlas_elite_compaction.freeze.active', true);
        config()->set('atlas_elite_compaction.freeze.blocks_autonomos_block_origination', true);

        $code = Artisan::call('atlas:brain:next', ['scope' => 'loop', '--json' => true]);
        $this->assertSame(1, $code);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('frozen', $payload['status'] ?? null);
    }

    public function test_hard_delete_loop_allowed_with_operator_override(): void
    {
        $code = Artisan::call('atlas:elite:compaction', [
            'action' => 'hard-delete-loop',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertTrue((bool) ($payload['allowed'] ?? false));
        $this->assertSame(0, (int) ($payload['deleted_count'] ?? -1));
    }
}
