<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality\Support;

/**
 * Pure residual helpers for {@see \App\Services\Ai\Reality\AtlasRealityGraphQueryService}.
 *
 * Extracted from the AURG Phase-2 query monstruo: expand normalisation, seed
 * merge, path ordering, PPR shadow status, entity token extraction, lexical
 * quality/priority, provider/workspace admission, and rank text surfaces.
 *
 * No I/O, no DI, no Eloquent, no config(), no clock, no filesystem, no provider.
 */
final class RealityGraphQuerySupport
{
    /** Bounded multi-term tokenisation / entity ref extraction. */
    public const MAX_QUERY_TERMS = 12;

    private function __construct()
    {
    }

    /**
     * @param  mixed  $expand  bool|string|list<string> — 'code' (alias true) selects the code drill-down.
     * @return list<string>
     */
    public static function normalizeExpand(mixed $expand): array
    {
        if ($expand === null || $expand === '' || $expand === false) {
            return [];
        }
        if ($expand === true) {
            return ['code'];
        }
        if (is_string($expand)) {
            $expand = preg_split('/[\s,]+/', $expand) ?: [];
        }
        if (! is_array($expand)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $expand,
        ), static fn (string $v): bool => $v !== '')));
    }

    /**
     * Exact entity seeds first, then semantic up to half the cap, lexical fills
     * the rest, leftover semantic tops up. Dedup by node id (earlier tiers win).
     *
     * @param  list<array<string,mixed>>  $entity
     * @param  list<array<string,mixed>>  $semantic
     * @param  list<array<string,mixed>>  $lexical
     * @return list<array<string,mixed>>
     */
    public static function mergeSeeds(array $entity, array $semantic, array $lexical, int $cap, bool &$overflow): array
    {
        $picked = [];
        $push = static function (array $seed) use (&$picked, $cap): void {
            if (count($picked) < $cap && ! isset($picked[$seed['node_id']])) {
                $picked[$seed['node_id']] = $seed;
            }
        };

        foreach ($entity as $seed) {
            $push($seed);
        }
        foreach (array_slice($semantic, 0, (int) ceil($cap / 2)) as $seed) {
            $push($seed);
        }
        foreach ($lexical as $seed) {
            $push($seed);
        }
        foreach ($semantic as $seed) {
            $push($seed);
        }

        $distinct = [];
        foreach (array_merge($entity, $semantic, $lexical) as $seed) {
            $distinct[$seed['node_id']] = true;
        }
        $overflow = count($distinct) > $cap;

        return array_values($picked);
    }

    /**
     * @param  list<array<string,mixed>>  $paths
     * @param  list<string>  $orderedIds
     * @return list<array<string,mixed>>
     */
    public static function orderPaths(array $paths, array $orderedIds): array
    {
        $position = array_flip($orderedIds);
        usort($paths, static function (array $a, array $b) use ($position): int {
            $aTarget = (string) ($a['target'] ?? '');
            $bTarget = (string) ($b['target'] ?? '');

            return ($position[$aTarget] ?? PHP_INT_MAX) <=> ($position[$bTarget] ?? PHP_INT_MAX)
                ?: ((int) ($a['depth'] ?? 0) <=> (int) ($b['depth'] ?? 0))
                ?: strcmp($aTarget, $bTarget);
        });

        return array_values($paths);
    }

    public static function pprShadowStatus(
        int $cases,
        int $targetsAvailable,
        mixed $baselineRecall,
        mixed $pprRecall,
        bool $withinLatencyBudget,
    ): string {
        if ($cases <= 0) {
            return 'observed_no_targets';
        }
        if ($targetsAvailable < $cases) {
            return 'targets_missing';
        }
        if (! is_numeric($baselineRecall) || ! is_numeric($pprRecall)) {
            return 'recall_unmeasured';
        }
        if (! $withinLatencyBudget) {
            return 'latency_budget_exceeded';
        }

        return (float) $pprRecall >= (float) $baselineRecall
            ? 'candidate_non_regression'
            : 'candidate_regression';
    }

    /**
     * @return list<string>
     */
    public static function normaliseTargetNodeIds(mixed $targets): array
    {
        if (is_string($targets)) {
            $targets = preg_split('/[\s,]+/', $targets) ?: [];
        }
        if (! is_array($targets)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $target): string => trim((string) $target),
            $targets,
        ), static fn (string $target): bool => $target !== '')));
    }

    /**
     * Lowercased lexical terms (>=2 chars), bounded. Keeps the original token
     * and also expands separators, so provider-bound terms such as
     * "reality_graph" and "provider-bound" can recall graph/provider nodes
     * without forcing the caller to phrase queries like the DB labels.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $tokens = preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [];
        $terms = [];
        foreach ($tokens as $token) {
            $token = trim($token, " \t\n\r\0\x0B.,:;()[]{}<>\"'");
            foreach (array_merge([$token], preg_split('/[^\p{L}\p{N}]+/u', $token) ?: []) as $candidate) {
                $candidate = trim((string) $candidate);
                if ($candidate !== '' && mb_strlen($candidate) >= 2 && ! in_array($candidate, $terms, true)) {
                    $terms[] = $candidate;
                }
            }
        }

        return array_slice($terms, 0, self::MAX_QUERY_TERMS);
    }

    /**
     * @return list<string>
     */
    public static function entityRepoPaths(string $query): array
    {
        preg_match_all(
            '~(?<![\pL\pN_])(?:app|tests|docs|config|routes|database|resources|scripts)/[A-Za-z0-9_./-]+~u',
            $query,
            $matches,
        );

        $paths = [];
        foreach ($matches[0] ?? [] as $match) {
            $path = rtrim($match, ".,;:!?)]}'\"`");
            if ($path !== '' && ! str_contains($path, '..')) {
                $paths[$path] = true;
            }
        }

        preg_match_all('/\bApp\\\\[A-Za-z0-9_\\\\]+\b/', $query, $fqcnMatches);
        foreach ($fqcnMatches[0] ?? [] as $fqcn) {
            $relative = substr($fqcn, strlen('App\\'));
            if ($relative === false || $relative === '') {
                continue;
            }
            $paths['app/'.str_replace('\\', '/', $relative).'.php'] = true;
        }

        return array_slice(array_keys($paths), 0, self::MAX_QUERY_TERMS);
    }

    /**
     * @return list<string>
     */
    public static function entityMemoryRefs(string $query): array
    {
        preg_match_all(
            '/\b(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9A-HJKMNP-TV-Z]{26})\b/i',
            $query,
            $matches,
        );

        $refs = [];
        foreach ($matches[0] ?? [] as $ref) {
            $refs[] = strtolower($ref);
            $refs[] = strtoupper($ref);
        }

        return array_slice(array_values(array_unique($refs)), 0, self::MAX_QUERY_TERMS);
    }

    /**
     * Provider-bound graph packs are initial context, not an audit log. Mission
     * outcome evidence is still available through local/unbounded query and the
     * mission-history surface; it should not win the first seed slots by matching
     * broad metadata such as "mission" or a touched path.
     *
     * @param  list<string>  $labelMatched
     */
    public static function lexicalSeedQuality(
        string $sourceKind,
        string $kind,
        string $label,
        array $labelMatched,
        bool $providerBound,
    ): ?int {
        if (! $providerBound) {
            return self::lexicalSourcePriority($sourceKind, $kind);
        }

        if (self::isGenericProviderSeedLabel($label)) {
            return null;
        }

        if ($sourceKind === 'mission') {
            if ($kind === 'evidence') {
                return null;
            }
            if ($labelMatched === []) {
                return null;
            }
        }

        if ($sourceKind === 'evidence' && $labelMatched === []) {
            return null;
        }

        return self::lexicalSourcePriority($sourceKind, $kind);
    }

    public static function lexicalSourcePriority(string $sourceKind, string $kind): int
    {
        if ($sourceKind === 'memory') {
            return 90;
        }
        if ($sourceKind === 'code') {
            return 80;
        }
        if (in_array($sourceKind, ['doc', 'docs', 'documentation'], true)) {
            return 75;
        }
        if ($sourceKind === 'domain') {
            return 55;
        }
        if ($sourceKind === 'evidence') {
            return 45;
        }
        if ($sourceKind === 'mission' && $kind === 'mission') {
            return 35;
        }
        if ($sourceKind === 'mission') {
            return 20;
        }

        return 10;
    }

    public static function isGenericProviderSeedLabel(string $label): bool
    {
        $label = mb_strtolower(trim($label));
        $label = (string) preg_replace('/\s+/u', ' ', $label);

        return in_array($label, [
            'mission_outcome',
            'mission outcome',
            '[request interrupted by user for tool use]',
            'request interrupted by user for tool use',
        ], true) || str_starts_with($label, '[request interrupted');
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public static function rankTextSurface(string $label, string $sourceId, array $meta = []): string
    {
        $parts = [
            $label,
            $sourceId,
        ];

        foreach ($meta as $value) {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
            } elseif (is_array($value)) {
                foreach ($value as $inner) {
                    if (is_scalar($inner)) {
                        $parts[] = (string) $inner;
                    }
                }
            }
        }

        return mb_substr(implode(' ', array_filter($parts, static fn (string $part): bool => trim($part) !== '')), 0, 2000);
    }

    public static function providerAdmissible(bool $providerSafe, bool $sensitive): bool
    {
        // provider_safe AND not sensitive — belt and suspenders: F1 marks
        // sensitive domains provider_safe=false already, but a sensitive row
        // must never ride a provider-bound answer regardless of its safe bit.
        return $providerSafe && ! $sensitive;
    }

    public static function workspaceAdmissible(mixed $nodeWorkspaceId, string $workspaceId): bool
    {
        $nodeWorkspace = trim((string) ($nodeWorkspaceId ?? ''));

        return $workspaceId === ''
            || $nodeWorkspace === ''
            || hash_equals($workspaceId, $nodeWorkspace);
    }
}
