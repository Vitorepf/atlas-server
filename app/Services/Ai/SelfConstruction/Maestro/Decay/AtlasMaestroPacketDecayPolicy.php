<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Decay;

use Closure;

/**
 * Advisory policy — proposes packets for parking when their age exceeds threshold
 * AND the queue_status indicates unclaimed. Reads FACTS from AtlasMaestroPacketAgeFactReporter.
 *
 * Never mutates queue state. Never auto-parks. Operator/CLI decides downstream.
 *
 * Optional per-packet meta (from $packetMetaProvider) additively unlocks finer-grained actions —
 * absent meta always falls back to the original park/keep behavior, byte-identical:
 *   - value (float 0..1): stale + below LOW_VALUE_CEILING ⇒ 'retire' instead of 'park'.
 *   - proof_strength (float 0..1): stale + high value + below STRONG_PROOF_FLOOR ⇒ 'refresh'.
 *     Fresh (age <= threshold) + at/above STRONG_PROOF_FLOOR ⇒ explicit 'keep' proposal.
 *   - dependency_fresh (bool): for dependency_critical + stale packets, weak proof or a stale
 *     dependency (dependency_fresh === false) ⇒ 'rescue' instead of the default 'keep'.
 */
final class AtlasMaestroPacketDecayPolicy
{
    public const SCHEMA = 'atlas.maestro.packet_decay_policy.v1';
    public const PROPOSED_ACTION = 'park';
    public const UNCLAIMED_STATUSES = ['waiting', 'queued', 'enqueued', 'pending'];
    /** Poison/give_back family decays at this fraction of the normal threshold. */
    public const POISON_THRESHOLD_RATIO = 0.5;
    /** value below this ⇒ 'low value' (retire candidate). */
    public const LOW_VALUE_CEILING = 0.34;
    /** proof_strength at/above this ⇒ 'strong proof'. */
    public const STRONG_PROOF_FLOOR = 0.67;

    /** @var Closure():int */
    private Closure $thresholdProvider;

    /** @var Closure():bool */
    private Closure $masterEnabled;

    /** @var callable|null Extra-context provider: (task_packet_id) => ['dependency_critical'=>bool, 'give_back_count'=>int, ...] */
    private $packetMetaProvider;

    public function __construct(
        private readonly AtlasMaestroPacketAgeFactReporter $reporter,
        ?callable $thresholdSeconds = null,
        ?callable $masterEnabled = null,
        ?callable $packetMetaProvider = null,
    ) {
        $this->thresholdProvider = Closure::fromCallable(
            $thresholdSeconds ?? static fn (): int => (int) config('atlas.loop.maestro.packet_decay.threshold_seconds', 21600),
        );
        $this->masterEnabled = Closure::fromCallable(
            $masterEnabled ?? static fn (): bool => (bool) env('ATLAS_LOOP_MASTER_ENABLED', false),
        );
        $this->packetMetaProvider = $packetMetaProvider;
    }

    /**
     * @return list<array{task_packet_id:string, age_seconds:int, threshold_seconds:int, proposed_action:string, reason:string}>
     */
    public function propose(): array
    {
        if (! ($this->masterEnabled)()) {
            return [];
        }
        $threshold = (int) ($this->thresholdProvider)();
        if ($threshold <= 0) {
            return [];
        }

        $poisonThreshold = (int) round($threshold * self::POISON_THRESHOLD_RATIO);

        $proposals = [];
        foreach ($this->reporter->report() as $fact) {
            $age = (int) ($fact['time_in_queue_seconds'] ?? 0);
            $status = (string) ($fact['queue_status'] ?? '');
            $id = (string) ($fact['task_packet_id'] ?? '');

            $meta = $this->packetMetaProvider !== null ? (array) ($this->packetMetaProvider)($id) : [];
            $critical = (bool) ($meta['dependency_critical'] ?? false);
            $poisonFamily = (int) ($meta['give_back_count'] ?? 0) > 0 || (bool) ($meta['poison'] ?? false);
            $hasValueSignal = array_key_exists('value', $meta);
            $value = (float) ($meta['value'] ?? 0.0);
            $hasProofSignal = array_key_exists('proof_strength', $meta);
            $proofStrength = (float) ($meta['proof_strength'] ?? 0.0);
            $hasDependencyFreshSignal = array_key_exists('dependency_fresh', $meta);
            $dependencyFresh = (bool) ($meta['dependency_fresh'] ?? true);

            // Dependency-critical packets that are stale must be kept explicitly, never parked —
            // unless proof is explicitly weak or the dependency is explicitly stale, then 'rescue'.
            if ($critical && $age > $threshold && in_array($status, self::UNCLAIMED_STATUSES, true)) {
                $needsRescue = ($hasProofSignal && $proofStrength < self::STRONG_PROOF_FLOOR)
                    || ($hasDependencyFreshSignal && ! $dependencyFresh);
                $proposals[] = [
                    'age_seconds' => $age,
                    'proposed_action' => $needsRescue ? 'rescue' : 'keep',
                    'reason' => $needsRescue
                        ? 'rescue_due_to_critical_dependency_weak_proof_or_stale_dependency'
                        : 'keep_due_to_critical_dependency',
                    'task_packet_id' => $id,
                    'threshold_seconds' => $threshold,
                ];
                continue;
            }

            if (! in_array($status, self::UNCLAIMED_STATUSES, true)) {
                continue;
            }

            // Poison/give_back family uses a lower threshold so stale waste exits sooner.
            if ($poisonFamily && $age > $poisonThreshold) {
                $proposals[] = [
                    'age_seconds' => $age,
                    'proposed_action' => self::PROPOSED_ACTION,
                    'reason' => sprintf('park_due_to_poison_age age_seconds=%d exceeds poison_threshold_seconds=%d', $age, $poisonThreshold),
                    'task_packet_id' => $id,
                    'threshold_seconds' => $threshold,
                ];
                continue;
            }

            if ($age <= $threshold) {
                // Fresh + explicitly strong-proof packets get an affirmative keep proposal.
                if ($hasProofSignal && $proofStrength >= self::STRONG_PROOF_FLOOR) {
                    $proposals[] = [
                        'age_seconds' => $age,
                        'proposed_action' => 'keep',
                        'reason' => 'keep_fresh_strong_proof',
                        'task_packet_id' => $id,
                        'threshold_seconds' => $threshold,
                    ];
                }
                continue;
            }

            // Stale, low-value ⇒ retire rather than park.
            if ($hasValueSignal && $value < self::LOW_VALUE_CEILING) {
                $proposals[] = [
                    'age_seconds' => $age,
                    'proposed_action' => 'retire',
                    'reason' => sprintf('retire_due_to_stale_low_value value=%.2f age_seconds=%d exceeds threshold_seconds=%d', $value, $age, $threshold),
                    'task_packet_id' => $id,
                    'threshold_seconds' => $threshold,
                ];
                continue;
            }

            // Stale, high-value, weak proof ⇒ refresh rather than park.
            if ($hasValueSignal && $value >= self::LOW_VALUE_CEILING && $hasProofSignal && $proofStrength < self::STRONG_PROOF_FLOOR) {
                $proposals[] = [
                    'age_seconds' => $age,
                    'proposed_action' => 'refresh',
                    'reason' => sprintf('refresh_due_to_stale_high_value_weak_proof value=%.2f proof_strength=%.2f', $value, $proofStrength),
                    'task_packet_id' => $id,
                    'threshold_seconds' => $threshold,
                ];
                continue;
            }

            $proposals[] = [
                'age_seconds' => $age,
                'proposed_action' => self::PROPOSED_ACTION,
                'reason' => sprintf('age_seconds=%d exceeds threshold_seconds=%d', $age, $threshold),
                'task_packet_id' => $id,
                'threshold_seconds' => $threshold,
            ];
        }

        return $proposals;
    }
}
