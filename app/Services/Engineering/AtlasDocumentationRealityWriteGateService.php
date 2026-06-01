<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CanonicalWorktreeWriteGuard;
use Throwable;

/**
 * ADRS L0 write-bound enforcement gate (operator mandate, 2026-06-01): ADRS is
 * the immune system of an AI-built codebase, but its checks have run read-only /
 * pipeline-bound. This decider binds ONE fail-closed verdict to a proposed change
 * at the commit boundary, over the canonical documentation surface only.
 *
 * It STRENGTHENS existing primitives — it never reimplements docs-health or the
 * AAEOS truth ledger. Reused contracts:
 *   - {@see EngineeringDocumentationHealthService::report()} — "blocking" is the
 *     ratchet output: NEW violation strings vs the frozen baseline. "legacy_debt"
 *     is the frozen debt and is IGNORED here (blocking on it would freeze every
 *     commit on debt this change did not introduce). Each violation string begins
 *     with the doc repo-relative path: "<path>: <message>".
 *   - {@see AtlasAaeosImplementationTruthService::ledger()} — per-doc over-claim
 *     drift for docs that DECLARE evidence_refs; a touched owner_doc with
 *     drift===true means the doc claims more than the code index can prove.
 *   - {@see AtlasAaeosImplementationTruthService::compute()} with EMPTY refs —
 *     catches the naked over-claim the ledger SKIPS (implementation_state
 *     partial/verified while declaring NO evidence_refs: it scans nothing yet
 *     claims runtime). Empty refs need no normalization, so this is safe to call
 *     directly with the touched doc's frontmatter state.
 *
 * This class is a PURE decider (mirrors {@see CanonicalWorktreeWriteGuard}):
 * constants + a single decide(); no filesystem, no git of its own. The caller
 * (the command) resolves touched paths, partially-staged paths, and per-doc
 * frontmatter; this class only adjudicates.
 *
 * Hardened 2026-06-01 after an adversarial pass closed five bypasses:
 *   1. Over-attribution / case bypass: docs-health violations are matched to a
 *      touched doc ONLY by the violation's leading "<path>:" subject token, case-
 *      INSENSITIVELY and EXACTLY — never by loose substring (several docs-health
 *      messages embed OTHER docs' full paths in their body, which both produced
 *      false positives and let a case-variant staged path dodge attribution).
 *   2. Naked over-claim: partial/verified with empty evidence_refs now blocks.
 *   3. TOCTOU: a touched canonical doc whose worktree differs from the index
 *      (partially staged) yields NEEDS_REVIEW — the analyzers read the worktree,
 *      so a split view must not be trusted as the committed bytes.
 *   (Rename/delete blindness and fail-open NEEDS_REVIEW are closed in the command
 *    and hook respectively; this decider treats NEEDS_REVIEW as fail-closed.)
 *
 * Fail posture:
 *   - is_mutating === false  -> ALLOWED (readiness/read-only runs everywhere).
 *   - no canonical docs touched -> ALLOWED (a code-only change passes THIS gate;
 *     the gate guards the doc immune surface, not all of engineering).
 *   - a touched canonical doc is partially staged (index != worktree) ->
 *     NEEDS_REVIEW (cannot trust a split view; fail to a human).
 *   - a touched canonical doc carries a NEW docs-health blocker, an over-claim
 *     drift row, or a naked over-claim -> BLOCKED (the immune surface regressed).
 *   - a collaborator throws -> NEEDS_REVIEW (fail-to-human; an internal error
 *     must never silently allow a doc regression through the boundary).
 *   - touched docs clean -> ALLOWED.
 */
final class AtlasDocumentationRealityWriteGateService
{
    public const SCHEMA_VERSION = 'atlas.documentation_reality.write_gate.v1';

    public const BLOCKER = 'adrs_write_bound_violation';

    public const DECISION_ALLOWED = 'allowed';

    public const DECISION_BLOCKED = 'blocked';

    public const DECISION_NEEDS_REVIEW = 'needs_review';

    /**
     * The canonical documentation area this gate guards. Only paths under this
     * prefix ending in .md participate; everything else is out of scope for the
     * doc immune surface and passes through untouched.
     */
    private const CANONICAL_DOC_PREFIX = 'docs/engineering-knowledge-base/';

    public function __construct(
        private readonly EngineeringDocumentationHealthService $docsHealth,
        private readonly AtlasAaeosImplementationTruthService $truth,
    ) {}

    /**
     * @param  array<string,mixed>  $input  {
     *                                      touched_paths: array<int,string>,
     *                                      is_mutating?: bool,
     *                                      touched_frontmatter?: array<string,array{implementation_state?:string, evidence_refs?:array<int,mixed>}>,
     *                                      partially_staged_paths?: array<int,string>
     *                                      }
     * @return array{schema_version:string, decision:string, is_mutating:bool, touched_doc_count:int, new_docs_health_blockers:array<int,string>, drift_blockers:array<int,array{owner_doc:string,claimed_state:mixed,computed_state:mixed,source:string}>, blocker:string|null, reason:string}
     */
    public function decide(array $input): array
    {
        $isMutating = (bool) ($input['is_mutating'] ?? true);

        $touchedDocs = $this->canonicalDocsTouched(
            is_array($input['touched_paths'] ?? null) ? $input['touched_paths'] : []
        );

        // Read-only / readiness runs everywhere; nothing is being written.
        if (! $isMutating) {
            return $this->result(self::DECISION_ALLOWED, $isMutating, $touchedDocs, [], [], null, 'read_only_allowed');
        }

        // A code-only change (no canonical doc touched) passes THIS gate.
        if ($touchedDocs === []) {
            return $this->result(self::DECISION_ALLOWED, $isMutating, $touchedDocs, [], [], null, 'no_canonical_docs_touched');
        }

        // TOCTOU guard: a touched canonical doc whose worktree differs from the
        // index (partially staged) cannot be trusted — the analyzers read the
        // worktree, but the commit writes the index. Fail to a human.
        $partial = $this->intersectDocs(
            is_array($input['partially_staged_paths'] ?? null) ? $input['partially_staged_paths'] : [],
            $touchedDocs,
        );
        if ($partial !== []) {
            return $this->result(
                self::DECISION_NEEDS_REVIEW, $isMutating, $touchedDocs, [], [], self::BLOCKER,
                'partially_staged_canonical_doc_index_differs_from_worktree',
            );
        }

        $frontmatter = is_array($input['touched_frontmatter'] ?? null) ? $input['touched_frontmatter'] : [];

        try {
            $report = $this->docsHealth->report();
            $ledger = $this->truth->ledger();
            $newBlockersOnTouched = $this->newBlockersOnTouched($report, $touchedDocs);
            $driftBlockers = $this->driftBlockersOnTouched($ledger, $touchedDocs);
            $nakedBlockers = $this->nakedOverClaimsOnTouched($frontmatter, $touchedDocs);
        } catch (Throwable $e) {
            // Fail-to-human: an internal error in a collaborator must never be
            // read as "clean". Hand the verdict to a person rather than allow.
            return $this->result(
                self::DECISION_NEEDS_REVIEW,
                $isMutating,
                $touchedDocs,
                [],
                [],
                self::BLOCKER,
                'gate_inputs_unavailable_'.$this->errorSlug($e),
            );
        }

        $allDrift = array_merge($driftBlockers, $nakedBlockers);

        if ($newBlockersOnTouched !== [] || $allDrift !== []) {
            return $this->result(
                self::DECISION_BLOCKED,
                $isMutating,
                $touchedDocs,
                $newBlockersOnTouched,
                $allDrift,
                self::BLOCKER,
                'touched_canonical_doc_regressed_immune_surface',
            );
        }

        return $this->result(self::DECISION_ALLOWED, $isMutating, $touchedDocs, [], [], null, 'touched_docs_clean');
    }

    /**
     * Normalize touched paths to repo-relative and keep ONLY canonical docs
     * (under docs/engineering-knowledge-base/ ending in .md). Case-insensitive
     * prefix detection (the default APFS/Windows filesystems are case-insensitive
     * and the git index can carry a case-variant path); anchored to the LAST
     * occurrence of the prefix so a wrapper segment cannot mis-anchor the path.
     *
     * @param  array<int,mixed>  $touchedPaths
     * @return array<int,string>
     */
    private function canonicalDocsTouched(array $touchedPaths): array
    {
        $docs = [];
        foreach ($touchedPaths as $raw) {
            $path = $this->normalize((string) $raw);
            if ($path === '') {
                continue;
            }
            if (stripos($path, self::CANONICAL_DOC_PREFIX) === false) {
                continue;
            }
            if (! str_ends_with(strtolower($path), '.md')) {
                continue;
            }
            $lastOffset = strripos($path, self::CANONICAL_DOC_PREFIX);
            $docs[] = $lastOffset === false ? $path : substr($path, $lastOffset);
        }

        return array_values(array_unique($docs));
    }

    /**
     * Strip a leading absolute base path and "./" so callers can pass either
     * absolute or repo-relative paths. Backslashes are normalized to forward
     * slashes for cross-platform diff input.
     */
    private function normalize(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = str_replace('\\', '/', $path);

        $base = str_replace('\\', '/', rtrim(base_path(), '/\\')).'/';
        if (str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return ltrim($path, '/');
    }

    /**
     * NEW docs-health blockers (never legacy_debt — the frozen ratchet floor)
     * whose SUBJECT doc is one of the touched docs. A docs-health violation string
     * is "<path>: <message>"; attribution is by the leading "<path>:" token ONLY,
     * compared case-insensitively and EXACTLY. A loose substring match would both
     * mis-charge a clean touched doc whose path appears inside another doc's
     * violation message AND let a case-variant staged path dodge attribution.
     *
     * @param  array<string,mixed>  $report
     * @param  array<int,string>  $touchedDocs
     * @return array<int,string>
     */
    private function newBlockersOnTouched(array $report, array $touchedDocs): array
    {
        $blocking = array_values(array_filter(
            array_map(static fn (mixed $v): string => (string) $v, (array) ($report['blocking'] ?? [])),
            static fn (string $v): bool => $v !== '',
        ));

        $touchedLc = array_fill_keys(array_map('strtolower', $touchedDocs), true);

        $hits = [];
        foreach ($blocking as $violation) {
            $subject = $this->violationSubjectPath($violation);
            if ($subject !== '' && isset($touchedLc[strtolower($subject)])) {
                $hits[] = $violation;
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * The leading "<repo-relative-path>:" subject token of a docs-health
     * violation string, normalized. Empty when the string has no path prefix.
     */
    private function violationSubjectPath(string $violation): string
    {
        $pos = strpos($violation, ': ');
        $candidate = $pos === false ? $violation : substr($violation, 0, $pos);

        return $this->normalize($candidate);
    }

    /**
     * Over-claim drift rows from the AAEOS truth ledger whose owner_doc is one of
     * the touched docs (case-insensitive) AND drift === true. Covers docs that
     * DECLARE evidence_refs which do not resolve to the claimed tier. Under-claim
     * is never a block.
     *
     * @param  array<string,mixed>  $ledger
     * @param  array<int,string>  $touchedDocs
     * @return array<int,array{owner_doc:string,claimed_state:mixed,computed_state:mixed,source:string}>
     */
    private function driftBlockersOnTouched(array $ledger, array $touchedDocs): array
    {
        $touchedLc = array_fill_keys(array_map('strtolower', $touchedDocs), true);
        $rows = [];

        foreach ((array) ($ledger['capabilities'] ?? []) as $capability) {
            if (! is_array($capability)) {
                continue;
            }
            if (($capability['drift'] ?? false) !== true) {
                continue;
            }
            $ownerDoc = $this->normalize((string) ($capability['owner_doc'] ?? ''));
            if ($ownerDoc === '' || ! isset($touchedLc[strtolower($ownerDoc)])) {
                continue;
            }
            $rows[] = [
                'owner_doc' => $ownerDoc,
                'claimed_state' => $capability['claimed_state'] ?? null,
                'computed_state' => $capability['computed_state'] ?? null,
                'source' => 'ledger_evidence_drift',
            ];
        }

        return array_values($rows);
    }

    /**
     * Naked over-claims: a touched doc whose frontmatter claims runtime
     * (implementation_state partial/verified) while declaring NO evidence_refs.
     * The ledger SKIPS empty-evidence docs, so this is the bypass that lets prose
     * shout "shipped/verified" with nothing structured to prove it. compute()
     * with EMPTY refs needs no normalization and applies the same state ranking,
     * so a partial/verified-without-evidence doc computes to spec => drift.
     *
     * @param  array<string,array{implementation_state?:string, evidence_refs?:array<int,mixed>}>  $frontmatter
     * @param  array<int,string>  $touchedDocs
     * @return array<int,array{owner_doc:string,claimed_state:mixed,computed_state:mixed,source:string}>
     */
    private function nakedOverClaimsOnTouched(array $frontmatter, array $touchedDocs): array
    {
        $byDoc = [];
        foreach ($frontmatter as $doc => $fm) {
            if (is_array($fm)) {
                $byDoc[strtolower($this->normalize((string) $doc))] = $fm;
            }
        }

        $rows = [];
        foreach ($touchedDocs as $doc) {
            $fm = $byDoc[strtolower($doc)] ?? null;
            if ($fm === null) {
                continue; // no frontmatter supplied (e.g. a deleted doc) — docs-health covers absence
            }
            $refs = is_array($fm['evidence_refs'] ?? null) ? array_values($fm['evidence_refs']) : [];
            if ($refs !== []) {
                continue; // declares evidence — handled by the ledger drift path
            }
            $state = (string) ($fm['implementation_state'] ?? '');
            $res = $this->truth->compute($state, []);
            if (($res['drift'] ?? false) === true) {
                $rows[] = [
                    'owner_doc' => $doc,
                    'claimed_state' => $res['claimed_state'] ?? $state,
                    'computed_state' => $res['computed_state'] ?? 'spec',
                    'source' => 'naked_claim_no_evidence',
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * Repo-relative touched docs present in a candidate path list, matched
     * case-insensitively.
     *
     * @param  array<int,mixed>  $candidates
     * @param  array<int,string>  $touchedDocs
     * @return array<int,string>
     */
    private function intersectDocs(array $candidates, array $touchedDocs): array
    {
        $touchedLc = array_fill_keys(array_map('strtolower', $touchedDocs), true);
        $hits = [];
        foreach ($candidates as $raw) {
            $p = $this->normalize((string) $raw);
            if ($p !== '' && isset($touchedLc[strtolower($p)])) {
                $hits[] = $p;
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @param  array<int,string>  $touchedDocs
     * @param  array<int,string>  $newBlockers
     * @param  array<int,array{owner_doc:string,claimed_state:mixed,computed_state:mixed,source:string}>  $driftBlockers
     * @return array{schema_version:string, decision:string, is_mutating:bool, touched_doc_count:int, new_docs_health_blockers:array<int,string>, drift_blockers:array<int,array{owner_doc:string,claimed_state:mixed,computed_state:mixed,source:string}>, blocker:string|null, reason:string}
     */
    private function result(
        string $decision,
        bool $isMutating,
        array $touchedDocs,
        array $newBlockers,
        array $driftBlockers,
        ?string $blocker,
        string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'is_mutating' => $isMutating,
            'touched_doc_count' => count($touchedDocs),
            'new_docs_health_blockers' => $newBlockers,
            'drift_blockers' => $driftBlockers,
            'blocker' => $blocker,
            'reason' => $reason,
        ];
    }

    private function errorSlug(Throwable $e): string
    {
        $short = strtolower((new \ReflectionClass($e))->getShortName());
        $slug = preg_replace('/[^a-z0-9]+/', '_', $short) ?? 'error';

        return trim($slug, '_') ?: 'error';
    }
}
