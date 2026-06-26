<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active;


use App\Services\Ai\SelfConstruction\Support\CanonicalizesNestedValues;
use LogicException;

/** Raised when a caller tries to overwrite or collapse the passive payload — passive is frozen by contract. */
final class CortexPassiveImmutabilityViolation extends LogicException
{
}

/**
 * CORTEX · ACTIVE SNAPSHOT — the extension record that composes the four ACTIVE Cortex probes
 * ({@see AtlasCortexHypotheticalChangeWalker}, {@see AtlasCortexCounterfactualProbe},
 * {@see AtlasCortexCallGraphProjector}, {@see AtlasCortexCriticalPathDetector}) on top of the PASSIVE
 * comprehension model (the Layer-1 AtlasCortexScopeComprehensionModel output). Passive and active live in
 * SEPARATE namespaces; this class refuses to overwrite or collapse passive FACTs (typed exception).
 *
 * Anti-Goodhart (loop-brain-comprehension-origination): NO `comprehension_score`, NO `score`, NO `rank`
 * anywhere in the active sub-tree — every blind spot a probe surfaces (UNKNOWN_REGION / DEPTH_TRUNCATED /
 * UNRESOLVED_TARGET) is aggregated into `active.unknown_regions` as a first-class fact. Output is byte-
 * deterministic JSON (recursively sorted associative keys, lists preserved).
 */
final class AtlasCortexActiveSnapshot
{
    use CanonicalizesNestedValues;
    public const SCHEMA = 'atlas.cortex.active.snapshot.v1';

    /** Sentinel reasons aggregated into active.unknown_regions. */
    private const BLIND_SPOT_REASONS = [
        AtlasCortexCriticalPathDetector::FACT_UNKNOWN_REGION,
        AtlasCortexCallGraphProjector::DEPTH_TRUNCATED,
        'UNRESOLVED_TARGET',
        'UNKNOWN_REGION',
    ];

    /** @var array<string,mixed> */
    private array $passive;

    /** @var array<string,mixed> */
    private array $active;

    /**
     * @param  array<string,mixed>  $passiveModel
     * @param  array{
     * hypothetical_changes?:list<array<string,mixed>>, counterfactuals?:list<array<string,mixed>>, call_graph_projections?:list<array<string,mixed>>, critical_paths?:list<array<string,mixed>>}  $activeProbes
     */
    public function __construct(array $passiveModel, array $activeProbes)
    {
        $this->passive = $passiveModel;
        $this->active = [
            'hypothetical_changes' => array_values((array) ($activeProbes['hypothetical_changes'] ?? [])),
            'counterfactuals' => array_values((array) ($activeProbes['counterfactuals'] ?? [])),
            'call_graph_projections' => array_values((array) ($activeProbes['call_graph_projections'] ?? [])),
            'critical_paths' => array_values((array) ($activeProbes['critical_paths'] ?? [])),
            'unknown_regions' => $this->aggregateBlindSpots($activeProbes),
        ];
    }

    /**
     * Frozen, byte-deterministic snapshot record.
     *
     * @return array{schema:string, passive:array<string,mixed>, active:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'passive' => $this->canonicalize($this->passive),
            'active' => $this->canonicalize($this->active),
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Read-only accessor for the passive payload (always returns a copy — caller mutations cannot leak in). */
    public function passive(): array
    {
        return $this->passive;
    }

    public function active(): array
    {
        return $this->active;
    }

    /**
     * Mutation guard: refuses any caller-supplied PASSIVE overwrite/merge. Active probes never write back into
     * passive — this is the chokepoint that enforces it at runtime.
     *
     * @param  array<string,mixed>  $_passive
     */
    public function mutatePassive(array $_passive): never
    {
        throw new CortexPassiveImmutabilityViolation(
            'AtlasCortexActiveSnapshot: passive payload is frozen — active and passive live in separate namespaces.'
        );
    }

    /**
     * Sweep every sub-probe output for UNKNOWN_REGION / DEPTH_TRUNCATED / UNRESOLVED_TARGET sentinels and lift
     * them into a first-class `unknown_regions` list. Each entry carries the probe it came from + the raw fact.
     *
     * @param  array<string,mixed>  $activeProbes
     * @return list<array{from:string, sentinel:string, fact:array<string,mixed>}>
     */
    private function aggregateBlindSpots(array $activeProbes): array
    {
        $out = [];
        foreach ($activeProbes as $probeKey => $facts) {
            if (! is_array($facts)) {
                continue;
            }
            foreach ($facts as $fact) {
                if (! is_array($fact)) {
                    continue;
                }
                $reason = $this->blindSpotReason($fact);
                if ($reason === null) {
                    continue;
                }
                $out[] = ['from' => (string) $probeKey, 'sentinel' => $reason, 'fact' => $fact];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['from'], $a['sentinel']] <=> [$b['from'], $b['sentinel']]);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function blindSpotReason(array $fact): ?string
    {
        foreach (['fact', 'sentinel', 'reason'] as $key) {
            $value = (string) ($fact[$key] ?? '');
            if ($value !== '' && in_array($value, self::BLIND_SPOT_REASONS, true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Recursively sort associative arrays (lists preserve order) so JSON serialization is byte-stable.
     */
}
