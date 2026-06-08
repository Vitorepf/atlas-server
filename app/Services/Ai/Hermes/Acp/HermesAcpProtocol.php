<?php

declare(strict_types=1);

namespace App\Services\Ai\Hermes\Acp;

use JsonException;

/**
 * Pure JSON-RPC 2.0 / ACP (Agent Client Protocol) codec for the persistent
 * `hermes acp` transport.
 *
 * Atlas is upgrading the Hermes EXECUTION transport away from the per-call
 * `hermes chat` subprocess (cold-boot, fragile stdout parsing) to a persistent
 * `hermes acp` process driven over newline-delimited JSON-RPC 2.0 on stdio.
 * This class is the PURE, fully-unit-testable core of that transport: it builds
 * the client request frames, encodes them to single-line JSON, and classifies /
 * decodes the agent's stdout lines (results, errors, notifications and
 * agent-initiated requests). It performs NO I/O, holds NO state, makes NO model
 * calls, and reads NO config — the proc_open transport that pumps these bytes is
 * built and proven separately.
 *
 * Sovereignty: Atlas decides, Hermes executes. The codec is fail-soft on the
 * read side — {@see classify()} never throws on a malformed/banner/partial line;
 * it returns a `noise` frame so the transport stays alive and default-deny.
 *
 * Frame shapes are exact to the proven live ACP spike:
 *  - Requests:      {jsonrpc:"2.0", id:<int>, method, params}
 *  - Responses:     {jsonrpc:"2.0", id:<int>, result|error}
 *  - Notifications: {jsonrpc:"2.0", method, params}            (no id)
 *  - Agent requests:{jsonrpc:"2.0", id:<int>, method, params}  (client MUST answer)
 */
final class HermesAcpProtocol
{
    /**
     * The single ACP protocol version proven by the live spike.
     */
    public const PROTOCOL_VERSION = 1;

    /**
     * Build the `initialize` request frame.
     *
     * Client filesystem capabilities are advertised fail-closed (read/write
     * both false): Atlas never lets the executor touch the host FS through ACP.
     *
     * @return array<string,mixed>
     */
    public function initializeRequest(int $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'clientCapabilities' => [
                    'fs' => [
                        'readTextFile' => false,
                        'writeTextFile' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Build the `session/new` request frame.
     *
     * @param  array<int,mixed>  $mcpServers
     * @return array<string,mixed>
     */
    public function sessionNewRequest(int $id, string $cwd, array $mcpServers = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'session/new',
            'params' => [
                'cwd' => $cwd,
                'mcpServers' => array_values($mcpServers),
            ],
        ];
    }

    /**
     * Build the `session/prompt` request frame, wrapping the text as a single
     * `{type:"text", text}` prompt block.
     *
     * @return array<string,mixed>
     */
    public function sessionPromptRequest(int $id, string $sessionId, string $text): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'session/prompt',
            'params' => [
                'sessionId' => $sessionId,
                'prompt' => [
                    [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }

    /**
     * Encode a single message to one newline-delimited JSON-RPC line.
     *
     * @param  array<string,mixed>  $message
     *
     * @throws JsonException on a non-encodable message (caller's bug, not agent input).
     */
    public function encode(array $message): string
    {
        return json_encode(
            $message,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * Classify one raw stdout line from the agent.
     *
     * Robust to noise: blank lines, ACP banners, partial/truncated frames, or
     * any non-JSON-object payload classify as `noise` and never throw, so the
     * transport stays alive.
     *
     * @return array{type:string,id:?int,method:?string,message:?array<string,mixed>}
     *               type ∈ {result, error, notification, agent_request, noise}
     */
    public function classify(string $line): array
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return $this->frame('noise');
        }

        // Cheap pre-filter: a JSON-RPC frame is always a JSON object.
        if ($trimmed[0] !== '{') {
            return $this->frame('noise');
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->frame('noise');
        }

        if (! is_array($decoded) || ! $this->isAssoc($decoded)) {
            return $this->frame('noise');
        }

        $hasId = array_key_exists('id', $decoded);
        $id = $hasId ? $this->normalizeId($decoded['id']) : null;
        $method = isset($decoded['method']) && is_string($decoded['method'])
            ? $decoded['method']
            : null;

        // Agent-initiated REQUEST: has BOTH an id and a method. The client MUST
        // answer these (e.g. session/request_permission).
        if ($hasId && $method !== null) {
            return $this->frame('agent_request', $id, $method, $decoded);
        }

        // NOTIFICATION: has a method but NO id.
        if (! $hasId && $method !== null) {
            return $this->frame('notification', null, $method, $decoded);
        }

        // RESPONSE to one of our requests: has an id and result|error, no method.
        if ($hasId && array_key_exists('error', $decoded)) {
            return $this->frame('error', $id, null, $decoded);
        }

        if ($hasId && array_key_exists('result', $decoded)) {
            return $this->frame('result', $id, null, $decoded);
        }

        // A JSON object that is not a recognizable JSON-RPC frame.
        return $this->frame('noise');
    }

    /**
     * True when the notification carries an assistant text chunk
     * (`params.update.sessionUpdate === "agent_message_chunk"`).
     *
     * @param  array<string,mixed>  $notification
     */
    public function isAgentMessageChunk(array $notification): bool
    {
        return ($notification['method'] ?? null) === 'session/update'
            && ($this->dig($notification, 'params', 'update', 'sessionUpdate')) === 'agent_message_chunk';
    }

    /**
     * Extract the assistant text chunk
     * (`params.update.content.text`) from an `agent_message_chunk` notification.
     *
     * @param  array<string,mixed>  $notification
     */
    public function agentMessageChunkText(array $notification): ?string
    {
        if (! $this->isAgentMessageChunk($notification)) {
            return null;
        }

        $content = $this->dig($notification, 'params', 'update', 'content');
        if (! is_array($content)) {
            return null;
        }

        if (($content['type'] ?? null) !== 'text') {
            return null;
        }

        $text = $content['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * True when the message is the agent's mid-prompt permission request
     * (`method === "session/request_permission"`).
     *
     * @param  array<string,mixed>  $message
     */
    public function isPermissionRequest(array $message): bool
    {
        return ($message['method'] ?? null) === 'session/request_permission';
    }

    /**
     * Build the client's response to a `session/request_permission` request.
     *
     * A non-null option id grants the selected option; a null option id cancels
     * (the fail-closed default the transport uses when Atlas does not approve).
     *
     * @return array<string,mixed>
     */
    public function permissionResponse(int $id, ?string $optionId): array
    {
        $outcome = $optionId !== null
            ? ['outcome' => 'selected', 'optionId' => $optionId]
            : ['outcome' => 'cancelled'];

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'outcome' => $outcome,
            ],
        ];
    }

    /**
     * Extract `result.stopReason` from a `session/prompt` response frame.
     *
     * @param  array<string,mixed>  $resultMessage
     */
    public function promptResultStopReason(array $resultMessage): ?string
    {
        $stopReason = $this->dig($resultMessage, 'result', 'stopReason');

        return is_string($stopReason) ? $stopReason : null;
    }

    /**
     * Extract `result.usage` (token accounting) from a `session/prompt`
     * response frame. Returns an empty array when absent or malformed.
     *
     * @param  array<string,mixed>  $resultMessage
     * @return array<string,mixed>
     */
    public function promptResultUsage(array $resultMessage): array
    {
        $usage = $this->dig($resultMessage, 'result', 'usage');

        return is_array($usage) && $this->isAssoc($usage) ? $usage : [];
    }

    /**
     * @param  array<string,mixed>|null  $message
     * @return array{type:string,id:?int,method:?string,message:?array<string,mixed>}
     */
    private function frame(string $type, ?int $id = null, ?string $method = null, ?array $message = null): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'method' => $method,
            'message' => $message,
        ];
    }

    /**
     * Normalize a JSON-RPC id to an int when it is an integer (or an
     * integer-valued numeric); otherwise null. ACP ids in the proven spike are
     * always ints.
     */
    private function normalizeId(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id;
        }

        if (is_float($id) && floor($id) === $id) {
            return (int) $id;
        }

        if (is_string($id) && $id !== '' && ctype_digit($id)) {
            return (int) $id;
        }

        return null;
    }

    /**
     * Safely dig nested array keys, returning null on any non-array hop or
     * missing key. Never throws.
     */
    private function dig(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * True when the array is an associative (string-keyed / JSON-object) array
     * rather than a JSON list. An empty array counts as associative (an empty
     * JSON object decodes to []).
     *
     * @param  array<mixed>  $value
     */
    private function isAssoc(array $value): bool
    {
        return $value === [] || array_keys($value) !== range(0, count($value) - 1);
    }
}
