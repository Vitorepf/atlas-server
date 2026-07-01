<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure redundancy mapper. Clusters Autonomous OS organs that likely overlap
 * so the brain can identify consolidation candidates before adding new code.
 *
 * Two organs are connected (same cluster) when they share the same canonical
 * layer AND have at least one overlapping purpose token. Clusters are the
 * connected components of that relation, so overlap is transitive: A-B and
 * B-C sharing tokens puts A, B, C in one cluster even if A and C share none.
 *
 * INPUT:
 *   organs: list<{
 *     organ_id:              string
 *     layer:                 string
 *     purpose_tokens?:       list<string>
 *     input_shape?:          string
 *     output_shape?:         string
 *     downstream_consumers?: list<string>
 *   }>
 *
 * OUTPUT:
 *   { schema, clusters: list<{layer, members, overlap_reasons, consolidation_priority}>, cluster_count }
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionSimplificationRedundancyMap
{
    public const SCHEMA = 'atlas.self_construction.simplification_redundancy_map.v1';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_MEDIUM = 'medium';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function map(array $input): array
    {
        $rawOrgans = is_array($input['organs'] ?? null) ? $input['organs'] : [];

        $organs = [];
        foreach ($rawOrgans as $raw) {
            $organId = (string) ($raw['organ_id'] ?? '');
            if ($organId === '' || isset($organs[$organId])) {
                continue;
            }
            $organs[$organId] = [
                'organ_id' => $organId,
                'layer' => (string) ($raw['layer'] ?? ''),
                'purpose_tokens' => array_values(array_unique(array_map('strval', (array) ($raw['purpose_tokens'] ?? [])))),
                'input_shape' => (string) ($raw['input_shape'] ?? ''),
                'output_shape' => (string) ($raw['output_shape'] ?? ''),
                'downstream_consumers' => array_values(array_unique(array_map('strval', (array) ($raw['downstream_consumers'] ?? [])))),
            ];
        }

        $ids = array_keys($organs);
        $parent = array_combine($ids, $ids);
        $find = function (string $id) use (&$parent, &$find): string {
            if ($parent[$id] !== $id) {
                $parent[$id] = $find($parent[$id]);
            }

            return $parent[$id];
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            $rootA = $find($a);
            $rootB = $find($b);
            if ($rootA !== $rootB) {
                $parent[$rootA] = $rootB;
            }
        };

        $pairReasons = [];
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = $organs[$ids[$i]];
                $b = $organs[$ids[$j]];
                if ($a['layer'] === '' || $a['layer'] !== $b['layer']) {
                    continue;
                }
                $sharedTokens = array_values(array_intersect($a['purpose_tokens'], $b['purpose_tokens']));
                if ($sharedTokens === []) {
                    continue;
                }
                $union($a['organ_id'], $b['organ_id']);
                $pairKey = $find($a['organ_id']);
                $pairReasons[$pairKey][] = 'shared_purpose_tokens:'.implode(',', $sharedTokens);

                $sharedConsumers = array_values(array_intersect($a['downstream_consumers'], $b['downstream_consumers']));
                if ($sharedConsumers !== []) {
                    $pairReasons[$pairKey][] = 'shared_consumers:'.implode(',', $sharedConsumers);
                }
                if ($a['input_shape'] !== '' && $a['input_shape'] === $b['input_shape']) {
                    $pairReasons[$pairKey][] = 'same_input_shape:'.$a['input_shape'];
                }
                if ($a['output_shape'] !== '' && $a['output_shape'] === $b['output_shape']) {
                    $pairReasons[$pairKey][] = 'same_output_shape:'.$a['output_shape'];
                }
            }
        }

        $groups = [];
        foreach ($ids as $id) {
            $root = $find($id);
            $groups[$root][] = $id;
        }

        $clusters = [];
        foreach ($groups as $root => $members) {
            if (count($members) < 2) {
                continue;
            }
            sort($members, SORT_STRING);
            $reasons = array_values(array_unique($pairReasons[$root] ?? []));
            sort($reasons, SORT_STRING);
            $sharedConsumerReason = count(array_filter($reasons, static fn (string $r): bool => str_starts_with($r, 'shared_consumers:'))) > 0;

            $clusters[] = [
                'layer' => $organs[$members[0]]['layer'],
                'members' => $members,
                'overlap_reasons' => $reasons,
                'consolidation_priority' => (count($members) >= 3 || $sharedConsumerReason) ? self::PRIORITY_HIGH : self::PRIORITY_MEDIUM,
            ];
        }

        usort($clusters, static function (array $a, array $b): int {
            $cmp = $b['members'] <=> $a['members'];

            return count($a['members']) === count($b['members'])
                ? strcmp($a['members'][0], $b['members'][0])
                : count($b['members']) <=> count($a['members']);
        });

        return [
            'schema' => self::SCHEMA,
            'clusters' => $clusters,
            'cluster_count' => count($clusters),
        ];
    }
}
