<?php

declare(strict_types=1);

namespace App\Services\Ai\Hermes\Acp;

use App\Services\Ai\Hermes\HermesAdapterReceipt;

/**
 * Maps a completed ACP (Agent Client Protocol) run into the canonical
 * `atlas.hermes.result_packet.v1` contract.
 *
 * Atlas is sovereign; Hermes only executes over the persistent `hermes acp`
 * process. This mapper is PURE: it takes the already-assembled assistant text
 * (concatenated from `session/update` agent_message_chunk notifications), the
 * `session/prompt` stopReason + usage, the session id and mission/invocation
 * metadata, and emits the sealed packet the Evidence Ledger records.
 *
 * Sealing reuses the shared Hermes receipt trait so the `receipt_hash` is
 * deterministic and identical in shape across the CLI and ACP transports.
 * No secrets and no raw prompt are ever placed in the packet — only hashes
 * (the assistant text is carried verbatim because the caller redacts it before
 * persistence, matching the result_packet.v1 caller contract).
 *
 * Fail-closed: never throws. A failed/empty run (no stopReason and no text)
 * still returns a valid sealed packet flagged `status => 'no_output'`.
 */
final class HermesAcpResultMapper
{
    use HermesAdapterReceipt;

    /**
     * @param  array<string,mixed>  $usage
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<string,mixed>
     */
    public function map(
        string $assistantText,
        ?string $stopReason,
        array $usage,
        string $sessionId,
        array $mission,
        array $invocation,
    ): array {
        $hasText = $assistantText !== '';
        $isEmptyRun = $stopReason === null && ! $hasText;

        $packet = [
            'schema_version' => 'atlas.hermes.result_packet.v1',
            'transport' => 'acp',
            'status' => $isEmptyRun ? 'no_output' : 'succeeded',
            'atlas_is_sovereign' => true,
            'provider_is_executor_only' => true,
            'hermes_can_decide' => false,
            'output' => [
                'text' => $assistantText,
                'text_hash' => $hasText ? $this->safeHash([$assistantText]) : null,
                'text_bytes' => strlen($assistantText),
                'stop_reason' => $stopReason,
            ],
            'usage' => $this->normalizeUsage($usage),
            'session_id_hash' => $sessionId !== '' ? $this->safeHash([$sessionId]) : null,
            'mission_id' => $this->stringOrNull($mission['mission_id'] ?? null),
            'mission_hash' => $this->stringOrNull($mission['mission_hash'] ?? null),
            'cli_invocation_hash' => $this->safeHash($invocation),
            'authority' => 'atlas',
        ];

        $packet['receipt_hash'] = $this->safeHash($packet);

        return $packet;
    }

    /**
     * Fail-closed sealing hash.
     *
     * The assistant text is concatenated from `session/update` chunks streamed
     * raw off the `hermes acp` subprocess stdio, so it (and any caller-supplied
     * invocation/mission metadata) can contain malformed UTF-8, unsupported
     * value types, or pathological nesting. The shared trait's `hashValue()`
     * uses `JSON_THROW_ON_ERROR`, which would make `map()` throw on such input
     * and violate the never-throw contract. This mirrors the hardening already
     * present in {@see \App\Services\Ai\Hermes\HermesResultPacketFactory}: try
     * the canonical (contract-locked) JSON encoding first, and on any encoding
     * failure fall back to a deterministic `serialize()`-based digest so a
     * sealed packet is always produced. The fallback is namespaced so it can
     * never collide with a real JSON-encoded digest.
     *
     * @param  array<mixed>  $value
     */
    private function safeHash(array $value): string
    {
        try {
            return $this->hashValue($value);
        } catch (\Throwable) {
            return hash('sha256', 'atlas.hermes.acp.nonjson:'.serialize($value));
        }
    }

    /**
     * Normalize ACP `usage` into the canonical integer token shape.
     *
     * @param  array<string,mixed>  $usage
     * @return array{input_tokens:int,output_tokens:int,total_tokens:int}
     */
    private function normalizeUsage(array $usage): array
    {
        return [
            'input_tokens' => $this->intOrZero($usage['inputTokens'] ?? $usage['input_tokens'] ?? null),
            'output_tokens' => $this->intOrZero($usage['outputTokens'] ?? $usage['output_tokens'] ?? null),
            'total_tokens' => $this->intOrZero($usage['totalTokens'] ?? $usage['total_tokens'] ?? null),
        ];
    }

    private function intOrZero(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
