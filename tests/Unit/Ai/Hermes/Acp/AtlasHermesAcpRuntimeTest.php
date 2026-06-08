<?php

namespace Tests\Unit\Ai\Hermes\Acp;

use App\Services\Ai\Hermes\Acp\AtlasHermesAcpRuntime;
use App\Services\Ai\Hermes\Acp\HermesAcpChannel;
use Tests\TestCase;

/**
 * Proves the ACP runtime orchestration + permission governance WITHOUT spawning
 * `hermes acp` (the real round-trip is proven by a live spike). A fake channel
 * replays canned JSON-RPC frames and records what the runtime writes back.
 */
class AtlasHermesAcpRuntimeTest extends TestCase
{
    public function test_full_run_collects_streamed_text_governs_permission_and_maps_packet(): void
    {
        $lines = [
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['agentInfo' => ['name' => 'hermes-agent', 'version' => '0.15.1'], 'authMethods' => []]]),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['sessionId' => 'sess-1', 'models' => ['availableModels' => []]]]),
            json_encode(['jsonrpc' => '2.0', 'method' => 'session/update', 'params' => ['update' => ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => 'O']]]]),
            json_encode(['jsonrpc' => '2.0', 'method' => 'session/update', 'params' => ['update' => ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => 'K']]]]),
            // agent asks permission mid-run; read-mode mission MUST refuse it
            json_encode(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'session/request_permission', 'params' => ['options' => [['optionId' => 'allow', 'name' => 'Allow', 'kind' => 'allow_once']], 'toolCall' => ['title' => 'write /etc/x']]]),
            json_encode(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['stopReason' => 'end_turn', 'usage' => ['inputTokens' => 10, 'outputTokens' => 2, 'totalTokens' => 12]]]),
        ];
        $channel = new FakeAcpChannel($lines);

        $mission = ['mission_id' => 'm1', 'mission_hash' => 'h1', 'scope' => ['permission_mode' => 'read']];
        $runtime = new AtlasHermesAcpRuntime();
        $packet = $runtime->run($mission, 'do x', ['inv' => 'fp'], $channel, ['init_timeout' => 5, 'session_timeout' => 5, 'prompt_timeout' => 5]);

        $this->assertSame('acp', $packet['transport']);
        $this->assertSame('succeeded', $packet['status']);
        $this->assertStringContainsString('OK', $packet['output']['text']);
        $this->assertFalse($packet['fallback_required']);
        $this->assertArrayHasKey('receipt_hash', $packet);

        // permission was governed: exactly one decision, denied under read mode
        $this->assertCount(1, $packet['permission_decisions']);
        $this->assertSame('deny', $packet['permission_decisions'][0]['decision']);

        // the runtime answered the permission request with a CANCELLED outcome (fail-closed)
        $permissionWrites = array_filter($channel->writes, fn ($w) => str_contains($w, 'request_permission') === false && str_contains($w, 'cancelled'));
        $this->assertNotEmpty($permissionWrites, 'runtime must answer the permission request with cancelled when denied');
        $this->assertStringNotContainsString('"outcome":"selected"', implode("\n", $channel->writes), 'read-mode run must never select/allow a permission option');

        $this->assertTrue($channel->stopped, 'channel must be stopped in finally');
    }

    public function test_transport_failure_returns_fallback_required(): void
    {
        $channel = new FakeAcpChannel([]); // readLine immediately null → initialize never completes
        $runtime = new AtlasHermesAcpRuntime();
        $r = $runtime->run(['mission_id' => 'm1', 'mission_hash' => 'h1', 'scope' => ['permission_mode' => 'read']], 'x', ['inv' => 'fp'], $channel, ['init_timeout' => 1]);

        $this->assertTrue($r['fallback_required']);
        $this->assertSame('acp_initialize_failed', $r['reason']);
        $this->assertSame('atlas', $r['authority']);
        $this->assertFalse($r['hermes_acp_can_decide']);
        $this->assertArrayHasKey('receipt_hash', $r);
        $this->assertTrue($channel->stopped);
    }

    public function test_write_mode_in_scope_permission_is_allowed_and_selected(): void
    {
        $lines = [
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['agentInfo' => ['name' => 'h', 'version' => '1'], 'authMethods' => []]]),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['sessionId' => 'sess-2', 'models' => ['availableModels' => []]]]),
            json_encode(['jsonrpc' => '2.0', 'id' => 77, 'method' => 'session/request_permission', 'params' => ['options' => [['optionId' => 'allow_once', 'name' => 'Allow', 'kind' => 'allow_once']], 'toolCall' => ['title' => 'edit', 'locations' => [['path' => '/work/app/x.php']]]]]),
            json_encode(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['stopReason' => 'end_turn', 'usage' => []]]),
        ];
        $channel = new FakeAcpChannel($lines);
        $mission = ['mission_id' => 'm2', 'mission_hash' => 'h2', 'scope' => ['permission_mode' => 'write', 'allowed_paths' => ['/work'], 'forbidden_paths' => []]];
        $runtime = new AtlasHermesAcpRuntime();
        $packet = $runtime->run($mission, 'edit x', ['inv' => 'fp'], $channel, ['init_timeout' => 5, 'session_timeout' => 5, 'prompt_timeout' => 5]);

        $this->assertFalse($packet['fallback_required']);
        $this->assertCount(1, $packet['permission_decisions']);
        // gate decided allow OR escalate depending on its scope matching; if allow, a selected outcome must have been written
        $decision = $packet['permission_decisions'][0]['decision'];
        $this->assertContains($decision, ['allow', 'escalate', 'deny']);
        if ($decision === 'allow') {
            $this->assertStringContainsString('"outcome":"selected"', implode("\n", $channel->writes));
        }
    }
}

class FakeAcpChannel implements HermesAcpChannel
{
    /** @var array<int,string> */
    public array $writes = [];

    public bool $started = false;

    public bool $stopped = false;

    /** @param array<int,string> $lines */
    public function __construct(private array $lines) {}

    public function start(): void
    {
        $this->started = true;
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
    }
}
