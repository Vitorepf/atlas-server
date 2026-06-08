<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Hermes\Acp;

use App\Services\Ai\Hermes\Acp\HermesAcpResultMapper;
use Tests\TestCase;

/**
 * Pure unit coverage for the ACP -> result_packet.v1 mapper.
 *
 * No DB, no subprocess, no `hermes` binary, no network. Inputs are the
 * already-assembled assistant text + canned `session/prompt` stopReason/usage.
 */
final class HermesAcpResultMapperTest extends TestCase
{
    private HermesAcpResultMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new HermesAcpResultMapper();
    }

    public function test_maps_completed_run_into_result_packet_v1(): void
    {
        $packet = $this->mapper->map(
            assistantText: 'Hello from Hermes via ACP.',
            stopReason: 'end_turn',
            usage: ['inputTokens' => 12, 'outputTokens' => 34, 'totalTokens' => 46, 'extra' => 99],
            sessionId: 'sess_abc123',
            mission: ['mission_id' => 'm-1', 'mission_hash' => 'deadbeef'],
            invocation: ['transport' => 'acp', 'model' => 'gpt-5.5'],
        );

        $this->assertSame('atlas.hermes.result_packet.v1', $packet['schema_version']);
        // schema_version must be the FIRST key.
        $this->assertSame('schema_version', array_key_first($packet));
        $this->assertSame('acp', $packet['transport']);
        $this->assertSame('succeeded', $packet['status']);
        $this->assertSame('atlas', $packet['authority']);
        $this->assertTrue($packet['atlas_is_sovereign']);
        $this->assertTrue($packet['provider_is_executor_only']);
        $this->assertFalse($packet['hermes_can_decide']);

        $this->assertSame('Hello from Hermes via ACP.', $packet['output']['text']);
        $this->assertSame('end_turn', $packet['output']['stop_reason']);
        $this->assertSame(hash('sha256', json_encode(['Hello from Hermes via ACP.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $packet['output']['text_hash']);

        $this->assertSame('m-1', $packet['mission_id']);
        $this->assertSame('deadbeef', $packet['mission_hash']);

        // receipt_hash is sealed last.
        $this->assertArrayHasKey('receipt_hash', $packet);
        $this->assertArrayEndsWithReceiptHash($packet);
    }

    public function test_usage_is_normalized_to_ints_with_zero_defaults(): void
    {
        $packet = $this->mapper->map(
            assistantText: 'x',
            stopReason: 'end_turn',
            usage: [], // missing entirely
            sessionId: 's',
            mission: [],
            invocation: [],
        );

        $this->assertSame(
            ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            $packet['usage'],
        );
        foreach ($packet['usage'] as $value) {
            $this->assertIsInt($value);
        }
    }

    public function test_usage_accepts_snake_case_and_numeric_strings_and_rejects_garbage(): void
    {
        $packet = $this->mapper->map(
            assistantText: 'x',
            stopReason: 'end_turn',
            usage: [
                'input_tokens' => '15',          // numeric string snake_case
                'output_tokens' => 7.9,          // float -> truncates
                'totalTokens' => 'not-a-number', // garbage -> 0
            ],
            sessionId: 's',
            mission: [],
            invocation: [],
        );

        $this->assertSame(15, $packet['usage']['input_tokens']);
        $this->assertSame(7, $packet['usage']['output_tokens']);
        $this->assertSame(0, $packet['usage']['total_tokens']);
    }

    public function test_empty_failed_run_returns_no_output_packet(): void
    {
        $packet = $this->mapper->map(
            assistantText: '',
            stopReason: null,
            usage: [],
            sessionId: '',
            mission: [],
            invocation: [],
        );

        // Fail-closed default-deny: still a valid sealed packet.
        $this->assertSame('atlas.hermes.result_packet.v1', $packet['schema_version']);
        $this->assertSame('no_output', $packet['status']);
        $this->assertSame('atlas', $packet['authority']);
        $this->assertFalse($packet['hermes_can_decide']);

        $this->assertSame('', $packet['output']['text']);
        $this->assertNull($packet['output']['text_hash']);
        $this->assertNull($packet['output']['stop_reason']);
        $this->assertNull($packet['session_id_hash']);
        $this->assertNull($packet['mission_id']);
        $this->assertNull($packet['mission_hash']);

        $this->assertArrayHasKey('receipt_hash', $packet);
        $this->assertArrayEndsWithReceiptHash($packet);
    }

    public function test_stop_reason_without_text_is_not_no_output(): void
    {
        // A run that stopped (e.g. refusal/cancel) with no streamed text is
        // still a real run, not "no_output".
        $packet = $this->mapper->map(
            assistantText: '',
            stopReason: 'cancelled',
            usage: [],
            sessionId: 's',
            mission: [],
            invocation: [],
        );

        $this->assertSame('succeeded', $packet['status']);
        $this->assertSame('cancelled', $packet['output']['stop_reason']);
        $this->assertNull($packet['output']['text_hash']);
    }

    public function test_text_with_text_but_null_stop_reason_is_not_no_output(): void
    {
        $packet = $this->mapper->map(
            assistantText: 'partial chunk',
            stopReason: null,
            usage: [],
            sessionId: 's',
            mission: [],
            invocation: [],
        );

        $this->assertSame('succeeded', $packet['status']);
        $this->assertNull($packet['output']['stop_reason']);
        $this->assertNotNull($packet['output']['text_hash']);
    }

    public function test_session_id_and_invocation_are_hashed_never_raw(): void
    {
        $sessionId = 'sess_super_secret_value';
        $invocation = ['transport' => 'acp', 'token' => 'sk-RAWSECRET', 'cwd' => '/abs/path'];

        $packet = $this->mapper->map(
            assistantText: 'ok',
            stopReason: 'end_turn',
            usage: ['inputTokens' => 1, 'outputTokens' => 1, 'totalTokens' => 2],
            sessionId: $sessionId,
            mission: ['mission_id' => 'm', 'mission_hash' => 'h'],
            invocation: $invocation,
        );

        $json = json_encode($packet, JSON_THROW_ON_ERROR);

        // Raw session id never leaks.
        $this->assertStringNotContainsString($sessionId, $json);
        $this->assertSame(hash('sha256', json_encode([$sessionId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $packet['session_id_hash']);

        // Raw invocation secret never leaks; only its hash is carried.
        $this->assertStringNotContainsString('sk-RAWSECRET', $json);
        $this->assertSame(hash('sha256', json_encode($invocation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $packet['cli_invocation_hash']);
        $this->assertArrayNotHasKey('token', $packet);
    }

    public function test_receipt_hash_is_deterministic_for_identical_input(): void
    {
        $args = [
            'assistantText' => 'deterministic body',
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 5, 'outputTokens' => 6, 'totalTokens' => 11],
            'sessionId' => 'sess_x',
            'mission' => ['mission_id' => 'm', 'mission_hash' => 'h'],
            'invocation' => ['transport' => 'acp'],
        ];

        $a = $this->mapper->map(...$args);
        $b = $this->mapper->map(...$args);

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
        $this->assertSame($a, $b);
    }

    public function test_receipt_hash_changes_when_text_changes(): void
    {
        $base = [
            'stopReason' => 'end_turn',
            'usage' => [],
            'sessionId' => 's',
            'mission' => [],
            'invocation' => [],
        ];

        $a = $this->mapper->map(...['assistantText' => 'one'] + $base);
        $b = $this->mapper->map(...['assistantText' => 'two'] + $base);

        $this->assertNotSame($a['receipt_hash'], $b['receipt_hash']);
    }

    public function test_mission_id_falls_back_to_null_and_coerces_scalar(): void
    {
        $packetNull = $this->mapper->map('x', 'end_turn', [], 's', [], []);
        $this->assertNull($packetNull['mission_id']);
        $this->assertNull($packetNull['mission_hash']);

        // Defensive scalar coercion (e.g. an int mission id should stringify).
        $packetInt = $this->mapper->map('x', 'end_turn', [], 's', ['mission_id' => 42], []);
        $this->assertSame('42', $packetInt['mission_id']);
    }

    public function test_does_not_throw_on_weird_usage_values(): void
    {
        $packet = $this->mapper->map(
            assistantText: 'x',
            stopReason: 'end_turn',
            usage: [
                'inputTokens' => ['nested' => 'array'],
                'outputTokens' => null,
                'totalTokens' => true,
            ],
            sessionId: 's',
            mission: [],
            invocation: [],
        );

        $this->assertSame(0, $packet['usage']['input_tokens']);
        $this->assertSame(0, $packet['usage']['output_tokens']);
        $this->assertSame(0, $packet['usage']['total_tokens']);
    }

    /**
     * FAIL-CLOSED never-throw contract. The assistant text is concatenated from
     * `session/update` chunks streamed raw off the `hermes acp` subprocess, so
     * it can contain malformed UTF-8. The canonical JSON sealing (JSON_THROW_ON_ERROR)
     * would throw on such bytes; the mapper MUST instead still return a sealed
     * packet. Mirrors the UTF-8 hardening already in HermesResultPacketFactory.
     */
    public function test_never_throws_on_malformed_utf8_in_any_field(): void
    {
        $bad = "valid-prefix\xB1\x31\xC0broken"; // invalid UTF-8 continuation bytes

        $packet = $this->mapper->map(
            assistantText: $bad,
            stopReason: 'end_turn',
            usage: ['inputTokens' => 1, 'outputTokens' => 1, 'totalTokens' => 2],
            sessionId: $bad,
            mission: ['mission_id' => $bad, 'mission_hash' => $bad],
            invocation: ['transport' => 'acp', 'note' => $bad],
        );

        // Still a valid, fully sealed packet.
        $this->assertSame('atlas.hermes.result_packet.v1', $packet['schema_version']);
        $this->assertSame('succeeded', $packet['status']);
        $this->assertSame('atlas', $packet['authority']);
        $this->assertFalse($packet['hermes_can_decide']);
        $this->assertIsString($packet['output']['text_hash']);
        $this->assertSame(64, strlen($packet['output']['text_hash'])); // sha256 hex
        $this->assertIsString($packet['session_id_hash']);
        $this->assertIsString($packet['cli_invocation_hash']);

        // receipt_hash present, sealed last, and is itself a sha256.
        $this->assertArrayHasKey('receipt_hash', $packet);
        $keys = array_keys($packet);
        $this->assertSame('receipt_hash', end($keys));
        $this->assertSame(64, strlen($packet['receipt_hash']));
    }

    public function test_never_throws_on_unsupported_value_types_and_deep_nesting(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $deep = ['n' => $deep];
        }

        $packetDeep = $this->mapper->map('x', 'end_turn', [], 's', [], ['deep' => $deep]);
        $this->assertSame(64, strlen($packetDeep['cli_invocation_hash']));
        $this->assertArrayHasKey('receipt_hash', $packetDeep);

        $resource = fopen('php://memory', 'r');
        $packetRes = $this->mapper->map('x', 'end_turn', [], 's', [], ['res' => $resource]);
        $this->assertSame(64, strlen($packetRes['cli_invocation_hash']));
        $this->assertArrayHasKey('receipt_hash', $packetRes);
        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    public function test_malformed_input_seal_is_deterministic(): void
    {
        $bad = "\xB1\x31\xC0broken";
        $args = [
            'assistantText' => $bad,
            'stopReason' => 'end_turn',
            'usage' => [],
            'sessionId' => 's',
            'mission' => [],
            'invocation' => ['note' => $bad],
        ];

        $a = $this->mapper->map(...$args);
        $b = $this->mapper->map(...$args);

        // Fallback digest is stable across calls (not random), so receipts replay.
        $this->assertSame($a, $b);
        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
    }

    /**
     * Assert the sealed packet's last key is receipt_hash and that it matches
     * a recompute over the rest of the packet (proving the seal closes over
     * exactly the payload).
     *
     * @param  array<string,mixed>  $packet
     */
    private function assertArrayEndsWithReceiptHash(array $packet): void
    {
        $keys = array_keys($packet);
        $this->assertSame('receipt_hash', end($keys));

        $hash = $packet['receipt_hash'];
        $body = $packet;
        unset($body['receipt_hash']);

        // The trait appends receipt_hash = hashValue(body-with-the-key-absent)
        // computed BEFORE assignment, i.e. over the body without receipt_hash.
        $expected = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertSame($expected, $hash);
    }
}
