<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopTaskClassCommandTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_task_class_facts_'.bin2hex(random_bytes(6)).'.json';
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

    public function test_register_action_refuses_without_operator_token(): void
    {
        $exit = Artisan::call('atlas:loop:task-class', ['action' => 'register', '--proposal-id' => 'p-1', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('operator_token_required', $p['error']);
    }

    public function test_history_action_requires_class_id(): void
    {
        $exit = Artisan::call('atlas:loop:task-class', ['action' => 'history', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('class_id_required', $p['error']);
    }

    public function test_unknown_action_returns_failure(): void
    {
        $exit = Artisan::call('atlas:loop:task-class', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertStringStartsWith('unknown_action:', $p['error']);
    }

    public function test_command_is_registered(): void
    {
        Artisan::call('list', []);
        $this->assertStringContainsString('atlas:loop:task-class', Artisan::output());
    }
}
