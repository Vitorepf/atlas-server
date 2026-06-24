<?php

declare(strict_types=1);

namespace Tests\Unit\Loop;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopKeepaliveSelfDeadlineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.loop.keepalive_self_deadline_seconds', 7);
    }

    public function test_pcntl_alarm_is_armed_from_config_and_cancelled_on_success(): void
    {
        $cmd = new KeepaliveSelfDeadlineHarness();

        $status = $cmd->handle();

        $this->assertSame(0, $status);
        $this->assertSame([true], $cmd->asyncSignals);
        $this->assertSame([SIGALRM], $cmd->registeredSignals);
        $this->assertSame([7, 0], $cmd->alarms);

        $out = $cmd->lastOutput();
        $this->assertSame('armed', $out['self_deadline']);
        $this->assertSame(7, $out['self_deadline_seconds']);
        $this->assertSame('/tmp/atlas-test-keepalive-self-deadline.log', $out['self_deadline_log']);
        $this->assertSame(1, $out['checked']);
    }

    public function test_alarm_is_cancelled_when_keepalive_body_throws(): void
    {
        $cmd = new KeepaliveSelfDeadlineHarness();
        $cmd->throwFromRun = true;

        try {
            $cmd->handle();
            $this->fail('Expected simulated keepalive failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated_keepalive_failure', $e->getMessage());
        }

        $this->assertSame([7, 0], $cmd->alarms);
    }

    public function test_without_pcntl_marks_output_unavailable_and_keeps_body_flow(): void
    {
        $cmd = new KeepaliveSelfDeadlineHarness();
        $cmd->selfDeadlineAvailable = false;

        $status = $cmd->handle();

        $this->assertSame(0, $status);
        $this->assertSame([], $cmd->asyncSignals);
        $this->assertSame([], $cmd->registeredSignals);
        $this->assertSame([], $cmd->alarms);

        $out = $cmd->lastOutput();
        $this->assertSame('unavailable', $out['self_deadline']);
        $this->assertSame(1, $out['checked']);
        $this->assertSame([], $out['respawned']);
    }

    public function test_master_off_returns_before_arming_self_deadline(): void
    {
        $cmd = new KeepaliveSelfDeadlineHarness();
        $cmd->masterEnabled = false;

        $status = $cmd->handle();

        $this->assertSame(0, $status);
        $this->assertSame([], $cmd->asyncSignals);
        $this->assertSame([], $cmd->registeredSignals);
        $this->assertSame([], $cmd->alarms);
        $this->assertFalse($cmd->bodyRan);

        $out = $cmd->lastOutput();
        $this->assertSame('off', $out['master']);
        $this->assertArrayNotHasKey('self_deadline', $out);
    }

    public function test_self_deadline_handler_writes_log_payload_and_sends_sigterm_to_self(): void
    {
        $cmd = new KeepaliveSelfDeadlineHarness();
        $cmd->handle();

        $cmd->triggerSelfDeadlineForTest();

        $this->assertSame([4242], $cmd->terminatedPids);
        $this->assertSame('/tmp/atlas-test-keepalive-self-deadline.log', $cmd->deadlineLogs[0]['path']);
        $this->assertSame([
            'schema_version' => 'atlas.loop.keepalive_self_deadline.v1',
            'pid' => 4242,
            'timestamp' => '2026-06-24T12:00:00+00:00',
            'reason' => 'self_deadline_exceeded',
            'deadline_seconds' => 7,
        ], $cmd->deadlineLogs[0]['payload']);
    }
}

final class KeepaliveSelfDeadlineHarness extends AtlasLoopKeepaliveCommand
{
    public bool $masterEnabled = true;

    public bool $selfDeadlineAvailable = true;

    public bool $throwFromRun = false;

    public bool $bodyRan = false;

    /** @var list<bool> */
    public array $asyncSignals = [];

    /** @var list<int> */
    public array $registeredSignals = [];

    /** @var list<int> */
    public array $alarms = [];

    /** @var list<string> */
    public array $lines = [];

    /** @var list<array{path:string,payload:array<string,mixed>}> */
    public array $deadlineLogs = [];

    /** @var list<int> */
    public array $terminatedPids = [];

    private mixed $selfDeadlineHandler = null;

    protected function masterSwitchEnabled(): bool
    {
        return $this->masterEnabled;
    }

    protected function selfDeadlineAvailable(): bool
    {
        return $this->selfDeadlineAvailable;
    }

    protected function pcntlAsyncSignals(bool $enabled): void
    {
        $this->asyncSignals[] = $enabled;
    }

    protected function pcntlSignal(int $signal, callable $handler): void
    {
        $this->registeredSignals[] = $signal;
        $this->selfDeadlineHandler = $handler;
    }

    protected function pcntlAlarm(int $seconds): void
    {
        $this->alarms[] = $seconds;
    }

    protected function runKeepalive(array &$out): int
    {
        $this->bodyRan = true;

        if ($this->throwFromRun) {
            throw new RuntimeException('simulated_keepalive_failure');
        }

        $out['checked'] = 1;
        $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    protected function appendSelfDeadlineLog(array $payload): void
    {
        $this->deadlineLogs[] = [
            'path' => $this->selfDeadlineLogPath(),
            'payload' => $payload,
        ];
    }

    protected function selfDeadlineLogPath(): string
    {
        return '/tmp/atlas-test-keepalive-self-deadline.log';
    }

    protected function selfDeadlineTimestamp(): string
    {
        return '2026-06-24T12:00:00+00:00';
    }

    protected function currentPid(): ?int
    {
        return 4242;
    }

    protected function terminateSelfProcess(): void
    {
        $pid = $this->currentPid();
        if ($pid !== null) {
            $this->terminatedPids[] = $pid;
        }
    }

    public function triggerSelfDeadlineForTest(): void
    {
        if (! is_callable($this->selfDeadlineHandler)) {
            throw new RuntimeException('self_deadline_handler_not_registered');
        }

        ($this->selfDeadlineHandler)();
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->lines[] = (string) $string;
    }

    /**
     * @return array<string,mixed>
     */
    public function lastOutput(): array
    {
        $line = end($this->lines);
        $decoded = json_decode(is_string($line) ? $line : '', true);

        return is_array($decoded) ? $decoded : [];
    }
}
