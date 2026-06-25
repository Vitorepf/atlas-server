<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopCortexMemoryIntegrationCommandTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_cortex_memory_facts_'.bin2hex(random_bytes(6)).'.json';
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

    public function test_read_action_emits_entries_array(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:memory:integration', ['action' => 'read', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame('read', $p['action']);
        $this->assertArrayHasKey('entries', $p);
    }

    public function test_ground_action_emits_verdict(): void
    {
        $this->writeJson(['memory_entries' => [], 'inventory' => []]);
        $exit = Artisan::call('atlas:loop:cortex:memory:integration', ['action' => 'ground', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame('ground', $p['action']);
        $this->assertArrayHasKey('verdict', $p);
    }

    public function test_propose_action_runs_through_gate(): void
    {
        $this->writeJson(['proposal' => ['memory_entry_id' => 'm-1', 'patch' => []]]);
        $exit = Artisan::call('atlas:loop:cortex:memory:integration', ['action' => 'propose', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame('propose', $p['action']);
        $this->assertArrayHasKey('verdict', $p);
    }

    public function test_history_action_emits_rows(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:memory:integration', ['action' => 'history', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame('history', $p['action']);
        $this->assertIsArray($p['rows']);
    }

    public function test_unknown_action_returns_failure(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:memory:integration', ['action' => 'bogus', '--json' => true]);
        $this->assertNotSame(0, $exit);
    }

    public function test_command_signature_is_registered(): void
    {
        $code = Artisan::call('list', []);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('atlas:loop:cortex:memory:integration', Artisan::output());
    }
}
