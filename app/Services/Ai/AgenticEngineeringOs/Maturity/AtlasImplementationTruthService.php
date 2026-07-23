<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\AgenticEngineeringOs\Support\AtlasEvidenceRefNormalizer;

/**
 * R4 keystone — computes the REAL implementation_state of a capability from the
 * code intelligence index instead of trusting the doc's self-declared prose.
 *
 * A doc claims an implementation_state and lists evidence_refs; this service
 * resolves each ref (via AtlasImplementationEvidenceResolver) and computes
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
 * a test count toward verified is a GREEN-RUN RECEIPT (AtlasCapabilityTestExecutionService),
 * written by the opt-in `atlas:aeos:verify-tests` command. Degrade-safe: if the
 * receipts table is absent, no capability is verified-by-existence (fails to partial).
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
class AtlasImplementationTruthService
{
    public const FIELD_ID = 'id';
    public const FIELD_RANK_COMPUTED = 'rank_computed';
    public const SCHEMA = 'atlas.aaeos.implementation_state.v1';

    public const LEDGER_SCHEMA = 'atlas.aaeos.capability_truth_ledger.v1';

    /** B3 freshness format — v2 anchors path set to canonical FQN (PIP-01); v1 receipts read stale honestly. */
    public const IMPL_FILES_HASH_FORMAT = 'atlas.aaeos.impl_files_hash.v2';

    public const DOC_RUNTIME_COVERAGE_SCHEMA = 'atlas.aaeos.doc_runtime_coverage.v1';

    /**
     * @var array<string,int>
     */
    public const LEVEL_SPEC = 'spec';

    public const LEVEL_PARTIAL = 'partial';

    public const LEVEL_VERIFIED = 'verified';

    public const LEVEL_EXISTENCE_ONLY = 'existence_only';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BUILDING = 'building';

    public const TEST_RESOLUTION_GREEN = 'green';

    public const TEST_RESOLUTION_MIXED = 'mixed';
    public const FIELD_RESOLVED = 'resolved';
    public const FIELD_EVIDENCE_REFS = 'evidence_refs';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_REF = 'ref';
    public const FIELD_KIND = 'kind';
    public const FIELD_IMPLEMENTATION_STATE = 'implementation_state';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_DRIFT = 'drift';
    public const FIELD_CAPABILITY_ID = 'capability_id';
    public const FIELD_OWNER_DOC = 'owner_doc';
    public const FIELD_CLAIMED_STATE = 'claimed_state';
    public const FIELD_CLAIMED_STATE_RAW = 'claimed_state_raw';
    public const FIELD_COMPUTED_STATE = 'computed_state';
    public const FIELD_UNDER_CLAIM = 'under_claim';
    public const FIELD_UNMET_EVIDENCE = 'unmet_evidence';
    public const FIELD_TEST_RESOLUTION = 'test_resolution';
    public const FIELD_IMPL_FILES_HASH = 'impl_files_hash';

    public const TEST_RESOLUTION_EXISTENCE_ONLY_UNRUN = 'existence_only_unrun';
    public const FIELD_PROOF_REFS_RESOLVED = 'proof_refs_resolved';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_EVALUATED = 'evaluated';
    public const FIELD_DRIFT_COUNT = 'drift_count';
    public const FIELD_BY_COMPUTED_STATE = 'by_computed_state';
    public const FIELD_TEST_BEARING_ROWS = 'test_bearing_rows';
    public const FIELD_GREEN_RUN_ROWS = 'green_run_rows';
    public const FIELD_CAPABILITIES = 'capabilities';
    public const FIELD_CLAIMS_RUNTIME = 'claims_runtime';
    public const FIELD_COMMAND = 'command';
    public const FIELD_COVERAGE_PCT = 'coverage_pct';
    public const FIELD_DOC_SCHEMA = 'doc_schema';
    public const FIELD_FORMAT = 'format';
    public const FIELD_FRONTMATTER = 'frontmatter';
    public const FIELD_PATH = 'path';
    public const FIELD_TEST = 'test';
    public const FIELD_MATCHED = 'matched';
    public const FIELD_SYMBOL = 'symbol';
    public const FIELD_TEST_GREEN = 'test_green';
    public const FIELD_RECEIPT = 'receipt';
    public const FIELD_GRAPH_ID = 'graph_id';
    public const FIELD_INDEX_RESOLVED = 'index_resolved';
    public const FIELD_TOTAL_CANONICAL_DOCS = 'total_canonical_docs';
    public const FIELD_WITH_EVIDENCE_REFS = 'with_evidence_refs';
    public const FIELD_PATHS = 'paths';
    public const FIELD_RANK_CLAIMED = 'rank_claimed';
    public const FIELD_ROUTE = 'route';
    public const FIELD_SCORE_OUT_OF_10 = 'score_out_of_10';
    public const FIELD_STATUS = 'status';
    public const FIELD_TEST_FILE_HASH = 'test_file_hash';
    public const FIELD_TEST_REFS = 'test_refs';
    public const FIELD_UNVERIFIABLE_CLAIMS = 'unverifiable_claims';
    public const FIELD_VERIFIABLY_BACKED = 'verifiably_backed';
    public const FIELD_WIRING = 'wiring';
    public const FIELD_GREEN_RUN = 'green_run';
    public const FIELD_NONE = 'none';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_IMPLEMENTED_PARTIAL = 'implemented_partial';
    public const FIELD_RUNTIME_VERIFIED = 'runtime_verified';
    public const FIELD_MD = 'md';
    public const FIELD_ARCHIVE = 'archive';
    public const FIELD_PARTIAL_RUNTIME = 'partial_runtime';
    public const FIELD_SOLID_RUNTIME = 'solid_runtime';
    public const FIELD_DOCS_ENGINEERING_KNOWLEDGE_BASE = 'docs/engineering-knowledge-base';
    public const FIELD_NEEDS___1_RESOLVED_ROUTE_OR_COMMAND_FOR_PARTIAL = 'needs >=1 resolved route or command for partial';
    public const FIELD_NEEDS___1_RESOLVED_SYMBOL__CLASS_METHOD__FOR_PARTIAL = 'needs >=1 resolved symbol (class/method) for partial';
    public const FIELD_NEEDS___1_RESOLVED_TEST_FOR_VERIFIED = 'needs >=1 resolved test for verified';
    public const FIELD_NEEDS___1_TEST_THAT_RAN_GREEN_FOR_VERIFIED___A_TEST_SYMBOL_RESOLVES_BUT_HAS_NO_GREEN_RUN_RECEIPT__RUN_ATLAS_AAEOS_VERIFY_TESTS_ = 'needs >=1 test that RAN GREEN for verified — a test symbol resolves but has no green-run receipt (run atlas:aeos:verify-tests)';
    public const FIELD_NEEDS___1_RESOLVED_RECEIPT__EVIDENCE_FILE__FOR_VERIFIED = 'needs >=1 resolved receipt (evidence file) for verified';
    public const INT_2 = 2;

    public const RANK = [
        self::LEVEL_SPEC => 0,
        self::LEVEL_PARTIAL => 1,
        self::LEVEL_VERIFIED => self::INT_2,
    ];

    public function __construct(
        private readonly AtlasImplementationEvidenceResolver $resolver,
        private readonly CanonicalDocsFrontmatterParser $frontmatter,
        private readonly AtlasCapabilityTestExecutionService $testExecution = new AtlasCapabilityTestExecutionService,
        private readonly AtlasEvidenceRefNormalizer $evidenceRefNormalizer = new AtlasEvidenceRefNormalizer,
    ) {}

    /**
     * Scan canonical docs that declare evidence_refs and compute the capability
     * truth ledger: per-doc computed state + over-claim drift, plus a summary.
     * Shared by the atlas:aeos:maturity command and the governance drift gate.
     *
     * @return array<string,mixed>
     */
    public function ledger(?string $capability = null): array
    {
        $docs = $this->scanDocsWithEvidence();
        if ($capability !== null && AiValueNormalizer::trimmedStringOrNull($capability) !== null) {
            $docs = array_values(array_filter(
                $docs,
                fn (array $doc): bool => $doc[self::FIELD_ID] === $capability || str_contains($doc[self::FIELD_PATH], $capability),
            ));
        }

        $rows = [];
        $driftCount = 0;
        $byComputed = [self::LEVEL_SPEC => 0, self::LEVEL_PARTIAL => 0, self::LEVEL_VERIFIED => 0];
        // Roll up per-row test resolution into a corpus stamp (see summaryTestResolution()).
        $testBearingRows = 0;
        $greenRows = 0;

        foreach ($docs as $doc) {
            $result = $this->compute($doc[self::FIELD_IMPLEMENTATION_STATE], $doc[self::FIELD_EVIDENCE_REFS], $doc[self::FIELD_ID]);
            $byComputed[$result[self::FIELD_COMPUTED_STATE]]++;
            if ($result[self::FIELD_DRIFT] === true) {
                $driftCount++;
            }
            if (($result[self::FIELD_RESOLVED][self::FIELD_TEST] ?? false) === true) {
                $testBearingRows++;
                if (($result[self::FIELD_RESOLVED][self::FIELD_TEST_GREEN] ?? false) === true) {
                    $greenRows++;
                }
            }
            $rows[] = [
                self::FIELD_SCHEMA_VERSION => self::LEDGER_SCHEMA,
                self::FIELD_CAPABILITY_ID => $doc[self::FIELD_ID],
                self::FIELD_OWNER_DOC => $doc[self::FIELD_PATH],
                self::FIELD_CLAIMED_STATE => $result[self::FIELD_CLAIMED_STATE],
                self::FIELD_CLAIMED_STATE_RAW => $result[self::FIELD_CLAIMED_STATE_RAW],
                self::FIELD_COMPUTED_STATE => $result[self::FIELD_COMPUTED_STATE],
                self::FIELD_DRIFT => $result[self::FIELD_DRIFT],
                self::FIELD_UNDER_CLAIM => $result[self::FIELD_UNDER_CLAIM],
                self::FIELD_RESOLVED => $result[self::FIELD_RESOLVED],
                self::FIELD_UNMET_EVIDENCE => $result[self::FIELD_UNMET_EVIDENCE],
                self::FIELD_PROOF_REFS_RESOLVED => array_values(array_filter(
                    $result[self::FIELD_EVIDENCE],
                    fn (array $e): bool => ($e[self::FIELD_RESOLVED] ?? false) === true,
                )),
                self::FIELD_EVIDENCE => $result[self::FIELD_EVIDENCE],
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::LEDGER_SCHEMA,
            self::FIELD_SUMMARY => [
                self::FIELD_EVALUATED => count($rows),
                self::FIELD_DRIFT_COUNT => $driftCount,
                self::FIELD_BY_COMPUTED_STATE => $byComputed,
                // Corpus-wide test-resolution stamp. 'green' ONLY when every test-bearing
                // row is backed by a green run; 'existence_only' when none are; 'mixed'
                // otherwise. Downstream that relaxes uncertainty on an exact 'green' match
                // (AtlasDocumentationRealityBidirectionalReconciliationService) therefore
                // stays fail-safe unless the whole corpus is green-proven.
                self::FIELD_TEST_RESOLUTION => $this->summaryTestResolution($testBearingRows, $greenRows),
                self::FIELD_TEST_BEARING_ROWS => $testBearingRows,
                self::FIELD_GREEN_RUN_ROWS => $greenRows,
            ],
            self::FIELD_CAPABILITIES => $rows,
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
            return self::LEVEL_EXISTENCE_ONLY;
        }

        return $greenRows === $testBearingRows ? self::TEST_RESOLUTION_GREEN : self::TEST_RESOLUTION_MIXED;
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
        $root = base_path(self::FIELD_DOCS_ENGINEERING_KNOWLEDGE_BASE);
        $total = 0;
        $claimsRuntime = 0;
        $withEvidence = 0;
        $backed = 0;          // claims runtime AND has evidence_refs
        $unverifiable = 0;    // claims runtime but NO evidence_refs

        if (File::isDirectory($root)) {
            foreach (File::allFiles($root) as $file) {
                /** @var SplFileInfo $file */
                if (AiValueNormalizer::lowerTrimmedString($file->getExtension()) !== self::FIELD_MD
                    || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.self::FIELD_ARCHIVE.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $parsed = $this->frontmatter->parse(File::get($file->getPathname()));
                $fm = AiValueNormalizer::arrayOrEmpty($parsed[self::FIELD_FRONTMATTER] ?? null);
                if (($fm[self::FIELD_DOC_SCHEMA] ?? null) === null) {
                    continue; // only canonical module docs participate
                }
                $total++;

                $state = $this->normalizeState((AiValueNormalizer::trimmedStringOrNull($fm[self::FIELD_IMPLEMENTATION_STATE] ?? null) ?? ''));
                $status = AiValueNormalizer::lowerTrimmedString($fm[self::FIELD_STATUS] ?? '');
                $claims = in_array($state, [self::LEVEL_PARTIAL, self::LEVEL_VERIFIED], true)
                    || in_array($status, [self::STATUS_ACTIVE, self::STATUS_BUILDING], true);
                $hasEvidence = $this->normalizeEvidenceRefs($fm[self::FIELD_EVIDENCE_REFS] ?? null) !== [];

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
            self::FIELD_SCHEMA_VERSION => self::DOC_RUNTIME_COVERAGE_SCHEMA,
            self::FIELD_TOTAL_CANONICAL_DOCS => $total,
            self::FIELD_CLAIMS_RUNTIME => $claimsRuntime,
            self::FIELD_WITH_EVIDENCE_REFS => $withEvidence,
            self::FIELD_VERIFIABLY_BACKED => $backed,
            self::FIELD_UNVERIFIABLE_CLAIMS => $unverifiable,
            self::FIELD_COVERAGE_PCT => $coveragePct,
            self::FIELD_SCORE_OUT_OF_10 => round($coveragePct / 10, 1),
        ];
    }

    /**
     * The test refs each capability DECLARED (kind: test), with the owner doc and the
     * resolver's existence match. This is what `atlas:aeos:verify-tests` iterates to
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
        if ($capability !== null && AiValueNormalizer::trimmedStringOrNull($capability) !== null) {
            $docs = array_values(array_filter(
                $docs,
                fn (array $doc): bool => $doc[self::FIELD_ID] === $capability || str_contains($doc[self::FIELD_PATH], $capability),
            ));
        }

        $out = [];
        foreach ($docs as $doc) {
            $testRefs = [];
            foreach ($doc[self::FIELD_EVIDENCE_REFS] as $ref) {
                if ($this->evidenceRefNormalizer->kind($ref[self::FIELD_KIND] ?? '') !== self::FIELD_TEST) {
                    continue;
                }
                $value = $this->evidenceRefNormalizer->ref($ref[self::FIELD_REF] ?? '');
                if ($value === '') {
                    continue;
                }
                $resolution = $this->resolver->resolve(self::FIELD_TEST, $value);
                $testRefs[] = [
                    self::FIELD_REF => $value,
                    self::FIELD_INDEX_RESOLVED => ($resolution[self::FIELD_RESOLVED] ?? false) === true,
                    self::FIELD_MATCHED => $resolution[self::FIELD_MATCHED] ?? null,
                ];
            }
            if ($testRefs === []) {
                continue;
            }
            $out[] = [
                self::FIELD_CAPABILITY_ID => $doc[self::FIELD_ID],
                self::FIELD_OWNER_DOC => $doc[self::FIELD_PATH],
                self::FIELD_EVIDENCE_REFS => $doc[self::FIELD_EVIDENCE_REFS],
                self::FIELD_TEST_REFS => $testRefs,
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
        $root = base_path(self::FIELD_DOCS_ENGINEERING_KNOWLEDGE_BASE);
        if (! File::isDirectory($root)) {
            return [];
        }

        $docs = [];
        foreach (File::allFiles($root) as $file) {
            /** @var SplFileInfo $file */
            if (AiValueNormalizer::lowerTrimmedString($file->getExtension()) !== self::FIELD_MD) {
                continue;
            }
            $parsed = $this->frontmatter->parse(File::get($file->getPathname()));
            $fm = AiValueNormalizer::arrayOrEmpty($parsed[self::FIELD_FRONTMATTER] ?? null);
            $evidenceRefs = $this->normalizeEvidenceRefs($fm[self::FIELD_EVIDENCE_REFS] ?? null);
            if ($evidenceRefs === []) {
                continue;
            }
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            $docs[] = [
                self::FIELD_ID => AiValueNormalizer::trimmedStringOrNull($fm[self::FIELD_ID] ?? $fm[self::FIELD_GRAPH_ID] ?? $relativePath) ?? $relativePath,
                self::FIELD_PATH => $relativePath,
                self::FIELD_IMPLEMENTATION_STATE => (AiValueNormalizer::trimmedStringOrNull($fm[self::FIELD_IMPLEMENTATION_STATE] ?? null) ?? self::LEVEL_SPEC),
                self::FIELD_EVIDENCE_REFS => $evidenceRefs,
            ];
        }

        usort($docs, fn (array $a, array $b): int => strcmp($a[self::FIELD_ID], $b[self::FIELD_ID]));

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
        return $this->evidenceRefNormalizer->listFromRaw($raw);
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
            $kind = $this->evidenceRefNormalizer->kind($ref[self::FIELD_KIND] ?? '');
            $value = $this->evidenceRefNormalizer->ref($ref[self::FIELD_REF] ?? '');
            if ($kind === '' || $value === '') {
                continue;
            }
            $resolution = $this->resolver->resolve($kind, $value);
            $resolutions[] = $resolution;
            if ($kind === self::FIELD_TEST && ($resolution[self::FIELD_RESOLVED] ?? false) === true) {
                $resolvedTestRefs[] = $value;
            }
        }

        // A capability's test counts toward verified ONLY with a GREEN-CURRENT receipt:
        // a real passing run WHOSE STORED CONTENT HASHES still match the live code+test
        // (B3 freshness, criterion C2). We compute the current hashes here — cheap: it
        // hashes a few files, it NEVER runs tests — and pass them to the receipt gate so
        // a stale green (code or test edited since the run) stops granting verified.
        // Unknown (no capability id) => null => evaluate() degrades safely to not-green.
        $greenTestRun = null;
        if ($capabilityId !== null && (AiValueNormalizer::trimmedStringOrNull($capabilityId) ?? '') !== '' && $resolvedTestRefs !== []) {
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
     * `atlas:aeos:verify-tests` to STAMP a fresh receipt the instant it records a green
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
            self::FIELD_TEST_FILE_HASH => $this->currentTestFileHash($testRef),
            self::FIELD_IMPL_FILES_HASH => $this->currentImplFilesHash($evidenceRefs),
        ];
    }

    /**
     * PIP-01 diagnostic — path=>content_hash breakdown for impl_files_hash, plus the
     * combined hash. Used by mint selo / watchdog when a receipt reads stale.
     *
     * @param  array<int,array{kind?:string, ref?:string}>  $evidenceRefs
     * @return array{format:string, impl_files_hash:?string, paths:array<string,string>}|null
     */
    public function explainImplFilesHash(array $evidenceRefs): ?array
    {
        $paths = $this->implFilePathsForHash($evidenceRefs);
        if ($paths === []) {
            return null;
        }

        $breakdown = [];
        foreach ($paths as $path) {
            $breakdown[$path] = $this->hashFileContent($path);
        }

        return [
            self::FIELD_FORMAT => self::IMPL_FILES_HASH_FORMAT,
            self::FIELD_IMPL_FILES_HASH => $this->combineImplFilesHash($breakdown),
            self::FIELD_PATHS => $breakdown,
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
        $paths = $this->implFilePathsForHash($evidenceRefs);
        if ($paths === []) {
            return null;
        }

        $breakdown = [];
        foreach ($paths as $path) {
            $breakdown[$path] = $this->hashFileContent($path);
        }

        return $this->combineImplFilesHash($breakdown);
    }

    /**
     * @param  array<int,array{kind?:string, ref?:string}>  $evidenceRefs
     * @return array<int,string> sorted distinct repo-relative paths
     */
    private function implFilePathsForHash(array $evidenceRefs): array
    {
        $paths = [];
        foreach ($evidenceRefs as $ref) {
            if ($this->evidenceRefNormalizer->kind($ref[self::FIELD_KIND] ?? '') !== self::FIELD_SYMBOL) {
                continue;
            }
            $value = $this->evidenceRefNormalizer->ref($ref[self::FIELD_REF] ?? '');
            if ($value === '') {
                continue;
            }
            foreach ($this->resolver->resolveSymbolFilePaths($value) as $path) {
                $paths[$path] = true;
            }
        }

        if ($paths === []) {
            return [];
        }

        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }

    /**
     * @param  array<string,string>  $pathContentHashes  repo-relative path => content sha256
     */
    private function combineImplFilesHash(array $pathContentHashes): string
    {
        ksort($pathContentHashes);
        $parts = [];
        foreach ($pathContentHashes as $path => $contentHash) {
            $parts[] = $path.'='.$contentHash;
        }

        return hash(self::FIELD_SHA256, self::IMPL_FILES_HASH_FORMAT."\n".implode("\n", $parts));
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
            return 'missing:'.hash(self::FIELD_SHA256, $relativePath);
        }

        $contents = @file_get_contents($absolute);
        if ($contents === false) {
            return 'missing:'.hash(self::FIELD_SHA256, $relativePath);
        }

        return hash(self::FIELD_SHA256, $contents);
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
            if (($resolution[self::FIELD_RESOLVED] ?? false) === true) {
                $resolvedKinds[(AiValueNormalizer::trimmedStringOrNull($resolution[self::FIELD_KIND] ?? null) ?? '')] = true;
            }
        }

        $hasSymbol = $resolvedKinds[self::FIELD_SYMBOL] ?? false;
        $hasWiring = ($resolvedKinds[self::FIELD_ROUTE] ?? false) || ($resolvedKinds[self::FIELD_COMMAND] ?? false);
        $hasTest = $resolvedKinds[self::FIELD_TEST] ?? false;
        $hasReceipt = $resolvedKinds[self::FIELD_RECEIPT] ?? false;

        // A test counts toward verified ONLY when it resolved AND a green run backs it.
        // $greenTestRun null/false => not green => existence-only never reaches verified.
        $hasGreenTest = $hasTest && ($greenTestRun === true);

        $computed = ($hasSymbol && $hasWiring && $hasGreenTest && $hasReceipt)
            ? self::LEVEL_VERIFIED
            : (($hasSymbol && $hasWiring) ? self::LEVEL_PARTIAL : self::LEVEL_SPEC);

        // Per-row test-resolution honesty stamp.
        $testResolution = match (true) {
            $hasGreenTest => self::FIELD_GREEN_RUN,
            // A test symbol matched but no green run proves it — the lie this kills.
            $hasTest => self::TEST_RESOLUTION_EXISTENCE_ONLY_UNRUN,
            default => self::FIELD_NONE,
        };

        $unmet = [];
        if ($computed === self::LEVEL_SPEC) {
            if (! $hasSymbol) {
                $unmet[] = self::FIELD_NEEDS___1_RESOLVED_SYMBOL__CLASS_METHOD__FOR_PARTIAL;
            }
            if (! $hasWiring) {
                $unmet[] = self::FIELD_NEEDS___1_RESOLVED_ROUTE_OR_COMMAND_FOR_PARTIAL;
            }
        } elseif ($computed === self::LEVEL_PARTIAL) {
            if (! $hasTest) {
                $unmet[] = self::FIELD_NEEDS___1_RESOLVED_TEST_FOR_VERIFIED;
            } elseif (! $hasGreenTest) {
                // The test EXISTS but never ran green — the existence-only gap.
                $unmet[] = self::FIELD_NEEDS___1_TEST_THAT_RAN_GREEN_FOR_VERIFIED___A_TEST_SYMBOL_RESOLVES_BUT_HAS_NO_GREEN_RUN_RECEIPT__RUN_ATLAS_AAEOS_VERIFY_TESTS_;
            }
            if (! $hasReceipt) {
                $unmet[] = self::FIELD_NEEDS___1_RESOLVED_RECEIPT__EVIDENCE_FILE__FOR_VERIFIED;
            }
        }

        $claimed = $this->normalizeState($claimedState);
        $rankClaimed = self::RANK[$claimed];
        $rankComputed = self::RANK[$computed];

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_CLAIMED_STATE => $claimed,
            self::FIELD_CLAIMED_STATE_RAW => AiValueNormalizer::trimmedStringOrNull($claimedState) ?? '',
            self::FIELD_COMPUTED_STATE => $computed,
            self::FIELD_RANK_CLAIMED => $rankClaimed,
            self::FIELD_RANK_COMPUTED => $rankComputed,
            // Over-claim: the doc claims MORE than the index can prove. This is the
            // blocking condition. Under-claim (computed > claimed) is fine (a warning).
            self::FIELD_DRIFT => $rankClaimed > $rankComputed,
            self::FIELD_UNDER_CLAIM => $rankComputed > $rankClaimed,
            self::FIELD_RESOLVED => [
                self::FIELD_SYMBOL => $hasSymbol,
                self::FIELD_WIRING => $hasWiring,
                self::FIELD_TEST => $hasTest,
                // test_green is the load-bearing new signal: a resolved test that is
                // NOT green-backed (existence-only) reports test=true, test_green=false.
                self::FIELD_TEST_GREEN => $hasGreenTest,
                self::FIELD_RECEIPT => $hasReceipt,
            ],
            self::FIELD_UNMET_EVIDENCE => $unmet,
            self::FIELD_EVIDENCE => array_values($resolutions),
            self::FIELD_TEST_RESOLUTION => $testResolution,
        ];
    }

    /**
     * Map the existing implementation_state vocabulary (and the gap-matrix
     * runtime taxonomy) onto the 3-tier rank. Anything not clearly verified or
     * partial collapses to spec — the safe, never-over-claim default.
     */
    public function normalizeState(string $state): string
    {
        $normalized = AiValueNormalizer::lowerTrimmedString($state);

        if (in_array($normalized, [self::LEVEL_VERIFIED, self::FIELD_RUNTIME_VERIFIED, self::FIELD_SOLID_RUNTIME], true)) {
            return self::LEVEL_VERIFIED;
        }

        if (in_array($normalized, [self::LEVEL_PARTIAL, self::FIELD_IMPLEMENTED_PARTIAL, self::FIELD_PARTIAL_RUNTIME], true)) {
            return self::LEVEL_PARTIAL;
        }

        // spec_only, north_star, roadmap_only_no_runtime, backlog_only_no_runtime,
        // spec_runtime_gap, drift_risk, '', unknown -> spec.
        return self::LEVEL_SPEC;
    }
}
