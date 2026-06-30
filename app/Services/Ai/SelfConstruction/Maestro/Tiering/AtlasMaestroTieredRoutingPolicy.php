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
 *   - A tier mismatch is refused UNLESS the worker record carries an explicit governed override
 *     (`meta.override_tier_mismatch === true`) — then it routes as allow_with_review, never silent allow.
 *   - Low worker outcome confidence (`meta.outcome_confidence` below the floor) on a hard/hardest packet
 *     downgrades an in-tier allow to allow_with_review — confidence never silently waves a hard packet through.
 *   - Pure: same (clientId, packet) ⇒ byte-identical verdict; sole I/O is the registry lookup + classifier call.
 */
final class AtlasMaestroTieredRoutingPolicy
{
    public const SCHEMA = 'atlas.maestro.tier_routing.v1';

    public const VERDICT_ALLOW = 'allow';

    public const VERDICT_REFUSE = 'refuse_tier_mismatch';

    public const VERDICT_ALLOW_WITH_REVIEW = 'allow_with_review';

    public const VERDICT_ALLOW_UNKNOWN = 'allow_unknown_worker';

    private const LOW_CONFIDENCE_THRESHOLD = 0.5;

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
     * @return array{schema:string, verdict:string, packet_tier:string, packet_fact_basis:list<string>, worker_declared_max_tier:?string, client_id:string, reason:string, worker_outcome_confidence:?float, override_applied:bool}
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
                'worker_outcome_confidence' => null,
                'override_applied' => false,
            ];
        }

        $workerTier = $workerRecord->declaredMaxTier;
        $packetRank = self::TIER_ORDER[$packetTier] ?? PHP_INT_MAX;
        $workerRank = self::TIER_ORDER[$workerTier] ?? 0;

        $meta = $workerRecord->meta;
        $overrideTierMismatch = (bool) ($meta['override_tier_mismatch'] ?? false);
        $confidence = isset($meta['outcome_confidence']) ? (float) $meta['outcome_confidence'] : null;

        if ($workerRank < $packetRank) {
            if ($overrideTierMismatch) {
                return [
                    'schema' => self::SCHEMA,
                    'verdict' => self::VERDICT_ALLOW_WITH_REVIEW,
                    'packet_tier' => $packetTier,
                    'packet_fact_basis' => $factBasis,
                    'worker_declared_max_tier' => $workerTier,
                    'client_id' => $clientId,
                    'reason' => sprintf(
                        'packet tier "%s" exceeds worker declared max tier "%s" but an explicit governed override is present; routed with review',
                        $packetTier,
                        $workerTier,
                    ),
                    'worker_outcome_confidence' => $confidence,
                    'override_applied' => true,
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
                'worker_outcome_confidence' => $confidence,
                'override_applied' => false,
            ];
        }

        // In-tier, but a hard/hardest packet routed to a worker with thin outcome evidence must not
        // silently pass — downgrade to allow_with_review so a human/governance layer can inspect it.
        $lowConfidenceOnHardPacket = $packetTier !== AtlasMaestroTaskTierClassifier::TIER_EASY
            && $confidence !== null
            && $confidence < self::LOW_CONFIDENCE_THRESHOLD;

        if ($lowConfidenceOnHardPacket) {
            return [
                'schema' => self::SCHEMA,
                'verdict' => self::VERDICT_ALLOW_WITH_REVIEW,
                'packet_tier' => $packetTier,
                'packet_fact_basis' => $factBasis,
                'worker_declared_max_tier' => $workerTier,
                'client_id' => $clientId,
                'reason' => sprintf(
                    'worker tier "%s" can serve packet tier "%s" but outcome confidence %.2f is below the review floor',
                    $workerTier,
                    $packetTier,
                    $confidence,
                ),
                'worker_outcome_confidence' => $confidence,
                'override_applied' => false,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'verdict' => self::VERDICT_ALLOW,
            'packet_tier' => $packetTier,
            'packet_fact_basis' => $factBasis,
            'worker_declared_max_tier' => $workerTier,
            'client_id' => $clientId,
            'reason' => sprintf('worker tier "%s" can serve packet tier "%s"', $workerTier, $packetTier),
            'worker_outcome_confidence' => $confidence,
            'override_applied' => false,
        ];
    }
}
