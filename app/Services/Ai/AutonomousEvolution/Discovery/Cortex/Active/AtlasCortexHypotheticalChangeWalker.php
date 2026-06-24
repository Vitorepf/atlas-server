<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

final class AtlasCortexHypotheticalChangeWalker
{
    public const SCHEMA = 'atlas.cortex.active.hypothetical_change_impact_facts.v1';
    public const UNKNOWN_REGION = 'UNKNOWN_REGION';

    private const REASONS = [
        'signature_arity_changed',
        'return_type_changed',
        'side_effect_removed',
        'symbol_removed',
        'symbol_renamed',
        'behavior_changed',
        'unindexed_region',
    ];

    public function __construct(private readonly AtlasLoopScopeComprehensionModel|array $index)
    {
    }

    /**
     * @param  array<string,mixed>|string  $edit
     * @return list<array{schema:string,target:string,caller_file:string,caller_line:int,caller_symbol:string,distance_from_target:int,propagation_reason:string,confidence_basis:string}>
     */
    public function walk(string $target, array|string $edit): array
    {
        $target = ltrim(trim($target), '\\/');
        if ($target === '') {
            return [];
        }

        $reason = $this->propagationReason($edit);
        $queue = [[$target, 0]];
        $visitedTargets = [$target => true];
        $facts = [];
        $factKeys = [];

        while ($queue !== []) {
            [$current, $distance] = array_shift($queue);
            foreach ($this->callerFactsFor((string) $current) as $caller) {
                $fact = $this->fact($target, $caller, $distance + 1, $caller['unindexed'] ? 'unindexed_region' : $reason);
                $key = implode('|', [
                    $fact['caller_file'],
                    (string) $fact['caller_line'],
                    $fact['caller_symbol'],
                    (string) $fact['distance_from_target'],
                    $fact['propagation_reason'],
                ]);
                if (! isset($factKeys[$key])) {
                    $factKeys[$key] = true;
                    $facts[] = $fact;
                }

                $next = (string) ($caller['caller_file'] ?? '');
                if (! $caller['unindexed'] && $next !== '' && ! isset($visitedTargets[$next])) {
                    $visitedTargets[$next] = true;
                    $queue[] = [$next, $distance + 1];
                }
            }
        }

        usort($facts, static fn (array $left, array $right): int => [
            $left['distance_from_target'],
            $left['caller_file'],
            $left['caller_line'],
            $left['caller_symbol'],
            $left['propagation_reason'],
        ] <=> [
            $right['distance_from_target'],
            $right['caller_file'],
            $right['caller_line'],
            $right['caller_symbol'],
            $right['propagation_reason'],
        ]);

        return $facts;
    }

    /**
     * @return list<array{caller_file:string,caller_line:int,caller_symbol:string,confidence_basis:string,unindexed:bool}>
     */
    private function callerFactsFor(string $target): array
    {
        if ($this->index instanceof AtlasLoopScopeComprehensionModel) {
            $callers = $this->index->callerPathsFor($target) ?? [];

            return array_map(fn (string $path): array => [
                'caller_file' => $path,
                'caller_line' => 1,
                'caller_symbol' => $this->index->fqcnForPath($path) ?? $path,
                'confidence_basis' => 'static_evidence',
                'unindexed' => false,
            ], array_values($callers));
        }

        $edges = (array) ($this->index['edges'][$target] ?? []);
        $locations = (array) ($this->index['caller_locations'][$target] ?? []);
        $unindexed = (array) ($this->index['unindexed_edges'][$target] ?? []);
        $facts = [];

        foreach ($edges as $edge) {
            $facts[] = $this->normalizeEdge($edge, $locations);
        }
        foreach ($unindexed as $edge) {
            $fact = $this->normalizeEdge($edge, []);
            $fact['caller_file'] = self::UNKNOWN_REGION;
            $fact['caller_line'] = 0;
            $fact['caller_symbol'] = self::UNKNOWN_REGION;
            $fact['confidence_basis'] = 'static_evidence';
            $fact['unindexed'] = true;
            $facts[] = $fact;
        }

        return $facts;
    }

    /**
     * @param  array<string,mixed>  $locations
     * @return array{caller_file:string,caller_line:int,caller_symbol:string,confidence_basis:string,unindexed:bool}
     */
    private function normalizeEdge(mixed $edge, array $locations): array
    {
        if (is_array($edge)) {
            $file = ltrim((string) ($edge['caller_file'] ?? $edge['file'] ?? ''), '/');

            return [
                'caller_file' => $file,
                'caller_line' => max(1, (int) ($edge['caller_line'] ?? $edge['line'] ?? 1)),
                'caller_symbol' => (string) ($edge['caller_symbol'] ?? $edge['symbol'] ?? $file),
                'confidence_basis' => $this->confidenceBasis($edge['confidence_basis'] ?? 'static_evidence'),
                'unindexed' => (bool) ($edge['unindexed'] ?? false),
            ];
        }

        $file = ltrim((string) $edge, '/');
        $location = (array) ($locations[$file] ?? []);

        return [
            'caller_file' => $file,
            'caller_line' => max(1, (int) ($location['line'] ?? 1)),
            'caller_symbol' => (string) ($location['symbol'] ?? $file),
            'confidence_basis' => $this->confidenceBasis($location['confidence_basis'] ?? 'static_evidence'),
            'unindexed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $caller
     * @return array{schema:string,target:string,caller_file:string,caller_line:int,caller_symbol:string,distance_from_target:int,propagation_reason:string,confidence_basis:string}
     */
    private function fact(string $target, array $caller, int $distance, string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'target' => $target,
            'caller_file' => $caller['caller_file'],
            'caller_line' => $caller['caller_line'],
            'caller_symbol' => $caller['caller_symbol'],
            'distance_from_target' => $distance,
            'propagation_reason' => $this->closedReason($reason),
            'confidence_basis' => $this->confidenceBasis($caller['confidence_basis']),
        ];
    }

    /**
     * @param  array<string,mixed>|string  $edit
     */
    private function propagationReason(array|string $edit): string
    {
        $kind = is_array($edit) ? (string) ($edit['propagation_reason'] ?? $edit['kind'] ?? $edit['type'] ?? '') : $edit;

        return match ($kind) {
            'signature_change' => 'signature_arity_changed',
            'remove' => 'symbol_removed',
            'rename' => 'symbol_renamed',
            'behavior_diff' => 'behavior_changed',
            default => $this->closedReason($kind),
        };
    }

    private function closedReason(string $reason): string
    {
        return in_array($reason, self::REASONS, true) ? $reason : 'behavior_changed';
    }

    private function confidenceBasis(mixed $basis): string
    {
        return $basis === 'reflective_evidence' ? 'reflective_evidence' : 'static_evidence';
    }
}
