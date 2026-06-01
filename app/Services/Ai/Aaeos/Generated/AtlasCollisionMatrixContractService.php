<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Collision Matrix — pure, deterministic, read-only
 * proof that parallel packets do or do not overlap.
 *
 * The Collision Matrix is computed BEFORE parallel AI sessions claim or execute
 * work. It never claims, reserves, rewrites, dispatches or overrides hot scopes
 * (the doc "Non Goals"). It only proves which packets may be parallelized.
 *
 * Contract (from the doc):
 *
 *   Pair Schema:
 *     { left_packet_id, right_packet_id, overlap[], dependency_related,
 *       hot_scope_present, collision, decision: parallel_safe|blocked }
 *
 *   Collision Rules — a pair is BLOCKED when ANY of:
 *     1. allowed files overlap;
 *     2. either side is withheld hot work;
 *     3. dependency relation requires ordering;
 *     4. either packet allows Voice/Kernel hot scope.
 *
 *   Purpose (matrix outputs):
 *     pairwise packet comparisons, allowed-file overlap, dependency relation,
 *     hot external scope exposure, collision decision, safe parallel groups,
 *     matrix hash.
 *
 * This service implements every one of those rules deterministically in pure
 * memory with NO database and NO I/O.
 *
 * @see docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md
 */
final class AtlasCollisionMatrixContractService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.collision_matrix_contract.v1';

    public const MODE = 'read_only_collision_matrix';

    public const DECISION_PARALLEL_SAFE = 'parallel_safe';

    public const DECISION_BLOCKED = 'blocked';

    /**
     * Hot scopes the doc forbids from running in parallel: a packet that ALLOWS
     * Voice realtime runtime or the Kernel scanner is hot and blocks every pair
     * it appears in (Collision Rule 4). Path fragments are matched case-insensitively.
     *
     * @var array<int,string>
     */
    public const VOICE_KERNEL_HOT_FRAGMENTS = [
        'voice/realtime',
        'voicerealtime',
        'voice_realtime',
        'kernel/scanner',
        'kernelscanner',
        'kernel_scanner',
    ];

    /** Reason codes the matrix attaches to a blocked pair, in evaluation order. */
    public const REASON_ALLOWED_FILE_OVERLAP = 'allowed_file_overlap';

    public const REASON_WITHHELD_HOT_WORK = 'withheld_hot_work';

    public const REASON_DEPENDENCY_ORDERING = 'dependency_relation_requires_ordering';

    public const REASON_VOICE_KERNEL_HOT_SCOPE = 'voice_or_kernel_hot_scope';

    /**
     * Compare a SINGLE pair of packets and emit the doc's Pair Schema, extended
     * with the concrete blocking reasons. PURE.
     *
     * A packet shape:
     *   {
     *     id: string,
     *     allowed: list<string>          // allowed write files/paths
     *     withheld_hot: bool             // packet is held back as hot work
     *     depends_on: list<string>       // packet ids this packet depends on
     *   }
     *
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     * @return array{
     *   left_packet_id:string,
     *   right_packet_id:string,
     *   overlap:array<int,string>,
     *   dependency_related:bool,
     *   hot_scope_present:bool,
     *   collision:bool,
     *   decision:string,
     *   block_reasons:array<int,string>
     * }
     */
    public function comparePair(array $left, array $right): array
    {
        $leftId = (string) ($left['id'] ?? '');
        $rightId = (string) ($right['id'] ?? '');

        $leftAllowed = $this->normalizePaths($left['allowed'] ?? []);
        $rightAllowed = $this->normalizePaths($right['allowed'] ?? []);

        // Rule 1 — allowed files overlap.
        $overlap = array_values(array_intersect($leftAllowed, $rightAllowed));

        // Rule 3 — dependency relation requires ordering (either direction).
        $leftDeps = $this->normalizeIds($left['depends_on'] ?? []);
        $rightDeps = $this->normalizeIds($right['depends_on'] ?? []);
        $dependencyRelated = in_array($rightId, $leftDeps, true)
            || in_array($leftId, $rightDeps, true);

        // Rule 2 — either side is withheld hot work.
        $withheldHot = ((bool) ($left['withheld_hot'] ?? false))
            || ((bool) ($right['withheld_hot'] ?? false));

        // Rule 4 — either packet allows a Voice/Kernel hot scope.
        $voiceKernelHot = $this->allowsVoiceOrKernel($leftAllowed)
            || $this->allowsVoiceOrKernel($rightAllowed);

        // hot_scope_present in the Pair Schema covers BOTH hot conditions the
        // doc names: withheld hot work OR an allowed Voice/Kernel scope.
        $hotScopePresent = $withheldHot || $voiceKernelHot;

        $reasons = [];
        if ($overlap !== []) {
            $reasons[] = self::REASON_ALLOWED_FILE_OVERLAP;
        }
        if ($withheldHot) {
            $reasons[] = self::REASON_WITHHELD_HOT_WORK;
        }
        if ($dependencyRelated) {
            $reasons[] = self::REASON_DEPENDENCY_ORDERING;
        }
        if ($voiceKernelHot) {
            $reasons[] = self::REASON_VOICE_KERNEL_HOT_SCOPE;
        }

        $collision = $reasons !== [];

        return [
            'left_packet_id' => $leftId,
            'right_packet_id' => $rightId,
            'overlap' => $overlap,
            'dependency_related' => $dependencyRelated,
            'hot_scope_present' => $hotScopePresent,
            'collision' => $collision,
            'decision' => $collision ? self::DECISION_BLOCKED : self::DECISION_PARALLEL_SAFE,
            'block_reasons' => $reasons,
        ];
    }

    /**
     * Build the full read-only Collision Matrix over a list of packets: all
     * unordered pairwise comparisons, the safe parallel groups, and a stable
     * matrix hash. PURE — no claims, no dispatch, no mutation (doc Non Goals).
     *
     * @param  array<int, array<string,mixed>>  $packets
     * @return array<string,mixed>
     */
    public function buildMatrix(array $packets): array
    {
        $packets = array_values($packets);
        $ids = [];
        foreach ($packets as $packet) {
            $ids[] = (string) ($packet['id'] ?? '');
        }

        $pairs = [];
        $blockedPairs = [];
        $count = count($packets);

        // Unordered pairs (i < j) so each comparison appears exactly once.
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pair = $this->comparePair($packets[$i], $packets[$j]);
                $pairs[] = $pair;
                if ($pair['collision']) {
                    $blockedPairs[] = [$pair['left_packet_id'], $pair['right_packet_id']];
                }
            }
        }

        $safeGroups = $this->safeParallelGroups($ids, $blockedPairs);

        $matrixHash = $this->matrixHash($ids, $pairs);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'packet_ids' => $ids,
            'packet_count' => $count,
            'pairs' => $pairs,
            'pair_count' => count($pairs),
            'collision_count' => count($blockedPairs),
            'safe_parallel_groups' => $safeGroups,
            // Whole batch is parallel-safe only when no pair collides.
            'all_parallel_safe' => $blockedPairs === [],
            'matrix_hash' => $matrixHash,
            'collision_rules' => [
                self::REASON_ALLOWED_FILE_OVERLAP,
                self::REASON_WITHHELD_HOT_WORK,
                self::REASON_DEPENDENCY_ORDERING,
                self::REASON_VOICE_KERNEL_HOT_SCOPE,
            ],
        ];
    }

    /**
     * Partition packet ids into maximal groups that are pairwise parallel-safe.
     * Greedy graph colouring: a packet joins an existing group only if it does
     * not collide with ANY member of that group; otherwise it opens a new group.
     * Deterministic — input order is preserved. PURE.
     *
     * @param  array<int,string>  $ids
     * @param  array<int, array{0:string,1:string}>  $blockedPairs
     * @return array<int, array<int,string>>
     */
    public function safeParallelGroups(array $ids, array $blockedPairs): array
    {
        // Build an undirected adjacency set of blocked relations.
        $blocked = [];
        foreach ($blockedPairs as [$a, $b]) {
            $blocked[$a][$b] = true;
            $blocked[$b][$a] = true;
        }

        /** @var array<int, array<int,string>> $groups */
        $groups = [];

        foreach ($ids as $id) {
            $placed = false;
            foreach ($groups as $g => $members) {
                $collidesWithGroup = false;
                foreach ($members as $member) {
                    if (isset($blocked[$id][$member])) {
                        $collidesWithGroup = true;
                        break;
                    }
                }
                if (! $collidesWithGroup) {
                    $groups[$g][] = $id;
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                $groups[] = [$id];
            }
        }

        return array_values($groups);
    }

    /**
     * Deterministic matrix hash over the normalized packet ids and pairwise
     * decisions. Two matrices over the same packets with the same decisions
     * always hash identically; any decision flip changes the hash. PURE.
     *
     * @param  array<int,string>  $ids
     * @param  array<int, array<string,mixed>>  $pairs
     */
    public function matrixHash(array $ids, array $pairs): string
    {
        sort($ids);

        $decisions = [];
        foreach ($pairs as $pair) {
            $left = (string) ($pair['left_packet_id'] ?? '');
            $right = (string) ($pair['right_packet_id'] ?? '');
            // Order-independent pair key so left/right swap does not change hash.
            $key = $left <= $right ? $left.'|'.$right : $right.'|'.$left;
            $decisions[$key] = (string) ($pair['decision'] ?? '');
        }
        ksort($decisions);

        $seed = (string) json_encode(
            ['ids' => array_values($ids), 'decisions' => $decisions],
            JSON_UNESCAPED_SLASHES,
        );

        return 'sha256:'.hash('sha256', $seed);
    }

    /**
     * Does any allowed path expose a Voice/Kernel hot scope? (Collision Rule 4.)
     *
     * @param  array<int,string>  $allowed
     */
    private function allowsVoiceOrKernel(array $allowed): bool
    {
        foreach ($allowed as $path) {
            $needle = $this->canonical($path);
            foreach (self::VOICE_KERNEL_HOT_FRAGMENTS as $fragment) {
                if (str_contains($needle, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalize a list of paths: trim, lowercase, drop empties, dedupe, sort —
     * so overlap detection is order- and case-insensitive and deterministic.
     *
     * @param  mixed  $paths
     * @return array<int,string>
     */
    private function normalizePaths(mixed $paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        $out = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }
            $norm = strtolower(trim($path));
            if ($norm === '') {
                continue;
            }
            $out[$norm] = true;
        }

        $keys = array_keys($out);
        sort($keys);

        return $keys;
    }

    /**
     * Normalize a list of packet ids: trim, drop empties, dedupe. IDs are kept
     * case-sensitive (they are opaque identifiers, not paths).
     *
     * @param  mixed  $ids
     * @return array<int,string>
     */
    private function normalizeIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }
            $norm = trim($id);
            if ($norm === '') {
                continue;
            }
            $out[$norm] = true;
        }

        return array_keys($out);
    }

    /** Canonical form of a path for hot-fragment matching. */
    private function canonical(string $path): string
    {
        return strtolower(trim($path));
    }
}
