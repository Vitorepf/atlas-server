<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;

/**
 * L1-P2 — Generative / Self-Healing, second increment: the BIDIRECTIONAL
 * doc<->code reconciliation packet that the generative-leap doc names for P2
 * ("reconciliacao bidirecional doc<->codigo").
 *
 * The first P2 increment (AtlasDocumentationRealityRepairProposerService) handles
 * one direction: OVER-claim (the doc claims a HIGHER implementation_state than the
 * code-intelligence index can prove -> downgrade the claim, or supply evidence).
 * This service adds the MISSING direction: UNDER-claim (the code resolves MORE than
 * the doc claims -> the doc under-documents reality, so propose UPGRADING the doc's
 * implementation_state to what the code already proves).
 *
 * Both directions are DOC-SIDE proposals only:
 *   - over_claim_repairs: DELEGATED verbatim to the existing repair proposer (it is
 *     the single owner of the over-claim direction; this service never re-derives it).
 *   - under_claim_upgrades: derived here from the ledger's own under_claim flag
 *     (rank(computed) > rank(claimed)); the only repair option is a frontmatter
 *     state UPGRADE on the owner doc.
 *
 * CRITICAL SAFETY ("auto-reparo cego", named as the risk in the generative-leap
 * doc; the risky surface is code GENERATION): this is strictly READ-ONLY. It NEVER
 * applies a proposal, NEVER writes/deletes/modifies any file, NEVER executes any
 * command, and NEVER generates, proposes or touches code in EITHER direction. The
 * code-from-spec direction (regenerate missing code from a doc spec) is the RISKY
 * later P2 increment and is DELIBERATELY OUT OF SCOPE here. Both directions only
 * ever propose an edit to the owner doc's own frontmatter; the real change still
 * travels through the existing gates + Evidence Ledger.
 *
 * Degrade-SAFE: an empty code-intelligence index makes EVERY doc look like it is
 * both over- AND under-claiming (no ref resolves -> computed collapses to spec ->
 * every partial/verified claim is "over", and any doc claiming spec while the index
 * is blind could spuriously flip). The over-claim proposer already withholds its
 * output on a present-but-empty index; this service REUSES that verdict: when the
 * delegated proposer degrades, this service degrades too and emits NO under_claim
 * upgrades either, rather than hand a human a corpus-wide "rewrite every state" plan.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-bidirectional-reconciliation.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
 */
class AtlasDocumentationRealityBidirectionalReconciliationService
{
    public const SCHEMA = 'atlas.documentation_reality.bidirectional_reconcile.v1';

    public const DIRECTION_UNDER_CLAIM = 'under_claim_doc_upgrade';

    public function __construct(
        private readonly AtlasAaeosImplementationTruthService $truth,
        private readonly AtlasDocumentationRealityRepairProposerService $repairProposer,
    ) {}

    /**
     * Reconcile the whole capability truth ledger in BOTH directions: delegate the
     * over-claim repairs to the existing proposer and add the under-claim doc
     * upgrades derived here.
     *
     * @return array<string,mixed>
     */
    public function reconcileAll(): array
    {
        return $this->reconcile(null);
    }

    /**
     * Same as reconcileAll() but restricted to a single owner doc (id/slug or path
     * substring, exactly like the maturity ledger and the repair proposer filters).
     *
     * @return array<string,mixed>
     */
    public function reconcileForDoc(string $ownerDoc): array
    {
        return $this->reconcile($ownerDoc);
    }

    /**
     * @return array<string,mixed>
     */
    private function reconcile(?string $capability): array
    {
        // DIRECTION 1 (over-claim): delegate verbatim to the existing proposer. It
        // owns the over-claim verdict AND the index-health guard; we never re-derive
        // either. Its envelope is embedded whole so the over-claim contract is
        // identical to calling the proposer directly.
        $overClaim = $capability !== null
            ? $this->repairProposer->proposeForDoc($capability)
            : $this->repairProposer->proposeAll();

        // FAIL-CLOSED gate. This guard's sole job is to WITHHOLD under uncertainty,
        // so it must default to "withhold", never to "proceed". We emit under_claim
        // upgrades ONLY when the delegate envelope proves itself healthy AND
        // well-formed: degraded is STRICTLY boolean false AND proposals is present
        // as an array. Any other shape — degraded true/null/absent/non-bool, or
        // proposals missing/non-array — is treated as a malformed/blind delegate and
        // BOTH directions are withheld, rather than emit upgrades against an index
        // whose health we cannot confirm.
        if (! $this->delegateEnvelopeHealthy($overClaim)) {
            // Distinguish the two legitimate withhold reasons: a delegate that
            // explicitly degraded (blind index, the existing path) vs. an envelope we
            // cannot trust the shape of at all.
            if (($overClaim['degraded'] ?? null) === true) {
                return $this->degradedEnvelope($capability, $overClaim);
            }

            return $this->malformedEnvelope($capability, $overClaim);
        }

        // DIRECTION 2 (under-claim): derive from the SAME ledger the proposer read.
        // The ledger's under_claim flag is the single source — we never recompute it.
        $ledger = $this->truth->ledger($capability);
        $rows = (array) ($ledger['capabilities'] ?? []);

        // Resolution-quality signal from the truth layer (existence_only means the
        // test tier resolved on a *Test* symbol EXISTING, not on a green run). We
        // read it; we never RE-GRADE green-ness here (that is the truth layer's job).
        $ledgerTestResolution = (string) data_get($ledger, 'summary.test_resolution', 'existence_only');

        $underClaimUpgrades = [];
        $underClaimCount = 0;
        $unconfirmedUpgradeCount = 0;
        foreach ($rows as $row) {
            if (($row['under_claim'] ?? false) !== true) {
                continue;
            }
            $underClaimCount++;
            $upgrade = $this->underClaimUpgradeFor($row, $ledgerTestResolution);
            if (($upgrade['requires_human_confirmation'] ?? false) === true) {
                $unconfirmedUpgradeCount++;
            }
            $underClaimUpgrades[] = $upgrade;
        }

        $overClaimRepairs = (array) ($overClaim['proposals'] ?? []);
        $overClaimCount = count($overClaimRepairs);

        $envelope = [
            'schema_version' => self::SCHEMA,
            'mode' => 'bidirectional_doc_side_reconciliation',
            'pillar' => 'P2_generative_self_healing',
            'increment' => 'bidirectional_reconciliation',
            'capability_filter' => $capability,
            'degraded' => false,
            'directions' => [
                'over_claim' => 'delegated_to_repair_proposer',
                'under_claim' => 'doc_state_upgrade_proposer',
            ],
            // Over-claim is the delegated proposer's output, embedded whole.
            'over_claim_repairs' => $overClaimRepairs,
            'over_claim_source' => [
                'schema_version' => (string) ($overClaim['schema_version'] ?? ''),
                'proposal_hash' => (string) ($overClaim['proposal_hash'] ?? ''),
                'degraded' => (bool) ($overClaim['degraded'] ?? false),
            ],
            // Under-claim is derived here from the ledger's under_claim flag.
            'under_claim_upgrades' => $underClaimUpgrades,
            'summary' => [
                'over_claim_count' => $overClaimCount,
                'under_claim_count' => $underClaimCount,
                // Subset of under_claim upgrades whose jump rests on existence-only /
                // presence-only proof: the human/agent must confirm BEFORE upgrading.
                'under_claim_unconfirmed_count' => $unconfirmedUpgradeCount,
                'total_reconciliations' => $overClaimCount + $underClaimCount,
                'ledger_evaluated' => (int) data_get($ledger, 'summary.evaluated', 0),
            ],
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($envelope);
    }

    /**
     * Build the under-claim DOC UPGRADE proposal for a single ledger row whose code
     * resolves MORE than the doc claims. The only repair option is a frontmatter
     * state upgrade on the owner doc — it never touches code and never generates code.
     *
     * Existence-only honesty (mirrors the L0 gate by symmetry): existence-only
     * resolution is conservative-safe for DOWNgrades but anti-conservative for
     * UPgrades. computed_state can reach verified off a test that merely EXISTS
     * (test_resolution=existence_only, NOT green) or a receipt that is just is_file().
     * When the jump RESTS ON that unconfirmed proof we DO NOT assert "the code already
     * proves it"; we carry calibrated uncertainty (evidence_quality, a confirm string,
     * requires_human_confirmation=true) so a consumer cannot create an over-claim from
     * an honest under-claim. We never RECOMMEND verified more confidently than the gate
     * would ALLOW the write — and never hold it to a HIGHER standard (no re-grading of
     * test green-ness here; that is the truth layer's job). target stays computed_state.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function underClaimUpgradeFor(array $row, string $ledgerTestResolution): array
    {
        $ownerDoc = (string) ($row['owner_doc'] ?? '');
        $claimedState = (string) ($row['claimed_state'] ?? 'spec');
        $computedState = (string) ($row['computed_state'] ?? 'spec');

        $evidenceQuality = $this->evidenceQualityFor($row, $computedState, $ledgerTestResolution);
        $unconfirmed = $evidenceQuality === 'existence_only_unconfirmed';

        // Honest, calibrated detail. Only assert "the code already proves it" when the
        // proof is actually confirmed-resolved; on existence-only proof, soften.
        $detail = $unconfirmed
            ? "raise implementation_state to {$computedState} — the code appears to resolve {$computedState}, but on existence-only proof (test present, not verified green; or receipt file present) — confirm before upgrading; the doc may under-document reality"
            : "raise implementation_state to {$computedState} — the code already proves it; the doc under-documents reality";

        $confirmBeforeUpgrade = $unconfirmed
            ? "before upgrading {$ownerDoc} to {$computedState}, confirm the test(s) backing the verified tier run GREEN and any receipt file is a genuine evidence artifact (not just present on disk) — resolution here is existence-only, not a passing-run guarantee"
            : null;

        // The single, safe, doc-side option: raise implementation_state to what the
        // code already proves. Reversible frontmatter edit; never code, never a file
        // write here (the apply still goes through the existing gates + Ledger).
        $upgrade = [
            'kind' => 'upgrade_state',
            'detail' => $detail,
            'target' => 'owner_doc_frontmatter',
            'field' => 'implementation_state',
            'from' => $claimedState,
            'to' => $computedState,
            'reversible' => true,
            'touches_code' => false,
            'generates_code' => false,
            // Carry the caveat on the option too, so it cannot be read in isolation.
            'evidence_quality' => $evidenceQuality,
            'requires_human_confirmation' => $unconfirmed,
            'confirm_before_upgrade' => $confirmBeforeUpgrade,
        ];

        return [
            'owner_doc' => $ownerDoc,
            'capability_id' => (string) ($row['capability_id'] ?? ''),
            'claimed_state' => $claimedState,
            'computed_state' => $computedState,
            'direction' => self::DIRECTION_UNDER_CLAIM,
            // Calibrated uncertainty surfaced at the proposal level too (mirrors the
            // L-infinity every-claim-carries-uncertainty ethic): a consumer reading
            // only the top of the upgrade object still sees whether to confirm first.
            'evidence_quality' => $evidenceQuality,
            'requires_human_confirmation' => $unconfirmed,
            'confirm_before_upgrade' => $confirmBeforeUpgrade,
            'proof_refs_resolved' => $this->resolvedRefs($row),
            'repair_options' => [$upgrade],
            'recommended' => 'upgrade_state',
            'safety' => [
                'read_only' => true,
                'auto_apply' => false,
                'proposes_code_change' => false,
                'generates_code' => false,
            ],
        ];
    }

    /**
     * Derive the evidence quality of an under-claim jump from the ledger row's own
     * resolution signals — never re-resolving and never re-grading.
     *
     * A jump "rests on" a kind only when reaching the target tier CONSUMES it. The
     * truth layer reaches `verified` only with symbol+wiring+test+receipt; it reaches
     * `partial` with symbol+wiring alone. So a jump to `partial` never consumes a test
     * or receipt and is always confirmed-`resolved`. A jump to `verified` consumes a
     * test (resolved under existence-only semantics — a *Test* symbol EXISTING, not a
     * green run) AND a receipt (resolved by is_file() alone), so it rests on
     * unconfirmed proof and is `existence_only_unconfirmed`.
     *
     * We default the verified tier to `existence_only_unconfirmed` and only relax to
     * `resolved` when the row's `resolved.test` is backed by a GREEN `test_resolution`
     * stamp AND the tier no longer leans on a presence-only (`is_file()`) receipt — so
     * the relax happens automatically if the truth layer ever grades green, while an
     * unrecognized/missing resolution shape stays cautious instead of defaulting to
     * confident. Safety is gated on `computedState === verified` directly (below),
     * never derived from "verified implies a receipt is present".
     *
     * @param  array<string,mixed>  $row
     */
    private function evidenceQualityFor(array $row, string $computedState, string $ledgerTestResolution): string
    {
        // Only the verified tier consumes test/receipt; partial/spec jumps never do.
        if ($computedState !== 'verified') {
            return 'resolved';
        }

        // Fail-safe: a verified tier stays UNCONFIRMED unless the test is affirmatively
        // GREEN and the tier no longer leans on a presence-only receipt. Defaulting here
        // (rather than only flagging known-soft shapes) means an unexpected resolution
        // map — or a future truth-layer shape we don't recognize — can never silently
        // make a verified upgrade read as confident. Today the resolver is existence-only
        // corpus-wide, so every verified row stays unconfirmed; this is identical live
        // behavior with a fail-safe default instead of a fail-open one.
        $resolved = is_array($row['resolved'] ?? null) ? $row['resolved'] : [];
        $testIsGreen = ($resolved['test'] ?? null) === true
            && strtolower(trim($ledgerTestResolution)) === 'green';
        $restsOnPresenceOnlyReceipt = ($resolved['receipt'] ?? null) === true;

        if ($testIsGreen && ! $restsOnPresenceOnlyReceipt) {
            return 'resolved';
        }

        return 'existence_only_unconfirmed';
    }

    /**
     * The evidence refs the doc declared that DID resolve in the index — the proof
     * that the code already reaches the higher tier. Surfaced (read-only) so the
     * upgrade names exactly what backs it, without inventing any ref. Prefers the
     * ledger's pre-computed proof_refs_resolved; falls back to filtering evidence.
     * The resolved===true (and non-empty kind/ref) filter is applied in BOTH branches
     * so no unresolved entry can be advertised as proof, whatever the producer.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array{kind:string, ref:string}>
     */
    private function resolvedRefs(array $row): array
    {
        $source = isset($row['proof_refs_resolved']) && is_array($row['proof_refs_resolved'])
            ? $row['proof_refs_resolved']
            : (array) ($row['evidence'] ?? []);

        $refs = [];
        foreach ($source as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            // Re-check resolution in BOTH branches. proof_refs_resolved is normally
            // pre-filtered by the truth layer, but we never advertise an unresolved
            // ref as proof: if a future/other producer puts unresolved entries into
            // proof_refs_resolved, the resolved===true filter still drops them.
            if (($entry['resolved'] ?? false) !== true) {
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
     * FAIL-CLOSED health check on the delegated over-claim envelope. Returns true
     * (proceed to emit under_claim upgrades) ONLY when the envelope proves itself
     * trustworthy: degraded is STRICTLY boolean false AND proposals is present as an
     * array. Everything else — degraded true/null/absent/non-bool, or proposals
     * missing/non-array — returns false so BOTH directions are withheld. A guard whose
     * job is to withhold under uncertainty must never default a malformed envelope to
     * "healthy/proceed".
     *
     * @param  array<string,mixed>  $overClaim
     */
    private function delegateEnvelopeHealthy(array $overClaim): bool
    {
        // degraded must be present AND strictly boolean false.
        if (! array_key_exists('degraded', $overClaim) || $overClaim['degraded'] !== false) {
            return false;
        }

        // proposals must be present AND an array (the expected over-claim payload key).
        if (! array_key_exists('proposals', $overClaim) || ! is_array($overClaim['proposals'])) {
            return false;
        }

        return true;
    }

    /**
     * Withhold BOTH directions because the delegate envelope is malformed (cannot be
     * trusted to reflect index health). Distinct degraded_reason from the legitimate
     * blind-index degrade so a consumer can tell the two withhold causes apart.
     *
     * @param  array<string,mixed>  $overClaim
     * @return array<string,mixed>
     */
    private function malformedEnvelope(?string $capability, array $overClaim): array
    {
        return $this->finalize([
            'schema_version' => self::SCHEMA,
            'mode' => 'bidirectional_doc_side_reconciliation',
            'pillar' => 'P2_generative_self_healing',
            'increment' => 'bidirectional_reconciliation',
            'capability_filter' => $capability,
            'degraded' => true,
            'degraded_reason' => 'delegate_envelope_malformed_reconciliation_withheld',
            'directions' => [
                'over_claim' => 'delegated_to_repair_proposer',
                'under_claim' => 'doc_state_upgrade_proposer',
            ],
            'over_claim_repairs' => [],
            'over_claim_source' => [
                'schema_version' => (string) ($overClaim['schema_version'] ?? ''),
                'proposal_hash' => (string) ($overClaim['proposal_hash'] ?? ''),
                'degraded' => true,
                'degraded_reason' => 'delegate_envelope_malformed',
            ],
            'under_claim_upgrades' => [],
            'summary' => [
                'over_claim_count' => 0,
                'under_claim_count' => 0,
                'under_claim_unconfirmed_count' => 0,
                'total_reconciliations' => 0,
                'ledger_evaluated' => 0,
            ],
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $overClaim
     * @return array<string,mixed>
     */
    private function degradedEnvelope(?string $capability, array $overClaim): array
    {
        return $this->finalize([
            'schema_version' => self::SCHEMA,
            'mode' => 'bidirectional_doc_side_reconciliation',
            'pillar' => 'P2_generative_self_healing',
            'increment' => 'bidirectional_reconciliation',
            'capability_filter' => $capability,
            'degraded' => true,
            'degraded_reason' => 'code_intelligence_index_empty_or_absent_reconciliation_withheld',
            'directions' => [
                'over_claim' => 'delegated_to_repair_proposer',
                'under_claim' => 'doc_state_upgrade_proposer',
            ],
            'over_claim_repairs' => [],
            'over_claim_source' => [
                'schema_version' => (string) ($overClaim['schema_version'] ?? ''),
                'proposal_hash' => (string) ($overClaim['proposal_hash'] ?? ''),
                'degraded' => true,
                'degraded_reason' => (string) ($overClaim['degraded_reason'] ?? ''),
            ],
            'under_claim_upgrades' => [],
            'summary' => [
                'over_claim_count' => 0,
                'under_claim_count' => 0,
                'under_claim_unconfirmed_count' => 0,
                'total_reconciliations' => 0,
                'ledger_evaluated' => 0,
            ],
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
            'generates_code' => false,
            'proposes_code_mutation' => false,
            'both_directions_doc_side_only' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function finalize(array $envelope): array
    {
        $hashPayload = $envelope;
        unset($hashPayload['generated_at'], $hashPayload['reconcile_hash']);
        $envelope['reconcile_hash'] = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
    }
}
