<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Foundry · Semantic Gap-Finder (FASE 4 / Pilar 2).
 *
 * GENERATES NOTHING. READ-ONLY. Replaces the boilerplate "keep doc in sync"
 * doc-miner with a SEMANTIC gap source: it compares the DOCUMENTED capability
 * claim (from canonical doc frontmatter: capability / documented_state /
 * required_tests / runtime_ref) against the REAL runtime evidence carried in an
 * AP-A dossier, and emits a CONCRETE, evidence-anchored capability_gap.v1 only
 * when the two diverge under a CLOSED set of drift kinds.
 *
 * Hard invariants (spec atlas-afef-semantic-gap-finder.md):
 *   - Every emitted gap MUST cite an anchor_id present in dossier.anchors[]
 *     whose {@see FoundryEvidenceVerifierService::verifyAnchor} returns
 *     verdict=confirmed (inherits I1 Evidence-Bound). No anchor => dropped.
 *   - drift_kind MUST be a member of DRIFT_KINDS. "keep doc in sync" /
 *     "update documentation" can NEVER become a gap (dropped at source).
 *   - A doc whose claim matches runtime produces ZERO gaps (no noise).
 *   - Each gap carries an outcome_contract (metric_id/baseline/target_delta +
 *     a REAL existing measure_command) so the downstream measured-or-reverted
 *     keystone can prove the gap was actually closed. The contract is built
 *     from the claim's declared metric; the service never invents a metric.
 *   - NEVER calls a provider, NEVER writes canon/code, NEVER mutates state.
 *
 * Emission is deterministic: identical (dossier, claims) => identical report_hash.
 */
final class FoundrySemanticGapFinderService
{
    public const REPORT_SCHEMA = 'atlas.foundry.capability_gap_report.v1';

    public const GAP_SCHEMA = 'atlas.foundry.capability_gap.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * CLOSED set of semantic drift kinds. Anything outside this set (notably the
     * old "keep doc in sync" pseudo-gap) is dropped at the source.
     *
     * @var list<string>
     */
    public const DRIFT_KINDS = [
        'claimed_available_runtime_blocked',
        'claimed_capability_no_runtime_evidence',
        'runtime_proven_capability_undocumented',
        'plan_incomplete_doc_claims_done',
        'required_test_unproven',
        // Governed RSI · Part B: the loop's OWN machinery under-delivers value
        // per token. The "capability" is a named loop component; the evidence
        // anchor is the component's own ComponentValueLedger cycle; the
        // outcome_contract demands a raise in that component's value-per-token.
        // Like every other member it produces a PROPOSAL-ONLY gap; a SELF gap is
        // additionally screened by the Immutable Invariant Registry guard before
        // it may ever reach the operator's human gate.
        'loop_component_low_value_per_token',
    ];

    /** @var list<string> drift kinds whose severity is medium (rest are high). */
    private const MEDIUM_DRIFT_KINDS = [
        'runtime_proven_capability_undocumented',
        'required_test_unproven',
        'loop_component_low_value_per_token',
    ];

    public function __construct(
        private readonly FoundryEvidenceVerifierService $verifier,
    ) {}

    /**
     * Project documented capability claims against an AP-A dossier.
     *
     * Recognised $input keys:
     *   - dossier:              array  AP-A dossier (atlas.foundry.dossier.v1)
     *   - capability_claims:    list<claim>  documented claims (injected seam)
     *   - verifier_input:       array  evidence seam forwarded to verifyAnchor
     *                                  (cycles / ledger_events / commit_hashes)
     *
     * Each claim is an array with:
     *   capability, documented_state, drift_kind, anchor_id, runtime_state?,
     *   source_doc?, source_doc_path?, source_doc_line?, missing_runtime_ref?,
     *   outcome_contract? { metric_id, baseline, target_delta, measure_command,
     *   metric_json_path? }
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed> atlas.foundry.capability_gap_report.v1
     */
    public function project(array $input): array
    {
        $dossier = is_array($input['dossier'] ?? null) ? $input['dossier'] : [];
        $claims = array_values(array_filter(
            is_array($input['capability_claims'] ?? null) ? $input['capability_claims'] : [],
            'is_array',
        ));
        $verifierInput = is_array($input['verifier_input'] ?? null) ? $input['verifier_input'] : [];

        $areaId = (string) ($dossier['area_id'] ?? 'agentic_engineering_os');
        $anchors = $this->indexAnchors($dossier);

        $gaps = [];
        $drops = [];

        foreach ($claims as $claim) {
            $capability = trim((string) ($claim['capability'] ?? ''));
            $documentedState = (string) ($claim['documented_state'] ?? '');
            $driftKind = (string) ($claim['drift_kind'] ?? '');
            $anchorId = (string) ($claim['anchor_id'] ?? '');

            if ($capability === '') {
                $drops[] = $this->drop($capability, $driftKind, 'capability_empty', '');

                continue;
            }

            // Closed drift-kind set: the old "keep doc in sync" boilerplate is
            // not a member and is dropped here, never emitted as a gap.
            if (! in_array($driftKind, self::DRIFT_KINDS, true)) {
                $drops[] = $this->drop($capability, $driftKind, 'drift_kind_not_in_closed_set', $driftKind);

                continue;
            }

            // Evidence-bound: anchor must exist in the dossier.
            if ($anchorId === '' || ! isset($anchors[$anchorId])) {
                $drops[] = $this->drop($capability, $driftKind, 'anchor_not_in_dossier', $anchorId);

                continue;
            }

            // Evidence-bound: anchor must verify CONFIRMED via the REAL verifier
            // (single source of truth — identical to gate I1).
            $verdict = $this->verifier->verifyAnchor($anchors[$anchorId], $verifierInput + ['area_id' => $areaId]);
            if (($verdict['verdict'] ?? '') !== FoundryEvidenceVerifierService::VERDICT_CONFIRMED) {
                $drops[] = $this->drop($capability, $driftKind, 'anchor_refuted', (string) ($verdict['drop_reason'] ?? ''));

                continue;
            }

            // Semantic divergence must be real for the claimed-available family:
            // if the doc does not claim "available" there is no claimed_available drift.
            if ($driftKind === 'claimed_available_runtime_blocked' && $documentedState !== 'available') {
                $drops[] = $this->drop($capability, $driftKind, 'no_semantic_divergence', $documentedState);

                continue;
            }

            // Outcome contract is mandatory: a gap with no measurable contract
            // cannot be proven closed (measured-or-reverted). The contract must
            // reference a REAL existing measure_command; the service never invents one.
            $outcomeContract = $this->normalizeOutcomeContract($claim['outcome_contract'] ?? null);
            if ($outcomeContract === null) {
                $drops[] = $this->drop($capability, $driftKind, 'outcome_contract_missing_or_invalid', $capability);

                continue;
            }

            $gaps[] = $this->makeGap($claim, $anchors[$anchorId], $capability, $documentedState, $driftKind, $anchorId, $outcomeContract);
        }

        usort($gaps, static fn (array $x, array $y): int => strcmp((string) $x['gap_id'], (string) $y['gap_id']));

        $status = match (true) {
            (string) ($dossier['status'] ?? '') !== 'ready' || $this->dossierAnchors($dossier) === [] => self::STATUS_BLOCKED,
            $drops !== [] && $gaps !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $report = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'area_id' => $areaId,
            'gaps' => $gaps,
            'drops' => $drops,
            'gap_count' => count($gaps),
            'claim_policy' => $this->claimPolicy(),
        ];
        $report['report_hash'] = 'sha256:'.$this->hash([
            'gaps' => $gaps,
            'drops' => $drops,
            'area_id' => $areaId,
            'status' => $status,
        ]);

        return $report;
    }

    /**
     * @param  array<string,mixed>  $claim
     * @param  array<string,mixed>  $anchor
     * @param  array{metric_id:string,baseline:float,target_delta:float,measure_command:string,metric_json_path:string}  $outcomeContract
     * @return array<string,mixed> atlas.foundry.capability_gap.v1
     */
    private function makeGap(
        array $claim,
        array $anchor,
        string $capability,
        string $documentedState,
        string $driftKind,
        string $anchorId,
        array $outcomeContract,
    ): array {
        $identity = ['capability' => $capability, 'drift_kind' => $driftKind, 'anchor_id' => $anchorId];
        $gapHash = 'sha256:'.$this->hash($identity);

        $sourceDocPath = trim((string) ($claim['source_doc_path'] ?? ''));
        $sourceDocLine = (int) ($claim['source_doc_line'] ?? 0);
        $missingRuntimeRef = trim((string) ($claim['missing_runtime_ref'] ?? ''));

        // Evidence excerpt fuses BOTH halves of the divergence: the doc anchor
        // (path:line) and the missing runtime reference the claim points at.
        $docAnchor = $sourceDocPath !== '' && $sourceDocLine > 0
            ? $sourceDocPath.':'.$sourceDocLine
            : (string) ($claim['source_doc'] ?? '');
        $evidenceExcerpt = (string) ($anchor['anchor_claim'] ?? '');
        if ($missingRuntimeRef !== '') {
            $evidenceExcerpt = trim($evidenceExcerpt.' | missing_runtime_ref:'.$missingRuntimeRef);
        }

        return [
            'gap_id' => 'gap_'.substr($this->hash($identity), 0, 16),
            'capability' => $capability,
            'documented_state' => $documentedState,
            'runtime_state' => (string) ($claim['runtime_state'] ?? 'unproven'),
            'drift_kind' => $driftKind,
            'anchor_id' => $anchorId,
            'anchor_verdict' => FoundryEvidenceVerifierService::VERDICT_CONFIRMED,
            'evidence_excerpt' => $evidenceExcerpt,
            'evidence_doc_anchor' => $docAnchor,
            'missing_runtime_ref' => $missingRuntimeRef,
            'source_doc' => (string) ($claim['source_doc'] ?? ''),
            'severity' => in_array($driftKind, self::MEDIUM_DRIFT_KINDS, true) ? 'medium' : 'high',
            'outcome_contract' => $outcomeContract,
            'gap_hash' => $gapHash,
        ];
    }

    /**
     * Validate + normalize a claim-declared outcome_contract. Returns null when
     * the contract is absent or malformed (the claim is then dropped — a gap is
     * never emitted without a measurable, REAL contract). The contract shape
     * mirrors MetricLedgerService::normalizeContract so it flows straight into
     * the measured-or-reverted keystone.
     *
     * @return array{metric_id:string,baseline:float,target_delta:float,measure_command:string,metric_json_path:string}|null
     */
    private function normalizeOutcomeContract(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        foreach (['metric_id', 'baseline', 'target_delta', 'measure_command'] as $key) {
            if (! array_key_exists($key, $raw)) {
                return null;
            }
        }
        $metricId = trim((string) $raw['metric_id']);
        $command = trim((string) $raw['measure_command']);
        if ($metricId === '' || $command === '' || ! is_numeric($raw['baseline']) || ! is_numeric($raw['target_delta'])) {
            return null;
        }
        $jsonPath = trim((string) ($raw['metric_json_path'] ?? 'metric'));

        return [
            'metric_id' => $metricId,
            'baseline' => (float) $raw['baseline'],
            'target_delta' => (float) $raw['target_delta'],
            'measure_command' => $command,
            'metric_json_path' => $jsonPath === '' ? 'metric' : $jsonPath,
        ];
    }

    /**
     * @param  array<string,mixed>  $dossier
     * @return array<string,array<string,mixed>>
     */
    private function indexAnchors(array $dossier): array
    {
        $indexed = [];
        foreach ($this->dossierAnchors($dossier) as $anchor) {
            $id = (string) ($anchor['anchor_id'] ?? '');
            if ($id !== '') {
                $indexed[$id] = $anchor;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string,mixed>  $dossier
     * @return list<array<string,mixed>>
     */
    private function dossierAnchors(array $dossier): array
    {
        return array_values(array_filter((array) ($dossier['anchors'] ?? []), 'is_array'));
    }

    /**
     * @return array{capability:string,drift_kind:string,drop_reason:string,detail:string}
     */
    private function drop(string $capability, string $driftKind, string $reason, string $detail): array
    {
        return [
            'capability' => $capability,
            'drift_kind' => $driftKind,
            'drop_reason' => $reason,
            'detail' => $detail,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'generates_code' => false,
            'provider_invoked' => false,
            'canonical_doc_write_allowed' => false,
            'writes_state' => false,
            'mutates_target_repo' => false,
        ];
    }

    private function hash(mixed $value): string
    {
        return MissionCanonicalHash::sha256($value);
    }
}
