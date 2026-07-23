<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Armor;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionCurationInboxService;

/**
 * Foundry AP-C Frontier Armor · I6 Dedup / Prior-Art gate.
 *
 * GENERATES NOTHING, CALLS NO PROVIDER, WRITES NO CANON, MUTATES NO CODE. This
 * is a pure deterministic rules engine: it receives already-generated frontier
 * proposals (produced upstream by the gated generator) and DROPS any proposal
 * that duplicates (a) another proposal in the SAME cycle, (b) a pending item
 * already sitting in the operator curation inbox, or (c) a proposal recorded in
 * the scoped read-only prior-proposal JSONL ledger written by the orchestrator.
 *
 * Identity is the SINGLE canonical proposal identity (shared_contracts
 * `proposal_identity`): the SAME sha256 used as proposal_hash on provenance and
 * as the basis for candidate_hash at inbox admission. No separate formula
 * exists here — I6 keys on exactly that hash, so a proposal that survives I6 is
 * guaranteed to collide-detect against the inbox candidate_hash
 * ('sha256:'+proposal_hash) it would later be admitted under.
 *
 * Every drop records a machine-readable reason. The three drop reasons are HARD
 * and independent: duplicate_proposal_in_cycle, duplicate_vs_curation_inbox,
 * duplicate_vs_prior_proposal_ledger.
 */
final class FrontierDedupPriorArtGate
{
    public const STAGE = 'I6';

    public const DROP_DUPLICATE_IN_CYCLE = 'duplicate_proposal_in_cycle';

    public const DROP_DUPLICATE_VS_INBOX = 'duplicate_vs_curation_inbox';

    public const DROP_DUPLICATE_VS_LEDGER = 'duplicate_vs_prior_proposal_ledger';

    private ?string $priorProposalLedgerPathOverride = null;

    public function __construct(
        private readonly SelfDirectedEvolutionCurationInboxService $curationInbox,
    ) {}

    /**
     * Scoped JSONL seam: redirect the read-only prior-proposal ledger path.
     */
    public function setPriorProposalLedgerPathForTesting(?string $path): void
    {
        $this->priorProposalLedgerPathOverride = $path;
    }

    /**
     * The CANONICAL proposal identity sha256 — the single source of truth reused
     * everywhere (proposal_hash, I6 dedup key, candidate_hash basis). NO other
     * identity formula appears in AP-C.
     *
     * @param  array<string,mixed>  $proposal
     */
    public function proposalIdentity(array $proposal): string
    {
        $citedAnchorIds = [];
        foreach ((array) ($proposal['evidence_refs'] ?? []) as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $anchorId = (string) ($ref['anchor_id'] ?? '');
            if ($anchorId !== '') {
                $citedAnchorIds[] = $anchorId;
            }
        }
        $citedAnchorIds = array_values(array_unique($citedAnchorIds));
        sort($citedAnchorIds);

        $packetFingerprint = [];
        foreach ((array) ($proposal['proposed_packets'] ?? []) as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $packetFingerprint[] = [
                'kind' => (string) ($packet['kind'] ?? ''),
                'owner_candidate' => (string) ($packet['owner_candidate'] ?? ''),
                'label' => (string) ($packet['label'] ?? ''),
            ];
        }
        // Stable, order-independent fingerprint of the proposed packets.
        usort($packetFingerprint, static fn (array $a, array $b): int =>
            MissionCanonicalHash::canonicalJson($a) <=> MissionCanonicalHash::canonicalJson($b));

        return MissionCanonicalHash::sha256([
            'title' => (string) ($proposal['title'] ?? ''),
            'thesis' => (string) ($proposal['thesis'] ?? ''),
            'cited_anchor_ids' => $citedAnchorIds,
            'packet_fingerprint' => $packetFingerprint,
        ]);
    }

    /**
     * Evaluate I6 over a cycle of proposals.
     *
     * @param  list<array<string,mixed>>  $proposals  proposals that survived prior armor stages
     * @param  array<string,mixed>  $context  {curation_inbox?: array, prior_proposal_hashes?: list<string>}
     * @return array<string,mixed>
     */
    public function evaluate(array $proposals, array $context = []): array
    {
        $inboxHashes = $this->inboxCandidateHashes($context);
        $ledgerHashes = $this->priorProposalHashes($context);

        $survivors = [];
        $drops = [];
        $seenInCycle = [];

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $proposalId = (string) ($proposal['proposal_id'] ?? '');
            $hash = $this->proposalIdentity($proposal);
            $candidateHash = 'sha256:'.$hash;

            if (isset($seenInCycle[$hash])) {
                $drops[] = $this->drop($proposalId, $hash, self::DROP_DUPLICATE_IN_CYCLE,
                    'identity collides with proposal '.$seenInCycle[$hash].' already accepted this cycle');
                continue;
            }
            if (in_array($candidateHash, $inboxHashes, true)) {
                $drops[] = $this->drop($proposalId, $hash, self::DROP_DUPLICATE_VS_INBOX,
                    'candidate_hash already pending operator review in curation inbox');
                continue;
            }
            if (in_array($hash, $ledgerHashes, true) || in_array($candidateHash, $ledgerHashes, true)) {
                $drops[] = $this->drop($proposalId, $hash, self::DROP_DUPLICATE_VS_LEDGER,
                    'proposal identity present in prior-proposal ledger');
                continue;
            }

            $seenInCycle[$hash] = $proposalId;
            $survivors[] = $proposal;
        }

        return [
            'stage' => self::STAGE,
            'provider_invoked' => false,
            'deterministic' => true,
            'input_count' => count($proposals),
            'survivors' => array_values($survivors),
            'survivors_count' => count($survivors),
            'drops' => $drops,
            'claim_policy' => [
                'read_only' => true,
                'provider_invoked' => false,
                'mutates_repo' => false,
                'canonical_doc_write_allowed' => false,
                'autoapproval_allowed' => false,
                'autoimplementation_allowed' => false,
                'executed' => false,
                'deterministic' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function drop(string $proposalId, string $hash, string $reason, string $detail): array
    {
        return [
            'proposal_id' => $proposalId,
            'drop_stage' => self::STAGE,
            'proposal_hash' => $hash,
            'reason' => $reason,
            'detail' => $detail,
        ];
    }

    /**
     * Pending curation-inbox candidate_hashes keyed for prior-art collision.
     *
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function inboxCandidateHashes(array $context): array
    {
        $inbox = is_array($context['curation_inbox'] ?? null)
            ? $context['curation_inbox']
            : $this->curationInbox->project($context['inbox_input'] ?? []);

        $hashes = [];
        foreach ((array) ($inbox['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $status = (string) ($item['status'] ?? '');
            if ($status !== '' && $status !== SelfDirectedEvolutionCurationInboxService::STATUS_PENDING_OPERATOR_REVIEW) {
                continue;
            }
            $hash = (string) ($item['candidate_hash'] ?? '');
            if ($hash !== '') {
                $hashes[] = $hash;
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * Read-only scoped prior-proposal JSONL ledger written by the orchestrator.
     * Each line is a JSON object carrying a proposal_hash (and/or candidate_hash).
     *
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function priorProposalHashes(array $context): array
    {
        if (is_array($context['prior_proposal_hashes'] ?? null)) {
            $hashes = [];
            foreach ($context['prior_proposal_hashes'] as $h) {
                $h = (string) $h;
                if ($h !== '') {
                    $hashes[] = $h;
                }
            }

            return array_values(array_unique($hashes));
        }

        $path = $this->priorProposalLedgerPathOverride;
        if ($path === null || ! is_file($path)) {
            return [];
        }

        $hashes = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }
            foreach (['proposal_hash', 'candidate_hash'] as $key) {
                $h = (string) ($row[$key] ?? '');
                if ($h !== '') {
                    $hashes[] = $h;
                }
            }
        }

        return array_values(array_unique($hashes));
    }
}
