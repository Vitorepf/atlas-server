<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use Tests\TestCase;

class AtlasConductorRoutingMemoryTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    private function memory(): AtlasConductorRoutingMemory
    {
        $m = new AtlasConductorRoutingMemory;
        $p = sys_get_temp_dir().'/atlas_routing_'.uniqid('', true).'.jsonl';
        $this->tmp[] = $p;
        $m->setLogPathForTesting($p);

        return $m;
    }

    public function test_recommends_best_provider_by_success_rate(): void
    {
        $m = $this->memory();
        $m->record(['task_category' => 'code', 'role' => 'primary', 'provider' => 'codex_cli', 'result' => 'success', 'latency_ms' => 100]);
        $m->record(['task_category' => 'code', 'role' => 'primary', 'provider' => 'codex_cli', 'result' => 'success', 'latency_ms' => 100]);
        $m->record(['task_category' => 'code', 'role' => 'primary', 'provider' => 'gemini_cli', 'result' => 'success', 'latency_ms' => 50]);
        $m->record(['task_category' => 'code', 'role' => 'primary', 'provider' => 'gemini_cli', 'result' => 'failure', 'latency_ms' => 50]);

        $rec = $m->recommend('code', 'primary');

        $this->assertIsArray($rec);
        $this->assertSame('codex_cli', $rec['provider']); // 1.0 rate beats 0.5
        $this->assertSame(1.0, $rec['success_rate']);
        $this->assertSame(2, $rec['samples']);
    }

    public function test_tie_breaks_equal_success_rate_by_lower_latency(): void
    {
        $m = $this->memory();
        $m->record(['task_category' => 't', 'role' => 'r', 'provider' => 'slow', 'result' => 'success', 'latency_ms' => 200]);
        $m->record(['task_category' => 't', 'role' => 'r', 'provider' => 'fast', 'result' => 'success', 'latency_ms' => 50]);

        $this->assertSame('fast', $m->recommend('t', 'r')['provider']);
    }

    public function test_returns_null_when_no_matching_history(): void
    {
        $m = $this->memory();
        $m->record(['task_category' => 'x', 'role' => 'r', 'provider' => 'a', 'result' => 'success', 'latency_ms' => 10]);

        $this->assertNull($m->recommend('y', 'r'));
        $this->assertNull($m->recommend('', ''));
    }

    public function test_record_ignores_incomplete_entries(): void
    {
        $m = $this->memory();
        $m->record(['task_category' => '', 'role' => 'r', 'provider' => 'a']);
        $m->record(['task_category' => 't', 'role' => '', 'provider' => 'a']);
        $m->record(['task_category' => 't', 'role' => 'r', 'provider' => '']);

        $this->assertSame([], $m->listEntries());
    }
}
