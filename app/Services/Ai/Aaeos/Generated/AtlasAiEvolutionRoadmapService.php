<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Evolution Roadmap — runtime.
 *
 * Turns the operational evolution INDEX doc into deterministic, pure decision
 * logic. The doc is explicitly "an index, not a parallel architecture", so this
 * service enforces the governance the index imposes on every evolution idea
 * instead of inventing a parallel subsystem:
 *
 *  - Non-Negotiable Frame (5 invariants): provider launches are INPUTS to Atlas
 *    (never existential threats); capabilities go through Kernel + Domain
 *    contracts; repeated patterns move to Core; important outcomes become
 *    Evidence; the Curator PROPOSES evolution but does NOT self-apply critical
 *    behavior.
 *  - Implementation Rule (anti-duplication): "If a contract already exists,
 *    extend it. If a table/event/service already exists, normalize the payload
 *    there. Do not create a new subsystem with a new name for the same
 *    function." A proposal that introduces a new subsystem for an existing
 *    function is rejected and told to extend the existing contract.
 *  - Current Implementation Spine: the phase -> contract -> status read model,
 *    with the documented status vocabulary (implemented / implemented-read-model
 *    / next / planned).
 *  - Authority table: topic -> owner-doc routing (Kernel, master architecture,
 *    provider performance, context retrieval, advanced backlog, personal memory,
 *    implementation order).
 *  - forbidden_changes invariant: runtime / maturity / readiness may NOT be
 *    declared without verifiable evidence and green gates. So a status claim of
 *    "implemented" without evidence is rejected.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
 */
final class AtlasAiEvolutionRoadmapService
{
    public const SCHEMA_VERSION = 'atlas.ai.evolution_roadmap.v1';

    /**
     * The five Non-Negotiable Frame invariants, in documented order.
     *
     * @var list<array{key:string,statement:string}>
     */
    public const FRAME = [
        ['key' => 'provider_launch_is_input', 'statement' => 'Provider launches become inputs to Atlas, not existential threats.'],
        ['key' => 'capabilities_through_contracts', 'statement' => 'Capabilities go through Kernel and Domain contracts.'],
        ['key' => 'repeated_patterns_to_core', 'statement' => 'Repeated patterns move to Core.'],
        ['key' => 'outcomes_become_evidence', 'statement' => 'All important outcomes become Evidence.'],
        ['key' => 'curator_proposes_not_self_applies', 'statement' => 'Curator proposes evolution, but does not self-apply critical behavior.'],
    ];

    /**
     * Documented status vocabulary for the Implementation Spine. Any status
     * outside this set is reported as unknown rather than guessed.
     *
     * @var list<string>
     */
    public const STATUSES = [
        'implemented',
        'implemented_read_model',
        'next',
        'planned',
    ];

    /** Statuses that assert running runtime and therefore require evidence + green gates. */
    public const STATUSES_REQUIRING_EVIDENCE = [
        'implemented',
        'implemented_read_model',
    ];

    /**
     * Current Implementation Spine (phase -> contract -> status), copied from the
     * doc table. Keyed by a stable phase id.
     *
     * @var array<string,array{phase:string,contract:string,status:string}>
     */
    private const SPINE = [
        'ap_99_provider_performance' => ['phase' => 'Phase 0', 'contract' => 'AP-99 Provider Performance Contract', 'status' => 'implemented_read_model'],
        'ap_100_context_pack_manifest' => ['phase' => 'Phase 1', 'contract' => 'AP-100 Context Pack Manifest Reflection', 'status' => 'implemented'],
        'ap_101_retrieval_router' => ['phase' => 'Phase 1', 'contract' => 'AP-101 Retrieval Router', 'status' => 'next'],
        'self_rag_reflection_gates' => ['phase' => 'Phase 2', 'contract' => 'Self-RAG and reflection gates', 'status' => 'planned'],
        'graph_rag' => ['phase' => 'Phase 2', 'contract' => 'Graph RAG explicit/observed/inferred', 'status' => 'planned'],
        'personal_longitudinal_memory' => ['phase' => 'Phase 3', 'contract' => 'Personal longitudinal memory', 'status' => 'planned'],
        'proactive_curator_adaptive_routing' => ['phase' => 'Phase 4', 'contract' => 'Proactive Curator and adaptive routing', 'status' => 'planned'],
    ];

    /**
     * Authority table (topic -> owner doc). Routing only; this service never
     * overrides the owner doc.
     *
     * @var array<string,string>
     */
    private const AUTHORITY = [
        'kernel' => 'atlas-ai-kernel-architecture.md',
        'product_planes' => 'atlas-ai-master-architecture.md',
        'provider' => 'evolution/provider-performance-roadmap.md',
        'model' => 'evolution/provider-performance-roadmap.md',
        'context_retrieval' => 'evolution/context-builder-roadmap.md',
        'rag' => 'evolution/context-builder-roadmap.md',
        'advanced_backlog' => 'evolution/advanced-capabilities-backlog.md',
        'personal_memory' => 'evolution/personal-longitudinal-roadmap.md',
        'implementation_order' => 'evolution/implementation-handoff.md',
    ];

    /**
     * Return the Implementation Spine as an ordered read model. Each row carries
     * whether its status asserts running runtime (and thus requires evidence).
     *
     * @return array{schema_version:string,spine:list<array{id:string,phase:string,contract:string,status:string,status_known:bool,requires_evidence:bool}>}
     */
    public function spine(): array
    {
        $rows = [];
        foreach (self::SPINE as $id => $row) {
            $status = $row['status'];
            $rows[] = [
                'id' => $id,
                'phase' => $row['phase'],
                'contract' => $row['contract'],
                'status' => $status,
                'status_known' => in_array($status, self::STATUSES, true),
                'requires_evidence' => in_array($status, self::STATUSES_REQUIRING_EVIDENCE, true),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'spine' => $rows,
        ];
    }

    /**
     * Resolve a roadmap topic to its owner doc per the Authority table. Topics
     * are matched on documented keywords; an unknown topic is reported as
     * unresolved (it must be placed via the canonical architecture index, not
     * guessed here).
     *
     * @return array{schema_version:string,topic:string,resolved:bool,owner_doc:?string,reason:?string}
     */
    public function resolveAuthority(string $topic): array
    {
        $needle = strtolower(trim($topic));

        $owner = null;
        if ($needle !== '') {
            // Exact key first, then keyword containment (longest key wins for stability).
            if (isset(self::AUTHORITY[$needle])) {
                $owner = self::AUTHORITY[$needle];
            } else {
                $keys = array_keys(self::AUTHORITY);
                usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
                foreach ($keys as $key) {
                    if (str_contains($needle, $key)) {
                        $owner = self::AUTHORITY[$key];
                        break;
                    }
                }
            }
        }

        if ($owner === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'topic' => $needle,
                'resolved' => false,
                'owner_doc' => null,
                'reason' => 'no_authority_owner_place_via_canonical_index',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'topic' => $needle,
            'resolved' => true,
            'owner_doc' => $owner,
            'reason' => null,
        ];
    }

    /**
     * Gate a status claim against the forbidden_changes invariant: runtime /
     * maturity / readiness may not be declared without verifiable evidence AND
     * green gates. A claim of an evidence-bearing status (implemented*) without
     * both is rejected and downgraded to "planned".
     *
     * @return array{
     *   schema_version:string,
     *   claimed_status:string,
     *   status_known:bool,
     *   requires_evidence:bool,
     *   evidence_present:bool,
     *   gates_green:bool,
     *   accepted:bool,
     *   effective_status:string,
     *   reason:?string
     * }
     */
    public function gateStatusClaim(
        string $claimedStatus,
        bool $evidencePresent = false,
        bool $gatesGreen = false,
    ): array {
        $status = strtolower(trim($claimedStatus));
        $known = in_array($status, self::STATUSES, true);

        if (! $known) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'claimed_status' => $status,
                'status_known' => false,
                'requires_evidence' => false,
                'evidence_present' => $evidencePresent,
                'gates_green' => $gatesGreen,
                'accepted' => false,
                'effective_status' => 'planned',
                'reason' => 'unknown_status_outside_documented_vocabulary',
            ];
        }

        $requiresEvidence = in_array($status, self::STATUSES_REQUIRING_EVIDENCE, true);

        // Statuses that do not assert running runtime (next/planned) are always
        // acceptable: they make no readiness claim.
        if (! $requiresEvidence) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'claimed_status' => $status,
                'status_known' => true,
                'requires_evidence' => false,
                'evidence_present' => $evidencePresent,
                'gates_green' => $gatesGreen,
                'accepted' => true,
                'effective_status' => $status,
                'reason' => null,
            ];
        }

        $accepted = $evidencePresent && $gatesGreen;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'claimed_status' => $status,
            'status_known' => true,
            'requires_evidence' => true,
            'evidence_present' => $evidencePresent,
            'gates_green' => $gatesGreen,
            'accepted' => $accepted,
            // forbidden_changes: cannot declare runtime without evidence + green gates.
            'effective_status' => $accepted ? $status : 'planned',
            'reason' => $accepted ? null : 'runtime_claim_requires_evidence_and_green_gates',
        ];
    }

    /**
     * Evaluate an evolution proposal against the Implementation Rule and the
     * Non-Negotiable Frame.
     *
     * Rules enforced:
     *  - Implementation Rule: if a contract/table/event/service already exists for
     *    the same function, a proposal that creates a NEW subsystem with a NEW
     *    name is rejected; the verdict is "extend_existing" pointing at the
     *    existing contract. Extending the existing contract is accepted.
     *  - Frame #2: capabilities must go through Kernel and Domain contracts; a
     *    proposal that bypasses contracts is rejected.
     *  - Frame #5: the Curator proposes but does not self-apply critical
     *    behavior; a proposal flagged critical that wants to self-apply is held
     *    for human/governance authorization (never auto-applied).
     *
     * @param  array{
     *   creates_new_subsystem?:bool,
     *   existing_contract?:?string,
     *   goes_through_contracts?:bool,
     *   is_critical_behavior?:bool,
     *   curator_self_apply?:bool
     * }  $proposal
     * @return array{
     *   schema_version:string,
     *   verdict:string,
     *   accepted:bool,
     *   extend_target:?string,
     *   auto_apply_allowed:bool,
     *   violations:list<string>
     * }
     */
    public function evaluateProposal(array $proposal): array
    {
        $createsNewSubsystem = (bool) ($proposal['creates_new_subsystem'] ?? false);
        $existingContract = $proposal['existing_contract'] ?? null;
        $existingContract = is_string($existingContract) && trim($existingContract) !== ''
            ? trim($existingContract)
            : null;
        $goesThroughContracts = (bool) ($proposal['goes_through_contracts'] ?? true);
        $isCritical = (bool) ($proposal['is_critical_behavior'] ?? false);
        $curatorSelfApply = (bool) ($proposal['curator_self_apply'] ?? false);

        $violations = [];

        // Frame #2: capabilities go through Kernel + Domain contracts.
        if (! $goesThroughContracts) {
            $violations[] = 'frame_capabilities_must_go_through_contracts';
        }

        // Implementation Rule: do not create a new subsystem for an existing function.
        $duplicatesExisting = $createsNewSubsystem && $existingContract !== null;
        if ($duplicatesExisting) {
            $violations[] = 'implementation_rule_extend_existing_do_not_duplicate';
        }

        // Frame #5: Curator may propose, never self-apply critical behavior.
        $curatorOverreach = $isCritical && $curatorSelfApply;
        if ($curatorOverreach) {
            $violations[] = 'frame_curator_must_not_self_apply_critical_behavior';
        }

        $accepted = $violations === [];

        // Auto-apply is allowed only when accepted AND the behavior is not critical.
        // Critical behavior is always proposal-only (held for authorization).
        $autoApplyAllowed = $accepted && ! $isCritical;

        if ($duplicatesExisting) {
            $verdict = 'extend_existing';
        } elseif (! $accepted) {
            $verdict = 'rejected';
        } else {
            $verdict = $autoApplyAllowed ? 'accepted_auto' : 'accepted_proposal_only';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'accepted' => $accepted,
            'extend_target' => $duplicatesExisting ? $existingContract : null,
            'auto_apply_allowed' => $autoApplyAllowed,
            'violations' => $violations,
        ];
    }

    /**
     * Primary entry point: produce the full evolution-roadmap governance
     * snapshot used by the command and as a single source of the doc's contract.
     *
     * @return array{
     *   schema_version:string,
     *   frame:list<array{key:string,statement:string}>,
     *   statuses:list<string>,
     *   spine:array<string,mixed>,
     *   authority_example:array<string,mixed>,
     *   status_gate_example:array<string,mixed>,
     *   proposal_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'frame' => self::FRAME,
            'statuses' => self::STATUSES,
            'spine' => $this->spine(),
            // Worked example: provider/model topic routes to the provider performance roadmap.
            'authority_example' => $this->resolveAuthority('provider'),
            // Worked example: claiming "implemented" without evidence is downgraded to planned.
            'status_gate_example' => $this->gateStatusClaim('implemented', false, false),
            // Worked example of the Implementation Rule: a new subsystem duplicating an
            // existing contract must extend it instead.
            'proposal_example' => $this->evaluateProposal([
                'creates_new_subsystem' => true,
                'existing_contract' => 'AP-99 Provider Performance Contract',
                'goes_through_contracts' => true,
            ]),
        ];
    }
}
