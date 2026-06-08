<?php

namespace Tests\Unit\Ai\Hermes\Acp;

use App\Services\Ai\Hermes\Acp\HermesAcpProtocol;
use App\Services\Ai\Hermes\HermesAdapterReceipt;
use JsonException;
use Tests\TestCase;

class HermesAcpProtocolTest extends TestCase
{
    private HermesAcpProtocol $protocol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->protocol = new HermesAcpProtocol();
    }

    // ---------------------------------------------------------------------
    // Request builders — exact proven ACP shapes
    // ---------------------------------------------------------------------

    public function test_initialize_request_matches_proven_shape_fail_closed_fs(): void
    {
        $req = $this->protocol->initializeRequest(1);

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => 1,
                'clientCapabilities' => [
                    'fs' => [
                        'readTextFile' => false,
                        'writeTextFile' => false,
                    ],
                ],
            ],
        ], $req);
    }

    public function test_protocol_version_constant_is_one(): void
    {
        $this->assertSame(1, HermesAcpProtocol::PROTOCOL_VERSION);
        $this->assertSame(1, $this->protocol->initializeRequest(7)['params']['protocolVersion']);
    }

    public function test_session_new_request_defaults_to_empty_mcp_servers(): void
    {
        $req = $this->protocol->sessionNewRequest(2, '/abs/path');

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'session/new',
            'params' => [
                'cwd' => '/abs/path',
                'mcpServers' => [],
            ],
        ], $req);
    }

    public function test_session_new_request_passes_and_reindexes_mcp_servers(): void
    {
        $servers = [5 => ['name' => 'atlas-open-brain'], 9 => ['name' => 'fs']];
        $req = $this->protocol->sessionNewRequest(3, '/abs/work', $servers);

        $this->assertSame('/abs/work', $req['params']['cwd']);
        // array_values normalizes the keys to a real JSON list.
        $this->assertSame([
            ['name' => 'atlas-open-brain'],
            ['name' => 'fs'],
        ], $req['params']['mcpServers']);
    }

    public function test_session_prompt_request_wraps_text_block(): void
    {
        $req = $this->protocol->sessionPromptRequest(4, 'sess-abc', 'hello world');

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'session/prompt',
            'params' => [
                'sessionId' => 'sess-abc',
                'prompt' => [
                    ['type' => 'text', 'text' => 'hello world'],
                ],
            ],
        ], $req);
    }

    // ---------------------------------------------------------------------
    // encode()
    // ---------------------------------------------------------------------

    public function test_encode_produces_single_line_with_trailing_newline(): void
    {
        $line = $this->protocol->encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        $this->assertStringEndsWith("\n", $line);
        // Exactly one newline (the terminator), nothing embedded.
        $this->assertSame(1, substr_count($line, "\n"));
    }

    public function test_encode_uses_unescaped_slashes_and_unicode(): void
    {
        $line = $this->protocol->encode([
            'cwd' => '/Users/vitorepf/develop/Atlas',
            'text' => 'café — açaí',
        ]);

        $this->assertStringContainsString('/Users/vitorepf/develop/Atlas', $line);
        $this->assertStringNotContainsString('\/', $line);
        $this->assertStringContainsString('café — açaí', $line);
        $this->assertStringNotContainsString('\u', $line);
    }

    public function test_encode_round_trips_through_classify(): void
    {
        $req = $this->protocol->sessionPromptRequest(11, 'sess-1', 'do the thing');
        $line = $this->protocol->encode($req);
        $classified = $this->protocol->classify(rtrim($line, "\n"));

        // A request frame (id + method) classifies as agent_request structurally.
        $this->assertSame('agent_request', $classified['type']);
        $this->assertSame(11, $classified['id']);
        $this->assertSame('session/prompt', $classified['method']);
        $this->assertSame($req, $classified['message']);
    }

    public function test_encode_throws_on_non_encodable_payload(): void
    {
        $this->expectException(JsonException::class);
        // A resource is not JSON-encodable; surfaces the caller's bug loudly.
        $this->protocol->encode(['bad' => fopen('php://memory', 'r')]);
    }

    // ---------------------------------------------------------------------
    // classify() — happy paths for each frame type
    // ---------------------------------------------------------------------

    public function test_classify_initialize_result(): void
    {
        $line = '{"jsonrpc":"2.0","id":1,"result":{"agentInfo":{"name":"hermes","version":"1.0"}}}';
        $frame = $this->protocol->classify($line);

        $this->assertSame('result', $frame['type']);
        $this->assertSame(1, $frame['id']);
        $this->assertNull($frame['method']);
        $this->assertSame('hermes', $frame['message']['result']['agentInfo']['name']);
    }

    public function test_classify_error_response(): void
    {
        $line = '{"jsonrpc":"2.0","id":2,"error":{"code":-32601,"message":"Method not found"}}';
        $frame = $this->protocol->classify($line);

        $this->assertSame('error', $frame['type']);
        $this->assertSame(2, $frame['id']);
        $this->assertSame(-32601, $frame['message']['error']['code']);
    }

    public function test_classify_notification_has_method_no_id(): void
    {
        $line = '{"jsonrpc":"2.0","method":"session/update","params":{"update":{"sessionUpdate":"agent_message_chunk"}}}';
        $frame = $this->protocol->classify($line);

        $this->assertSame('notification', $frame['type']);
        $this->assertNull($frame['id']);
        $this->assertSame('session/update', $frame['method']);
    }

    public function test_classify_agent_request_has_both_id_and_method(): void
    {
        $line = '{"jsonrpc":"2.0","id":7,"method":"session/request_permission","params":{"options":[]}}';
        $frame = $this->protocol->classify($line);

        $this->assertSame('agent_request', $frame['type']);
        $this->assertSame(7, $frame['id']);
        $this->assertSame('session/request_permission', $frame['method']);
    }

    // ---------------------------------------------------------------------
    // classify() — noise / malformed / partial frames (must never throw)
    // ---------------------------------------------------------------------

    public function test_classify_blank_line_is_noise(): void
    {
        $frame = $this->protocol->classify('   ');
        $this->assertSame('noise', $frame['type']);
        $this->assertNull($frame['id']);
        $this->assertNull($frame['method']);
        $this->assertNull($frame['message']);
    }

    public function test_classify_banner_line_is_noise(): void
    {
        $frame = $this->protocol->classify('Hermes ACP agent v1.2.3 starting on stdio...');
        $this->assertSame('noise', $frame['type']);
    }

    public function test_classify_partial_or_truncated_json_is_noise(): void
    {
        $frame = $this->protocol->classify('{"jsonrpc":"2.0","id":1,"resul');
        $this->assertSame('noise', $frame['type']);
    }

    public function test_classify_json_array_is_noise(): void
    {
        // A top-level JSON array is not a JSON-RPC frame.
        $frame = $this->protocol->classify('[1,2,3]');
        $this->assertSame('noise', $frame['type']);
    }

    public function test_classify_json_scalar_is_noise(): void
    {
        $this->assertSame('noise', $this->protocol->classify('42')['type']);
        $this->assertSame('noise', $this->protocol->classify('"just a string"')['type']);
        $this->assertSame('noise', $this->protocol->classify('null')['type']);
        $this->assertSame('noise', $this->protocol->classify('true')['type']);
    }

    public function test_classify_object_without_id_result_error_or_method_is_noise(): void
    {
        // Valid JSON object but not a recognizable JSON-RPC frame.
        $frame = $this->protocol->classify('{"jsonrpc":"2.0","foo":"bar"}');
        $this->assertSame('noise', $frame['type']);
    }

    public function test_classify_response_without_method_id_only_with_result_wins_over_noise(): void
    {
        $frame = $this->protocol->classify('{"id":99,"result":{}}');
        $this->assertSame('result', $frame['type']);
        $this->assertSame(99, $frame['id']);
    }

    public function test_classify_handles_leading_whitespace_before_json(): void
    {
        $frame = $this->protocol->classify("  \t{\"jsonrpc\":\"2.0\",\"id\":3,\"result\":{}}");
        $this->assertSame('result', $frame['type']);
        $this->assertSame(3, $frame['id']);
    }

    // ---------------------------------------------------------------------
    // agent_message_chunk extraction
    // ---------------------------------------------------------------------

    public function test_is_agent_message_chunk_true_and_text_extracted(): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => 'session/update',
            'params' => [
                'update' => [
                    'sessionUpdate' => 'agent_message_chunk',
                    'content' => ['type' => 'text', 'text' => 'partial answer'],
                ],
            ],
        ];

        $this->assertTrue($this->protocol->isAgentMessageChunk($notification));
        $this->assertSame('partial answer', $this->protocol->agentMessageChunkText($notification));
    }

    public function test_chunks_concatenate_to_full_assistant_text(): void
    {
        $lines = [
            '{"jsonrpc":"2.0","method":"session/update","params":{"update":{"sessionUpdate":"agent_message_chunk","content":{"type":"text","text":"Hello, "}}}}',
            '{"jsonrpc":"2.0","method":"session/update","params":{"update":{"sessionUpdate":"agent_message_chunk","content":{"type":"text","text":"world"}}}}',
            '{"jsonrpc":"2.0","method":"session/update","params":{"update":{"sessionUpdate":"agent_message_chunk","content":{"type":"text","text":"!"}}}}',
            '{"jsonrpc":"2.0","id":5,"result":{"stopReason":"end_turn"}}',
        ];

        $assistant = '';
        $stopReason = null;
        foreach ($lines as $line) {
            $frame = $this->protocol->classify($line);
            if ($frame['type'] === 'notification' && $this->protocol->isAgentMessageChunk($frame['message'])) {
                $assistant .= $this->protocol->agentMessageChunkText($frame['message']);
            }
            if ($frame['type'] === 'result') {
                $stopReason = $this->protocol->promptResultStopReason($frame['message']);
            }
        }

        $this->assertSame('Hello, world!', $assistant);
        $this->assertSame('end_turn', $stopReason);
    }

    public function test_is_agent_message_chunk_false_for_other_session_update(): void
    {
        $notification = [
            'method' => 'session/update',
            'params' => ['update' => ['sessionUpdate' => 'tool_call', 'content' => []]],
        ];

        $this->assertFalse($this->protocol->isAgentMessageChunk($notification));
        $this->assertNull($this->protocol->agentMessageChunkText($notification));
    }

    public function test_agent_message_chunk_text_null_when_content_not_text_type(): void
    {
        $notification = [
            'method' => 'session/update',
            'params' => [
                'update' => [
                    'sessionUpdate' => 'agent_message_chunk',
                    'content' => ['type' => 'image', 'data' => 'xxx'],
                ],
            ],
        ];

        $this->assertNull($this->protocol->agentMessageChunkText($notification));
    }

    public function test_agent_message_chunk_text_null_when_text_missing_or_non_string(): void
    {
        $missing = [
            'method' => 'session/update',
            'params' => ['update' => ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text']]],
        ];
        $nonString = [
            'method' => 'session/update',
            'params' => ['update' => ['sessionUpdate' => 'agent_message_chunk', 'content' => ['type' => 'text', 'text' => 123]]],
        ];

        $this->assertNull($this->protocol->agentMessageChunkText($missing));
        $this->assertNull($this->protocol->agentMessageChunkText($nonString));
    }

    public function test_agent_message_chunk_helpers_safe_on_empty_and_malformed(): void
    {
        $this->assertFalse($this->protocol->isAgentMessageChunk([]));
        $this->assertNull($this->protocol->agentMessageChunkText([]));
        $this->assertFalse($this->protocol->isAgentMessageChunk(['method' => 'session/update', 'params' => 'not-an-array']));
    }

    // ---------------------------------------------------------------------
    // permission request / response  (fail-closed default-deny)
    // ---------------------------------------------------------------------

    public function test_is_permission_request_true_for_session_request_permission(): void
    {
        $msg = ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'session/request_permission', 'params' => []];
        $this->assertTrue($this->protocol->isPermissionRequest($msg));
    }

    public function test_is_permission_request_false_for_other_methods_and_empty(): void
    {
        $this->assertFalse($this->protocol->isPermissionRequest(['method' => 'session/update']));
        $this->assertFalse($this->protocol->isPermissionRequest([]));
    }

    public function test_permission_response_selected_when_option_id_given(): void
    {
        $resp = $this->protocol->permissionResponse(8, 'allow-once');

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 8,
            'result' => [
                'outcome' => ['outcome' => 'selected', 'optionId' => 'allow-once'],
            ],
        ], $resp);
    }

    public function test_permission_response_cancelled_when_option_id_null_default_deny(): void
    {
        $resp = $this->protocol->permissionResponse(8, null);

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 8,
            'result' => [
                'outcome' => ['outcome' => 'cancelled'],
            ],
        ], $resp);
    }

    public function test_permission_request_round_trips_classify_then_deny(): void
    {
        $line = '{"jsonrpc":"2.0","id":12,"method":"session/request_permission","params":{"options":[{"optionId":"allow","name":"Allow","kind":"allow_once"}],"toolCall":{"name":"write"}}}';
        $frame = $this->protocol->classify($line);

        $this->assertSame('agent_request', $frame['type']);
        $this->assertTrue($this->protocol->isPermissionRequest($frame['message']));

        // Default-deny: Atlas did not approve -> cancel using the SAME id.
        $deny = $this->protocol->permissionResponse($frame['id'], null);
        $this->assertSame(12, $deny['id']);
        $this->assertSame('cancelled', $deny['result']['outcome']['outcome']);
    }

    // ---------------------------------------------------------------------
    // session/prompt result accessors
    // ---------------------------------------------------------------------

    public function test_prompt_result_stop_reason_and_usage(): void
    {
        $result = [
            'jsonrpc' => '2.0',
            'id' => 5,
            'result' => [
                'stopReason' => 'end_turn',
                'usage' => ['inputTokens' => 10, 'outputTokens' => 20, 'totalTokens' => 30],
            ],
        ];

        $this->assertSame('end_turn', $this->protocol->promptResultStopReason($result));
        $this->assertSame(
            ['inputTokens' => 10, 'outputTokens' => 20, 'totalTokens' => 30],
            $this->protocol->promptResultUsage($result),
        );
    }

    public function test_prompt_result_stop_reason_null_when_missing_or_non_string(): void
    {
        $this->assertNull($this->protocol->promptResultStopReason([]));
        $this->assertNull($this->protocol->promptResultStopReason(['result' => []]));
        $this->assertNull($this->protocol->promptResultStopReason(['result' => ['stopReason' => 99]]));
    }

    public function test_prompt_result_usage_empty_when_missing_or_malformed(): void
    {
        $this->assertSame([], $this->protocol->promptResultUsage([]));
        $this->assertSame([], $this->protocol->promptResultUsage(['result' => []]));
        $this->assertSame([], $this->protocol->promptResultUsage(['result' => ['usage' => 'nope']]));
        $this->assertSame([], $this->protocol->promptResultUsage(['result' => ['usage' => [1, 2, 3]]]));
    }

    // ---------------------------------------------------------------------
    // determinism — the byte substrate the sealed receipt_hash relies on
    // ---------------------------------------------------------------------

    public function test_encode_is_byte_deterministic_for_identical_input(): void
    {
        $req = $this->protocol->sessionPromptRequest(1, 'sess-x', 'stable payload');

        $a = $this->protocol->encode($req);
        $b = $this->protocol->encode($req);

        $this->assertSame($a, $b);
    }

    public function test_receipt_hash_over_encoded_frame_is_deterministic(): void
    {
        // The ACP transport seals receipts with the shared HermesAdapterReceipt
        // trait (same json flags). Prove the codec's encoded frame hashes
        // identically every time and matches the trait's own sealing of the
        // same array — i.e. encode() is a faithful hash substrate.
        $sealer = new class
        {
            use HermesAdapterReceipt;

            /** @param array<string,mixed> $v */
            public function seal(array $v): string
            {
                return $this->hashValue($v);
            }
        };

        $frame = $this->protocol->initializeRequest(1);

        $hashA = hash('sha256', rtrim($this->protocol->encode($frame), "\n"));
        $hashB = hash('sha256', rtrim($this->protocol->encode($frame), "\n"));

        $this->assertSame($hashA, $hashB);
        // The trait hashes the same array to the same bytes (shared json flags).
        $this->assertSame($sealer->seal($frame), $hashA);
    }

    public function test_classify_does_not_throw_on_a_battery_of_garbage(): void
    {
        $garbage = [
            '', ' ', "\t", '{', '}', '{]', '[}', 'NaN', 'undefined',
            '{"jsonrpc":}', '{"id":}', "\x00\x01binary", 'banner: ok',
            '{"jsonrpc":"2.0"}', '{"jsonrpc":"2.0","id":null}',
        ];

        foreach ($garbage as $line) {
            $frame = $this->protocol->classify($line);
            $this->assertArrayHasKey('type', $frame);
            $this->assertContains($frame['type'], ['result', 'error', 'notification', 'agent_request', 'noise']);
        }
    }
}
