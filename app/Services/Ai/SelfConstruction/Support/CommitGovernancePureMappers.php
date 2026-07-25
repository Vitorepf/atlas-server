<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;

/**
 * Pure mappers peeled from {@see \App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain}.
 *
 * No FS, DI, clock, policy plane, or ledger I/O — only deterministic string/array transforms
 * the chain composes around organ classification, admission, and ledger recording.
 * The chain keeps policy resolution, ledger appends, and kernel authority issuance.
 */
final class CommitGovernancePureMappers
{
    /**
     * Statuses the verification gate explicitly did not attribute to this task.
     * They must never become this worker's unmet obligation.
     *
     * @var list<string>
     */
    public const EXEMPT_CHECK_STATUSES = [
        'fail_unattributed_open',
        'skip_infra',
        'fail_open_runner_error',
    ];

    private function __construct()
    {
    }

    /**
     * Map changed file paths to the human-readable organ labels the RiskClassifier scores.
     *
     * @param  list<string>  $changed
     * @return list<string>
     */
    public static function touchedOrgans(array $changed): array
    {
        $organs = [];
        foreach ($changed as $path) {
            if (str_contains($path, 'MergeGovernor')) {
                $organs['Merge Governor'] = true;
            }
            if (str_contains($path, 'VerificationCourt')) {
                $organs['Verification Court'] = true;
            }
            if (str_contains($path, 'AgentControlPlane') || str_contains($path, 'TaskServing') || str_contains($path, 'TaskPacket') || str_contains($path, 'AtlasTaskQueue') || str_contains($path, 'TaskQueueOrchestrator')) {
                $organs['Task Fabric'] = true;
            }
            if (str_contains($path, 'HarnessGuard') || str_contains($path, 'Constitution')) {
                $organs['Constitution'] = true;
            }
            if (str_contains($path, 'MasterSwitch') || str_contains($path, 'LoopMasterSwitch')) {
                $organs['MasterSwitch'] = true;
            }
            if (str_contains($path, 'WorkspaceMaterializer')) {
                $organs['WorkspaceMaterializer'] = true;
            }
        }

        return array_keys($organs);
    }

    /**
     * Distinct directory roots of the changed files — used as the project-lane scope roots
     * so the rollback plan is lane-conformant (every affected file lives under a declared root).
     *
     * @param  list<string>  $changed
     * @return list<string>
     */
    public static function scopeRoots(array $changed): array
    {
        $roots = [];
        foreach ($changed as $path) {
            $dir = trim(dirname($path), '.');
            if ($dir !== '' && $dir !== '/') {
                $roots[$dir] = true;
            }
        }

        return $roots === [] ? ['.'] : array_keys($roots);
    }

    /**
     * Normalize changed-file paths: forward slashes, strip leading slash, drop empties, de-dupe.
     *
     * @param  list<string>|array<mixed>  $files
     * @return list<string>
     */
    public static function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            $p = ltrim(trim(str_replace('\\', '/', (string) $f)), '/');
            if ($p !== '') {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }

    /** The AdmissionPolicy decision space ⇒ the VerdictLedger's {passed,failed,blocked} enum. */
    public static function decisionToVerdict(string $decision): string
    {
        return match ($decision) {
            AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED => AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED,
            AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED => AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED,
            default => AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED,
        };
    }

    /**
     * @param  array<string,mixed>  $rollback
     */
    public static function rollbackPosture(array $rollback): string
    {
        if (($rollback['conformant'] ?? false) === true) {
            $strategy = (string) ($rollback['facts']['restore_strategy'] ?? 'git_revert_scoped_commit');

            return 'revertible:'.$strategy;
        }

        $blockers = array_values(array_map('strval', (array) ($rollback['blockers'] ?? [])));

        return 'blocked:'.($blockers !== [] ? implode(',', $blockers) : 'rollback_not_conformant');
    }

    /**
     * @param  array<string,mixed>  $recorded
     * @return list<string>
     */
    public static function ledgerErrorBlockers(array $recorded): array
    {
        $blockers = [];
        foreach (['verdict_ledger', 'release_ledger'] as $key) {
            $status = (string) ($recorded[$key] ?? 'error');
            if ($status === 'error' || str_starts_with($status, 'error:')) {
                $blockers[] = $key.'_error';
            }
        }

        return $blockers;
    }

    /**
     * Compares a policy-declared required-check set against the checks that ACTUALLY ran.
     * A required check that ran and was recorded as anything other than 'pass' (and is not
     * an attribution-exempt status) is a missing rerun. A required check that never appears
     * in $checks is left alone (not flagged) — additive safety net over observed skip/fail.
     * An empty required set yields empty (byte-identical to "nothing required").
     *
     * Policy resolution (requiredChecksFor) stays on the chain — this method is pure comparison.
     *
     * @param  list<string>  $required
     * @param  array<string,string>  $checks
     * @return list<string>
     */
    public static function missingRerun(array $required, array $checks): array
    {
        if ($required === []) {
            return [];
        }

        $missing = [];
        foreach ($required as $check) {
            if (! array_key_exists($check, $checks)) {
                continue;
            }
            $status = strtolower(trim((string) $checks[$check]));
            if (in_array($status, self::EXEMPT_CHECK_STATUSES, true)) {
                continue;
            }
            if ($status !== 'pass') {
                $missing[] = $check;
            }
        }

        return $missing;
    }

    public static function deterministicHash(mixed $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
