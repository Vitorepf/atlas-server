<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Armor;

use App\Services\Ai\Foundry\FoundryEvidenceVerifierService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Foundry AP-C · Frontier Armor invariant I1 (Evidence-Bound).
 *
 * GENERATES NOTHING, WRITES NOTHING TO CANON OR CODE, INVOKES NO PROVIDER.
 * This is a HARD adversarial gate inside the AP-C frontier pipeline: a single
 * generated proposal is dropped (auto-reject) the instant ANY anchor it cites
 * fails to bind to REAL evidence. Every drop records a machine-readable reason.
 *
 * The three independent drop reasons (each can sink a proposal alone):
 *   - cited_anchor_not_in_dossier : the proposal cites an anchor_id that is NOT
 *     present in dossier.anchors[]. The generator may only cite the Harvester
 *     dossier it was handed; a citation outside it is unbound by construction.
 *   - cited_anchor_refuted : a cited anchor IS in the dossier but the
 *     FoundryEvidenceVerifierService refutes it against real evidence
 *     (input.cycles = dossier raw + input.ledger_events).
 *   - dossier_hash_tamper : the generator_input_hash recorded in the sibling
 *     provenance map for this proposal does NOT equal dossier.dossier_hash —
 *     the proposal was generated against a tampered/forged dossier, so the
 *     ENTIRE proposal is dropped before any anchor is even examined.
 *
 * Composes (never duplicates) FoundryEvidenceVerifierService::verifyAnchor for
 * the per-anchor verdict and ::verify for a dossier-wide precheck. The verdict
 * tuple mirrors MultiAgentIntegrationJudgeService::verdict()
 * ({status,reason,detail,blockers}); the verdict envelope is hashed via
 * MissionCanonicalHash::sha256.
 */
final class FrontierEvidenceBoundGate
{
    public const STAGE = 'I1';

    public const STATUS_PASS = 'pass';

    public const STATUS_DROP = 'drop';

    public const REASON_CITED_ANCHOR_REFUTED = 'cited_anchor_refuted';

    public const REASON_CITED_ANCHOR_NOT_IN_DOSSIER = 'cited_anchor_not_in_dossier';

    public const REASON_DOSSIER_HASH_TAMPER = 'dossier_hash_tamper';

    public function __construct(
        private readonly FoundryEvidenceVerifierService $verifier,
    ) {}

    /**
     * Evaluate one generated proposal against the dossier it was generated from.
     *
     * @param  array<string,mixed>  $proposal     atlas.foundry.evolution_proposal.v1 (13-key object; provenance NOT stamped here)
     * @param  array<string,mixed>  $dossier      atlas.foundry.dossier.v1
     * @param  array<string,mixed>  $provenance   sibling provenance map entry for this proposal_id ({generator_label, generator_input_hash, proposal_hash})
     * @param  array<string,mixed>  $ledgerEvents real ledger rows for ledger_event anchors (input.ledger_events seam)
     * @return array{status:string,reason:string,detail:string,blockers:list<string>}
     */
    public function evaluate(array $proposal, array $dossier, array $provenance, array $ledgerEvents = []): array
    {
        $proposalId = (string) ($proposal['proposal_id'] ?? '');

        // ---- guard: dossier_hash_tamper (entire proposal dropped first) ----
        // generator_input_hash is read from the SIBLING provenance map, never
        // from the proposal object (the proposal carries only its 13 canon keys).
        $dossierHash = (string) ($dossier['dossier_hash'] ?? '');
        $generatorInputHash = (string) ($provenance['generator_input_hash'] ?? '');

        if ($dossierHash === '' || $generatorInputHash === '' || ! hash_equals($dossierHash, $generatorInputHash)) {
            return $this->verdict(
                self::STATUS_DROP,
                self::REASON_DOSSIER_HASH_TAMPER,
                "provenance.generator_input_hash '{$generatorInputHash}' != dossier.dossier_hash '{$dossierHash}' for proposal {$proposalId}",
                $proposalId,
                $dossier,
            );
        }

        // Index the dossier anchors by anchor_id for membership + refute checks.
        /** @var array<string,array<string,mixed>> $anchorIndex */
        $anchorIndex = [];
        foreach (array_values(array_filter((array) ($dossier['anchors'] ?? []), 'is_array')) as $anchor) {
            $anchorId = (string) ($anchor['anchor_id'] ?? '');
            if ($anchorId !== '') {
                $anchorIndex[$anchorId] = $anchor;
            }
        }

        // Dossier-wide precheck (composed; never re-derived). A refuted dossier
        // anchor makes any proposal citing it auto-rejectable below.
        $verifyInput = [
            'area_id' => (string) ($dossier['area_id'] ?? 'agentic_engineering_os'),
            'cycles' => array_values(array_filter((array) ($dossier['cycle_receipts'] ?? []), 'is_array')),
            'ledger_events' => array_values(array_filter($ledgerEvents, 'is_array')),
        ];

        $citedAnchorIds = $this->citedAnchorIds($proposal);

        foreach ($citedAnchorIds as $citedId) {
            if (! array_key_exists($citedId, $anchorIndex)) {
                return $this->verdict(
                    self::STATUS_DROP,
                    self::REASON_CITED_ANCHOR_NOT_IN_DOSSIER,
                    "proposal {$proposalId} cites anchor_id '{$citedId}' absent from dossier.anchors",
                    $proposalId,
                    $dossier,
                );
            }

            $anchorVerdict = $this->verifier->verifyAnchor($anchorIndex[$citedId], $verifyInput);
            if (($anchorVerdict['verdict'] ?? null) === FoundryEvidenceVerifierService::VERDICT_REFUTED) {
                $dropReason = (string) ($anchorVerdict['drop_reason'] ?? 'unspecified');

                return $this->verdict(
                    self::STATUS_DROP,
                    self::REASON_CITED_ANCHOR_REFUTED,
                    "proposal {$proposalId} cites anchor_id '{$citedId}' refuted by verifier (drop_reason={$dropReason})",
                    $proposalId,
                    $dossier,
                );
            }
        }

        return $this->verdict(
            self::STATUS_PASS,
            'all_cited_anchors_confirmed',
            $citedAnchorIds === []
                ? "proposal {$proposalId} cites no anchors; nothing to refute"
                : "proposal {$proposalId} cited anchors all confirmed against real evidence (".count($citedAnchorIds).' anchors)',
            $proposalId,
            $dossier,
        );
    }

    /**
     * Stable, sorted, unique cited anchor ids from evidence_refs[].anchor_id.
     *
     * @param  array<string,mixed>  $proposal
     * @return list<string>
     */
    private function citedAnchorIds(array $proposal): array
    {
        $ids = [];
        foreach (array_values(array_filter((array) ($proposal['evidence_refs'] ?? []), 'is_array')) as $ref) {
            $id = (string) ($ref['anchor_id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return array_values($ids);
    }

    /**
     * Build the verdict tuple (mirrors MultiAgentIntegrationJudgeService::verdict)
     * plus a deterministic verdict envelope hash.
     *
     * @param  array<string,mixed>  $dossier
     * @return array{status:string,reason:string,detail:string,blockers:list<string>}
     */
    private function verdict(string $status, string $reason, string $detail, string $proposalId, array $dossier): array
    {
        $tuple = [
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => $status === self::STATUS_PASS ? [] : [$reason],
        ];

        // Verdict envelope hash: deterministic over the stable projection (no clocks).
        $tuple['stage'] = self::STAGE;
        $tuple['proposal_id'] = $proposalId;
        $tuple['claim_policy'] = $this->claimPolicy();
        $tuple['verdict_hash'] = MissionCanonicalHash::sha256([
            'stage' => self::STAGE,
            'proposal_id' => $proposalId,
            'dossier_hash' => (string) ($dossier['dossier_hash'] ?? ''),
            'status' => $status,
            'reason' => $reason,
            'blockers' => $tuple['blockers'],
        ]);

        return $tuple;
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'provider_invoked' => false,
            'mutates_repo' => false,
            'canonical_doc_write_allowed' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'executed' => false,
            'deterministic' => true,
        ];
    }
}
