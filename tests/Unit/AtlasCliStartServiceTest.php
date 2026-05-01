<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasCliSessionService;
use App\Services\Ai\Cli\AtlasCliStartService;
use ReflectionMethod;
use Tests\TestCase;

class AtlasCliStartServiceTest extends TestCase
{
    private AtlasCliStartService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasCliStartService(app(AtlasCliSessionService::class));
    }

    public function test_pick_next_action_prefers_resumable_dev_plan(): void
    {
        $action = $this->callPicker('/repo', ['title' => 'thread 1'], null, [
            'plan_id' => 'plan-abc',
            'task' => 'refatorar handlers',
            'reason' => 'interrompido',
            'workspace' => '/repo',
        ]);

        $this->assertSame('resume', $action['kind']);
        $this->assertSame('atlas continue', $action['command']);
        $this->assertStringContainsString('refatorar handlers', $action['title']);
    }

    public function test_pick_next_action_uses_pending_steer_when_no_resumable(): void
    {
        $action = $this->callPicker('/repo', ['title' => 't'], [
            'pending_steer' => 'lembre de migrar storage',
            'next_steps' => [],
        ], null);

        $this->assertSame('pending_steer', $action['kind']);
        $this->assertSame('atlas chat', $action['command']);
    }

    public function test_pick_next_action_uses_first_next_step_when_present(): void
    {
        $action = $this->callPicker('/repo', ['title' => 't'], [
            'next_steps' => [['text' => 'abrir LoginController e mapear validators']],
        ], null);

        $this->assertSame('next_step', $action['kind']);
        $this->assertSame('abrir LoginController e mapear validators', $action['title']);
        $this->assertSame('abrir LoginController e mapear validators', $action['microaction']);
    }

    public function test_pick_next_action_falls_back_to_objective(): void
    {
        $action = $this->callPicker('/repo', ['title' => 't'], [
            'objective' => 'refatorar autenticacao para extrair Trait',
            'current_topic' => 'auth refactor',
        ], null);

        $this->assertSame('objective', $action['kind']);
        $this->assertStringContainsString('refatorar', $action['title']);
        $this->assertSame('atlas chat', $action['command']);
    }

    public function test_pick_next_action_falls_back_to_open_loop_when_no_objective(): void
    {
        $action = $this->callPicker('/repo', ['title' => 't'], [
            'open_loops' => [['text' => 'decidir naming do trait']],
        ], null);

        $this->assertSame('open_loop', $action['kind']);
        $this->assertStringContainsString('decidir naming', $action['title']);
    }

    public function test_pick_next_action_uses_thread_title_as_last_resort(): void
    {
        $action = $this->callPicker('/repo', [
            'title' => 'investigando bug do login',
        ], [], null);

        $this->assertSame('thread_resume', $action['kind']);
        $this->assertStringContainsString('investigando bug', $action['title']);
    }

    public function test_pick_next_action_returns_fresh_when_no_signals(): void
    {
        $action = $this->callPicker('/repo', ['title' => 'sem titulo'], [], null);

        $this->assertSame('fresh', $action['kind']);
        $this->assertStringContainsString('atlas dev', $action['command']);
    }

    public function test_pick_next_action_always_includes_one_command(): void
    {
        $cases = [
            ['thread' => ['title' => 't'], 'state' => null, 'resumable' => null],
            ['thread' => ['title' => 't'], 'state' => ['next_steps' => [['text' => 'x']]], 'resumable' => null],
            ['thread' => ['title' => 't'], 'state' => ['objective' => 'foo'], 'resumable' => null],
            ['thread' => ['title' => 't'], 'state' => null, 'resumable' => ['plan_id' => 'p', 'task' => 'foo', 'reason' => 'x']],
        ];

        foreach ($cases as $case) {
            $action = $this->callPicker('/repo', $case['thread'], $case['state'], $case['resumable']);
            $this->assertNotEmpty($action['command'], 'briefing deve sempre terminar com UM comando');
            $this->assertStringStartsWith('atlas ', (string) $action['command']);
        }
    }

    /**
     * @param  array<string,mixed>  $thread
     * @param  array<string,mixed>|null  $state
     * @param  array<string,mixed>|null  $resumable
     * @return array<string,mixed>
     */
    private function callPicker(string $workspace, array $thread, ?array $state, ?array $resumable): array
    {
        $method = new ReflectionMethod(AtlasCliStartService::class, 'pickNextAction');
        $method->setAccessible(true);

        return (array) $method->invoke($this->service, $workspace, $thread, $state, $resumable);
    }
}
