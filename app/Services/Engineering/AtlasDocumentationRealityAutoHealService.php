<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * C4 — COMMIT-BOUNDARY AUTO-HEAL for the documentation reality immune surface.
 *
 * The write-bound gate ({@see AtlasDocumentationRealityWriteGateService}) DETECTS an
 * over-claiming staged doc (a doc whose declared implementation_state outranks what the
 * code-intelligence index can prove) and BLOCKS the commit. The repair proposer
 * ({@see AtlasDocumentationRealityRepairProposerService}) PROPOSES the honest downgrade
 * but never applies it. This service closes that last inch: at the commit boundary it
 * AUTO-CORRECTS each over-claiming staged doc to exactly the honest computed state,
 * re-stages it, and emits an auditable receipt — so the commit can proceed with an
 * honest doc instead of being stopped or sailing through a lie.
 *
 * COMPOSES, never re-derives. The (claimed, computed, drift) triple is produced ONCE by
 * {@see AtlasAaeosImplementationTruthService::driftForFrontmatter()} (the SAME authoritative
 * per-doc evaluator the write-gate consumes — no ledger pre-filter, so empty/junk evidence
 * on a partial/verified claim still computes to spec => drift). This service resolves
 * NOTHING itself: it does not resolve evidence, recompute a tier, or re-rank. It reads the
 * triple and applies the downgrade value. The downgrade TARGET (computed_state) is identical
 * to the repair proposer's repair_options[0].to for the same doc.
 *
 * HARD SAFETY (C4 contract — this touches the commit flow; the operator stays in control):
 *   - DOWNGRADE-ONLY. It acts iff RANK[claimed] > RANK[computed] (a genuine over-claim,
 *     guaranteed by the drift verdict) and sets implementation_state to EXACTLY the computed
 *     honest state — never a raise, never below computed, never an under-claim, never an
 *     already-honest doc (no-op). The downgrade-only precondition is re-asserted defensively
 *     against the truth service's own RANK even though drift implies it.
 *   - SURGICAL. It rewrites ONLY the single implementation_state frontmatter line, by
 *     byte-offset splice of the LAST top-level occurrence inside the FIRST `---`…`---` fence
 *     (last-wins — the exact value the parser resolves; a naive first-occurrence edit on a
 *     doc with a duplicate in-fence key is a silent no-op). The doc body, every other
 *     frontmatter field, line endings and an optional BOM are byte-preserved. It NEVER
 *     round-trips through the lossy FrontmatterParser::build()/dumpYaml().
 *   - It rewrites only an EXISTING claim; if the doc has no frontmatter or no
 *     implementation_state field it is skipped (healing = correcting a claim, never authoring
 *     one).
 *   - BLIND-INDEX DEGRADE. The drift verdict resolves evidence against the code index; a
 *     PRESENT-but-EMPTY index would make every claiming doc look over-claiming, so a blind
 *     auto-downgrade would erase real runtime truth corpus-wide. It reuses the repair
 *     proposer's index-health semantics (table present + zero active rows => withhold) and
 *     refuses to heal anything when degraded.
 *   - It NEVER installs/modifies/re-enables a git hook, and it operates ONLY on the explicit
 *     repo root it is given (so the real docs/ tree is never the target unless the operator
 *     points it there). Activation is the operator's separate manual switch — out of scope.
 *   - Every heal emits an auditable receipt {doc_path, before_state, after_state=computed,
 *     reason, evidence the computation used}. No silent edits: an edit + re-stage never
 *     happens without a corresponding receipt.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-write-bound-enforcement.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
 */
final class AtlasDocumentationRealityAutoHealService
{
    public const SCHEMA = 'atlas.documentation_reality.commit_auto_heal.v1';

    public const ACTION = 'auto_heal_downgrade_state';

    /**
     * The canonical documentation area this healer is scoped to. Only staged paths
     * under this prefix ending in .md are ever considered; everything else passes
     * through untouched.
     */
    private const CANONICAL_DOC_PREFIX = 'docs/engineering-knowledge-base/';

    /**
     * The 3-tier rank, mirrored from the truth service for the defensive downgrade-only
     * re-assertion. The truth service remains the authority that PRODUCES the states; this
     * is only used to confirm RANK[claimed] > RANK[computed] before writing.
     *
     * @var array<string,int>
     */
    private const RANK = ['spec' => 0, 'partial' => 1, 'verified' => 2];

    public function __construct(
        private readonly AtlasAaeosImplementationTruthService $truth,
        private readonly CanonicalDocsFrontmatterParser $frontmatter,
    ) {}

    /**
     * Heal every over-claiming staged canonical doc in the repo rooted at $repoRoot.
     *
     * @param  bool  $dryRun  when true, compute the heals and the receipts but write
     *                        NOTHING (no file edit, no `git add`) — a preview.
     * @return array{
     *     schema_version:string,
     *     mode:string,
     *     dry_run:bool,
     *     repo_root:string,
     *     degraded:bool,
     *     degraded_reason:?string,
     *     summary:array{staged_canonical_docs:int, over_claims:int, healed:int, skipped:int},
     *     heals:array<int,array<string,mixed>>,
     *     skipped:array<int,array<string,mixed>>,
     *     receipts:array<int,array<string,mixed>>,
     *     writes:bool
     * }
     */
    public function heal(string $repoRoot, bool $dryRun = false): array
    {
        $repoRoot = rtrim($repoRoot, '/\\');

        // BLIND-INDEX DEGRADE: a present-but-empty code index would make every claim look
        // like an over-claim. Withhold ALL heals and degrade rather than mass-downgrade.
        if (! $this->indexHealthy()) {
            return $this->degradedEnvelope($repoRoot, $dryRun);
        }

        $stagedDocs = $this->stagedCanonicalDocs($repoRoot);

        $heals = [];
        $skipped = [];
        $receipts = [];

        foreach ($stagedDocs as $relPath) {
            $outcome = $this->healDoc($repoRoot, $relPath, $dryRun);

            if (($outcome['healed'] ?? false) === true) {
                $heals[] = $outcome['heal'];
                $receipts[] = $outcome['receipt'];

                continue;
            }

            $skipped[] = [
                'doc_path' => $relPath,
                'reason' => (string) ($outcome['reason'] ?? 'no_op'),
            ] + array_filter([
                'claimed_state' => $outcome['claimed_state'] ?? null,
                'computed_state' => $outcome['computed_state'] ?? null,
            ], static fn ($v): bool => $v !== null);
        }

        $overClaims = count($heals)
            + count(array_filter(
                $skipped,
                static fn (array $s): bool => ($s['reason'] ?? '') === 'over_claim_unwritable',
            ));

        return [
            'schema_version' => self::SCHEMA,
            'mode' => 'commit_boundary_doc_side_auto_heal',
            'dry_run' => $dryRun,
            'repo_root' => $repoRoot,
            'degraded' => false,
            'degraded_reason' => null,
            'summary' => [
                'staged_canonical_docs' => count($stagedDocs),
                'over_claims' => $overClaims,
                'healed' => count($heals),
                'skipped' => count($skipped),
            ],
            'heals' => $heals,
            'skipped' => $skipped,
            'receipts' => $receipts,
            'writes' => ! $dryRun && $heals !== [],
        ];
    }

    /**
     * Compute the honest state for one staged doc and, on a genuine over-claim, perform the
     * surgical downgrade + re-stage. Returns either a healed outcome (with heal + receipt) or
     * a skip outcome with a reason. Reads the doc's RAW frontmatter from the worktree file
     * under $repoRoot (which, at the commit boundary / in the sandbox, IS the staged content);
     * the honest state is computed from that frontmatter via the truth service — staged-scoped,
     * never a corpus scan, never base_path('docs/...').
     *
     * @return array<string,mixed>
     */
    private function healDoc(string $repoRoot, string $relPath, bool $dryRun): array
    {
        $abs = $repoRoot.'/'.$relPath;
        if (! is_file($abs)) {
            // A staged delete / rename-away has no worktree copy — nothing to heal.
            return ['healed' => false, 'reason' => 'absent_in_worktree'];
        }

        $raw = @file_get_contents($abs);
        if ($raw === false) {
            return ['healed' => false, 'reason' => 'unreadable'];
        }

        // PARSE-FIRST GUARD: only ever rewrite an EXISTING claim. No fence / no
        // implementation_state field => skip (inserting a field would be authoring).
        $parsed = $this->frontmatter->parse($raw);
        $errors = (array) ($parsed['errors'] ?? []);
        if (in_array('missing_frontmatter', $errors, true) || in_array('invalid_frontmatter_delimiters', $errors, true)) {
            return ['healed' => false, 'reason' => 'no_frontmatter'];
        }
        $fm = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
        if (! array_key_exists('implementation_state', $fm)) {
            return ['healed' => false, 'reason' => 'no_implementation_state_field'];
        }

        $rawState = (string) ($fm['implementation_state'] ?? '');

        // THE TRIPLE — produced ONCE by the truth service. No re-derivation here. We bind the
        // doc's CAPABILITY ID (its frontmatter id, same derivation as the ledger's
        // scanDocsWithEvidence) so the computation consults the GREEN-RUN RECEIPT gate exactly
        // as the corpus ledger does: a `verified` claim whose named test has a green-CURRENT
        // receipt legitimately computes verified (no over-claim, no heal), while the same claim
        // with only an existence-only test degrades to partial (a real over-claim). The bare
        // gate path (driftForFrontmatter, no capability) can never see a receipt and would
        // wrongly downgrade an honestly green-verified doc — so the heal is capability-bound,
        // strictly more truthful, and re-derives nothing (compute() still owns the tier+rank).
        $capabilityId = $this->capabilityId($fm, $relPath);
        $verdict = $this->truth->driftForFrontmatterBound(
            $rawState,
            $fm['evidence_refs'] ?? null,
            $capabilityId,
        );
        $claimed = (string) ($verdict['claimed_state'] ?? $rawState);
        $computed = (string) ($verdict['computed_state'] ?? 'spec');

        // Only a genuine over-claim is healed. drift === true already means
        // RANK[claimed] > RANK[computed]; the explicit rank re-check is defense in depth so a
        // future verdict-shape change can never let this raise a claim or touch an honest doc.
        $drift = ($verdict['drift'] ?? false) === true;
        if (! $drift || ! $this->isStrictDowngrade($claimed, $computed)) {
            return [
                'healed' => false,
                'reason' => $this->noOpReason($claimed, $computed),
                'claimed_state' => $claimed,
                'computed_state' => $computed,
            ];
        }

        // Surgical rewrite of ONLY the implementation_state line (last in-fence occurrence).
        $rewrite = $this->rewriteImplementationState($raw, $computed);
        if ($rewrite === null) {
            // The parser saw the field but the byte-level locator could not isolate the
            // first-fence state line (should not happen for a well-formed doc) — never guess;
            // surface it as an unwritten over-claim rather than risk a wrong edit.
            return [
                'healed' => false,
                'reason' => 'over_claim_unwritable',
                'claimed_state' => $claimed,
                'computed_state' => $computed,
            ];
        }
        [$newBytes, $oldToken] = $rewrite;

        // IDEMPOTENCE: if the bytes did not change, the doc already reads the computed token
        // on the resolved line — nothing to write or receipt.
        if ($newBytes === $raw) {
            return [
                'healed' => false,
                'reason' => 'already_honest',
                'claimed_state' => $claimed,
                'computed_state' => $computed,
            ];
        }

        $evidence = $this->evidenceSummary($verdict);
        $reason = sprintf(
            'over_claim: declared implementation_state outranks what the code index proves (claimed %s > computed %s); downgraded to the honest computed state',
            $claimed,
            $computed,
        );

        $receipt = [
            'schema_version' => self::SCHEMA,
            'action' => self::ACTION,
            'doc_path' => $relPath,
            'before_state' => $claimed,
            'before_state_raw' => trim($oldToken),
            'after_state' => $computed,
            'reason' => $reason,
            'evidence' => $evidence,
            'dry_run' => $dryRun,
        ];

        $heal = [
            'doc_path' => $relPath,
            'before_state' => $claimed,
            'after_state' => $computed,
            'field' => 'implementation_state',
            'restaged' => false,
        ];

        if ($dryRun) {
            // Preview only — never write, never stage.
            return ['healed' => true, 'heal' => $heal, 'receipt' => $receipt];
        }

        // Write the single-line byte change back, preserving everything else.
        if (@file_put_contents($abs, $newBytes) === false) {
            return [
                'healed' => false,
                'reason' => 'write_failed',
                'claimed_state' => $claimed,
                'computed_state' => $computed,
            ];
        }

        // Post-write self-check: the resolved frontmatter value is now the computed state.
        $reParsed = $this->frontmatter->parse((string) @file_get_contents($abs));
        $writtenState = (string) (($reParsed['frontmatter'] ?? [])['implementation_state'] ?? '');
        if ($this->truth->normalizeState($writtenState) !== $computed) {
            return [
                'healed' => false,
                'reason' => 'post_write_verification_failed',
                'claimed_state' => $claimed,
                'computed_state' => $computed,
            ];
        }

        // RE-STAGE so the commit captures the honest bytes (TOCTOU: the gate reads the
        // worktree but the commit writes the index — heal then re-add).
        $restaged = $this->gitAdd($repoRoot, $relPath);
        $heal['restaged'] = $restaged;
        $receipt['restaged'] = $restaged;

        return ['healed' => true, 'heal' => $heal, 'receipt' => $receipt];
    }

    /**
     * Surgical byte-offset rewrite of the implementation_state value on the LAST top-level
     * state line inside the FIRST `---`…`---` fence (the value the parser resolves). Returns
     * [newBytes, oldFullLine] or null when the first fence / state line cannot be isolated at
     * the byte level. Everything outside that one line — body, fences, an optional leading
     * BOM, any second YAML-looking block in the body, all other frontmatter lines, the line's
     * own EOL — is byte-preserved. Never normalizes line endings.
     *
     * @return array{0:string,1:string}|null
     */
    private function rewriteImplementationState(string $raw, string $computed): ?array
    {
        // Isolate the first fenced frontmatter region by byte offsets (optional BOM tolerated;
        // \r?\n EOL flavor preserved). Non-greedy: only the FIRST fence is the frontmatter.
        if (preg_match('/\A(\xEF\xBB\xBF)?---\r?\n(.*?)(\r?\n)---\r?\n/s', $raw, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        // The inner frontmatter text span is capture group 2.
        $fmText = $m[2][0];
        $fmStart = $m[2][1];
        $fmEnd = $fmStart + strlen($fmText);

        // Locate every top-level (column-0) implementation_state line within ONLY that span.
        // The line END is a CRLF-tolerant lookahead, NOT `$`: in PCRE /m the `$` anchor matches
        // immediately before a `\n` (or at end of subject), but with `[^\r\n]*` stopping before a
        // CRLF's `\r` the `$` would sit before that `\r` and fail to match — so on a CRLF doc the
        // old `$`-anchored pattern found ZERO state lines and every CRLF over-claim fell through
        // to `over_claim_unwritable`. The zero-width `(?=\r?\n|$)` lookahead matches an LF, a CRLF,
        // or end-of-fence WITHOUT consuming the EOL, so the located span still excludes the line's
        // own EOL bytes and the splice below preserves them byte-identically (the docstring's
        // "\r?\n EOL flavor preserved"). LF docs match the exact same span as before (no behavior
        // change); last-wins is preserved (preg_match_all order + end()).
        if (preg_match_all('/^implementation_state:[^\r\n]*(?=\r?\n|$)/m', $fmText, $lineMatches, PREG_OFFSET_CAPTURE) !== false
            && $lineMatches[0] !== []) {
            // LAST-WINS: target the final occurrence, matching the parser's last-wins semantics.
            $last = end($lineMatches[0]);
            $lineText = $last[0];
            $lineStartInFm = $last[1];

            $lineStart = $fmStart + $lineStartInFm;
            $lineEnd = $lineStart + strlen($lineText);

            // Defensive: the located range must sit fully inside the frontmatter span.
            if ($lineStart < $fmStart || $lineEnd > $fmEnd) {
                return null;
            }

            $replacementLine = 'implementation_state: '.$computed;
            $newBytes = substr($raw, 0, $lineStart).$replacementLine.substr($raw, $lineEnd);

            return [$newBytes, $lineText];
        }

        return null;
    }

    /**
     * Strict downgrade-only precondition: RANK[claimed] > RANK[computed]. Defensive — the
     * drift verdict already guarantees it. Unknown tokens collapse to spec via the truth
     * service's normalizer, so RANK is never indexed by an unknown key.
     */
    private function isStrictDowngrade(string $claimed, string $computed): bool
    {
        $rankClaimed = self::RANK[$this->truth->normalizeState($claimed)] ?? 0;
        $rankComputed = self::RANK[$this->truth->normalizeState($computed)] ?? 0;

        return $rankClaimed > $rankComputed;
    }

    /**
     * Why a doc was NOT healed: an under-claim (computed outranks the claim — left exactly as
     * authored, the healer is downgrade-only) or an honest claim (equal rank).
     */
    private function noOpReason(string $claimed, string $computed): string
    {
        $rankClaimed = self::RANK[$this->truth->normalizeState($claimed)] ?? 0;
        $rankComputed = self::RANK[$this->truth->normalizeState($computed)] ?? 0;

        if ($rankComputed > $rankClaimed) {
            return 'under_claim_left_as_authored';
        }

        return 'already_honest';
    }

    /**
     * The capability id for green-receipt binding, derived EXACTLY as the truth ledger's
     * scanDocsWithEvidence() does: frontmatter `id`, else `graph_id`, else the repo-relative
     * path. This is the key receipts are stored under (capability_id), so binding to it lets a
     * green-CURRENT receipt lift a `verified` claim to a legitimately-computed verified.
     *
     * @param  array<string,mixed>  $fm
     */
    private function capabilityId(array $fm, string $relPath): string
    {
        $id = trim((string) ($fm['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }
        $graphId = trim((string) ($fm['graph_id'] ?? ''));
        if ($graphId !== '') {
            return $graphId;
        }

        return $relPath;
    }

    /**
     * The evidence the computation used, surfaced for the receipt so the heal is auditable
     * and never silent: the resolved/unmet flags, the unmet-evidence messages, and the
     * per-ref resolution list straight from the truth verdict. Re-derives nothing.
     *
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    private function evidenceSummary(array $verdict): array
    {
        return [
            'resolved' => (array) ($verdict['resolved'] ?? []),
            'unmet_evidence' => array_values(array_map('strval', (array) ($verdict['unmet_evidence'] ?? []))),
            'test_resolution' => (string) ($verdict['test_resolution'] ?? 'none'),
            'evidence_refs' => array_values((array) ($verdict['evidence'] ?? [])),
        ];
    }

    /**
     * Staged canonical docs under $repoRoot, read from the staged git diff with -z
     * (NUL-delimited, no C-quoting) and -M -C so renames/copies surface both sides and
     * deletes are included. Filtered to docs/engineering-knowledge-base/*.md.
     *
     * @return array<int,string> repo-relative paths, de-duplicated
     */
    private function stagedCanonicalDocs(string $repoRoot): array
    {
        $out = $this->git($repoRoot, ['diff', '--cached', '--name-status', '-M', '-C', '-z']);
        if ($out === null) {
            return [];
        }

        $paths = [];
        foreach ($this->parseNameStatusZ($out) as [, $pathList]) {
            foreach ($pathList as $p) {
                $rel = ltrim(str_replace('\\', '/', $p), '/');
                if ($rel === '') {
                    continue;
                }
                if (stripos($rel, self::CANONICAL_DOC_PREFIX) === false) {
                    continue;
                }
                if (! str_ends_with(strtolower($rel), '.md')) {
                    continue;
                }
                $paths[] = $rel;
            }
        }

        return EngineeringStringListNormalizer::uniqueStringCasts($paths, filterEmpty: true);
    }

    /**
     * Parse `git ... --name-status -z` into [status, [paths]] records. A status token then
     * 1 path (A/M/D/T/U) or 2 (R/C: old, new). Mirrors the write-gate command's parser.
     *
     * @return array<int,array{0:string,1:array<int,string>}>
     */
    private function parseNameStatusZ(string $out): array
    {
        $tokens = explode("\0", $out);
        $records = [];
        $i = 0;
        $n = count($tokens);

        while ($i < $n) {
            $status = trim($tokens[$i]);
            if ($status === '') {
                $i++;

                continue;
            }
            $code = $status[0] ?? '';
            if ($code === 'R' || $code === 'C') {
                $old = $tokens[$i + 1] ?? '';
                $new = $tokens[$i + 2] ?? '';
                $records[] = [$status, array_values(array_filter([$old, $new], static fn (string $p): bool => $p !== ''))];
                $i += 3;

                continue;
            }
            $path = $tokens[$i + 1] ?? '';
            $records[] = [$status, $path === '' ? [] : [$path]];
            $i += 2;
        }

        return $records;
    }

    /**
     * `git -C <root> add -- <path>` (re-stage the healed worktree bytes). Returns whether the
     * add succeeded. The ONLY git mutation this service ever performs — it NEVER installs a
     * hook, commits, or touches anything but the index entry for the healed doc.
     */
    private function gitAdd(string $repoRoot, string $relPath): bool
    {
        $process = new Process(['git', '-C', $repoRoot, 'add', '--', $relPath]);
        $process->setTimeout(60.0);
        try {
            $process->run();
        } catch (Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * Run a read-only git command rooted at $repoRoot via -C, returning stdout or null.
     *
     * @param  array<int,string>  $args
     */
    private function git(string $repoRoot, array $args): ?string
    {
        $process = new Process(array_merge(['git', '-C', $repoRoot], $args));
        $process->setTimeout(60.0);
        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Reuse of {@see AtlasDocumentationRealityRepairProposerService::indexHealthy()} semantics:
     * an ABSENT symbol table is a non-production/test context (trust the verdict); a
     * PRESENT-but-EMPTY table is the dangerous blind index and must degrade so a blind
     * auto-downgrade can never erase real runtime truth.
     */
    private function indexHealthy(): bool
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            return true;
        }

        return AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->limit(1)
            ->exists();
    }

    /**
     * @return array{
     *     schema_version:string, mode:string, dry_run:bool, repo_root:string, degraded:bool,
     *     degraded_reason:?string,
     *     summary:array{staged_canonical_docs:int, over_claims:int, healed:int, skipped:int},
     *     heals:array<int,array<string,mixed>>, skipped:array<int,array<string,mixed>>,
     *     receipts:array<int,array<string,mixed>>, writes:bool
     * }
     */
    private function degradedEnvelope(string $repoRoot, bool $dryRun): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'mode' => 'commit_boundary_doc_side_auto_heal',
            'dry_run' => $dryRun,
            'repo_root' => $repoRoot,
            'degraded' => true,
            'degraded_reason' => 'code_intelligence_index_empty_or_absent_auto_heal_withheld',
            'summary' => [
                'staged_canonical_docs' => 0,
                'over_claims' => 0,
                'healed' => 0,
                'skipped' => 0,
            ],
            'heals' => [],
            'skipped' => [],
            'receipts' => [],
            'writes' => false,
        ];
    }
}
