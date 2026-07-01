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

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function analyze(array $candidate): array
    {
        $consumers = array_values((array) ($candidate['consumers'] ?? []));

        $byCategory = [];
        $blockers = [];

        foreach ($consumers as $consumer) {
            $name = (string) ($consumer['name'] ?? '');
            $category = (string) ($consumer['category'] ?? '');

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

        $failClosed = $blockers !== [];

        return [
            'impacted_classes' => array_keys($byCategory),
            'consumers_by_category' => $byCategory,
            'blockers' => $blockers,
            'fail_closed' => $failClosed,
            'safe_to_continue' => ! $failClosed,
        ];
    }
}
