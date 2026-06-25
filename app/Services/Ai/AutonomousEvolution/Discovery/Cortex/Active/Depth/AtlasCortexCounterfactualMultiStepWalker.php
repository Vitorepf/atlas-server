<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth;

/**
 * Walks N hypothetical changes deep beyond the v+2 single-hop counterfactual surface.
 *
 * At each step the walker composes a hypothesis (touch site S_i with mutation M_i), records the
 * FACT-only projected reachability/coverage delta against the read-model snapshot, then
 * conditionally extends the walk only when the next step is grounded in a real symbol/file the
 * prior step would have transitively affected.
 *
 * Pure projection over the snapshot. Never edits the live tree. NEVER scores / NEVER ranks.
 * Refuses to walk into FORBIDDEN scope (MarketingDomain, Aaeos, Forge, desktop).
 *
 * Flag-off ⇒ byte-identical no-op (empty WalkResult).
 */
final class AtlasCortexCounterfactualMultiStepWalker
{
    public const SCHEMA = 'atlas.cortex.counterfactual_multi_step_walk.v1';

    public const DEFAULT_DEPTH = 3;

    public const HARD_CAP_DEPTH = 8;

    public const FORBIDDEN_PATH_FRAGMENTS = [
        'MarketingDomain',
        'Aaeos',
        'Forge',
        'desktop',
    ];

    public const TERMINATED_DEPTH_REACHED = 'depth_reached';

    public const TERMINATED_FORBIDDEN_SCOPE = 'forbidden_scope';

    public const TERMINATED_UNREACHABLE = 'next_step_not_reachable_from_prior';

    public function __construct(
        private readonly bool $enabled,
        private readonly int $depth = self::DEFAULT_DEPTH,
    ) {}

    /**
     * @param  array<string,mixed>  $snapshot         AtlasLoopReadModelSnapshot subset
     * @param  list<array<string,mixed>>  $hypotheses  ordered list of {site, mutation_kind} per step
     * @return array<string,mixed>
     */
    public function walk(array $snapshot, array $hypotheses): array
    {
        if (! $this->enabled) {
            return $this->envelope([], 'flag_off');
        }

        $depth = min(max(0, $this->depth), self::HARD_CAP_DEPTH);
        $reachable = is_array($snapshot['reachability'] ?? null) ? $snapshot['reachability'] : [];

        $steps = [];
        $priorAffected = null;
        $terminatedReason = self::TERMINATED_DEPTH_REACHED;

        foreach ($hypotheses as $i => $hypothesis) {
            if (! is_array($hypothesis)) {
                continue;
            }
            if ($i >= $depth) {
                break;
            }
            $site = (string) ($hypothesis['site'] ?? '');
            $mutationKind = (string) ($hypothesis['mutation_kind'] ?? '');

            if ($this->isForbidden($site)) {
                $terminatedReason = self::TERMINATED_FORBIDDEN_SCOPE;
                break;
            }

            if ($priorAffected !== null && ! $this->reachableFromPrior($site, $priorAffected, $reachable)) {
                $terminatedReason = self::TERMINATED_UNREACHABLE;
                break;
            }

            $delta = $this->observeDelta($site, $mutationKind, $reachable);
            $steps[] = [
                'step_index' => $i,
                'site' => $site,
                'mutation_kind' => $mutationKind,
                'observable_fact_delta' => $delta,
            ];
            $priorAffected = $delta['affected_files'];
        }

        return $this->envelope($steps, $terminatedReason);
    }

    /**
     * @param  list<array<string,mixed>>  $steps
     * @return array<string,mixed>
     */
    private function envelope(array $steps, string $terminatedReason): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'steps' => $steps,
            'terminated_reason' => $terminatedReason,
        ];
    }

    private function isForbidden(string $site): bool
    {
        foreach (self::FORBIDDEN_PATH_FRAGMENTS as $fragment) {
            if (str_contains($site, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $priorAffected
     * @param  array<string, list<string>>  $reachable
     */
    private function reachableFromPrior(string $site, array $priorAffected, array $reachable): bool
    {
        $site = ltrim($site, '/');
        foreach ($priorAffected as $affected) {
            $a = ltrim((string) $affected, '/');
            if ($a === $site) {
                return true;
            }
            $links = (array) ($reachable[$a] ?? []);
            foreach ($links as $link) {
                if (ltrim((string) $link, '/') === $site) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<string>>  $reachable
     * @return array<string,mixed>
     */
    private function observeDelta(string $site, string $mutationKind, array $reachable): array
    {
        $affected = [$site];
        foreach ((array) ($reachable[ltrim($site, '/')] ?? []) as $link) {
            $affected[] = (string) $link;
        }
        sort($affected, SORT_STRING);

        return [
            'mutation_kind' => $mutationKind,
            'affected_files' => array_values(array_unique($affected)),
            'projected_reachable_count' => count($affected),
        ];
    }
}
