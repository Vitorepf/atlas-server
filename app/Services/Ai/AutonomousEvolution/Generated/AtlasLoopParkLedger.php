<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Generated;

final class AtlasLoopParkLedger
{
    public const SCHEMA_VERSION = 'atlas.loop.park_ledger.v1';

    public const MODE = 'read_only_generated_docgap_capability';

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $requiredFields = [
            'objective',
            'park_reason',
            'expected_value',
            'escalation_level',
            'next_action',
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'reentry_channel' => 'refiller_candidate_scoring',
            'escalation_policy' => [
                'retry_same_shape',
                'retry_with_stronger_model',
                'retry_with_decomposition',
                'operator_review_only_for_external_two_key',
            ],
            'health_metric' => 'high_ev_parking_faster_than_delivered',
            'required_fields' => $requiredFields,
            'required_field_count' => count($requiredFields),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'workspace_mutation_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function park(string $objective, string $reason, int|float $expectedValue, int $deliveryCount, int $parkedCount): array
    {
        $escalationLevel = $expectedValue >= 80 ? 'stronger_model_or_decomposition' : 'retry_same_shape';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'parked',
            'objective' => trim($objective),
            'park_reason' => trim($reason),
            'expected_value' => $expectedValue,
            'escalation_level' => $escalationLevel,
            'next_action' => 'reenter_refiller_candidate_scoring',
            'health' => $this->evaluateHealth($deliveryCount, $parkedCount, $expectedValue),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'workspace_mutation_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateHealth(int $deliveryCount, int $parkedCount, int|float $highestExpectedValue): array
    {
        $flagged = $highestExpectedValue >= 80 && $parkedCount > $deliveryCount;

        return [
            'metric' => 'high_ev_parking_faster_than_delivered',
            'flagged' => $flagged,
            'delivery_count' => $deliveryCount,
            'parked_count' => $parkedCount,
            'highest_expected_value' => $highestExpectedValue,
        ];
    }
}
