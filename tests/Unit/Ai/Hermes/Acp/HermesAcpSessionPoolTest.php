<?php

namespace Tests\Unit\Ai\Hermes\Acp;

use App\Services\Ai\Hermes\Acp\AtlasHermesAcpRuntime;
use App\Services\Ai\Hermes\Acp\HermesAcpChannel;
use App\Services\Ai\Hermes\Acp\HermesAcpSessionPool;
use Tests\TestCase;

/**
 * Proves the warm ACP session pool reuses ONE `hermes acp` process across jobs
 * (only the first job initializes), never reuses a failed/half-consumed session,
 * recycles after a bound, replaces dead processes, isolates keys, and cleans up
 * on shutdown — all WITHOUT spawning Hermes (a fake channel replays frames). The
 * live process reuse itself is proven by a separate live spike.
 */
class HermesAcpSessionPoolTest extends TestCase
{
    private const MISSION = ['mission_id' => 'm', 'mission_hash' => 'h', 'scope' => ['permission_mode' => 'read']];

    private const OPTS = ['init_timeout' => 5, 'session_timeout' => 5, 'prompt_timeout' => 5];

    public function test_warm_session_is_reused_across_jobs_initializing_once(): void
    {
        // job1: initialize(1) + session/new(2) + chunks + prompt(3); job2 reuses the
        // SAME process: NO initialize, session/new(4) + chunks + prompt(5).
        $channel = new FakeWarmChannel([
            $this->initFrame(1), $this->sessionFrame(2, 's1'), $this->chunkFrame('O'), $this->chunkFrame('K'), $this->promptFrame(3),
            $this->sessionFrame(4, 's2'), $this->chunkFrame('H'), $this->chunkFrame('I'), $this->promptFrame(5),
        ]);
        $factoryCalls = 0;
        $factory = function () use (&$factoryCalls, $channel): HermesAcpChannel {
            $factoryCalls++;

            return $channel;
        };
        $pool = new HermesAcpSessionPool(50);
        $runtime = new AtlasHermesAcpRuntime();

        $p1 = $runtime->runPooled($pool, 'k1', $factory, self::MISSION, 'do1', ['inv' => 'x'], self::OPTS);
        $p2 = $runtime->runPooled($pool, 'k1', $factory, self::MISSION, 'do2', ['inv' => 'x'], self::OPTS);

        $this->assertFalse($p1['fallback_required']);
        $this->assertSame('OK', $p1['output']['text']);
        $this->assertFalse($p2['fallback_required']);
        $this->assertSame('HI', $p2['output']['text'], 'second job got its own session output, not contaminated by the first');

        $this->assertSame(1, $factoryCalls, 'the process is created once and reused across jobs');
        $initWrites = array_values(array_filter($channel->writes, fn ($w) => str_contains($w, '"method":"initialize"')));
        $this->assertCount(1, $initWrites, 'initialize is sent exactly once across two jobs (the ~5s cold start is paid once)');
        $this->assertFalse($channel->stopped, 'the warm channel is not stopped between jobs');
        $this->assertSame(1, $pool->activeCount());
    }

    public function test_failed_prompt_discards_session_so_next_job_rewarms(): void
    {
        // job1: initialize + session ok, but the prompt result never arrives → fallback.
        $bad = new FakeWarmChannel([$this->initFrame(1), $this->sessionFrame(2, 's1'), $this->chunkFrame('X')]);
        $good = new FakeWarmChannel([$this->initFrame(1), $this->sessionFrame(2, 's2'), $this->chunkFrame('Y'), $this->promptFrame(3)]);
        $built = [];
        $seq = [$bad, $good];
        $factory = function () use (&$built, $seq): HermesAcpChannel {
            $c = $seq[count($built)];
            $built[] = $c;

            return $c;
        };
        $pool = new HermesAcpSessionPool(50);
        $runtime = new AtlasHermesAcpRuntime();

        $p1 = $runtime->runPooled($pool, 'k', $factory, self::MISSION, 'do', ['inv' => 'x'], self::OPTS);
        $this->assertTrue($p1['fallback_required']);
        $this->assertSame('acp_prompt_incomplete', $p1['reason']);
        $this->assertTrue($bad->stopped, 'a half-consumed session is stopped, never reused');
        $this->assertSame(0, $pool->activeCount());

        // next job must build a FRESH process and succeed
        $p2 = $runtime->runPooled($pool, 'k', $factory, self::MISSION, 'do', ['inv' => 'x'], self::OPTS);
        $this->assertFalse($p2['fallback_required']);
        $this->assertSame('Y', $p2['output']['text']);
        $this->assertCount(2, $built, 'a fresh process was built for the retry');
        $this->assertSame(1, $pool->activeCount());
    }

    public function test_initialize_failure_discards_and_falls_back(): void
    {
        $channel = new FakeWarmChannel([]); // readLine null immediately → initialize never completes
        $factory = fn (): HermesAcpChannel => $channel;
        $pool = new HermesAcpSessionPool(50);
        $runtime = new AtlasHermesAcpRuntime();

        $p = $runtime->runPooled($pool, 'k', $factory, self::MISSION, 'do', ['inv' => 'x'], ['init_timeout' => 1]);

        $this->assertTrue($p['fallback_required']);
        $this->assertSame('acp_initialize_failed', $p['reason']);
        $this->assertTrue($channel->stopped);
        $this->assertSame(0, $pool->activeCount());
    }

    public function test_session_recycled_after_max_served(): void
    {
        $channel = new FakeWarmChannel([$this->initFrame(1), $this->sessionFrame(2, 's1'), $this->chunkFrame('A'), $this->promptFrame(3)]);
        $factory = fn (): HermesAcpChannel => $channel;
        $pool = new HermesAcpSessionPool(1); // recycle after a single prompt
        $runtime = new AtlasHermesAcpRuntime();

        $p1 = $runtime->runPooled($pool, 'k', $factory, self::MISSION, 'do', ['inv' => 'x'], self::OPTS);

        $this->assertFalse($p1['fallback_required']);
        $this->assertTrue($channel->stopped, 'the process is recycled (stopped) once it hit the prompt cap');
        $this->assertSame(0, $pool->activeCount());
    }

    public function test_dead_channel_is_replaced_on_next_lease(): void
    {
        $c1 = new FakeWarmChannel([]);
        $c2 = new FakeWarmChannel([]);
        $seq = [$c1, $c2];
        $i = 0;
        $factory = function () use (&$i, $seq): HermesAcpChannel {
            return $seq[$i++];
        };
        $pool = new HermesAcpSessionPool(50);

        $s1 = $pool->lease('k', $factory);
        $s1->channel->start();
        $this->assertSame($c1, $s1->channel);

        $c1->stop(); // process dies between jobs

        $s2 = $pool->lease('k', $factory);
        $this->assertSame($c2, $s2->channel, 'a dead process is dropped and replaced via the factory');
        $this->assertSame(1, $pool->activeCount());
    }

    public function test_distinct_keys_keep_separate_sessions(): void
    {
        $c1 = new FakeWarmChannel([]);
        $c2 = new FakeWarmChannel([]);
        $seq = [$c1, $c2];
        $i = 0;
        $factory = function () use (&$i, $seq): HermesAcpChannel {
            return $seq[$i++];
        };
        $pool = new HermesAcpSessionPool(50);

        $a = $pool->lease('home-a', $factory);
        $a->channel->start();
        $b = $pool->lease('home-b', $factory);
        $b->channel->start();

        $this->assertNotSame($a->channel, $b->channel);
        $this->assertSame(2, $pool->activeCount(), 'each (binary|cwd|home) key gets its own warm process');
    }

    public function test_shutdown_stops_every_session(): void
    {
        $c1 = new FakeWarmChannel([]);
        $c2 = new FakeWarmChannel([]);
        $seq = [$c1, $c2];
        $i = 0;
        $factory = function () use (&$i, $seq): HermesAcpChannel {
            return $seq[$i++];
        };
        $pool = new HermesAcpSessionPool(50);
        $pool->lease('a', $factory)->channel->start();
        $pool->lease('b', $factory)->channel->start();

        $pool->shutdown();

        $this->assertTrue($c1->stopped);
        $this->assertTrue($c2->stopped);
        $this->assertSame(0, $pool->activeCount(), 'no orphaned hermes acp process survives worker shutdown');
    }

    public function test_pooled_run_governs_permission_like_cold_run(): void
    {
        // mid-run permission request must be DENIED under read mode — same governance
        // as run(), proving the shared promptCycle path keeps the gate intact.
        $channel = new FakeWarmChannel([
            $this->initFrame(1),
            $this->sessionFrame(2, 's1'),
            json_encode(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'session/request_permission', 'params' => ['options' => [['optionId' => 'allow', 'name' => 'Allow', 'kind' => 'allow_once']], 'toolCall' => ['title' => 'write /etc/x']]]),
            $this->promptFrame(3),
        ]);
        $factory = fn (): HermesAcpChannel => $channel;
        $pool = new HermesAcpSessionPool(50);
        $runtime = new AtlasHermesAcpRuntime();

        $p = $runtime->runPooled($pool, 'k', $factory, self::MISSION, 'do', ['inv' => 'x'], self::OPTS);

        $this->assertFalse($p['fallback_required']);
        $this->assertCount(1, $p['permission_decisions']);
        $this->assertSame('deny', $p['permission_decisions'][0]['decision']);
        $this->assertStringNotContainsString('"outcome":"selected"', implode("\n", $channel->writes), 'read-mode pooled run must never allow a permission option');
    }

    private function initFrame(int $id): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['agentInfo' => ['name' => 'h', 'version' => '1'], 'authMethods' => []]]);
    }

    private function sessionFrame(int $id, string $sid): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['sessionId' => $sid, 'models' => ['availableModels' => []]]]);
    }

    private function chunkFrame(string $text): string
    {
        return json_encode(['jsonrpc' => '2.0', 'method' => 'session/update', 'params' => ['update' => ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => $text]]]]);
    }

    private function promptFrame(int $id): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['stopReason' => 'end_turn', 'usage' => ['totalTokens' => 3]]]);
    }
}

class FakeWarmChannel implements HermesAcpChannel
{
    /** @var array<int,string> */
    public array $writes = [];

    public bool $started = false;

    public bool $stopped = false;

    public int $stopCount = 0;

    /** @param array<int,string> $lines */
    public function __construct(private array $lines) {}

    public function start(): void
    {
        $this->started = true; // idempotent, like the real proc_open transport
    }

    public function writeLine(string $line): void
    {
        $this->writes[] = $line;
    }

    public function readLine(float $budget): ?string
    {
        return array_shift($this->lines);
    }

    public function drainStderr(): string
    {
        return '';
    }

    public function isRunning(): bool
    {
        return $this->started && ! $this->stopped;
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->stopCount++;
    }
}
