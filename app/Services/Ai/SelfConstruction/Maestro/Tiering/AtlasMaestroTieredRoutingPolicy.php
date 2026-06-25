<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Tiering;

/**
 * Advisory routing gate. Combines {@see AtlasMaestroTaskTierClassifier} (packet tier) with
 * {@see AtlasMaestroWorkerTierRegistry} (worker declaredMaxTier) and emits a structured RoutingVerdict.
 *
 * INVARIANTS:
 *   - ADVISORY only — does not mutate AtlasTaskServingService::next().
 *   - Unknown worker (not in the registry) ⇒ verdict=allow_unknown_worker (legacy un-tiered flows stay green).
 *   - A worker can serve its declared tier AND BELOW (easy < hard < hardest).
 *   - Pure: same (clientId, packet) ⇒ byte-identical verdict; sole I/O is the registry lookup + classifier call.
 */
final class AtlasMaestroTieredRoutingPolicy
{
    public const SCHEMA = 'atlas.maestro.tier_routing.v1';

    public const VERDICT_ALLOW = 'allow';

    public const VERDICT_REFUSE = 'refuse_tier_mismatch';

    public const VERDICT_ALLOW_UNKNOWN = 'allow_unknown_worker';

    /**
     * easy < hard < hardest — numeric for comparison only.
     */
    private const TIER_ORDER = [
        AtlasMaestroTaskTierClassifier::TIER_EASY => 1,
        AtlasMaestroTaskTierClassifier::TIER_HARD => 2,
        AtlasMaestroTaskTierClassifier::TIER_HARDEST => 3,
    ];

    public function __construct(
        private readonly AtlasMaestroTaskTierClassifier $classifier,
        private readonly AtlasMaestroWorkerTierRegistry $registry,
    ) {}

    /**
     * @param  array<string,mixed>  $packet
     * @return array{schema:string, verdict:string, packet_tier:string, packet_fact_basis:list<string>, worker_declared_max_tier:?string, client_id:string, reason:string}
     */
    public function evaluate(string $clientId, array $packet): array
    {
        $classification = $this->classifier->classify($packet);
        $packetTier = (string) ($classification['tier'] ?? '');
        $factBasis = (array) ($classification['fact_basis'] ?? []);

        $workerRecord = $this->registry->lookup($clientId);

        if ($workerRecord === null) {
            return [
                'schema' => self::SCHEMA,
                'verdict' => self::VERDICT_ALLOW_UNKNOWN,
                'packet_tier' => $packetTier,
                'packet_fact_basis' => $factBasis,
                'worker_declared_max_tier' => null,
                'client_id' => $clientId,
                'reason' => 'worker not registered; legacy un-tiered flow preserved',
            ];
        }

        $workerTier = $workerRecord->declaredMaxTier;
        $packetRank = self::TIER_ORDER[$packetTier] ?? PHP_INT_MAX;
        $workerRank = self::TIER_ORDER[$workerTier] ?? 0;

        if ($workerRank >= $packetRank) {
            return [
                'schema' => self::SCHEMA,
                'verdict' => self::VERDICT_ALLOW,
                'packet_tier' => $packetTier,
                'packet_fact_basis' => $factBasis,
                'worker_declared_max_tier' => $workerTier,
                'client_id' => $clientId,
                'reason' => sprintf('worker tier "%s" can serve packet tier "%s"', $workerTier, $packetTier),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'verdict' => self::VERDICT_REFUSE,
            'packet_tier' => $packetTier,
            'packet_fact_basis' => $factBasis,
            'worker_declared_max_tier' => $workerTier,
            'client_id' => $clientId,
            'reason' => sprintf('packet tier "%s" exceeds worker declared max tier "%s"', $packetTier, $workerTier),
        ];
    }
}
