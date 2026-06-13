<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Services\Ai\Policy\PolicyCanon;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Self-Construction trust ladder: a per-change-CLASS record of re-checkable evidence
 * (frozen-judge passes, clean promotions) that lets a class EARN lower friction over
 * time — the scaffolding for Atlas to safely earn the right to improve itself.
 *
 * Two pétreo sovereignty properties, structural:
 *   1. DEFAULT = MAX FRICTION. With no operator-configured thresholds (the default),
 *      every class stays at AUTONOMY_SUGGEST — operator approval for everything.
 *      Autonomy rises ONLY past thresholds the OPERATOR sets, from evidence.
 *   2. NEVER above the risk cap. earnedAutonomy() tops out at AUTONOMOUS, and
 *      admission re-binds it under the risk-canon cap (min), so it can only relax
 *      friction WITHIN the cap, never beyond it.
 *
 * Trust is asymmetric: a single revert resets the class's clean streak to zero —
 * friction returns immediately. Trust accrues only from re-checkable evidence,
 * never from a provider self-report.
 */
final class AtlasChangeClassTrustLadder
{
    public const SCHEMA = 'atlas.ai.change_class_trust_ladder.v1';

    public const EVIDENCE_FROZEN_JUDGE_PASS = 'frozen_judge_pass';

    public const EVIDENCE_CLEAN_PROMOTION = 'clean_promotion';

    public const EVIDENCE_REVERT = 'revert';

    private ?string $logOverride = null;

    public function setLogPathForTesting(?string $path): void
    {
        $this->logOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logOverride !== null) {
            return $this->logOverride;
        }
        // Config seam: lets the operator/tests pin the canonical ledger path explicitly
        // (one seam for the production feed and isolated frozen tests). Absent ⇒ canonical
        // storage path. Never silently uses temp in production (storage_path always exists).
        $configured = trim((string) config('atlas.ai.trust_ladder.log_path', ''));
        if ($configured !== '') {
            return $configured;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'change_class_trust.jsonl';
    }

    /**
     * Real-history feed: translate a CONCRETE Loop auto-merge OUTCOME into ladder
     * evidence so the class can auto-green from real merges — never from a self-report.
     *
     * This is the missing wire of L6-14: until this is called by the real merge pipeline,
     * the production ledger sits at entry_count=0 forever and NO class can ever earn
     * autonomy. It is deliberately STRUCTURAL about what it will and won't accrue:
     *
     *   - Gated: does nothing unless the trust ladder is enabled (default OFF).
     *   - Evidence-only: a clean promotion accrues ONLY when a REAL commit landed
     *     ($commit non-empty) AND the post-merge canary was NOT red. The commit SHA is
     *     the distinct re-checkable ref, so re-draining the same merge cannot inflate.
     *   - Asymmetric & automatic: a red canary (the v2 regression signal — fix-forward,
     *     never auto-revert) records a REVERT that resets the class streak to zero. This
     *     is the DoD's "a regression in the class REVERTS the trust automatically".
     *   - Class-derived from the real diff, never operator-claimed: the change_class is
     *     computed from the changed file paths (documentation_only / tests_only /
     *     docs_and_tests / code). The release policy (allowlist + blocked patterns) still
     *     decides whether the class is even eligible — `code` is never allowlisted by
     *     default, so ordinary code merges accrue history but earn nothing until the
     *     operator allowlists the class.
     *
     * Best-effort and side-effect-pure w.r.t. the merge: the merge already happened and
     * this never throws back into it (the caller wraps it, and the guards here are total).
     *
     * @param  list<string>  $changedFiles  repo-relative paths the merge touched
     * @param  array{ran?:bool,passed?:bool|null,target?:string|null}  $canary
     */
    public function recordMergeOutcome(array $changedFiles, ?string $commit, array $canary): string
    {
        if (! (bool) config('atlas.ai.trust_ladder.enabled', false)) {
            return '';
        }
        $class = $this->classifyChangedFiles($changedFiles);
        if ($class === '') {
            return '';
        }

        // A red canary is the only regression signal in merge-livre v2 (the policy never
        // auto-reverts; it fix-forwards). It must asymmetrically revoke trust. The revert
        // is intentionally NOT keyed on a distinct ref — every regression resets, and the
        // closed vocabulary already makes a revert un-fabricable as a clean evidence.
        if (($canary['ran'] ?? false) === true && ($canary['passed'] ?? null) === false) {
            $this->recordEvidence($class, self::EVIDENCE_REVERT, 'canary-red:'.($commit !== null && $commit !== '' ? $commit : 'uncommitted'));

            return $class;
        }

        // A clean promotion requires a REAL landed commit. No commit ⇒ nothing happened
        // that can be re-checked, so nothing accrues (refuses to fabricate a green).
        if ($commit === null || trim($commit) === '') {
            return '';
        }
        $this->recordEvidence($class, self::EVIDENCE_CLEAN_PROMOTION, 'commit:'.trim($commit));

        return $class;
    }

    /**
     * Derive a change class from the real changed-file set. Conservative by construction:
     * a single non-docs/non-tests file makes the whole merge `code` (never allowlisted by
     * default), so a class only stays `documentation_only` / `tests_only` when EVERY
     * touched file is of that kind. Empty set ⇒ '' (nothing to classify).
     *
     * @param  list<string>  $changedFiles
     */
    public function classifyChangedFiles(array $changedFiles): string
    {
        $files = [];
        foreach ($changedFiles as $f) {
            $f = strtolower(trim(str_replace('\\', '/', (string) $f)));
            if ($f !== '') {
                $files[] = $f;
            }
        }
        if ($files === []) {
            return '';
        }

        $allDocs = true;
        $allTests = true;
        $sawDocs = false;
        $sawTests = false;
        foreach ($files as $f) {
            $isDoc = str_ends_with($f, '.md') || str_starts_with($f, 'docs/');
            $isTest = str_ends_with($f, 'test.php') || str_contains($f, '/tests/') || str_starts_with($f, 'tests/');
            $sawDocs = $sawDocs || $isDoc;
            $sawTests = $sawTests || $isTest;
            $allDocs = $allDocs && $isDoc;
            $allTests = $allTests && $isTest;
            if (! $isDoc && ! $isTest) {
                return 'code';
            }
        }

        if ($allDocs) {
            return 'documentation_only';
        }
        if ($allTests) {
            return 'tests_only';
        }
        if ($sawDocs && $sawTests) {
            return 'docs_and_tests';
        }

        return 'code';
    }

    public function recordEvidence(string $changeClass, string $kind, ?string $ref = null): void
    {
        $class = trim($changeClass);
        if ($class === '') {
            return;
        }
        // Only the closed evidence vocabulary accrues — a fabricated kind cannot
        // advance the streak (the "evidence-only" guarantee is structural here, not
        // merely whitelisted in the CLI).
        if (! in_array($kind, [self::EVIDENCE_FROZEN_JUDGE_PASS, self::EVIDENCE_CLEAN_PROMOTION, self::EVIDENCE_REVERT], true)) {
            return;
        }
        $isClean = $kind !== self::EVIDENCE_REVERT;
        // Re-checkable evidence MUST carry a real id (an acceptance_hash / receipt_hash)
        // and be DISTINCT — so re-running the same frozen proof cannot inflate the
        // streak. This makes "evidence, never a self-report" structural, not accidental.
        if ($isClean && ($ref === null || trim($ref) === '')) {
            return;
        }
        if ($isClean && $this->hasRef($class, (string) $ref)) {
            return;
        }
        $this->append([
            'schema_version' => self::SCHEMA,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'change_class' => $class,
            'evidence_kind' => $kind,
            'clean' => $isClean,
            'ref' => $ref,
        ]);
    }

    private function hasRef(string $changeClass, string $ref): bool
    {
        foreach ($this->read() as $e) {
            if ((string) ($e['change_class'] ?? '') === $changeClass
                && (string) ($e['ref'] ?? '') === $ref
                && ($e['clean'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** Consecutive clean evidences since the last revert (a revert resets to 0). */
    public function cleanStreak(string $changeClass): int
    {
        $class = trim($changeClass);
        $streak = 0;
        foreach ($this->read() as $e) {
            if ((string) ($e['change_class'] ?? '') !== $class) {
                continue;
            }
            $streak = ($e['clean'] ?? false) === true ? $streak + 1 : 0;
        }

        return $streak;
    }

    /**
     * The autonomy a class has EARNED. Default (no thresholds) = SUGGEST (max
     * friction). Never above AUTONOMOUS; admission re-binds under the risk cap.
     */
    public function earnedAutonomy(string $changeClass): string
    {
        if ((array) $this->releasePolicy($changeClass)['blockers'] !== []) {
            return PolicyCanon::AUTONOMY_SUGGEST;
        }

        $streak = $this->cleanStreak($changeClass);
        $t = (array) config('atlas.ai.trust_ladder.thresholds', []);

        if ($streak >= $this->threshold($t, 'autonomous')) {
            return PolicyCanon::AUTONOMY_AUTONOMOUS;
        }
        if ($streak >= $this->threshold($t, 'execute_with_approval')) {
            return PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL;
        }
        if ($streak >= $this->threshold($t, 'draft')) {
            return PolicyCanon::AUTONOMY_DRAFT;
        }

        return PolicyCanon::AUTONOMY_SUGGEST;
    }

    /**
     * @return array{schema_version:string,change_class:string,clean_streak:int,earned_autonomy:string}
     */
    public function snapshot(string $changeClass): array
    {
        $policy = $this->releasePolicy($changeClass);

        return [
            'schema_version' => self::SCHEMA,
            'change_class' => trim($changeClass),
            'clean_streak' => $this->cleanStreak($changeClass),
            'earned_autonomy' => $this->earnedAutonomy($changeClass),
            'release_policy' => $policy,
        ];
    }

    /**
     * @return array{eligible:bool,blockers:list<string>,eligible_classes:list<string>,blocked_class_patterns:list<string>}
     */
    public function releasePolicy(string $changeClass): array
    {
        $class = $this->normalizeClass($changeClass);
        $eligibleClasses = $this->stringListConfig('atlas.ai.trust_ladder.eligible_classes');
        $blockedPatterns = $this->stringListConfig('atlas.ai.trust_ladder.blocked_class_patterns');

        $blockers = [];
        if ($class === '') {
            $blockers[] = 'change_class_missing';
        }

        foreach ($blockedPatterns as $pattern) {
            if ($pattern !== '' && $class !== '' && str_contains($class, $pattern)) {
                $blockers[] = 'blocked_class_pattern:'.$pattern;
            }
        }

        if ($eligibleClasses !== [] && $class !== '' && ! in_array($class, $eligibleClasses, true)) {
            $blockers[] = 'change_class_not_allowlisted';
        }

        return [
            'eligible' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'eligible_classes' => $eligibleClasses,
            'blocked_class_patterns' => $blockedPatterns,
        ];
    }

    /**
     * @param  array<string,mixed>  $thresholds
     */
    private function threshold(array $thresholds, string $key): int
    {
        $v = $thresholds[$key] ?? null;

        // Absent / non-positive => unreachable (disabled) => the tier never unlocks.
        return is_numeric($v) && (int) $v > 0 ? (int) $v : PHP_INT_MAX;
    }

    /**
     * @return list<string>
     */
    private function stringListConfig(string $key): array
    {
        $value = config($key, []);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }
            $normalized = $this->normalizeClass((string) $item);
            if ($normalized !== '') {
                $out[] = $normalized;
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeClass(string $class): string
    {
        return strtolower(trim($class));
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function append(array $row): void
    {
        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $this->logPath(),
            $row,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            FILE_APPEND,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function read(): array
    {
        return AppendOnlyJsonlStore::read($this->logPath());
    }
}
