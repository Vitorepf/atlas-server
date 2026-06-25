<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopPauseResumeCommand;
use App\Services\Ai\AutonomousEvolution\PauseResume\AtlasLoopCyclePauseFlag;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopPauseResumeCommandTest extends TestCase
{
    private string $sentinelPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sentinelPath = sys_get_temp_dir().'/atlas-pause-cli-'.bin2hex(random_bytes(6)).'.json';
        $this->app->instance(
            AtlasLoopPauseResumeCommand::PAUSE_FLAG_BINDING,
            new AtlasLoopCyclePauseFlag($this->sentinelPath),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->sentinelPath);
        parent::tearDown();
    }

    private function runCli(string $action, array $options = []): array
    {
        $opts = ['action' => $action, '--json' => true];
        foreach ($options as $k => $v) {
            $opts['--'.$k] = $v;
        }
        $exit = Artisan::call('atlas:loop:pause-resume', $opts);
        $payload = json_decode(trim(Artisan::output()), true);

        return ['exit' => $exit, 'payload' => is_array($payload) ? $payload : []];
    }

    public function test_pause_status_resume_round_trip(): void
    {
        $paused = $this->runCli('pause', ['cycle' => 'cyc-X', 'phase' => 'verify', 'reason' => 'operator']);
        self::assertSame(0, $paused['exit']);
        self::assertSame('raised', $paused['payload']['outcome']);
        self::assertSame('cyc-X', $paused['payload']['sentinel']['cycle_id']);

        $status = $this->runCli('status');
        self::assertSame(0, $status['exit']);
        self::assertSame('raised', $status['payload']['outcome']);
        self::assertSame('cyc-X', $status['payload']['sentinel']['cycle_id']);

        $resumed = $this->runCli('resume', ['cycle' => 'cyc-X', 'phase' => 'verify']);
        self::assertSame(0, $resumed['exit']);
        self::assertSame('resumed', $resumed['payload']['outcome']);
        self::assertSame('verify', $resumed['payload']['resumed_phase']);

        // After resume, sentinel is lowered.
        $statusAfter = $this->runCli('status');
        self::assertSame('not_raised', $statusAfter['payload']['outcome']);
    }

    public function test_clear_lowers_existing_sentinel_and_noop_when_absent(): void
    {
        $this->runCli('pause', ['cycle' => 'cyc-Y', 'phase' => 'plan', 'reason' => 'r']);
        $cleared = $this->runCli('clear');
        self::assertSame(0, $cleared['exit']);
        self::assertSame('cleared', $cleared['payload']['outcome']);

        $noop = $this->runCli('clear');
        self::assertSame(0, $noop['exit']);
        self::assertSame('noop', $noop['payload']['outcome']);
    }

    public function test_pause_refuses_missing_options(): void
    {
        $r = $this->runCli('pause', ['cycle' => 'cyc-Z']);
        self::assertSame(1, $r['exit']);
        self::assertSame('refused', $r['payload']['outcome']);
        self::assertSame('missing_required_options', $r['payload']['reason']);
    }

    public function test_resume_refuses_when_sentinel_absent(): void
    {
        $r = $this->runCli('resume', ['cycle' => 'cyc-W', 'phase' => 'verify']);
        self::assertSame(1, $r['exit']);
        self::assertSame('refused', $r['payload']['outcome']);
        self::assertSame('missing_pause_sentinel', $r['payload']['reason']);
    }

    public function test_unknown_action_returns_structured_refusal(): void
    {
        $r = $this->runCli('bogus');
        self::assertSame(1, $r['exit']);
        self::assertSame('refused', $r['payload']['outcome']);
        self::assertSame('unknown_action', $r['payload']['reason']);
    }
}
