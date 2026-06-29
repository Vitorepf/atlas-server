<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the task-class miner is live at the operator surface: the command runs mine() over a campaign and
 * emits the discovered cluster facts (empty when no outcome ledger exists, never a crash). Missing --campaign
 * is a usage_error.
 */
final class AtlasLoopTaskClassMineCommandTest extends TestCase
{
    public function test_task_class_mine_emits_clusters(): void
    {
        $this->app->bind('atlas.loop.task_class.records', fn (): array => [
            ['task_packet_id' => 'pkt-1', 'target_path' => 'app/Services/Ai/AutonomousEvolution/Foo.php', 'evidence_requirements' => ['tests_or_gates_result']],
        ]);

        $exit = Artisan::call('atlas:loop:task-class-mine', ['--campaign' => 'camp-test', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.task_class_mine.v1', $decoded['schema_version']);
        $this->assertSame('camp-test', $decoded['campaign']);
        $this->assertIsArray($decoded['clusters']);
        $this->assertSame(count($decoded['clusters']), $decoded['clusters_count']);
    }

    public function test_missing_campaign_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:task-class-mine', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
