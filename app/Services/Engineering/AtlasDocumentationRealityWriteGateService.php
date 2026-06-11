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
 *   - {@see AtlasAaeosImplementationTruthService::driftForFrontmatter()} — the
 *     authoritative per-doc over-claim verdict from a touched doc's RAW frontmatter
 *     (state + evidence_refs as authored). It normalizes refs the same way the
 *     ledger does, then computes tier vs claim — but WITHOUT the ledger's pre-filter,
 *     so empty OR all-unresolvable (junk) evidence on a partial/verified doc still
 *     computes to spec => drift. One path closes both the empty-evidence and the
 *     junk-evidence naked-claim bypasses.
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
            $newBlockersOnTouched = $this->newBlockersOnTouched($report, $touchedDocs);
            $driftBlockers = $this->overClaimsOnTouched($frontmatter, $touchedDocs);
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

        if ($newBlockersOnTouched !== [] || $driftBlockers !== []) {
            return $this->result(
                self::DECISION_BLOCKED,
                $isMutating,
                $touchedDocs,
                $newBlockersOnTouched,
                $driftBlockers,
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
     * and the git index can carry a case-variant path). The normalized path is
     * kept verbatim — git already emits repo-relative paths that match the
     * report()/ledger() naming, so there is NO re-anchor (re-anchoring would
     * corrupt a doc physically nested under a same-named directory).
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
            // git emits repo-relative paths and normalize() strips any base path,
            // so the path already matches the report()/ledger() naming exactly. Do
            // NOT re-anchor to an occurrence of the prefix — that would corrupt a
            // doc physically nested under a same-named directory.
            $docs[] = $path;
        }

        return EngineeringStringListNormalizer::uniqueStringCasts($docs);
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
     * attributable to the change. A docs-health violation string is
     * "<path>: <message>" and may EMBED other canonical doc paths in its body
     * (e.g. "B.md: duplicate graph_id [x] already used by <A.md>", or
     * "X.md: authority chain must reference <Y.md>"). A touched doc is a plausible
     * CAUSE when its WHOLE repo-relative path appears anywhere in a NEW blocker —
     * as the subject OR as a referenced path — so an edit to A that collides with
     * an untouched B (the blocker keyed to B) is still caught. Matching extracts
     * complete docs/.../*.md path tokens (NOT loose substrings, so atlas-foo.md
     * does not match atlas-foobar.md) and compares them case-insensitively.
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
            foreach ($this->canonicalPathsIn($violation) as $path) {
                if (isset($touchedLc[strtolower($path)])) {
                    $hits[] = $violation;
                    break;
                }
            }
        }

        return EngineeringStringListNormalizer::uniqueStringCasts($hits);
    }

    /**
     * Every WHOLE docs/engineering-knowledge-base/*.md path token in a string,
     * normalized and de-duplicated. Bounded extraction (a path token ends at the
     * first ".md" and cannot run past whitespace/punctuation) so a path can never
     * match a longer path that merely shares its prefix.
     *
     * @return array<int,string>
     */
    private function canonicalPathsIn(string $text): array
    {
        $pattern = '#'.preg_quote(self::CANONICAL_DOC_PREFIX, '#').'[^\s\]\)\},;:"\'`]+?\.md#i';
        if (preg_match_all($pattern, $text, $matches) === false) {
            return [];
        }

        $out = [];
        foreach ($matches[0] as $raw) {
            $p = $this->normalize($raw);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return EngineeringStringListNormalizer::uniqueStringCasts($out);
    }

    /**
     * Over-claim rows for touched docs, computed PER DOC from the caller-supplied
     * RAW frontmatter via the truth service's authoritative evaluator
     * {@see AtlasAaeosImplementationTruthService::driftForFrontmatter()}. ONE path,
     * ONE normalization — it replaces both the old ledger filter (which SKIPPED a
     * doc whose evidence was empty or all-junk) and the count-keyed naked check
     * (which trusted any non-empty list). A touched doc claiming partial/verified
     * whose evidence_refs are empty OR all-unresolvable (junk strings, malformed
     * maps, non-existent symbols) computes to spec => drift => blocked. A deleted
     * doc (no frontmatter supplied) is covered by docs-health absence, not here.
     *
     * @param  array<string,array{implementation_state?:string, evidence_refs?:mixed}>  $frontmatter
     * @param  array<int,string>  $touchedDocs
     * @return array<int,array{owner_doc:string,claimed_state:mixed,computed_state:mixed,source:string}>
     */
    private function overClaimsOnTouched(array $frontmatter, array $touchedDocs): array
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
            $state = (string) ($fm['implementation_state'] ?? '');
            $res = $this->truth->driftForFrontmatter($state, $fm['evidence_refs'] ?? null);
            if (($res['drift'] ?? false) === true) {
                $rows[] = [
                    'owner_doc' => $doc,
                    'claimed_state' => $res['claimed_state'] ?? $state,
                    'computed_state' => $res['computed_state'] ?? 'spec',
                    'source' => 'frontmatter_over_claim',
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

        return EngineeringStringListNormalizer::uniqueStringCasts($hits);
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
