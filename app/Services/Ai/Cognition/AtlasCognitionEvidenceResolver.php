<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationEvidenceResolver;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Aaeos\AtlasAaeosTestExecutionService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Throwable;

/**
 * Resolves the doc_status and pipeline_status of an ACOS scorecard subsystem from
 * REAL evidence at runtime — never from a self-declared constant.
 *
 * This is the anti-over-claim half of the ACOS scorecard: code_status is the one
 * already-real dimension (class_exists, owned by AtlasCognitionScoreCardService),
 * but doc_status and pipeline_status used to be HARDCODED 'ready' literals in the
 * SUBSYSTEMS tuple — 2/3 of "10/10" was self-declared, exactly the ADRS "52/52"
 * over-claim. This resolver makes both a FUNCTION of resolvable evidence, REUSING
 * the existing ADRS resolution stack verbatim (no parallel runner, no new memory
 * flow):
 *
 *   - doc_status=ready (per service_class FQN): some canonical doc declares a
 *     `symbol: <ref>` evidence_ref AND
 *     AtlasAaeosImplementationEvidenceResolver::resolve('symbol', <ref>) matches
 *     the scorecard FQN EXACTLY (or by the FQN suffix '\<FQN>'). This FQN-BIND is
 *     mandatory: a doc declaring `symbol: AtlasTokenEconomyRuntimeService` (which
 *     resolves to App\Services\Ai\Context\AtlasTokenEconomyRuntimeService) must NOT
 *     credit the scorecard's different App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService.
 *     A doc body merely MENTIONING the short class name is NOT ownership — only the
 *     structured `symbol:` evidence_ref + FQN-bound resolver match counts.
 *
 *   - pipeline_status=ready (per service_class FQN): the owner doc's declared test
 *     ref (or the <Short>Test naming convention) has a REAL, FRESH GREEN-RUN RECEIPT
 *     (AtlasAaeosTestExecutionService::hasGreenReceipt), keyed on the OWNER DOC id
 *     exactly as atlas:aaeos:verify-tests recorded it, with freshness hashes from
 *     AtlasAaeosImplementationTruthService::freshnessHashes (cheap — hashes a few
 *     files, NEVER runs a test). A test symbol that exists but has no green receipt
 *     computes to PARTIAL (existence_only_unrun), mirroring the truth service.
 *
 * LOAD-SAFE: resolving a status only READS the shared code-intel index (loaded once
 * via the ADRS resolver's existing shared-index + 512MB memory floor — reused, never
 * a second load) and READS receipt rows. NO PHPUnit spawn here; the expensive real
 * runs stay in the opt-in atlas:aaeos:verify-tests command.
 *
 * DEGRADE-SAFE: a blind index (resolve returns nothing) or an absent receipts table
 * yields an honest non-ready status (building/partial), NEVER a false ready.
 *
 * @see app/Services/Ai/Aaeos/AtlasAaeosImplementationEvidenceResolver.php
 * @see app/Services/Ai/Aaeos/AtlasAaeosTestExecutionService.php
 * @see app/Services/Ai/Aaeos/AtlasAaeosImplementationTruthService.php
 */
class AtlasCognitionEvidenceResolver
{
    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BUILDING = 'building';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Memoized FQN-ownership index: short class name => list of owner-doc capabilities
     * that declare `symbol: <ref>` (built from ONE docsWithEvidence() scan). Each entry
     * carries the doc id (capability_id, the green-receipt key), the declared symbol ref,
     * the doc's full evidence_refs, and the doc's declared test refs. Null until built.
     *
     * @var array<string,array<int,array{capability_id:string, owner_doc:string, symbol_ref:string, evidence_refs:array<int,array{kind:string,ref:string}>, test_refs:array<int,string>}>>|null
     */
    private ?array $ownershipIndex = null;

    public function __construct(
        private readonly AtlasAaeosImplementationEvidenceResolver $resolver = new AtlasAaeosImplementationEvidenceResolver,
        private readonly AtlasAaeosTestExecutionService $testExecution = new AtlasAaeosTestExecutionService,
        private readonly AtlasAaeosImplementationTruthService $truth = new AtlasAaeosImplementationTruthService(
            new AtlasAaeosImplementationEvidenceResolver,
            new CanonicalDocsFrontmatterParser,
        ),
    ) {}

    /**
     * Resolve doc_status for a scorecard service_class FQN from REAL doc ownership.
     *
     * ready    -> >=1 owner doc declares `symbol: <ref>` AND resolve('symbol',<ref>)
     *             matches THIS FQN exactly (FQN-bound; an alias short-name collision
     *             cannot mis-credit).
     * blocked  -> a null/blank service_class can never own a doc.
     * building -> no FQN-bound owning doc symbol-ref resolves.
     *
     * Never reads the SUBSYSTEMS constant; flipping the evidence (strip the doc's
     * `symbol:` line / rename the class) flips this from ready to building.
     */
    public function resolveDocStatus(?string $serviceClass): string
    {
        $fqn = $this->normalizeFqn($serviceClass);
        if ($fqn === null) {
            return self::STATUS_BLOCKED;
        }

        return $this->ownerDocsForFqn($fqn) !== []
            ? self::STATUS_READY
            : self::STATUS_BUILDING;
    }

    /**
     * Resolve pipeline_status for a scorecard service_class FQN from a REAL, FRESH
     * green-run receipt — REUSING the B3 green-receipt gate verbatim.
     *
     * ready    -> the owner doc's declared test ref (or <Short>Test) has a green-CURRENT
     *             receipt (AtlasAaeosTestExecutionService::hasGreenReceipt) keyed on the
     *             OWNER DOC id, fresh against the current code+test content.
     * partial  -> a <Short>Test / declared test symbol resolves in the index but has NO
     *             green receipt (existence_only_unrun), mirroring the truth service.
     * building -> no test symbol resolves at all (or no owning doc to key receipts on).
     * blocked  -> a null/blank service_class.
     *
     * Never reads the SUBSYSTEMS constant; deleting the receipt row, or editing the
     * impl/test file so the freshness hash changes, flips this from ready to partial.
     */
    public function resolvePipelineStatus(?string $serviceClass): string
    {
        $fqn = $this->normalizeFqn($serviceClass);
        if ($fqn === null) {
            return self::STATUS_BLOCKED;
        }

        $short = $this->classBasename($fqn);
        $owners = $this->ownerDocsForFqn($fqn);

        // The test refs to consider: every owner doc's declared `test:` refs, plus the
        // <Short>Test naming-convention fallback. resolveTestFqn() refuses an ambiguous
        // ref, so a non-resolving fallback simply does not count.
        $candidateTestRefs = [$short.'Test'];
        foreach ($owners as $owner) {
            foreach ($owner['test_refs'] as $testRef) {
                $candidateTestRefs[] = $testRef;
            }
        }
        $candidateTestRefs = array_values(array_unique(array_filter(
            $candidateTestRefs,
            static fn (string $r): bool => trim($r) !== '',
        )));

        $anyTestSymbolExists = false;
        foreach ($candidateTestRefs as $testRef) {
            if (! $this->testSymbolExists($testRef)) {
                continue;
            }
            $anyTestSymbolExists = true;

            // A green receipt is keyed on the OWNER DOC id (capability_id) — never the
            // scorecard acronym/FQN. With no owning doc there is no capability to key on,
            // so a green receipt can never be found and pipeline degrades honestly.
            foreach ($owners as $owner) {
                // Freshness hashing reads the index for the resolved file paths; a blind
                // index / storage error degrades to null hashes (the green scope alone
                // decides) rather than crashing — hasGreenReceipt itself is degrade-safe
                // (false when the receipts table is absent), so this never fabricates ready.
                try {
                    $hashes = $this->truth->freshnessHashes($owner['evidence_refs'], $testRef);
                } catch (Throwable) {
                    $hashes = ['test_file_hash' => null, 'impl_files_hash' => null];
                }
                if ($this->testExecution->hasGreenReceipt(
                    $owner['capability_id'],
                    $testRef,
                    $hashes['test_file_hash'],
                    $hashes['impl_files_hash'],
                )) {
                    return self::STATUS_READY;
                }
            }
        }

        // A test symbol exists (in the index) but no fresh green receipt backs it ->
        // existence-only -> partial, exactly like the truth service. No test symbol at
        // all -> building.
        return $anyTestSymbolExists ? self::STATUS_PARTIAL : self::STATUS_BUILDING;
    }

    /**
     * The owner-doc capabilities that OWN this FQN: a doc declares `symbol: <ref>` AND
     * resolve('symbol',<ref>) matches the FQN exactly (or by the '\<FQN>' suffix). Reads
     * the memoized ownership index; degrade-safe (empty on a blind index/no docs).
     *
     * @return array<int,array{capability_id:string, owner_doc:string, symbol_ref:string, evidence_refs:array<int,array{kind:string,ref:string}>, test_refs:array<int,string>}>
     */
    private function ownerDocsForFqn(string $fqn): array
    {
        $short = $this->classBasename($fqn);
        $candidates = $this->ownershipIndex()[$short] ?? [];
        if ($candidates === []) {
            return [];
        }

        $owners = [];
        foreach ($candidates as $candidate) {
            // Degrade-safe: a blind index (table absent) or any storage error must
            // WITHHOLD ownership (-> doc not ready), never crash a scorecard read and
            // never fabricate a match. Mirrors the ADRS resolver's never-fabricate rule.
            try {
                $resolution = $this->resolver->resolve('symbol', $candidate['symbol_ref']);
            } catch (Throwable) {
                continue;
            }
            $matched = $resolution['matched'] ?? null;
            if ($matched === null) {
                continue;
            }
            if ($this->matchedBindsFqn($matched, $fqn)) {
                $owners[] = $candidate;
            }
        }

        return $owners;
    }

    /**
     * FQN-bind: the resolver's matched symbol must BE this FQN (exact) or end with the
     * FQN suffix '\<FQN>'. This is what stops a doc declaring an alias short-name from
     * crediting a DIFFERENT namespace's class with the same basename.
     */
    private function matchedBindsFqn(string $matched, string $fqn): bool
    {
        $matched = ltrim($matched, '\\');
        $fqn = ltrim($fqn, '\\');

        return $matched === $fqn || str_ends_with($matched, '\\'.$fqn);
    }

    /**
     * Does a *Test* symbol for this ref exist in the code-intel index? FQN-anchored via
     * resolveTestFqn (refuses an ambiguous bare fragment); falls back to the existence
     * match resolve('test',...) for a bare-but-resolvable <Short>Test class. EXISTENCE
     * only — never green-ness.
     */
    private function testSymbolExists(string $testRef): bool
    {
        if (trim($testRef) === '') {
            return false;
        }

        // Degrade-safe: a blind index / storage error withholds existence (-> the
        // pipeline degrades to building, never a false ready).
        try {
            if ($this->resolver->resolveTestFqn($testRef) !== null) {
                return true;
            }
            $resolution = $this->resolver->resolve('test', $testRef);
        } catch (Throwable) {
            return false;
        }

        return ($resolution['resolved'] ?? false) === true;
    }

    /**
     * Build (once) the short-class-name => [owner-doc candidates] index from ONE
     * docsWithEvidence() scan. A candidate is any doc that declares a `symbol: <ref>`
     * evidence_ref; the FQN-bind check happens later per-lookup (the resolver call is
     * what anchors the ref to a concrete FQN). Carries each doc's declared `test:` refs
     * so the pipeline gate keys receipts on the owning doc.
     *
     * Degrade-safe: any scan failure yields an empty index (honest withhold), never a
     * crash and never a fabricated owner.
     *
     * @return array<string,array<int,array{capability_id:string, owner_doc:string, symbol_ref:string, evidence_refs:array<int,array{kind:string,ref:string}>, test_refs:array<int,string>}>>
     */
    private function ownershipIndex(): array
    {
        if ($this->ownershipIndex !== null) {
            return $this->ownershipIndex;
        }

        $index = [];
        try {
            $docs = $this->truth->docsWithEvidence();
        } catch (Throwable) {
            return $this->ownershipIndex = [];
        }

        foreach ($docs as $doc) {
            $evidenceRefs = $doc['evidence_refs'];
            $testRefs = [];
            foreach ($evidenceRefs as $ref) {
                if (strtolower(trim((string) ($ref['kind'] ?? ''))) === 'test') {
                    $value = trim((string) ($ref['ref'] ?? ''));
                    if ($value !== '') {
                        $testRefs[] = $value;
                    }
                }
            }

            foreach ($evidenceRefs as $ref) {
                if (strtolower(trim((string) ($ref['kind'] ?? ''))) !== 'symbol') {
                    continue;
                }
                $symbolRef = trim((string) ($ref['ref'] ?? ''));
                if ($symbolRef === '') {
                    continue;
                }
                // Key on the basename of the DECLARED ref so the per-FQN lookup is O(1);
                // the FQN-bind (resolver match) still gates ownership, so this key is only
                // a coarse bucket, never the ownership decision.
                $short = $this->classBasename($symbolRef);
                $index[$short][] = [
                    'capability_id' => (string) $doc['id'],
                    'owner_doc' => (string) $doc['path'],
                    'symbol_ref' => $symbolRef,
                    'evidence_refs' => $evidenceRefs,
                    'test_refs' => $testRefs,
                ];
            }
        }

        return $this->ownershipIndex = $index;
    }

    /**
     * Trim + normalize a service_class to a non-empty FQN, or null for null/blank.
     */
    private function normalizeFqn(?string $serviceClass): ?string
    {
        if ($serviceClass === null) {
            return null;
        }
        $fqn = ltrim(trim($serviceClass), '\\');

        return $fqn !== '' ? $fqn : null;
    }

    /**
     * Short class name of an FQN or a `Class::method` ref (no leading namespace).
     */
    private function classBasename(string $ref): string
    {
        $ref = ltrim(trim($ref), '\\');
        if (str_contains($ref, '::')) {
            $ref = substr($ref, 0, (int) strpos($ref, '::'));
        }
        if (str_contains($ref, '\\')) {
            $ref = substr($ref, (int) strrpos($ref, '\\') + 1);
        }

        return $ref;
    }
}
