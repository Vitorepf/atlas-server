<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasAaelTraceCommandTest extends TestCase
{
    private string $traceRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->traceRoot = storage_path('atlas/aael/traces');
        @mkdir($this->traceRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->traceRoot.'/*.jsonl') as $f) {
            @unlink((string) $f);
        }
        parent::tearDown();
    }

    public function test_record_emits_uuid_v7_trace_id(): void
    {
        $exit = Artisan::call('atlas:aael:trace', ['action' => 'record', '--json' => true]);
        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) ($decoded['trace_id'] ?? ''),
        );
    }

    public function test_replay_null_actor_is_non_divergent_and_json_shape(): void
    {
        Artisan::call('atlas:aael:trace', ['action' => 'record', '--json' => true]);
        $traceId = (string) (json_decode(trim(Artisan::output()), true)['trace_id'] ?? '');
        $this->assertNotSame('', $traceId);

        $exit = Artisan::call('atlas:aael:trace', ['action' => 'replay', '--trace-id' => $traceId, '--json' => true]);
        $this->assertSame(0, $exit);
        $report = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($report);
        foreach (['total_steps_replayed', 'diverged_step_count', 'first_diverged_step_index'] as $k) {
            $this->assertArrayHasKey($k, $report);
        }
        $this->assertSame(0, $report['diverged_step_count']);
        $this->assertNull($report['first_diverged_step_index']);
        $this->assertGreaterThanOrEqual(1, $report['total_steps_replayed']);
    }

    public function test_replay_with_divergent_actor_exits_one(): void
    {
        Artisan::call('atlas:aael:trace', ['action' => 'record', '--json' => true]);
        $traceId = (string) (json_decode(trim(Artisan::output()), true)['trace_id'] ?? '');

        $exit = Artisan::call('atlas:aael:trace', ['action' => 'replay', '--trace-id' => $traceId, '--actor' => 'divergent', '--json' => true]);
        $this->assertSame(1, $exit);
    }

    public function test_history_returns_json_array_with_required_keys(): void
    {
        Artisan::call('atlas:aael:trace', ['action' => 'record', '--json' => true]);
        $exit = Artisan::call('atlas:aael:trace', ['action' => 'history', '--limit' => 5, '--json' => true]);
        $this->assertSame(0, $exit);
        $rows = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($rows);
        $this->assertLessThanOrEqual(5, count($rows));
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            foreach (['trace_id', 'started_at', 'finished_at', 'total_steps', 'terminal_status'] as $k) {
                $this->assertArrayHasKey($k, $r);
            }
        }
    }

    public function test_unknown_action_exits_two(): void
    {
        $exit = Artisan::call('atlas:aael:trace', ['action' => 'bogus']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('unknown action', Artisan::output());
    }

    public function test_replay_without_trace_id_exits_two(): void
    {
        $exit = Artisan::call('atlas:aael:trace', ['action' => 'replay']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('trace-id required', Artisan::output());
    }

    public function test_history_on_empty_dir_returns_empty_json_array(): void
    {
        foreach ((array) glob($this->traceRoot.'/*.jsonl') as $f) {
            @unlink((string) $f);
        }
        $exit = Artisan::call('atlas:aael:trace', ['action' => 'history', '--limit' => 5, '--json' => true]);
        $this->assertSame(0, $exit);
        $rows = json_decode(trim(Artisan::output()), true);
        $this->assertSame([], $rows);
    }
}
