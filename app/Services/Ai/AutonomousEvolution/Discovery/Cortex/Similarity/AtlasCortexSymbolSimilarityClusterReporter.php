<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity;

use InvalidArgumentException;

final class AtlasCortexSymbolSimilarityClusterReporter
{
    public const MATCH_ANY = 'any';

    public const MATCH_ALL = 'all';

    /** @var list<string> */
    private const SUPPORTED_CHANNELS = [
        'token_overlap_ratio',
        'ast_shape_overlap_ratio',
        'method_name_overlap_ratio',
    ];

    /**
     * @param  iterable<AtlasCortexSymbolSimilarityPairFact>  $pairFacts
     * @param  list<string>  $channels
     * @return list<AtlasCortexSymbolSimilarityClusterFact>
     */
    public function report(iterable $pairFacts, float $threshold, array $channels, string $matchMode = self::MATCH_ANY): array
    {
        $channels = $this->normalizedChannels($channels);
        $edges = $this->qualifyingEdges($pairFacts, $threshold, $channels, $matchMode);
        if ($edges === []) {
            return [];
        }

        $parents = [];
        foreach ($edges as $edge) {
            $parents[$edge->pairA] ??= $edge->pairA;
            $parents[$edge->pairB] ??= $edge->pairB;
            $this->union($parents, $edge->pairA, $edge->pairB);
        }

        $membersByRoot = [];
        foreach (array_keys($parents) as $member) {
            $root = $this->find($parents, $member);
            $membersByRoot[$root][] = $member;
        }

        $edgesByCluster = [];
        foreach ($edges as $edge) {
            $root = $this->find($parents, $edge->pairA);
            $edgesByCluster[$root][] = $edge;
        }

        $clusters = [];
        foreach ($membersByRoot as $root => $members) {
            sort($members, SORT_STRING);
            $clusterId = sha1(implode('|', $members));
            $clusterEdges = $edgesByCluster[$root] ?? [];
            $clusters[] = new AtlasCortexSymbolSimilarityClusterFact(
                clusterId: $clusterId,
                members: $members,
                edgeCount: count($clusterEdges),
                tokenOverlap: $this->channelStats($clusterEdges, 'tokenOverlapRatio'),
                astShapeOverlap: $this->channelStats($clusterEdges, 'astShapeOverlapRatio'),
                methodNameOverlap: $this->channelStats($clusterEdges, 'methodNameOverlapRatio'),
            );
        }

        usort(
            $clusters,
            static fn (AtlasCortexSymbolSimilarityClusterFact $left, AtlasCortexSymbolSimilarityClusterFact $right): int => strcmp($left->clusterId, $right->clusterId),
        );

        return $clusters;
    }

    /**
     * @param  iterable<AtlasCortexSymbolSimilarityPairFact>  $pairFacts
     * @param  list<string>  $channels
     * @return list<AtlasCortexSymbolSimilarityPairFact>
     */
    private function qualifyingEdges(iterable $pairFacts, float $threshold, array $channels, string $matchMode): array
    {
        $this->assertMatchMode($matchMode);

        $edges = [];
        foreach ($pairFacts as $pairFact) {
            if (! $pairFact instanceof AtlasCortexSymbolSimilarityPairFact) {
                continue;
            }

            $matches = array_map(
                fn (string $channel): bool => $this->channelValue($pairFact, $channel) >= $threshold,
                $channels,
            );

            $qualifies = $matchMode === self::MATCH_ALL
                ? ! in_array(false, $matches, true)
                : in_array(true, $matches, true);

            if (! $qualifies) {
                continue;
            }

            $edges[] = $pairFact;
        }

        usort(
            $edges,
            static fn (AtlasCortexSymbolSimilarityPairFact $left, AtlasCortexSymbolSimilarityPairFact $right): int => [$left->pairA, $left->pairB] <=> [$right->pairA, $right->pairB],
        );

        return $edges;
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function normalizedChannels(array $channels): array
    {
        $channels = array_values(array_unique(array_filter(
            array_map(static fn (mixed $channel): string => trim((string) $channel), $channels),
            static fn (string $channel): bool => $channel !== '',
        )));

        sort($channels, SORT_STRING);

        if ($channels === []) {
            throw new InvalidArgumentException('At least one similarity channel must be provided.');
        }

        foreach ($channels as $channel) {
            if (! in_array($channel, self::SUPPORTED_CHANNELS, true)) {
                throw new InvalidArgumentException('Unsupported similarity channel: '.$channel);
            }
        }

        return $channels;
    }

    private function assertMatchMode(string $matchMode): void
    {
        if (! in_array($matchMode, [self::MATCH_ANY, self::MATCH_ALL], true)) {
            throw new InvalidArgumentException('Unsupported match mode: '.$matchMode);
        }
    }

    private function channelValue(AtlasCortexSymbolSimilarityPairFact $pairFact, string $channel): float
    {
        return match ($channel) {
            'token_overlap_ratio' => $pairFact->tokenOverlapRatio,
            'ast_shape_overlap_ratio' => $pairFact->astShapeOverlapRatio,
            'method_name_overlap_ratio' => $pairFact->methodNameOverlapRatio,
            default => throw new InvalidArgumentException('Unsupported similarity channel: '.$channel),
        };
    }

    /**
     * @param  array<string,string>  $parents
     */
    private function union(array &$parents, string $left, string $right): void
    {
        $leftRoot = $this->find($parents, $left);
        $rightRoot = $this->find($parents, $right);

        if ($leftRoot === $rightRoot) {
            return;
        }

        if (strcmp($leftRoot, $rightRoot) < 0) {
            $parents[$rightRoot] = $leftRoot;
        } else {
            $parents[$leftRoot] = $rightRoot;
        }
    }

    /**
     * @param  array<string,string>  $parents
     */
    private function find(array &$parents, string $node): string
    {
        $parents[$node] ??= $node;
        if ($parents[$node] === $node) {
            return $node;
        }

        $parents[$node] = $this->find($parents, $parents[$node]);

        return $parents[$node];
    }

    /**
     * @param  list<AtlasCortexSymbolSimilarityPairFact>  $edges
     * @return array{min:float,max:float,mean:float}
     */
    private function channelStats(array $edges, string $property): array
    {
        $values = array_map(
            static fn (AtlasCortexSymbolSimilarityPairFact $edge): float => $edge->{$property},
            $edges,
        );

        sort($values, SORT_NUMERIC);

        $count = count($values);
        if ($count === 0) {
            return ['min' => 0.0, 'max' => 0.0, 'mean' => 0.0];
        }

        return [
            'min' => round($values[0], 6),
            'max' => round($values[$count - 1], 6),
            'mean' => round(array_sum($values) / $count, 6),
        ];
    }
}
