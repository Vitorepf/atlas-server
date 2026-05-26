<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\DurableExecution;

/**
 * Programming Harness — Durable Execution Handoff Packet (AP-286).
 *
 * The packet that hands work from Atlas Dev's fast lane to either:
 *
 *   - the durable worker (when decision=execute_durable), or
 *   - Forge (when decision=escalate_to_forge).
 *
 * Carries the canonical references the destination needs: preflight,
 * decision, work item id, plan/spec hashes. Provider-safe by default —
 * no raw operator input, no secrets, only hashes and refs.
 *
 * Implements AP-286 under the canon naming policy.
 */
final class DurableExecutionHandoffPacket
{
    public const SCHEMA_VERSION = 'atlas.programming.durable_execution_handoff.v1';

    public const AP_REFERENCE = 'AP-286';

    public const TARGET_DURABLE_RUNNER = 'durable_runner';

    public const TARGET_FORGE = 'forge_handoff';

    public const ALLOWED_TARGETS = [
        self::TARGET_DURABLE_RUNNER,
        self::TARGET_FORGE,
    ];

    /**
     * @param  array<string,mixed>  $preflight  Envelope from DurableExecutionPreflight.
     * @param  array<string,mixed>  $decision   Envelope from DurableExecutionDecisionContract.
     * @return array{
     *   schema_version: string,
     *   ap_reference: string,
     *   target: string,
     *   handed_off_at: string,
     *   work_item_id: ?string,
     *   plan_hash: ?string,
     *   spec_hash: ?string,
     *   preflight_status: string,
     *   decision: string,
     *   actor: string,
     *   evidence_refs: array{
     *     preflight_schema: string,
     *     decision_schema: string
     *   },
     *   handoff_hash: string,
     *   provider_safe: bool
     * }
     */
    public function build(array $preflight, array $decision): array
    {
        $target = match ($this->extractString($decision, 'decision')) {
            'execute_durable' => self::TARGET_DURABLE_RUNNER,
            'escalate_to_forge' => self::TARGET_FORGE,
            default => throw new \LogicException(
                'Handoff packet can only be built for execute_durable or escalate_to_forge decisions; got "'.($decision['decision'] ?? 'null').'".',
            ),
        };

        $packet = [
            'schema_version' => self::SCHEMA_VERSION,
            'ap_reference' => self::AP_REFERENCE,
            'target' => $target,
            'handed_off_at' => now()->toAtomString(),
            'work_item_id' => $this->extractString($decision, 'work_item_id'),
            'plan_hash' => $this->extractString($decision, 'plan_hash'),
            'spec_hash' => $this->extractString($decision, 'spec_hash'),
            'preflight_status' => (string) ($preflight['status'] ?? 'unknown'),
            'decision' => (string) $decision['decision'],
            'actor' => (string) $decision['actor'],
            'evidence_refs' => [
                'preflight_schema' => (string) ($preflight['schema_version'] ?? ''),
                'decision_schema' => (string) ($decision['schema_version'] ?? ''),
            ],
            'handoff_hash' => 'pending',
            'provider_safe' => true,
        ];

        $packet['handoff_hash'] = hash('sha256', json_encode(
            array_diff_key($packet, ['handoff_hash' => true]),
            JSON_UNESCAPED_SLASHES,
        ) ?: '');

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function extractString(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
