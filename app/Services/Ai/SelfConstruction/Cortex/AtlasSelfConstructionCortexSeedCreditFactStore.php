<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Cortex;

/**
 * Pure store that records seed-credit facts from enqueue and post-round gates
 * so future rounds can learn from credited versus uncredited seeds.
 *
 * Fact types:
 *   - credited: round was credited (clean enqueue + healthy gates)
 *   - rejected: round was rejected (spec or enqueue failure)
 *   - operationally_blocked: round was blocked by operational issues
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionCortexSeedCreditFactStore
{
    public const SCHEMA = 'atlas.self_construction.cortex_seed_credit_fact_store.v1';

    public const FACT_CREDITED = 'credited';
    public const FACT_REJECTED = 'rejected';
    public const FACT_OPERATIONALLY_BLOCKED = 'operationally_blocked';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function store(array $input): array
    {
        $roundId = (string) ($input['round_id'] ?? '');
        $originatorId = (string) ($input['originator_id'] ?? '');
        $verdict = (string) ($input['verdict'] ?? '');
        $denialReasons = (array) ($input['denial_reasons'] ?? []);
        $creditsGranted = (int) ($input['credits_granted'] ?? 0);
        $emittedTargets = (array) ($input['emitted_targets'] ?? []);

        // Determine fact type from verdict and denial reasons.
        $factType = match ($verdict) {
            'credited' => self::FACT_CREDITED,
            'denied' => $this->classifyDenial($denialReasons),
            default => self::FACT_OPERATIONALLY_BLOCKED,
        };

        $reasonCode = $factType === self::FACT_CREDITED
            ? 'clean_enqueue_healthy_gates'
            : ($denialReasons !== [] ? implode(';', $denialReasons) : 'unknown');

        return [
            'schema_version' => self::SCHEMA,
            'round_id' => $roundId,
            'originator_id' => $originatorId,
            'fact_type' => $factType,
            'verdict' => $verdict,
            'reason_code' => $reasonCode,
            'credits_granted' => $creditsGranted,
            'emitted_targets' => array_values(array_unique($emittedTargets)),
            'denial_reasons' => $denialReasons,
            'stored_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'provider_safe' => true,
        ];
    }

    /**
     * @param  array<int, string>  $denialReasons
     */
    private function classifyDenial(array $denialReasons): string
    {
        foreach ($denialReasons as $reason) {
            $lower = strtolower($reason);
            if (str_contains($lower, 'enqueue') || str_contains($lower, 'rejected') || str_contains($lower, 'malformed')) {
                return self::FACT_REJECTED;
            }
        }

        return self::FACT_OPERATIONALLY_BLOCKED;
    }
}
