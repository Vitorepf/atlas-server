<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Single-writer guard (operator mandate, 2026-05-31): the autonomous loop must
 * run in a DEDICATED git worktree, never mutate the canonical/human checkout.
 * Two agents (the loop + the operator/another agent) writing the same working
 * tree is a proven hazard — interleaved staging, swept WIP, lane divergence.
 *
 * This decider is PURE: the caller resolves the paths + whether the action is
 * mutating (the {@see LoopWorktreeTopologyVerifierService} classifies repo_root);
 * this class only decides allowed|refused.
 *
 * Rules:
 *   - Read-only / readiness (is_mutating=false): ALWAYS allowed (readiness must
 *     run anywhere, incl. the canonical checkout).
 *   - Mutating on the canonical checkout: REFUSED with blocker
 *     canonical_worktree_write_refused — UNLESS the operator explicitly opts in
 *     with allow_canonical_worktree_write=true (single-machine intentional run).
 *   - Mutating on a dedicated loop worktree (not canonical): allowed.
 *   - Fail-SAFE: if topology could not classify repo_root (degraded/non-git,
 *     on_canonical=false and on_dedicated_loop_worktree=false), a mutating run is
 *     ALLOWED but flagged unverified — the guard never fabricates a block from an
 *     unknown topology (mirrors RuntimeClassConsumptionScanner's degraded stance).
 *     The caller (verifier) is responsible for setting on_canonical=true on a
 *     genuine canonical checkout so this guard catches the case that matters.
 */
final class CanonicalWorktreeWriteGuard
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.canonical_worktree_write_guard.v1';

    public const BLOCKER = 'canonical_worktree_write_refused';

    public const DECISION_ALLOWED = 'allowed';

    public const DECISION_REFUSED = 'refused';

    /**
     * @param  array<string,mixed>  $input  {repo_root, canonical_root, loop_worktree_root, on_canonical?:bool, on_dedicated_loop_worktree?:bool, is_mutating:bool, allow_canonical_worktree_write?:bool}
     * @return array{schema_version:string, decision:string, is_mutating:bool, on_canonical:bool, on_dedicated_loop_worktree:bool, allow_opt_in:bool, topology_verified:bool, blocker:string|null, reason:string}
     */
    public function decide(array $input): array
    {
        $isMutating = (bool) ($input['is_mutating'] ?? true);
        $allowOptIn = (bool) ($input['allow_canonical_worktree_write'] ?? false);

        // on_canonical may be supplied directly by the verifier; otherwise derive
        // from a realpath comparison of repo_root vs canonical_root.
        $repoRoot = $this->norm((string) ($input['repo_root'] ?? ''));
        $canonicalRoot = $this->norm((string) ($input['canonical_root'] ?? ''));
        $loopWorktreeRoot = $this->norm((string) ($input['loop_worktree_root'] ?? ''));

        $onCanonical = array_key_exists('on_canonical', $input)
            ? (bool) $input['on_canonical']
            : ($repoRoot !== '' && $canonicalRoot !== '' && $repoRoot === $canonicalRoot);
        $onDedicated = array_key_exists('on_dedicated_loop_worktree', $input)
            ? (bool) $input['on_dedicated_loop_worktree']
            : ($repoRoot !== '' && $loopWorktreeRoot !== '' && $repoRoot === $loopWorktreeRoot);

        $topologyVerified = $onCanonical || $onDedicated;

        // Read-only / readiness runs everywhere.
        if (! $isMutating) {
            return $this->result(self::DECISION_ALLOWED, $isMutating, $onCanonical, $onDedicated, $allowOptIn, $topologyVerified, null, 'read_only_allowed_on_any_worktree');
        }

        // Mutating on the canonical checkout: refuse unless explicitly opted in.
        if ($onCanonical) {
            if ($allowOptIn) {
                return $this->result(self::DECISION_ALLOWED, $isMutating, $onCanonical, $onDedicated, $allowOptIn, $topologyVerified, null, 'canonical_write_allowed_by_explicit_opt_in');
            }

            return $this->result(self::DECISION_REFUSED, $isMutating, $onCanonical, $onDedicated, $allowOptIn, $topologyVerified, self::BLOCKER, 'mutating_run_on_canonical_checkout_refused_run_in_dedicated_loop_worktree_or_opt_in');
        }

        if ($onDedicated) {
            return $this->result(self::DECISION_ALLOWED, $isMutating, $onCanonical, $onDedicated, $allowOptIn, $topologyVerified, null, 'mutating_run_on_dedicated_loop_worktree_allowed');
        }

        // Fail-safe: topology could not classify repo_root. Allow but flag
        // unverified rather than fabricate a block from an unknown topology.
        return $this->result(self::DECISION_ALLOWED, $isMutating, $onCanonical, $onDedicated, $allowOptIn, $topologyVerified, null, 'topology_unverified_mutating_run_allowed_unenforced');
    }

    /**
     * @return array{schema_version:string, decision:string, is_mutating:bool, on_canonical:bool, on_dedicated_loop_worktree:bool, allow_opt_in:bool, topology_verified:bool, blocker:string|null, reason:string}
     */
    private function result(string $decision, bool $isMutating, bool $onCanonical, bool $onDedicated, bool $allowOptIn, bool $topologyVerified, ?string $blocker, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'is_mutating' => $isMutating,
            'on_canonical' => $onCanonical,
            'on_dedicated_loop_worktree' => $onDedicated,
            'allow_opt_in' => $allowOptIn,
            'topology_verified' => $topologyVerified,
            'blocker' => $blocker,
            'reason' => $reason,
        ];
    }

    private function norm(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $real = realpath($path);

        return rtrim($real !== false ? $real : $path, '/');
    }
}
