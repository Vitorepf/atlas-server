<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;
use SplFileInfo;

/**
 * R4 keystone — computes the REAL implementation_state of a capability from the
 * code intelligence index instead of trusting the doc's self-declared prose.
 *
 * A doc claims an implementation_state and lists evidence_refs; this service
 * resolves each ref (via AtlasAaeosImplementationEvidenceResolver) and computes
 * the tier from what actually resolves. The only way to raise the computed tier
 * is to make a ref resolve — i.e. to ship the code/test/merge. Over-claim
 * (rank(claim) > rank(computed)) is flagged as drift so the AI cannot lie to
 * itself about completion.
 *
 * Tiers (atlas.aaeos.implementation_state.v1):
 *   spec(0)     -> nothing required
 *   partial(1)  -> >=1 symbol resolves AND (>=1 route OR command resolves)
 *   verified(2) -> partial AND >=1 test resolves GREEN AND >=1 receipt resolves
 *
 * B3 / criterion C2 — the `verified` tier no longer trusts a *Test* symbol merely
 * EXISTING in the index (existence-only). A test ref that resolves by existence but
 * has NO recorded GREEN run for the capability does NOT reach verified — it computes
 * to `partial` with test_resolution='existence_only_unrun'. The only thing that makes
 * a test count toward verified is a GREEN-RUN RECEIPT (AtlasAaeosTestExecutionService),
 * written by the opt-in `atlas:aaeos:verify-tests` command. Degrade-safe: if the
 * receipts table is absent, no capability is verified-by-existence (fails to partial).
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
class AtlasAaeosImplementationTruthService
{
    public const SCHEMA = 'atlas.aaeos.implementation_state.v1';

    public const LEDGER_SCHEMA = 'atlas.aaeos.capability_truth_ledger.v1';

    /**
     * @var array<string,int>
     */
    private const RANK = ['spec' => 0, 'partial' => 1, 'verified' => 2];

    public function __construct(
        private readonly AtlasAaeosImplementationEvidenceResolver $resolver,
        private readonly CanonicalDocsFrontmatterParser $frontmatter,
        private readonly AtlasAaeosTestExecutionService $testExecution = new AtlasAaeosTestExecutionService,
    ) {}

    /**
     * Scan canonical docs that declare evidence_refs and compute the capability
     * truth ledger: per-doc computed state + over-claim drift, plus a summary.
     * Shared by the atlas:aaeos:maturity command and the governance drift gate.
     *
     * @return array<string,mixed>
     */
    public function ledger(?string $capability = null): array
    {
        $docs = $this->scanDocsWithEvidence();
        if ($capability !== null && trim($capability) !== '') {
            $docs = array_values(array_filter(
                $docs,
                fn (array $doc): bool => $doc['id'] === $capability || str_contains($doc['path'], $capability),
            ));
        }

        $rows = [];
        $driftCount = 0;
        $byComputed = ['spec' => 0, 'partial' => 0, 'verified' => 0];
        // Roll up per-row test resolution into a corpus stamp (see summaryTestResolution()).
        $testBearingRows = 0;
        $greenRows = 0;

        foreach ($docs as $doc) {
            $result = $this->compute($doc['implementation_state'], $doc['evidence_refs'], $doc['id']);
            $byComputed[$result['computed_state']]++;
            if ($result['drift'] === true) {
                $driftCount++;
            }
            if (($result['resolved']['test'] ?? false) === true) {
                $testBearingRows++;
                if (($result['resolved']['test_green'] ?? false) === true) {
                    $greenRows++;
                }
            }
            $rows[] = [
                'schema_version' => self::LEDGER_SCHEMA,
                'capability_id' => $doc['id'],
                'owner_doc' => $doc['path'],
                'claimed_state' => $result['claimed_state'],
                'claimed_state_raw' => $result['claimed_state_raw'],
                'computed_state' => $result['computed_state'],
                'drift' => $result['drift'],
                'under_claim' => $result['under_claim'],
                'resolved' => $result['resolved'],
                'unmet_evidence' => $result['unmet_evidence'],
                'proof_refs_resolved' => array_values(array_filter(
                    $result['evidence'],
                    fn (array $e): bool => ($e['resolved'] ?? false) === true,
                )),
                'evidence' => $result['evidence'],
            ];
        }

        return [
            'schema_version' => self::LEDGER_SCHEMA,
            'summary' => [
                'evaluated' => count($rows),
                'drift_count' => $driftCount,
                'by_computed_state' => $byComputed,
                // Corpus-wide test-resolution stamp. 'green' ONLY when every test-bearing
                // row is backed by a green run; 'existence_only' when none are; 'mixed'
                // otherwise. Downstream that relaxes uncertainty on an exact 'green' match
                // (AtlasDocumentationRealityBidirectionalReconciliationService) therefore
                // stays fail-safe unless the whole corpus is green-proven.
                'test_resolution' => $this->summaryTestResolution($testBearingRows, $greenRows),
                'test_bearing_rows' => $testBearingRows,
                'green_run_rows' => $greenRows,
            ],
            'capabilities' => $rows,
        ];
    }

    /**
     * Roll per-row test resolution up to one corpus stamp without ever over-stating:
     * 'green' demands EVERY test-bearing row be green; a single existence-only-unrun row
     * keeps it 'mixed'; no green rows at all is 'existence_only'.
     */
    private function summaryTestResolution(int $testBearingRows, int $greenRows): string
    {
        if ($testBearingRows === 0 || $greenRows === 0) {
            return 'existence_only';
        }

        return $greenRows === $testBearingRows ? 'green' : 'mixed';
    }

    /**
     * Corpus-wide doc<->runtime coverage: how much of the governance documentation
     * is machine-verifiable. A doc "claims runtime" when its implementation_state
     * is partial/verified or its status is active/building; it is "verifiable"
     * when it declares evidence_refs the index can resolve. The gap — claims
     * runtime WITHOUT evidence_refs — is the literal "doc vs runtime 10/10" deficit.
     *
     * @return array<string,mixed>
     */
    public function coverage(): array
    {
        $root = base_path('docs/engineering-knowledge-base');
        $total = 0;
        $claimsRuntime = 0;
        $withEvidence = 0;
        $backed = 0;          // claims runtime AND has evidence_refs
        $unverifiable = 0;    // claims runtime but NO evidence_refs

        if (File::isDirectory($root)) {
            foreach (File::allFiles($root) as $file) {
                /** @var SplFileInfo $file */
                if (strtolower($file->getExtension()) !== 'md'
                    || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'archive'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $parsed = $this->frontmatter->parse(File::get($file->getPathname()));
                $fm = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
                if (($fm['doc_schema'] ?? null) === null) {
                    continue; // only canonical module docs participate
                }
                $total++;

                $state = $this->normalizeState((string) ($fm['implementation_state'] ?? ''));
                $status = strtolower((string) ($fm['status'] ?? ''));
                $claims = in_array($state, ['partial', 'verified'], true)
                    || in_array($status, ['active', 'building'], true);
                $hasEvidence = $this->normalizeEvidenceRefs($fm['evidence_refs'] ?? null) !== [];

                if ($claims) {
                    $claimsRuntime++;
                }
                if ($hasEvidence) {
                    $withEvidence++;
                }
                if ($claims && $hasEvidence) {
                    $backed++;
                }
                if ($claims && ! $hasEvidence) {
                    $unverifiable++;
                }
            }
        }

        $coveragePct = $claimsRuntime > 0
            ? (int) round($backed / $claimsRuntime * 100)
            : 100;

        return [
            'schema_version' => 'atlas.aaeos.doc_runtime_coverage.v1',
            'total_canonical_docs' => $total,
            'claims_runtime' => $claimsRuntime,
            'with_evidence_refs' => $withEvidence,
            'verifiably_backed' => $backed,
            'unverifiable_claims' => $unverifiable,
            'coverage_pct' => $coveragePct,
            'score_out_of_10' => round($coveragePct / 10, 1),
        ];
    }

    /**
     * The test refs each capability DECLARED (kind: test), with the owner doc and the
     * resolver's existence match. This is what `atlas:aaeos:verify-tests` iterates to
     * decide which named tests to actually RUN. Honors the same id/slug/path filter as
     * ledger(). `index_resolved` reflects whether the test symbol even exists (a ref the
     * index cannot resolve will never run green and is surfaced honestly).
     *
     * The returned `evidence_refs` is the capability's FULL normalized ref list — the
     * verify-tests command needs it (with the test ref) to compute the B3 freshness
     * content hashes it stamps onto each green receipt.
     *
     * @return array<int,array{capability_id:string, owner_doc:string, evidence_refs:array<int,array{kind:string,ref:string}>, test_refs:array<int,array{ref:string,index_resolved:bool,matched:?string}>}>
     */
    public function capabilityTestRefs(?string $capability = null): array
    {
        $docs = $this->scanDocsWithEvidence();
        if ($capability !== null && trim($capability) !== '') {
            $docs = array_values(array_filter(
                $docs,
                fn (array $doc): bool => $doc['id'] === $capability || str_contains($doc['path'], $capability),
            ));
        }

        $out = [];
        foreach ($docs as $doc) {
            $testRefs = [];
            foreach ($doc['evidence_refs'] as $ref) {
                if (strtolower(trim((string) ($ref['kind'] ?? ''))) !== 'test') {
                    continue;
                }
                $value = trim((string) ($ref['ref'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $resolution = $this->resolver->resolve('test', $value);
                $testRefs[] = [
                    'ref' => $value,
                    'index_resolved' => ($resolution['resolved'] ?? false) === true,
                    'matched' => $resolution['matched'] ?? null,
                ];
            }
            if ($testRefs === []) {
                continue;
            }
            $out[] = [
                'capability_id' => $doc['id'],
                'owner_doc' => $doc['path'],
                'evidence_refs' => $doc['evidence_refs'],
                'test_refs' => $testRefs,
            ];
        }

        return $out;
    }

    /**
     * Thin public passthrough over {@see scanDocsWithEvidence()} — the canonical
     * owner-doc + evidence_refs scan (capability_id = doc id), reused VERBATIM by the
     * ACOS scorecard's doc/pipeline resolver so it binds a service_class FQN to the
     * SAME evidence the truth ledger uses (one scan, no parallel doc flow). Read-only.
     *
     * @return array<int,array{id:string, path:string, implementation_state:string, evidence_refs:array<int,array{kind:string,ref:string}>}>
     */
    public function docsWithEvidence(): array
    {
        return $this->scanDocsWithEvidence();
    }

    /**
     * Scan canonical docs and keep only those declaring a non-empty evidence_refs
     * list (R4 is opt-in; absence computes to spec and is never an over-claim).
     *
     * @return array<int,array{id:string, path:string, implementation_state:string, evidence_refs:array<int,array{kind:string,ref:string}>}>
     */
    private function scanDocsWithEvidence(): array
    {
        $root = base_path('docs/engineering-knowledge-base');
        if (! File::isDirectory($root)) {
            return [];
        }

        $docs = [];
        foreach (File::allFiles($root) as $file) {
            /** @var SplFileInfo $file */
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $parsed = $this->frontmatter->parse(File::get($file->getPathname()));
            $fm = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
            $evidenceRefs = $this->normalizeEvidenceRefs($fm['evidence_refs'] ?? null);
            if ($evidenceRefs === []) {
                continue;
            }
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            $docs[] = [
                'id' => (string) ($fm['id'] ?? $fm['graph_id'] ?? $relativePath),
                'path' => $relativePath,
                'implementation_state' => (string) ($fm['implementation_state'] ?? 'spec'),
                'evidence_refs' => $evidenceRefs,
            ];
        }

        usort($docs, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $docs;
    }

    /**
     * Accept evidence_refs as a list of "kind: ref" strings (the canonical
     * frontmatter parser flattens nested maps to their first line) or {kind,ref}
     * maps. Returns a normalized list of {kind, ref}.
     *
     * @return array<int,array{kind:string, ref:string}>
     */
    private function normalizeEvidenceRefs(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $kind = trim((string) ($entry['kind'] ?? ''));
                $ref = trim((string) ($entry['ref'] ?? ''));
            } elseif (is_string($entry) && str_contains($entry, ':')) {
                [$kind, $ref] = array_map('trim', explode(':', $entry, 2));
            } else {
                continue;
            }
            if ($kind !== '' && $ref !== '') {
                $refs[] = ['kind' => $kind, 'ref' => $ref];
            }
        }

        return $refs;
    }

    /**
     * Resolve every evidence_ref against the live index, then evaluate the tier.
     *
     * When $capabilityId is provided, the test refs that resolve are checked for a
     * GREEN-RUN RECEIPT (a real passing recorded run) — this is what gates the
     * `verified` tier. With no capability id (the pure-evaluate path) the green signal
     * is unknown and the tier degrades safely (never verified-by-existence).
     *
     * @param  array<int,array{kind?:string, ref?:string}>  $evidenceRefs
     * @return array<string,mixed>
     */
    public function compute(string $claimedState, array $evidenceRefs, ?string $capabilityId = null): array
    {
        $resolutions = [];
        $resolvedTestRefs = [];
        foreach ($evidenceRefs as $ref) {
            $kind = (string) ($ref['kind'] ?? '');
            $value = (string) ($ref['ref'] ?? '');
            if (trim($kind) === '' || trim($value) === '') {
                continue;
            }
            $resolution = $this->resolver->resolve($kind, $value);
            $resolutions[] = $resolution;
            if (strtolower(trim($kind)) === 'test' && ($resolution['resolved'] ?? false) === true) {
                $resolvedTestRefs[] = trim($value);
            }
        }

        // A capability's test counts toward verified ONLY with a GREEN-CURRENT receipt:
        // a real passing run WHOSE STORED CONTENT HASHES still match the live code+test
        // (B3 freshness, criterion C2). We compute the current hashes here — cheap: it
        // hashes a few files, it NEVER runs tests — and pass them to the receipt gate so
        // a stale green (code or test edited since the run) stops granting verified.
        // Unknown (no capability id) => null => evaluate() degrades safely to not-green.
        $greenTestRun = null;
        if ($capabilityId !== null && trim($capabilityId) !== '' && $resolvedTestRefs !== []) {
            $implFilesHash = $this->currentImplFilesHash($evidenceRefs);
            $greenTestRun = false;
            foreach ($resolvedTestRefs as $testRef) {
                $testFileHash = $this->currentTestFileHash($testRef);
                if ($this->testExecution->hasGreenReceipt($capabilityId, $testRef, $testFileHash, $implFilesHash)) {
                    $greenTestRun = true;
                    break;
                }
            }
        }

        return $this->evaluate($claimedState, $resolutions, $greenTestRun);
    }

    /**
     * B3 freshness (criterion C2) — the CURRENT content hashes for a capability, used by
     * `atlas:aaeos:verify-tests` to STAMP a fresh receipt the instant it records a green
     * run, so the stored hashes equal the live files at record time. The SAME computation
     * is used on every maturity read (compute()) to detect drift — store-time and
     * read-time use one code path, so a freshly recorded receipt reads as fresh and any
     * later edit reads as stale.
     *
     * @param  array<int,array{kind?:string, ref?:string}>  $evidenceRefs
     * @return array{test_file_hash:?string, impl_files_hash:?string}
     */
    public function freshnessHashes(array $evidenceRefs, string $testRef): array
    {
        return [
            'test_file_hash' => $this->currentTestFileHash($testRef),
            'impl_files_hash' => $this->currentImplFilesHash($evidenceRefs),
        ];
    }

    /**
     * sha256 over the CONTENT of the implementation file(s) the capability's
     * {kind: symbol} evidence_refs resolve to (symbol file_path(s) from the index),
     * combined deterministically. Returns null when the capability declares no symbol
     * ref (nothing to bind to). Degrade-safe: a resolved path whose file is missing or
     * unreadable contributes a stable "missing:" sentinel so the combined hash still
     * CHANGES vs a real hash — a once-proven file that is later deleted reads as stale,
     * never silently fresh.
     *
     * @param  array<int,array{kind?:string, ref?:string}>  $evidenceRefs
     */
    private function currentImplFilesHash(array $evidenceRefs): ?string
    {
        $paths = [];
        foreach ($evidenceRefs as $ref) {
            if (strtolower(trim((string) ($ref['kind'] ?? ''))) !== 'symbol') {
                continue;
            }
            $value = trim((string) ($ref['ref'] ?? ''));
            if ($value === '') {
                continue;
            }
            foreach ($this->resolver->resolveSymbolFilePaths($value) as $path) {
                $paths[$path] = true;
            }
        }

        if ($paths === []) {
            return null;
        }

        $paths = array_keys($paths);
        sort($paths);

        $parts = [];
        foreach ($paths as $path) {
            $parts[] = $path.'='.$this->hashFileContent($path);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * sha256 of the CONTENT of the test class FILE a {kind: test} ref resolves to.
     * Returns null when no test file resolves. Degrade-safe: a resolved-but-unreadable
     * file yields a "missing:" sentinel hash that can never equal a real stored hash, so
     * the receipt reads as stale rather than silently fresh.
     */
    private function currentTestFileHash(string $testRef): ?string
    {
        $path = $this->resolver->resolveTestFilePath($testRef);
        if ($path === null) {
            return null;
        }

        return $this->hashFileContent($path);
    }

    /**
     * Hash one indexed (relative) file's content. Missing/unreadable -> a deterministic
     * sentinel that never collides with a real content hash (so deletion/tamper => stale).
     */
    private function hashFileContent(string $relativePath): string
    {
        $absolute = base_path($relativePath);
        if (! is_file($absolute) || ! is_readable($absolute)) {
            return 'missing:'.hash('sha256', $relativePath);
        }

        $contents = @file_get_contents($absolute);
        if ($contents === false) {
            return 'missing:'.hash('sha256', $relativePath);
        }

        return hash('sha256', $contents);
    }

    /**
     * Drift verdict for a single doc straight from its RAW frontmatter (the value
     * as authored: a list of "kind: ref" strings and/or {kind,ref} maps, possibly
     * empty or all-junk). Normalizes refs exactly like the ledger, then computes
     * tier + over-claim — but WITHOUT the ledger's pre-filter, so a partial/verified
     * doc whose refs are empty OR all-unresolvable (junk strings/bools) is still
     * correctly flagged as over-claim. The ADRS write-bound gate calls this per
     * touched doc so a naked or junk-evidence claim cannot slip the boundary.
     *
     * @return array<string,mixed> the compute() result (drift, claimed_state, computed_state, ...)
     */
    public function driftForFrontmatter(string $implementationState, mixed $rawEvidenceRefs): array
    {
        return $this->compute($implementationState, $this->normalizeEvidenceRefs($rawEvidenceRefs));
    }

    /**
     * Same as {@see driftForFrontmatter()} but CAPABILITY-BOUND: the computation consults the
     * GREEN-RUN RECEIPT gate for $capabilityId, exactly as the corpus ledger does per doc. This
     * is the seam the commit-boundary auto-heal needs so a `verified` claim backed by a
     * green-CURRENT receipt legitimately computes verified (no over-claim), while an
     * existence-only test still degrades to partial. The raw frontmatter evidence_refs (a list
     * of "kind: ref" strings and/or {kind,ref} maps) is normalized the same way the ledger
     * normalizes, then handed to compute() — which alone owns tier + rank + drift. Re-derives
     * nothing here.
     *
     * @return array<string,mixed> the compute() result (drift, claimed_state, computed_state, ...)
     */
    public function driftForFrontmatterBound(string $implementationState, mixed $rawEvidenceRefs, ?string $capabilityId): array
    {
        return $this->compute(
            $implementationState,
            $this->normalizeEvidenceRefs($rawEvidenceRefs),
            $capabilityId,
        );
    }

    /**
     * Pure tier + drift computation from already-resolved evidence. Exposed for
     * unit testing without touching the database. Never fabricates: an
     * unresolved ref simply does not contribute to any tier.
     *
     * B3 / criterion C2 — the `verified` tier requires the test to be GREEN, not
     * merely present. $greenTestRun is the per-capability green-receipt signal:
     *   true  -> a real passing recorded run backs the resolved test.
     *   false -> a test resolved (symbol exists) but has NO green receipt: this is
     *            existence-only and must NOT reach verified.
     *   null  -> green-ness is UNKNOWN (the pure-evaluate path, no capability context,
     *            or the receipts table is absent). Degrade-safe: treated as NOT green,
     *            so an existence-only match can never silently keep verified.
     * The honest distinction is carried in test_resolution ('green_run' vs
     * 'existence_only_unrun' vs 'none') and resolved.test_green — the computed_state
     * vocabulary stays the closed 3-tier set so drift + every downstream switch hold.
     *
     * @param  array<int,array{kind:string, ref:string, resolved:bool, matched:?string}>  $resolutions
     * @return array<string,mixed>
     */
    public function evaluate(string $claimedState, array $resolutions, ?bool $greenTestRun = null): array
    {
        $resolvedKinds = [];
        foreach ($resolutions as $resolution) {
            if (($resolution['resolved'] ?? false) === true) {
                $resolvedKinds[(string) ($resolution['kind'] ?? '')] = true;
            }
        }

        $hasSymbol = $resolvedKinds['symbol'] ?? false;
        $hasWiring = ($resolvedKinds['route'] ?? false) || ($resolvedKinds['command'] ?? false);
        $hasTest = $resolvedKinds['test'] ?? false;
        $hasReceipt = $resolvedKinds['receipt'] ?? false;

        // A test counts toward verified ONLY when it resolved AND a green run backs it.
        // $greenTestRun null/false => not green => existence-only never reaches verified.
        $hasGreenTest = $hasTest && ($greenTestRun === true);

        $computed = ($hasSymbol && $hasWiring && $hasGreenTest && $hasReceipt)
            ? 'verified'
            : (($hasSymbol && $hasWiring) ? 'partial' : 'spec');

        // Per-row test-resolution honesty stamp.
        $testResolution = match (true) {
            $hasGreenTest => 'green_run',
            // A test symbol matched but no green run proves it — the lie this kills.
            $hasTest => 'existence_only_unrun',
            default => 'none',
        };

        $unmet = [];
        if ($computed === 'spec') {
            if (! $hasSymbol) {
                $unmet[] = 'needs >=1 resolved symbol (class/method) for partial';
            }
            if (! $hasWiring) {
                $unmet[] = 'needs >=1 resolved route or command for partial';
            }
        } elseif ($computed === 'partial') {
            if (! $hasTest) {
                $unmet[] = 'needs >=1 resolved test for verified';
            } elseif (! $hasGreenTest) {
                // The test EXISTS but never ran green — the existence-only gap.
                $unmet[] = 'needs >=1 test that RAN GREEN for verified — a test symbol resolves but has no green-run receipt (run atlas:aaeos:verify-tests)';
            }
            if (! $hasReceipt) {
                $unmet[] = 'needs >=1 resolved receipt (evidence file) for verified';
            }
        }

        $claimed = $this->normalizeState($claimedState);
        $rankClaimed = self::RANK[$claimed];
        $rankComputed = self::RANK[$computed];

        return [
            'schema_version' => self::SCHEMA,
            'claimed_state' => $claimed,
            'claimed_state_raw' => trim($claimedState),
            'computed_state' => $computed,
            'rank_claimed' => $rankClaimed,
            'rank_computed' => $rankComputed,
            // Over-claim: the doc claims MORE than the index can prove. This is the
            // blocking condition. Under-claim (computed > claimed) is fine (a warning).
            'drift' => $rankClaimed > $rankComputed,
            'under_claim' => $rankComputed > $rankClaimed,
            'resolved' => [
                'symbol' => $hasSymbol,
                'wiring' => $hasWiring,
                'test' => $hasTest,
                // test_green is the load-bearing new signal: a resolved test that is
                // NOT green-backed (existence-only) reports test=true, test_green=false.
                'test_green' => $hasGreenTest,
                'receipt' => $hasReceipt,
            ],
            'unmet_evidence' => $unmet,
            'evidence' => array_values($resolutions),
            'test_resolution' => $testResolution,
        ];
    }

    /**
     * Map the existing implementation_state vocabulary (and the gap-matrix
     * runtime taxonomy) onto the 3-tier rank. Anything not clearly verified or
     * partial collapses to spec — the safe, never-over-claim default.
     */
    public function normalizeState(string $state): string
    {
        $normalized = strtolower(trim($state));

        if (in_array($normalized, ['verified', 'runtime_verified', 'solid_runtime'], true)) {
            return 'verified';
        }

        if (in_array($normalized, ['partial', 'implemented_partial', 'partial_runtime'], true)) {
            return 'partial';
        }

        // spec_only, north_star, roadmap_only_no_runtime, backlog_only_no_runtime,
        // spec_runtime_gap, drift_risk, '', unknown -> spec.
        return 'spec';
    }
}
