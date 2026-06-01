<?php

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
 *   verified(2) -> partial AND >=1 test resolves AND >=1 receipt resolves
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

        foreach ($docs as $doc) {
            $result = $this->compute($doc['implementation_state'], $doc['evidence_refs']);
            $byComputed[$result['computed_state']]++;
            if ($result['drift'] === true) {
                $driftCount++;
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
                'test_resolution' => 'existence_only',
            ],
            'capabilities' => $rows,
        ];
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
     * @param  array<int,array{kind?:string, ref?:string}>  $evidenceRefs
     * @return array<string,mixed>
     */
    public function compute(string $claimedState, array $evidenceRefs): array
    {
        $resolutions = [];
        foreach ($evidenceRefs as $ref) {
            $kind = (string) ($ref['kind'] ?? '');
            $value = (string) ($ref['ref'] ?? '');
            if (trim($kind) === '' || trim($value) === '') {
                continue;
            }
            $resolutions[] = $this->resolver->resolve($kind, $value);
        }

        return $this->evaluate($claimedState, $resolutions);
    }

    /**
     * Pure tier + drift computation from already-resolved evidence. Exposed for
     * unit testing without touching the database. Never fabricates: an
     * unresolved ref simply does not contribute to any tier.
     *
     * @param  array<int,array{kind:string, ref:string, resolved:bool, matched:?string}>  $resolutions
     * @return array<string,mixed>
     */
    public function evaluate(string $claimedState, array $resolutions): array
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

        $computed = ($hasSymbol && $hasWiring && $hasTest && $hasReceipt)
            ? 'verified'
            : (($hasSymbol && $hasWiring) ? 'partial' : 'spec');

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
                'receipt' => $hasReceipt,
            ],
            'unmet_evidence' => $unmet,
            'evidence' => array_values($resolutions),
            'test_resolution' => 'existence_only',
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
