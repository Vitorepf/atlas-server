<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Identifies duplicate organs that can be collapsed into one
 * implementation task with preservation tests.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasSelfConstructionSimplificationDuplicateOrganCollapser
{
    public const SCHEMA = 'atlas.self_construction.simplification_duplicate_organ_collapser.v1';

    /**
     * @param  array<int, array<string, mixed>>  $organs
     * @return array<string, mixed>
     */
    public function identify(array $organs): array
    {
        $groups = [];
        $standalone = [];

        foreach ($organs as $organ) {
            if (! is_array($organ)) {
                continue;
            }
            $id = (string) ($organ['id'] ?? '');
            $inputShape = (string) ($organ['input_shape'] ?? '');
            $outputShape = (string) ($organ['output_shape'] ?? '');
            $capability = (string) ($organ['capability'] ?? '');

            $key = md5($inputShape.'|'.$outputShape.'|'.$capability);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'collapse_key' => $key,
                    'capability' => $capability,
                    'input_shape' => $inputShape,
                    'output_shape' => $outputShape,
                    'organs' => [],
                ];
            }
            $groups[$key]['organs'][] = $id;
        }

        $collapseCandidates = [];
        $kept = [];

        foreach ($groups as $key => $group) {
            if (count($group['organs']) > 1) {
                $collapseCandidates[] = $group;
            } else {
                $kept[] = $group;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'collapse_candidates' => $collapseCandidates,
            'candidate_count' => count($collapseCandidates),
            'kept_separate' => $kept,
            'kept_count' => count($kept),
            'total_organs' => count($organs),
        ];
    }
}
