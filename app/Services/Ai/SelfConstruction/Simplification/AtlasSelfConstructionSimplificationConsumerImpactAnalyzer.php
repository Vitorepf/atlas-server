<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Maps consumers of a simplification candidate (merge/move/delete) into
 * runtime/test/docs/config/command classes and fails closed whenever a
 * consumer is unclassified or an impacted class has zero proof coverage.
 * Pure, deterministic, no I/O.
 */
final class AtlasSelfConstructionSimplificationConsumerImpactAnalyzer
{
    private const KNOWN_CATEGORIES = ['runtime', 'test', 'docs', 'config', 'command'];

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    /** Risk levels at or above this are refused for a plain refactor. */
    private const SAFE_REFACTOR_RISK_FLOOR = self::RISK_HIGH;

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function analyze(array $candidate): array
    {
        $consumers = array_values((array) ($candidate['consumers'] ?? []));

        $byCategory = [];
        $blockers = [];
        $directCount = 0;
        $transitiveCount = 0;
        $touchesPublicCommand = false;
        $touchesPublicContract = false;

        foreach ($consumers as $consumer) {
            $name = (string) ($consumer['name'] ?? '');
            $category = (string) ($consumer['category'] ?? '');

            if ((bool) ($consumer['transitive'] ?? false)) {
                $transitiveCount++;
            } else {
                $directCount++;
            }

            if ((bool) ($consumer['is_public_command'] ?? false)) {
                $touchesPublicCommand = true;
            }
            if ((bool) ($consumer['is_public_contract'] ?? false)) {
                $touchesPublicContract = true;
            }

            if (! in_array($category, self::KNOWN_CATEGORIES, true)) {
                $blockers[] = "consumer_unclassified:{$name}";

                continue;
            }

            $byCategory[$category][] = $consumer;
        }

        foreach ($byCategory as $category => $categoryConsumers) {
            $hasProof = false;
            foreach ($categoryConsumers as $consumer) {
                if (array_values((array) ($consumer['proof_refs'] ?? [])) !== []) {
                    $hasProof = true;

                    break;
                }
            }
            if (! $hasProof) {
                $blockers[] = "missing_proof_coverage:{$category}";
            }
        }

        $touchedCategories = array_keys($byCategory);
        $onlyTestOrDocs = $touchedCategories !== [] && array_diff($touchedCategories, ['test', 'docs']) === [];

        $riskLevel = match (true) {
            $touchesPublicCommand || $touchesPublicContract => self::RISK_HIGH,
            $onlyTestOrDocs => self::RISK_LOW,
            array_intersect($touchedCategories, ['runtime', 'command']) !== [] => self::RISK_MEDIUM,
            default => self::RISK_LOW,
        };

        if ($riskLevel === self::SAFE_REFACTOR_RISK_FLOOR) {
            $blockers[] = "consumer_risk_exceeds_safe_refactor_floor:{$riskLevel}";
        }

        $failClosed = $blockers !== [];

        return [
            'impacted_classes' => $touchedCategories,
            'consumers_by_category' => $byCategory,
            'direct_consumer_count' => $directCount,
            'transitive_consumer_count' => $transitiveCount,
            'touches_public_command' => $touchesPublicCommand,
            'touches_public_contract' => $touchesPublicContract,
            'risk_level' => $riskLevel,
            'blockers' => $blockers,
            'blocking_reasons' => $blockers,
            'fail_closed' => $failClosed,
            'safe_to_continue' => ! $failClosed,
        ];
    }
}
