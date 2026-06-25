<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Tiering;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Throwable;

/**
 * Operator front-door for the Maestro tiering surface (4 verbs):
 *   classify        — emit {@see AtlasMaestroTaskTierClassifier} output for a given packet
 *   register-worker — persist a worker's declaredMaxTier in {@see AtlasMaestroWorkerTierRegistry}
 *   policy          — emit advisory {@see AtlasMaestroTieredRoutingPolicy} verdict for (client, packet)
 *   history         — dump {@see AtlasMaestroTierMismatchLedger} tail (optionally per-client)
 *
 * INVARIANTS:
 *   - Gated by ATLAS_LOOP_MASTER_ENABLED: every verb prints {status:'disabled'} and is byte-identical
 *     no-op when the master switch is off (no registry write, no ledger append).
 *   - NEVER mutates AtlasTaskServingService — purely advisory surface.
 */
final class AtlasMaestroTieringCli
{
    public function __construct(
        private readonly AtlasMaestroTaskTierClassifier $classifier,
        private readonly AtlasMaestroWorkerTierRegistry $registry,
        private readonly AtlasMaestroTieredRoutingPolicy $policy,
        private readonly AtlasMaestroTierMismatchLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $options  CLI options/args bundle from AtlasTaskCommand:
     *   verb, client_id, packet, tier, limit, packet_lookup (callable: string -> ?array)
     * @return array<string,mixed>  envelope for JSON emission
     */
    public function dispatch(array $options): array
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            return ['schema' => 'atlas.maestro.tiering.v1', 'status' => 'disabled', 'verb' => (string) ($options['verb'] ?? '')];
        }

        $verb = (string) ($options['verb'] ?? '');

        return match ($verb) {
            'classify' => $this->classifyVerb($options),
            'register-worker' => $this->registerWorkerVerb($options),
            'policy' => $this->policyVerb($options),
            'history' => $this->historyVerb($options),
            default => ['schema' => 'atlas.maestro.tiering.v1', 'status' => 'unknown_verb', 'verb' => $verb],
        };
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function classifyVerb(array $options): array
    {
        $packet = $this->resolvePacket($options);
        if ($packet === null) {
            return ['schema' => 'atlas.maestro.tiering.v1', 'status' => 'packet_not_found', 'verb' => 'classify'];
        }

        return ['schema' => 'atlas.maestro.tiering.v1', 'status' => 'ok', 'verb' => 'classify', 'result' => $this->classifier->classify($packet)];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function registerWorkerVerb(array $options): array
    {
        $client = trim((string) ($options['client_id'] ?? ''));
        $tier = trim((string) ($options['tier'] ?? ''));
        if ($client === '' || $tier === '') {
            return ['schema' => 'atlas.maestro.tiering.v1', 'status' => 'usage_error', 'verb' => 'register-worker', 'reason' => '--client and --tier are required'];
        }
        try {
            $rec = $this->registry->register($client, $tier);
        } catch (Throwable $e) {
            return ['schema' => 'atlas.maestro.tiering.v1', 'status' => 'invalid_tier', 'verb' => 'register-worker', 'message' => $e->getMessage()];
        }

        return [
            'schema' => 'atlas.maestro.tiering.v1',
            'status' => 'ok',
            'verb' => 'register-worker',
            'client_id' => $rec->clientId,
            'declared_max_tier' => $rec->declaredMaxTier,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function policyVerb(array $options): array
    {
        $client = trim((string) ($options['client_id'] ?? ''));
        $packet = $this->resolvePacket($options);
        if ($client === '' || $packet === null) {
            return ['schema' => 'atlas.maestro.tiering.v1', 'status' => 'usage_error', 'verb' => 'policy', 'reason' => '--client and --packet are required'];
        }
        $verdict = $this->policy->evaluate($client, $packet);

        // Record refusals to the audit ledger (allow verdicts are intentionally NOT recorded).
        $this->ledger->record($verdict, ['packet_id' => (string) ($packet['packet_id'] ?? '')]);

        // 'ok' for advisory allow verdicts so AtlasTaskCommand exits 0; 'refuse_tier_mismatch' surfaces the
        // routing refusal as a non-zero exit (per the existing success-status allowlist contract).
        $status = match ($verdict['verdict'] ?? '') {
            AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE => 'refuse_tier_mismatch',
            AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW, AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW_UNKNOWN => 'ok',
            default => 'unknown',
        };

        return ['schema' => 'atlas.maestro.tiering.v1', 'status' => $status, 'verb' => 'policy', 'verdict' => $verdict];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function historyVerb(array $options): array
    {
        $client = trim((string) ($options['client_id'] ?? ''));
        $limit = max(1, (int) ($options['limit'] ?? 10));
        $rows = $this->ledger->history($limit, $client === '' ? null : $client);

        return ['schema' => 'atlas.maestro.tiering.v1', 'status' => 'ok', 'verb' => 'history', 'rows' => $rows];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolvePacket(array $options): ?array
    {
        // Inline-supplied packet wins (used by tests).
        if (is_array($options['packet_inline'] ?? null)) {
            return $options['packet_inline'];
        }
        $packetId = trim((string) ($options['packet'] ?? ''));
        if ($packetId === '') {
            return null;
        }
        $lookup = $options['packet_lookup'] ?? null;
        if (is_callable($lookup)) {
            $hit = $lookup($packetId);

            return is_array($hit) ? $hit : null;
        }

        return null;
    }
}
