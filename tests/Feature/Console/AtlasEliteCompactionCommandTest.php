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
        // Obra 3 / AUT-01: freeze may be lifted after final-dod; status still emits schema.
        $this->assertArrayHasKey('freeze_active', $payload);
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

    public function test_acde_inventory_never_marks_keep_list_as_dead(): void
    {
        $inventory = app(\App\Services\Engineering\EliteCompactionInventoryService::class)->inventoryAcde();
        $keepList = array_values(array_map('strval', (array) config('atlas_elite_compaction.acde_keep_list', [])));

        $this->assertCount(26, $keepList);
        $this->assertTrue((bool) ($inventory['fail_closed'] ?? false), 'inventory must be fail_closed');
        $this->assertSame([], $inventory['keep_list_in_dead_sample'] ?? ['missing']);
        $this->assertSame([], $inventory['basename_body_mismatches'] ?? ['missing']);

        $deadClasses = array_column((array) ($inventory['dead_sample'] ?? []), 'class');
        $this->assertSame([], array_values(array_intersect($keepList, $deadClasses)));
        foreach (['LensContract', 'LoopExecutionDriver', 'ScopeRuntimeFacts'] as $loadBearingContract) {
            $this->assertNotContains(
                $loadBearingContract,
                $deadClasses,
                "{$loadBearingContract} has non-Atlas references and must never be pruned by the inventory parser",
            );
        }
    }

    public function test_prune_acde_dry_run_excludes_keep_list(): void
    {
        $code = Artisan::call('atlas:elite:compaction', [
            'action' => 'prune-acde',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(0, $code, 'prune-acde dry-run must not be blocked when inventory is safe');
        $this->assertFalse((bool) ($payload['blocked'] ?? false));

        $keepList = array_values(array_map('strval', (array) config('atlas_elite_compaction.acde_keep_list', [])));
        $deletedBasenames = array_map(
            static fn (string $file): string => basename($file, '.php'),
            array_map('strval', (array) ($payload['deleted'] ?? [])),
        );
        $this->assertSame([], array_values(array_intersect($keepList, $deletedBasenames)));
    }
}
