<?php

namespace Tests\Feature\Ai\Hermes;

use App\Http\Controllers\HermesHookSinkController;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Hermes\HermesHookSink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HermesHookSinkTest extends TestCase
{
    private const TRACE = 'trace-sink-001';

    private const SECRET = 'sk-SECRETKEY1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateLedger();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    private function token(string $trace = self::TRACE): string
    {
        return substr(hash('sha256', 'hermes_hook_token|'.$trace), 0, 48);
    }

    /**
     * @return array<string,mixed>
     */
    private function scope(): array
    {
        return [
            'allowed_tools' => ['bash', 'read', 'edit'],
            'allowed_paths' => ['/workspace/atlas'],
            'forbidden_paths' => ['/workspace/atlas/.git', '/etc'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionContext(array $overrides = []): array
    {
        return array_merge([
            'trace_id' => self::TRACE,
            'sink' => ['token' => $this->token()],
            'presented_token' => $this->token(),
            'mission_scope' => $this->scope(),
            'mission_id' => 'mission-sink-1',
            'mission_hash' => str_repeat('b', 64),
        ], $overrides);
    }

    private function sink(): HermesHookSink
    {
        return app(HermesHookSink::class);
    }

    // --- Direct sink decision logic -----------------------------------------

    public function test_pre_tool_call_blocks_out_of_scope_tool(): void
    {
        $event = [
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'web_fetch',
            'tool_input' => ['url' => 'https://example.com'],
            'session_id' => self::TRACE,
        ];

        $decision = $this->sink()->ingest($event, $this->sessionContext());

        $this->assertSame('atlas.hermes.hook_event_decision.v1', $decision['schema_version']);
        $this->assertSame('block', $decision['decision']);
        $this->assertSame('tool_not_in_allowed_tools', $decision['reason']);
        $this->assertNotEmpty($decision['receipt_hash']);
        $this->assertTrue((bool) $decision['recorded']);
    }

    public function test_pre_tool_call_allows_in_scope_tool_and_path(): void
    {
        $event = [
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'edit',
            'tool_input' => ['file_path' => '/workspace/atlas/app/Foo.php'],
            'session_id' => self::TRACE,
        ];

        $decision = $this->sink()->ingest($event, $this->sessionContext());

        $this->assertSame('allow', $decision['decision']);
        $this->assertSame('in_scope', $decision['reason']);
        $this->assertTrue((bool) $decision['recorded']);
        $this->assertNotEmpty($decision['receipt_hash']);
    }

    public function test_pre_tool_call_blocks_forbidden_path(): void
    {
        $event = [
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'edit',
            'tool_input' => ['file_path' => '/workspace/atlas/.git/config'],
            'session_id' => self::TRACE,
        ];

        $decision = $this->sink()->ingest($event, $this->sessionContext());

        $this->assertSame('block', $decision['decision']);
        $this->assertSame('path_in_forbidden_paths', $decision['reason']);
    }

    public function test_tool_input_secret_is_redacted_and_only_hash_is_kept(): void
    {
        $event = [
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'bash',
            'tool_input' => [
                'cwd' => '/workspace/atlas',
                'api_key' => self::SECRET,
                'command' => 'curl -H "Authorization: Bearer '.self::SECRET.'" https://x',
            ],
            'session_id' => self::TRACE,
        ];

        $decision = $this->sink()->ingest($event, $this->sessionContext());

        $this->assertNotEmpty($decision['tool_input_hash']);

        $json = json_encode($decision);
        $this->assertIsString($json);
        $this->assertStringNotContainsString(self::SECRET, $json);

        // The ledger event must also not carry the raw secret.
        $event = AtlasLedgerEvent::query()
            ->where('trace_id', self::TRACE)
            ->latest('occurred_at')
            ->first();
        $this->assertNotNull($event);
        $ledgerJson = json_encode($event->payload);
        $this->assertIsString($ledgerJson);
        $this->assertStringNotContainsString(self::SECRET, $ledgerJson);
        $this->assertSame($decision['tool_input_hash'], data_get($event->payload, 'tool_input_hash'));
    }

    public function test_fail_closed_when_scope_missing_blocks(): void
    {
        $event = [
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'edit',
            'tool_input' => ['file_path' => '/workspace/atlas/app/Foo.php'],
            'session_id' => self::TRACE,
        ];

        $decision = $this->sink()->ingest($event, $this->sessionContext(['mission_scope' => null]));

        $this->assertSame('block', $decision['decision']);
        $this->assertSame('mission_scope_missing', $decision['reason']);
        $this->assertNotEmpty($decision['receipt_hash']);
    }

    public function test_fail_closed_when_token_invalid_blocks_without_trusting_session(): void
    {
        $event = [
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'edit',
            'tool_input' => ['file_path' => '/workspace/atlas/app/Foo.php'],
            'session_id' => self::TRACE,
        ];

        $decision = $this->sink()->ingest($event, $this->sessionContext(['presented_token' => 'wrong-token']));

        $this->assertSame('block', $decision['decision']);
        $this->assertSame('token_invalid', $decision['reason']);
        $this->assertFalse((bool) $decision['recorded']);
    }

    public function test_post_tool_call_is_observed_not_blocked(): void
    {
        $event = [
            'hook_event_name' => 'post_tool_call',
            'tool_name' => 'edit',
            'tool_input' => ['file_path' => '/workspace/atlas/app/Foo.php'],
            'session_id' => self::TRACE,
        ];

        $decision = $this->sink()->ingest($event, $this->sessionContext());

        $this->assertSame('observed', $decision['decision']);
        $this->assertTrue((bool) $decision['recorded']);
    }

    // --- Controller boundary: loopback + token ------------------------------

    /**
     * @param  array<string,mixed>  $body
     */
    private function invoke(array $body, ?string $token, string $remoteAddr = '127.0.0.1', string $trace = self::TRACE): \Illuminate\Http\JsonResponse
    {
        $server = ['REMOTE_ADDR' => $remoteAddr];
        if ($token !== null) {
            $server['HTTP_X_ATLAS_HOOK_TOKEN'] = $token;
        }

        $request = Request::create(
            '/internal/hermes/hooks/'.$trace,
            'POST',
            [],
            [],
            [],
            $server,
            json_encode($body) ?: '{}',
        );
        $request->headers->set('Content-Type', 'application/json');

        return app(HermesHookSinkController::class)->__invoke($request, $trace, $this->sink());
    }

    public function test_controller_rejects_non_loopback_origin_with_403_and_no_record(): void
    {
        $response = $this->invoke([
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'edit',
            'extra' => ['mission_scope' => $this->scope()],
        ], $this->token(), remoteAddr: '203.0.113.7');

        $this->assertSame(403, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('block', $data['decision']);
        $this->assertSame('non_loopback_origin', $data['reason']);
        $this->assertSame(0, AtlasLedgerEvent::query()->count());
    }

    public function test_controller_rejects_bad_token_with_403_and_no_record(): void
    {
        $response = $this->invoke([
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'edit',
            'extra' => ['mission_scope' => $this->scope()],
        ], 'totally-wrong-token');

        $this->assertSame(403, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('block', $data['decision']);
        $this->assertSame('hook_token_invalid', $data['reason']);
        $this->assertSame(0, AtlasLedgerEvent::query()->count());
    }

    public function test_controller_allows_in_scope_tool_with_valid_token_over_loopback(): void
    {
        $response = $this->invoke([
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'edit',
            'tool_input' => ['file_path' => '/workspace/atlas/app/Foo.php'],
            'extra' => ['mission_scope' => $this->scope(), 'mission_id' => 'mission-sink-1'],
        ], $this->token());

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('atlas.hermes.hook_event_decision.v1', $data['schema_version']);
        $this->assertSame('allow', $data['decision']);
        $this->assertNotEmpty($data['receipt_hash']);
    }

    public function test_controller_blocks_out_of_scope_tool_with_200_block_body(): void
    {
        $response = $this->invoke([
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'web_fetch',
            'tool_input' => ['url' => 'https://example.com'],
            'extra' => ['mission_scope' => $this->scope()],
        ], $this->token());

        // pre_tool_call blocks come back as a 200 so Hermes reads the verdict.
        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('block', $data['decision']);
        $this->assertSame('tool_not_in_allowed_tools', $data['reason']);
    }

    public function test_controller_fails_closed_to_block_when_scope_not_injected(): void
    {
        $response = $this->invoke([
            'hook_event_name' => 'pre_tool_call',
            'tool_name' => 'edit',
            'tool_input' => ['file_path' => '/workspace/atlas/app/Foo.php'],
            // no extra.mission_scope
        ], $this->token());

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('block', $data['decision']);
        $this->assertSame('mission_scope_missing', $data['reason']);
    }
}
