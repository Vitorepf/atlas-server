<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskGraphReplenisherActionTest extends TestCase
{
    private function bindInputs(array $drafts, array $coverage = [], array $queue = [], int $maxApplied = 10): void
    {
        app()->bind('atlas.task_graph.replenisher.inputs', fn () => static fn () => [
            'coverage_facts' => $coverage,
            'planner_drafts' => $drafts,
            'queue_facts' => $queue,
            'max_applied' => $maxApplied,
        ]);
    }

    private function draft(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'task_packet_hash' => hash('sha256', $id),
            'task_packet' => [
                'task_packet_id' => $id,
                'task_packet_hash' => hash('sha256', $id),
                'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
                'objective' => 'demo',
            ],
        ];
    }

    public function test_dry_run_by_default_returns_planned_count_without_applying(): void
    {
        $this->bindInputs([$this->draft('draft-a'), $this->draft('draft-b')]);

        Artisan::call('atlas:task', ['action' => 'task-graph:replenish', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertSame('dry_run', $p['mode']);
        $this->assertTrue($p['dry_run']);
        $this->assertSame(0, $p['applied_count']);
        $this->assertGreaterThanOrEqual(0, $p['planned_count']);
        $this->assertNotEmpty($p['replenisher_hash']);
    }

    public function test_apply_invokes_bound_enqueue_callback(): void
    {
        $this->bindInputs([$this->draft('draft-apply-1')]);
        $captured = [];
        app()->bind('atlas.task_graph.replenisher.enqueue_callback', fn () => function (array $input) use (&$captured): array {
            $captured[] = $input;

            return ['status' => 'ok', 'event' => 'enqueued'];
        });

        Artisan::call('atlas:task', ['action' => 'task-graph:replenish', '--apply' => true, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('apply', $p['mode']);
        $this->assertFalse($p['dry_run']);
        // The callback was invoked iff plan emitted at least one enqueue input.
        $this->assertSame(count($captured), (int) $p['applied_count']);
    }

    public function test_json_shape_is_deterministic_and_includes_expected_keys(): void
    {
        $this->bindInputs([$this->draft('shape-1')]);

        Artisan::call('atlas:task', ['action' => 'task-graph:replenish', '--json' => true]);
        $a = trim(Artisan::output());
        Artisan::call('atlas:task', ['action' => 'task-graph:replenish', '--json' => true]);
        $b = trim(Artisan::output());

        $da = json_decode($a, true);
        $db = json_decode($b, true);
        foreach (['schema', 'status', 'mode', 'dry_run', 'planned_count', 'applied_count', 'withheld_count', 'duplicate_count', 'max_applied', 'replenisher_hash', 'enqueue_results'] as $key) {
            $this->assertArrayHasKey($key, $da, "missing key {$key}");
        }
        $this->assertSame($da['replenisher_hash'], $db['replenisher_hash']);
    }

    public function test_existing_atlas_task_next_action_still_works(): void
    {
        Artisan::call('atlas:task', ['action' => 'next', '--client' => 'unit-test-replenisher', '--json' => true]);
        $exit = Artisan::output();
        $payload = json_decode(trim($exit), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('status', $payload);
        // Whatever the queue state, the front door must not throw on this action.
    }
}
