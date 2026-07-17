<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationEvidenceResolver;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Aaeos\AtlasAaeosTestExecutionService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiValueNormalizer;
use Carbon\CarbonImmutable;
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
    /**
     * L3-11: os capability_ids dos owner docs que governam um service_class. Permite ao
     * mint de receipts MIRAR exatamente os subsistemas `partial` (cada receipt verde flipa
     * um partial→ready) em vez de varrer todas as capabilities com conversão baixa.
     * Degrade-safe: FQN inválido / índice cego ⇒ lista vazia.
     *
     * @return list<string>
     */
    public function ownerCapabilityIdsForFqn(?string $serviceClass): array
    {
        $fqn = $this->normalizeFqn($serviceClass);
        if ($fqn === null) {
            return [];
        }

        $ids = [];
        foreach ($this->ownerDocsForFqn($fqn) as $owner) {
            $id = AiValueNormalizer::trimmedStringOrNull($owner['capability_id'] ?? null) ?? '';
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Inverse ownership lookup for PIP-04: touched relative paths -> owner doc
     * capability ids. Degrade-safe: blind index / resolver errors simply withhold
     * ownership for that path.
     *
     * @param  list<string>  $paths
     * @return array<string,list<string>>
     */
    public function ownerCapabilityIdsForPaths(array $paths): array
    {
        $targets = [];
        foreach ($paths as $path) {
            $normalized = $this->normalizePath($path);
            if ($normalized !== '') {
                $targets[$normalized] = [];
            }
        }
        if ($targets === []) {
            return [];
        }

        try {
            $docs = $this->truth->docsWithEvidence();
        } catch (Throwable) {
            return array_fill_keys(array_keys($targets), []);
        }

        foreach ($docs as $doc) {
            $capabilityId = AiValueNormalizer::trimmedStringOrNull($doc['id'] ?? null) ?? '';
            if ($capabilityId === '') {
                continue;
            }

            $ownerDoc = $this->normalizePath((string) ($doc['path'] ?? ''));
            if ($ownerDoc !== '' && array_key_exists($ownerDoc, $targets)) {
                $targets[$ownerDoc][] = $capabilityId;
            }

            foreach (AiValueNormalizer::arrayOrEmpty($doc['evidence_refs'] ?? null) as $ref) {
                if (AiValueNormalizer::lowerTrimmedString($ref['kind'] ?? '') !== 'symbol') {
                    continue;
                }

                try {
                    $filePaths = $this->resolver->resolveSymbolFilePaths((string) ($ref['ref'] ?? ''));
                } catch (Throwable) {
                    $filePaths = [];
                }

                foreach ($filePaths as $filePath) {
                    $normalized = $this->normalizePath($filePath);
                    if ($normalized !== '' && array_key_exists($normalized, $targets)) {
                        $targets[$normalized][] = $capabilityId;
                    }
                }
            }
        }

        foreach ($targets as $path => $ids) {
            $targets[$path] = array_values(array_unique($ids));
        }

        return $targets;
    }

    /**
     * OPE-10: explain why a scorecard facet remains pipeline=partial/building.
     *
     * @return array<string,mixed>
     */
    public function resolvePipelineDiagnosis(?string $serviceClass): array
    {
        $fqn = $this->normalizeFqn($serviceClass);
        if ($fqn === null) {
            return [
                'status' => self::STATUS_BLOCKED,
                'reason' => 'service_class_missing',
                'owner_capability_ids' => [],
                'candidate_test_refs' => [],
                'latest_receipt_at' => null,
                'latest_receipt_age_days' => null,
                'green_receipt_count' => 0,
            ];
        }

        $owners = $this->ownerDocsForFqn($fqn);
        $candidateTestRefs = $this->candidateTestRefsFor($fqn, $owners);
        $existingTestRefs = array_values(array_filter(
            $candidateTestRefs,
            fn (string $testRef): bool => $this->testSymbolExists($testRef),
        ));
        $ownerIds = array_values(array_unique(array_filter(array_map(
            static fn (array $owner): string => AiValueNormalizer::trimmedStringOrNull($owner['capability_id'] ?? null) ?? '',
            $owners,
        ))));

        $latestReceipt = null;
        $greenCount = 0;
        if ($ownerIds !== [] && DatabaseTableAvailability::has('atlas_aaeos_test_run_receipts')) {
            $query = AtlasAaeosTestRunReceipt::query()
                ->whereIn('capability_id', $ownerIds);
            if ($candidateTestRefs !== []) {
                $query->whereIn('test_ref', $candidateTestRefs);
            }

            $latestReceipt = (clone $query)->orderByDesc('ran_at')->orderByDesc('created_at')->first();
            $greenCount = (clone $query)->green()->count();
        }

        $latestAt = $this->parseDate($latestReceipt?->ran_at ?? $latestReceipt?->created_at);
        $pipelineStatus = $this->resolvePipelineStatus($fqn);
        $reason = match (true) {
            $owners === [] => 'owner_doc_missing',
            $candidateTestRefs === [] => 'candidate_test_ref_missing',
            $existingTestRefs === [] => 'candidate_test_symbol_missing',
            $greenCount === 0 => 'green_receipt_missing',
            $pipelineStatus !== self::STATUS_READY => 'green_receipt_stale_or_unmatched',
            default => 'ready',
        };

        return [
            'status' => $pipelineStatus,
            'reason' => $reason,
            'owner_capability_ids' => $ownerIds,
            'candidate_test_refs' => $candidateTestRefs,
            'existing_test_refs' => $existingTestRefs,
            'latest_receipt_at' => $latestAt?->toIso8601String(),
            'latest_receipt_age_days' => $latestAt === null ? null : round($latestAt->diffInHours(CarbonImmutable::now('UTC')) / 24, 2),
            'green_receipt_count' => $greenCount,
        ];
    }

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
     * @param  array<int,array{capability_id:string, owner_doc:string, symbol_ref:string, evidence_refs:array<int,array{kind:string,ref:string}>, test_refs:array<int,string>}>  $owners
     * @return list<string>
     */
    private function candidateTestRefsFor(string $fqn, array $owners): array
    {
        $candidateTestRefs = [$this->classBasename($fqn).'Test'];
        foreach ($owners as $owner) {
            foreach ($owner['test_refs'] as $testRef) {
                $candidateTestRefs[] = $testRef;
            }
        }

        return array_values(array_unique(array_filter(
            $candidateTestRefs,
            static fn (string $ref): bool => trim($ref) !== '',
        )));
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
                if (AiValueNormalizer::lowerTrimmedString($ref['kind'] ?? '') === 'test') {
                    $value = AiValueNormalizer::trimmedStringOrNull($ref['ref'] ?? null) ?? '';
                    if ($value !== '') {
                        $testRefs[] = $value;
                    }
                }
            }

            foreach ($evidenceRefs as $ref) {
                if (AiValueNormalizer::lowerTrimmedString($ref['kind'] ?? '') !== 'symbol') {
                    continue;
                }
                $symbolRef = AiValueNormalizer::trimmedStringOrNull($ref['ref'] ?? null) ?? '';
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

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, base_path())) {
            $path = substr($path, strlen(base_path()));
        }

        return ltrim($path, '/');
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
