<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use Symfony\Component\Process\Process;

/**
 * Worktree topology verifier (operator mandate, 2026-05-31). Read-only/honest:
 * it NEVER merges, deletes, prunes or mutates anything — it only classifies the
 * git worktree topology and PROPOSES governed cleanup. It answers:
 *   - is repo_root the canonical/human checkout (git's main working tree)?
 *   - is a dedicated loop worktree present, and on what controller branch?
 *   - is the canonical worktree clean?
 *   - how many stale/orphan sandbox worktrees linger, and what is the cleanup plan?
 *
 * Canonical detection: `git worktree list --porcelain` always lists git's MAIN
 * working tree first; that path is the canonical checkout. Fail-SAFE: in a
 * degraded/non-git env canonical_root resolves to '' and {@see isCanonicalCheckout()}
 * returns false, so the single-writer guard ALLOWS rather than fabricating a block.
 *
 * Tests inject the raw porcelain via the `worktree_list_porcelain` / `status_porcelain`
 * input seams (mirrors LoopProcessIsolationStatusService::mergeFixture()).
 */
final class LoopWorktreeTopologyVerifierService
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.loop_worktree_topology.v1';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_BLOCKED = 'blocked';

    private const SANDBOX_MARKER = 'area_focus_branch_sandboxes';

    private const CONTROLLER_PREFIXES = ['atlas/loop-runner/', 'atlas/loop-controller/'];

    /**
     * Predicate used by {@see CanonicalWorktreeWriteGuard}. Returns false when the
     * canonical root cannot be resolved (degraded/non-git) so the guard fails safe.
     *
     * @param  array<string,mixed>  $seam
     */
    public function isCanonicalCheckout(string $repoRoot, array $seam = []): bool
    {
        $canonical = $this->canonicalRoot($repoRoot, $seam);

        return $canonical !== '' && $this->norm($repoRoot) === $canonical;
    }

    /**
     * @param  array<string,mixed>  $input  {repo_root, loop_worktree_root?, area?, worktree_list_porcelain?, status_porcelain?}
     * @return array<string,mixed>
     */
    public function verify(array $input): array
    {
        $repoRoot = $this->norm((string) ($input['repo_root'] ?? ''));
        $expectedLoopRoot = $this->norm((string) ($input['loop_worktree_root'] ?? ''));
        $worktrees = $this->parseWorktrees($repoRoot, $input);

        if ($worktrees === []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => self::STATUS_BLOCKED,
                'repo_root' => $repoRoot,
                'canonical_root' => '',
                'loop_worktree_root' => $expectedLoopRoot,
                'is_canonical_checkout' => false,
                'canonical_clean' => null,
                'loop_worktree_present' => false,
                'loop_branch_ref' => '',
                'stale_count' => 0,
                'cleanup_plan' => [],
                'blockers' => ['repo_root_not_git_or_no_worktrees'],
                'claim_policy' => $this->claimPolicy(),
            ];
        }

        $canonicalRoot = $worktrees[0]['path'];
        $isCanonical = $canonicalRoot !== '' && $repoRoot === $canonicalRoot;

        // The dedicated loop worktree = a linked worktree on a controller branch
        // (or matching the expected path). Sandbox scratch worktrees are excluded.
        $loopWorktree = null;
        foreach (array_slice($worktrees, 1) as $wt) {
            if (str_contains($wt['path'], self::SANDBOX_MARKER)) {
                continue;
            }
            if (($expectedLoopRoot !== '' && $wt['path'] === $expectedLoopRoot) || $this->isControllerBranch($wt['branch'])) {
                $loopWorktree = $wt;
                break;
            }
        }

        // Stale/orphan = sandbox scratch worktrees still registered (transient by
        // design; their presence is the pollution to propose cleaning).
        $stale = array_values(array_filter(
            $worktrees,
            static fn (array $wt): bool => str_contains($wt['path'], self::SANDBOX_MARKER),
        ));
        $cleanupPlan = array_map(
            static fn (array $wt): string => 'propose_governed_cleanup_via_ap756:'.$wt['path'].($wt['branch'] !== '' ? ' ('.$wt['branch'].')' : ''),
            $stale,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => self::STATUS_VERIFIED,
            'repo_root' => $repoRoot,
            'canonical_root' => $canonicalRoot,
            'loop_worktree_root' => $loopWorktree['path'] ?? $expectedLoopRoot,
            'is_canonical_checkout' => $isCanonical,
            'canonical_clean' => $this->canonicalClean($canonicalRoot, $input),
            'loop_worktree_present' => $loopWorktree !== null,
            'loop_branch_ref' => $loopWorktree['branch'] ?? '',
            'stale_count' => count($stale),
            'cleanup_plan' => array_values($cleanupPlan),
            'blockers' => [],
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array{path:string,branch:string,detached:bool}>
     */
    private function parseWorktrees(string $repoRoot, array $input): array
    {
        $porcelain = array_key_exists('worktree_list_porcelain', $input)
            ? (string) $input['worktree_list_porcelain']
            : (string) ($this->git($repoRoot, ['worktree', 'list', '--porcelain'])['out'] ?? '');

        if (trim($porcelain) === '') {
            return [];
        }

        $worktrees = [];
        $current = null;
        foreach (preg_split('/\R/', $porcelain) ?: [] as $line) {
            if (str_starts_with($line, 'worktree ')) {
                if ($current !== null) {
                    $worktrees[] = $current;
                }
                $current = ['path' => $this->norm(substr($line, 9)), 'branch' => '', 'detached' => false];
            } elseif ($current !== null && str_starts_with($line, 'branch ')) {
                $current['branch'] = preg_replace('#^refs/heads/#', '', trim(substr($line, 7))) ?? '';
            } elseif ($current !== null && trim($line) === 'detached') {
                $current['detached'] = true;
            }
        }
        if ($current !== null) {
            $worktrees[] = $current;
        }

        return $worktrees;
    }

    private function canonicalRoot(string $repoRoot, array $seam): string
    {
        $worktrees = $this->parseWorktrees($this->norm($repoRoot), $seam);

        return $worktrees[0]['path'] ?? '';
    }

    private function canonicalClean(string $canonicalRoot, array $input): ?bool
    {
        if (array_key_exists('status_porcelain', $input)) {
            return trim((string) $input['status_porcelain']) === '';
        }
        if ($canonicalRoot === '') {
            return null;
        }
        $result = $this->git($canonicalRoot, ['status', '--porcelain']);
        if ($result['ok'] !== true) {
            return null;
        }

        return trim((string) $result['out']) === '';
    }

    private function isControllerBranch(string $branch): bool
    {
        foreach (self::CONTROLLER_PREFIXES as $prefix) {
            if (str_starts_with($branch, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'merge_performed' => false,
            'branch_deleted' => false,
            'worktree_removed' => false,
            'prune_performed' => false,
            'read_only' => true,
        ];
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,out:string,err:string}
     */
    private function git(string $cwd, array $args): array
    {
        if ($cwd === '') {
            return ['ok' => false, 'out' => '', 'err' => 'empty_cwd'];
        }
        try {
            $process = new Process(array_merge(['git'], $args), $cwd);
            $process->setTimeout(30);
            $process->run();

            return ['ok' => $process->isSuccessful(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'out' => '', 'err' => $e->getMessage()];
        }
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
