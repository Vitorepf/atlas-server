<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay;

use App\Console\Commands\AtlasAaelTraceCommand;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay\AtlasAaelExecutionTraceRecorder;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay\AtlasAaelExecutionTraceReplayer;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * brain-orphan-e4195928732f — prove AtlasAaelExecutionTraceReplayer is wired through the live
 * `atlas:aael:trace replay` CLI path (previously the command had its own ad-hoc replay loop and
 * the replayer was an orphan with zero production callers).
 */
class AtlasAaelExecutionTraceReplayerWiringWiredTest extends TestCase
{
    private string $traceRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->traceRoot = sys_get_temp_dir().'/atlas-trace-replayer-wiring-'.bin2hex(random_bytes(4));
        mkdir($this->traceRoot, 0o755, true);
        config()->set('atlas.aael.trace.root', $this->traceRoot);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->traceRoot.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->traceRoot);
        parent::tearDown();
    }

    public function test_cli_replay_action_invokes_the_replayer_through_the_real_call_path(): void
    {
        $traceId = $this->seedTrace();
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);

        // null-actor: the replayer reports a structured report. Without storing the original
        // pre-image bytes (sha256 is one-way), the actor cannot exactly reproduce the recorded
        // fingerprint, so divergence may be reported — what matters is that the REPLAYER is the
        // single component producing the report (no ad-hoc CLI replay logic remains).
        $exit = $kernel->call('atlas:aael:trace', [
            'action' => 'replay',
            '--trace-id' => $traceId,
            '--json' => true,
        ], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertContains($exit, [0, 1], 'replayer-driven exit must be 0 (no divergence) or 1 (divergence)');
        self::assertIsArray($payload);
        foreach (['total_steps_replayed', 'diverged_step_count', 'divergences', 'root_commit_at_replay'] as $key) {
            self::assertArrayHasKey($key, $payload, 'shape mirrors AtlasAaelExecutionTraceReplayer::ReplayReport::toArray');
        }
        self::assertGreaterThanOrEqual(1, $payload['total_steps_replayed']);
    }

    public function test_cli_replay_with_divergent_actor_reports_divergence_through_the_replayer(): void
    {
        $traceId = $this->seedTrace();
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);

        $exit = $kernel->call('atlas:aael:trace', [
            'action' => 'replay',
            '--trace-id' => $traceId,
            '--actor' => 'divergent',
            '--json' => true,
        ], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertSame(1, $exit, 'divergent actor must surface non-zero exit');
        self::assertGreaterThan(0, $payload['diverged_step_count']);
        self::assertNotNull($payload['first_diverged_step_index']);
    }

    public function test_replayer_accepts_recorder_native_trace_format_directly(): void
    {
        // Direct service-level wiring proof: a trace produced by the recorder is consumable by
        // the replayer without any adapter layer (the format compatibility is part of the wiring).
        $traceId = $this->seedTrace();
        $path = $this->traceRoot.'/'.$traceId.'.jsonl';
        $replayer = new AtlasAaelExecutionTraceReplayer('test-replay');
        $report = $replayer->replay($path, new class implements \App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay\AaelStepActor {
            public function perform(int $stepIndex, string $action, mixed $input): string
            {
                return 'divergent:'.$stepIndex;
            }
        });

        self::assertGreaterThanOrEqual(1, $report->totalStepsReplayed);
        self::assertGreaterThan(0, $report->divergedStepCount);
    }

    public function test_cli_command_class_imports_the_replayer(): void
    {
        // Structural proof: AtlasAaelExecutionTraceReplayer is a real production caller.
        $source = (string) file_get_contents(
            base_path('app/Console/Commands/AtlasAaelTraceCommand.php')
        );
        self::assertStringContainsString(AtlasAaelExecutionTraceReplayer::class, $source);
        self::assertStringContainsString('$replayer->replay(', $source);
        self::assertInstanceOf(AtlasAaelTraceCommand::class, app(AtlasAaelTraceCommand::class));
    }

    private function seedTrace(): string
    {
        $recorder = new AtlasAaelExecutionTraceRecorder(
            traceRoot: $this->traceRoot,
            traceIdGenerator: static fn (): string => 'trace-'.bin2hex(random_bytes(6)),
        );
        $recorder->begin(['cli' => 'test'], 'unknown');
        $recorder->recordStep([
            'action_name' => 'step-1',
            'input' => ['k' => 1],
            'output' => ['k' => 1],
            'stdout' => '',
            'stderr' => '',
            'provider_id' => 'cli',
            'exit_code' => 0,
            'decision_context_id' => 'demo',
        ]);
        $recorder->recordStep([
            'action_name' => 'step-2',
            'input' => ['k' => 2],
            'output' => ['k' => 2],
            'stdout' => '',
            'stderr' => '',
            'provider_id' => 'cli',
            'exit_code' => 0,
            'decision_context_id' => 'demo',
        ]);
        $recorder->finish('closed-ok');

        return $recorder->traceId();
    }
}
