<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use Illuminate\Support\Facades\Schema;

/**
 * L1-P2 — Generative / Self-Healing (first increment): the RECONCILIATION REPAIR
 * PROPOSER.
 *
 * The maturity/drift system already DETECTS doc<->code divergence: a doc claims a
 * higher implementation_state than the code intelligence index can prove (an
 * over-claim). This decider begins to HEAL — from a detected drift it GENERATES a
 * concrete, conservative repair PROPOSAL. It does NOT detect drift itself: it
 * REUSES AtlasAaeosImplementationTruthService::ledger() as the single source of
 * the drift verdict.
 *
 * CRITICAL SAFETY ("auto-reparo cego", named as a risk in the generative-leap
 * doc): this is strictly READ-ONLY. It NEVER applies a repair, NEVER writes any
 * file, NEVER executes any command, and NEVER proposes deleting or modifying live
 * code. It proposes only DOC-SIDE reconciliation — either lower the doc's claim to
 * what the index proves, or supply the evidence_refs that would justify the claim.
 * The real change still travels through the existing gates + Evidence Ledger; this
 * service only hands a human/agent a reviewable proposal.
 *
 * The bidirectional code<->doc generation (regenerating code from doc spec) is a
 * LATER P2 increment and is deliberately out of scope here.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
 */
class AtlasDocumentationRealityRepairProposerService
{
    public const SCHEMA = 'atlas.documentation_reality.repair_proposal.v1';

    public function __construct(
        private readonly AtlasAaeosImplementationTruthService $truth,
    ) {}

    /**
     * Scan the whole capability truth ledger and emit a conservative doc-side
     * repair proposal for every over-claim drift row.
     *
     * @return array<string,mixed>
     */
    public function proposeAll(): array
    {
        return $this->propose(null);
    }

    /**
     * Same as proposeAll() but restricted to a single owner doc (id/slug or path
     * substring, exactly like the maturity ledger filter).
     *
     * @return array<string,mixed>
     */
    public function proposeForDoc(string $ownerDoc): array
    {
        return $this->propose($ownerDoc);
    }

    /**
     * @return array<string,mixed>
     */
    private function propose(?string $capability): array
    {
        // Fail-SAFE against a BLIND index: the drift verdict resolves every
        // evidence_ref against the code-intelligence index. If that index is present
        // but EMPTY (DB up, index-code never run / pruned), EVERY claiming doc would
        // falsely look like an over-claim — and recommending a mass downgrade would
        // ERASE real runtime truth. Withhold all proposals and degrade rather than
        // hand a human a corpus-wide "downgrade everything" plan.
        if (! $this->indexHealthy()) {
            return $this->degradedEnvelope($capability);
        }

        $ledger = $this->truth->ledger($capability);
        $rows = (array) ($ledger['capabilities'] ?? []);

        $proposals = [];
        $driftCount = 0;

        foreach ($rows as $row) {
            // REUSE the ledger's drift verdict verbatim. Over-claim only: the doc
            // claims a higher rank than the code index proves. Under-claim (the doc
            // is more modest than reality) is never "healed" — promoting a claim
            // would itself be an over-claim risk, so it is left alone.
            if (($row['drift'] ?? false) !== true) {
                continue;
            }
            $driftCount++;
            $proposals[] = $this->proposalFor($row);
        }

        $envelope = [
            'schema_version' => self::SCHEMA,
            'mode' => 'doc_side_reconciliation_repair_proposer',
            'pillar' => 'P2_generative_self_healing',
            'increment' => 'conservative_doc_side_proposer',
            'capability_filter' => $capability,
            'summary' => [
                'drift_count' => $driftCount,
                'proposal_count' => count($proposals),
                'ledger_evaluated' => (int) data_get($ledger, 'summary.evaluated', 0),
                'ledger_drift_count' => (int) data_get($ledger, 'summary.drift_count', 0),
            ],
            'degraded' => false,
            'proposals' => $proposals,
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($envelope);
    }

    /**
     * The code-intelligence index is "healthy enough to trust the drift verdict"
     * when its symbol table holds at least one active row. An ABSENT table is a
     * non-production/test context (a real checkout always has it migrated), so the
     * ledger is trusted there; a PRESENT-but-EMPTY table is the dangerous blind
     * index and must degrade.
     */
    private function indexHealthy(): bool
    {
        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            return true;
        }

        return AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->limit(1)
            ->exists();
    }

    /**
     * @return array<string,mixed>
     */
    private function degradedEnvelope(?string $capability): array
    {
        return $this->finalize([
            'schema_version' => self::SCHEMA,
            'mode' => 'doc_side_reconciliation_repair_proposer',
            'pillar' => 'P2_generative_self_healing',
            'increment' => 'conservative_doc_side_proposer',
            'capability_filter' => $capability,
            'degraded' => true,
            'degraded_reason' => 'code_intelligence_index_empty_or_absent_proposals_withheld',
            'summary' => ['drift_count' => 0, 'proposal_count' => 0],
            'proposals' => [],
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'auto_applies' => false,
            'proposes_code_mutation' => false,
            'proposes_deletion' => false,
            'applies_repair' => false,
            'executes_commands' => false,
            'goes_through_gates_and_ledger' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function finalize(array $envelope): array
    {
        $hashPayload = $envelope;
        unset($hashPayload['generated_at'], $hashPayload['proposal_hash']);
        $envelope['proposal_hash'] = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
    }

    /**
     * Build the conservative repair proposal for a single over-claim drift row.
     * Both repair options are DOC-SIDE and reversible; no option ever touches code
     * or any file.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function proposalFor(array $row): array
    {
        $claimedState = (string) ($row['claimed_state'] ?? 'spec');
        $computedState = (string) ($row['computed_state'] ?? 'spec');
        $unmet = array_values(array_map('strval', (array) ($row['unmet_evidence'] ?? [])));
        $unmetRefs = $this->unmetRefs($row);

        // Option A — always safe: downgrade the doc's declared state to what the
        // code index already proves. Purely a frontmatter edit on the owner doc.
        $downgrade = [
            'kind' => 'downgrade_state',
            'detail' => "set implementation_state to {$computedState} to match what the code proves",
            'target' => 'owner_doc_frontmatter',
            'field' => 'implementation_state',
            'from' => $claimedState,
            'to' => $computedState,
            'reversible' => true,
            'touches_code' => false,
            'deletes' => false,
        ];

        // Option B — supply the missing evidence: add evidence_refs that resolve so
        // the claim becomes truthful. This is a doc-side edit too; the ACTUAL work
        // of making those refs resolve (ship the symbol/command/test) still goes
        // through the existing gates + Evidence Ledger.
        $supply = [
            'kind' => 'supply_evidence',
            'detail' => "add evidence_refs that resolve to justify {$claimedState}",
            'target' => 'owner_doc_frontmatter',
            'field' => 'evidence_refs',
            'missing' => $unmet,
            'missing_refs' => $unmetRefs,
            'reversible' => true,
            'touches_code' => false,
            'deletes' => false,
        ];

        // Recommend supply_evidence only when there is concrete, named missing
        // evidence to chase (resolvable); otherwise the only safe move is to lower
        // the claim. Either recommendation is a doc-side edit, never code.
        $recommended = $unmet !== [] && $this->looksResolvable($unmetRefs)
            ? 'supply_evidence'
            : 'downgrade_state';

        return [
            'owner_doc' => (string) ($row['owner_doc'] ?? ''),
            'capability_id' => (string) ($row['capability_id'] ?? ''),
            'claimed_state' => $claimedState,
            'computed_state' => $computedState,
            'unmet_evidence' => $unmet,
            'repair_options' => [$downgrade, $supply],
            'recommended' => $recommended,
            'safety' => [
                'read_only' => true,
                'auto_apply' => false,
                'proposes_code_change' => false,
                'proposes_deletion' => false,
            ],
        ];
    }

    /**
     * The unmet evidence refs the doc declared but that did NOT resolve in the
     * index. These come straight from the ledger row's per-ref evidence list; we
     * surface the unresolved {kind, ref} so the proposal names exactly what to
     * supply — without inventing any new ref.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array{kind:string, ref:string}>
     */
    private function unmetRefs(array $row): array
    {
        $refs = [];
        foreach ((array) ($row['evidence'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['resolved'] ?? false) === true) {
                continue;
            }
            $kind = trim((string) ($entry['kind'] ?? ''));
            $ref = trim((string) ($entry['ref'] ?? ''));
            if ($kind !== '' && $ref !== '') {
                $refs[] = ['kind' => $kind, 'ref' => $ref];
            }
        }

        return $refs;
    }

    /**
     * A drift is "resolvable by supplying evidence" when the doc actually declared
     * the missing refs (named, unresolved). If the gap is structural (the unmet
     * message names a missing tier kind with no declared ref to chase), supplying
     * evidence is not actionable from the doc alone, so the conservative
     * recommendation is to downgrade the claim instead.
     *
     * @param  array<int,array{kind:string, ref:string}>  $unmetRefs
     */
    private function looksResolvable(array $unmetRefs): bool
    {
        return $unmetRefs !== [];
    }
}
