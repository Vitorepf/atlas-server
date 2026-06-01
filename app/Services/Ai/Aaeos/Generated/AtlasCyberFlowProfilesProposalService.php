<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cyber Flow Profiles Proposal — runtime.
 *
 * Turns the documented Cyber flow-profile catalog into deterministic, pure
 * decision logic. The doc proposes (does NOT register) a set of Cyber flows
 * that live as an extension of programming + security; each flow declares an
 * owner domain, runtime preference, autonomy default, gates, evidence required
 * and an output target. This service makes those declarations enforceable.
 *
 * Concrete contract enforced (from the doc):
 *
 *  - Catalog: the seven proposed flows with their exact documented fields
 *    (proposed_owner_domain, default_runtime, autonomy_default,
 *    background_allowed, required_gates, evidence_required, output_target,
 *    scheduled). cyber.bb.triage is foreground-only (background_allowed=false).
 *
 *  - Status invariant: every proposed flow is `proposal` (scaffold) and is NOT
 *    registered in AtlasDomainProfileRegistry until the 8-step promotion path
 *    is satisfied. promote() never returns `implemented` while any gate fails.
 *
 *  - Anti-pattern #4 — autonomy `high` is NEVER valid in a Cyber flow; a
 *    mutative action always requires the operator. validateProfile() rejects it.
 *
 *  - Anti-pattern #3 — a BB *final* flow (cyber.bb.full-flow) whose output
 *    target is anything other than `proposal_inbox_human_review` breaks
 *    "Atlas observa, nao corrige" and is rejected.
 *
 *  - Anti-pattern #2 — a flow without a referenced refusal matrix fails the
 *    Cyber-specific gate.
 *
 *  - Anti-pattern #1 — a flow whose primary skill is still `draft` cannot be
 *    promoted (skill must be at least `candidate`).
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/cyber-security/flow-profiles-proposal.md
 */
final class AtlasCyberFlowProfilesProposalService
{
    public const SCHEMA_VERSION = 'atlas.cyber.flow_profiles_proposal.v1';

    /** Every proposed flow starts here — proposed, not registered. */
    public const STATUS_PROPOSAL = 'proposal';
    public const STATUS_IMPLEMENTED = 'implemented';

    /** Canonical refusal matrix every Cyber flow must reference. */
    public const REFUSAL_MATRIX_REF = 'cyber-security/refusal-matrix.md';

    /** The single output target a BB-final flow is allowed to emit. */
    public const BB_FINAL_OUTPUT_TARGET = 'proposal_inbox_human_review';

    /** Autonomy levels (closed set). `high` is forbidden for Cyber flows. */
    private const AUTONOMY_LEVELS = ['low', 'medium', 'high'];

    /**
     * Minimum primary-skill maturity required for promotion (anti-pattern #1).
     * Ordered weakest -> strongest.
     *
     * @var list<string>
     */
    private const SKILL_STATUS_RANK = ['draft', 'candidate', 'stable'];

    /**
     * The proposed Cyber flow catalog, transcribed from the doc. Each entry is
     * the documented profile. `bb_final` marks the flagship BB flow whose output
     * target is locked to the human-review inbox (anti-pattern #3).
     *
     * @var array<string,array{
     *   proposed_owner_domain:string,
     *   extension_of:?string,
     *   default_runtime:string,
     *   autonomy_default:string,
     *   background_allowed:bool,
     *   scheduled:bool,
     *   primary_skill:string,
     *   required_gates:list<string>,
     *   evidence_required:list<string>,
     *   output_target:string,
     *   bb_final:bool
     * }>
     */
    private const CATALOG = [
        'programming.security.audit' => [
            'proposed_owner_domain' => 'programming',
            'extension_of' => 'programming.security',
            'default_runtime' => 'engineering_harness',
            'autonomy_default' => 'low',
            'background_allowed' => true,
            'scheduled' => false,
            'primary_skill' => 'cyber-pentest-webapp',
            'required_gates' => [
                'secrets_checked',
                'permissions_scoped',
                'findings_have_evidence',
                'refusal_matrix_check',
            ],
            'evidence_required' => [
                'sast_scan_result',
                'dependency_scan_result',
                'secret_scan_result',
                'finding_with_repro',
                'patch_proposal',
            ],
            'output_target' => 'proposal_inbox_human_review',
            'bb_final' => false,
        ],
        'cyber.recon' => [
            'proposed_owner_domain' => 'programming',
            'extension_of' => null,
            'default_runtime' => 'super_tool_runtime',
            'autonomy_default' => 'low',
            'background_allowed' => true,
            'scheduled' => false,
            'primary_skill' => 'cyber-recon',
            'required_gates' => [
                'bb_program_proof',
                'scope_validation',
                'rate_limit_compliance',
                'refusal_matrix_check',
            ],
            'evidence_required' => [
                'asset_map_artifact',
                'tech_fingerprint',
                'leak_findings_if_any',
                'tool_run_traces',
            ],
            'output_target' => 'handoff_to_cyber_pentest_or_inbox',
            'bb_final' => false,
        ],
        'cyber.recon.continuous' => [
            'proposed_owner_domain' => 'programming',
            'extension_of' => 'cyber.recon',
            'default_runtime' => 'super_tool_runtime',
            'autonomy_default' => 'low',
            'background_allowed' => true,
            'scheduled' => true,
            'primary_skill' => 'cyber-recon',
            'required_gates' => [
                'bb_program_proof_still_valid',
                'scope_validation',
                'rate_limit_compliance',
                'cost_quota_per_run',
                'refusal_matrix_check',
                'delta_only_processing',
            ],
            'evidence_required' => [
                'asset_map_delta',
                'new_findings_since_last_run',
                'tool_run_traces',
            ],
            'output_target' => 'proposal_inbox_human_review',
            'bb_final' => false,
        ],
        'cyber.bb.full-flow' => [
            'proposed_owner_domain' => 'programming',
            'extension_of' => null,
            'default_runtime' => 'super_tool_runtime + engineering_harness',
            'autonomy_default' => 'low',
            'background_allowed' => true,
            'scheduled' => false,
            'primary_skill' => 'cyber-bb-runner',
            'required_gates' => [
                'bb_program_proof',
                'scope_validation',
                'refusal_matrix_check',
                'findings_have_evidence',
                'severity_justified',
                'dedup_against_program',
                'human_review_before_submission',
            ],
            'evidence_required' => [
                'bb_program_snapshot',
                'asset_map_artifact',
                'findings_with_repro',
                'bb_payload_draft',
            ],
            'output_target' => 'proposal_inbox_human_review',
            'bb_final' => true,
        ],
        'cyber.bb.triage' => [
            'proposed_owner_domain' => 'programming',
            'extension_of' => null,
            'default_runtime' => 'super_tool_runtime',
            'autonomy_default' => 'low',
            // Triage is interactive: foreground only.
            'background_allowed' => false,
            'scheduled' => false,
            'primary_skill' => 'cyber-bb-triage',
            'required_gates' => [
                'severity_justified',
                'dedup_against_program',
                'pii_redacted_in_evidence',
                'human_review_before_submission',
            ],
            'evidence_required' => [
                'finding_validated',
                'bb_payload_draft',
            ],
            'output_target' => 'proposal_inbox_human_review',
            'bb_final' => true,
        ],
        'cyber.purple.validate' => [
            'proposed_owner_domain' => 'self_improvement',
            'extension_of' => null,
            'default_runtime' => 'engineering_harness + super_tool_runtime',
            'autonomy_default' => 'low',
            'background_allowed' => true,
            'scheduled' => false,
            'primary_skill' => 'cyber-purple-runner',
            'required_gates' => [
                'purple_target_isolation',
                'refusal_matrix_check',
                'detection_pairing_required',
            ],
            'evidence_required' => [
                'purple_run_log',
                'detection_outcomes',
                'gap_analysis',
                'curator_proposal_drafts',
            ],
            'output_target' => 'curator_proposal_review',
            'bb_final' => false,
        ],
        'cyber.ir.investigate' => [
            'proposed_owner_domain' => 'security',
            'extension_of' => 'security.incident_review',
            'default_runtime' => 'super_tool_runtime + engineering_harness',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'scheduled' => false,
            'primary_skill' => 'cyber-purple-runner',
            'required_gates' => [
                'human_review_required',
                'evidence_chain_of_custody',
                'refusal_matrix_check',
            ],
            'evidence_required' => [
                'incident_timeline',
                'ioc_hunt_results',
                'postmortem_draft',
            ],
            'output_target' => 'incident_review_inbox',
            'bb_final' => false,
        ],
    ];

    /**
     * The 8 documented promotion-path steps. A flow is promotable only when
     * every step is satisfied ("Flow vai de proposal para implemented quando").
     *
     * @var list<string>
     */
    public const PROMOTION_STEPS = [
        'registry_entry_added',          // 1. entry in AtlasDomainProfileRegistry
        'runtime_config_added',          // 2. config in config/atlas_ai.php
        'migration_added',               // 3. register_<flow>_flow migration
        'primary_skill_candidate',       // 4. primary skill >= candidate, eval passing
        'refusal_matrix_injected',       // 5. refusal matrix injected as Policy
        'flow_test_added',               // 6. tests/Feature/Ai/Flows/<Flow>Test
        'domains_command_ready',         // 7. atlas:ai:domains --json shows ready
        'architecture_validate_passes',  // 8. atlas:ai:architecture-validate passes
    ];

    /** @return list<string> the proposed flow ids in catalog order. */
    public function flowIds(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * Return one flow profile by id, decorated with its current status.
     * Unknown ids are reported as unresolved rather than guessed.
     *
     * @return array<string,mixed>
     */
    public function profile(string $flowId): array
    {
        $id = $this->normalizeFlowId($flowId);
        $entry = self::CATALOG[$id] ?? null;

        if ($entry === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'flow_id' => $id,
                'known' => false,
                'reason' => 'unknown_flow_id',
            ];
        }

        return array_merge(
            ['schema_version' => self::SCHEMA_VERSION, 'flow_id' => $id, 'known' => true],
            $entry,
            [
                // Doc status: proposed, NOT registered. Always proposal here.
                'status' => self::STATUS_PROPOSAL,
                'registered_in_domain_registry' => false,
                'refusal_matrix_ref' => self::REFUSAL_MATRIX_REF,
            ],
        );
    }

    /**
     * Validate a flow profile against the doc's anti-patterns. Returns the list
     * of violations; `valid` is true only when empty.
     *
     * Anti-patterns enforced:
     *  #2  refusal matrix must be referenced.
     *  #3  a BB-final flow's output target must be proposal_inbox_human_review.
     *  #4  autonomy `high` is never allowed for a Cyber flow.
     *
     * @param array<string,mixed> $profile
     * @return array{
     *   schema_version:string,
     *   flow_id:string,
     *   valid:bool,
     *   violations:list<string>
     * }
     */
    public function validateProfile(array $profile): array
    {
        $flowId = $this->normalizeFlowId(is_string($profile['flow_id'] ?? null) ? $profile['flow_id'] : '');
        $violations = [];

        $autonomy = strtolower(trim((string) ($profile['autonomy_default'] ?? '')));
        if (! in_array($autonomy, self::AUTONOMY_LEVELS, true)) {
            $violations[] = 'autonomy_default_invalid';
        }
        // Anti-pattern #4 — autonomy high in a Cyber flow is forbidden.
        if ($autonomy === 'high') {
            $violations[] = 'autonomy_high_forbidden_for_cyber_flow';
        }

        // Anti-pattern #2 — refusal matrix must be referenced.
        $matrix = trim((string) ($profile['refusal_matrix_ref'] ?? ''));
        if ($matrix === '') {
            $violations[] = 'missing_refusal_matrix_reference';
        }

        // Anti-pattern #3 — BB final flow output must be the human-review inbox.
        $bbFinal = (bool) ($profile['bb_final'] ?? false);
        $outputTarget = trim((string) ($profile['output_target'] ?? ''));
        if ($bbFinal && $outputTarget !== self::BB_FINAL_OUTPUT_TARGET) {
            $violations[] = 'bb_final_output_target_must_be_human_review_inbox';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'flow_id' => $flowId,
            'valid' => $violations === [],
            'violations' => array_values($violations),
        ];
    }

    /**
     * Evaluate whether a proposed flow may be promoted from `proposal` to
     * `implemented`. Promotion requires (a) the profile to pass validation and
     * (b) every one of the 8 promotion-path steps to be satisfied, AND (c) the
     * primary skill to be at least `candidate` (anti-pattern #1).
     *
     * The resulting status is `implemented` ONLY when promotable; otherwise the
     * flow stays `proposal`. The doc's invariant — proposed flows are never
     * registered until onboarding — is therefore mechanically enforced.
     *
     * @param array<string,bool> $checklist  step_key => satisfied
     * @return array{
     *   schema_version:string,
     *   flow_id:string,
     *   profile_valid:bool,
     *   profile_violations:list<string>,
     *   skill_status:string,
     *   skill_ready:bool,
     *   steps:array<string,bool>,
     *   missing_steps:list<string>,
     *   promotable:bool,
     *   status:string,
     *   blockers:list<string>
     * }
     */
    public function promote(string $flowId, array $checklist = [], string $skillStatus = 'draft'): array
    {
        $profile = $this->profile($flowId);
        $known = (bool) ($profile['known'] ?? false);

        $validation = $known
            ? $this->validateProfile($profile)
            : ['valid' => false, 'violations' => ['unknown_flow_id']];

        $skill = $this->normalizeSkillStatus($skillStatus);
        $skillReady = $this->skillRank($skill) >= $this->skillRank('candidate');

        // Resolve the 8 documented steps from the supplied checklist.
        $steps = [];
        $missing = [];
        foreach (self::PROMOTION_STEPS as $step) {
            $done = (bool) ($checklist[$step] ?? false);
            $steps[$step] = $done;
            if (! $done) {
                $missing[] = $step;
            }
        }

        $blockers = [];
        if (! $known) {
            $blockers[] = 'unknown_flow_id';
        }
        if (! ($validation['valid'] ?? false)) {
            $blockers[] = 'profile_invalid';
        }
        if (! $skillReady) {
            // Anti-pattern #1 — primary skill in draft blocks promotion.
            $blockers[] = 'primary_skill_below_candidate';
        }
        if ($missing !== []) {
            $blockers[] = 'promotion_steps_incomplete';
        }

        $promotable = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'flow_id' => $this->normalizeFlowId($flowId),
            'profile_valid' => (bool) ($validation['valid'] ?? false),
            'profile_violations' => array_values($validation['violations'] ?? []),
            'skill_status' => $skill,
            'skill_ready' => $skillReady,
            'steps' => $steps,
            'missing_steps' => $missing,
            'promotable' => $promotable,
            // Invariant: never `implemented` unless every gate is green.
            'status' => $promotable ? self::STATUS_IMPLEMENTED : self::STATUS_PROPOSAL,
            'blockers' => array_values($blockers),
        ];
    }

    /**
     * Primary entry point: the full proposal snapshot used by the command and as
     * a single source of the doc's contract. It validates every catalog profile
     * (all must be valid) and proves the default promotion state (nothing is
     * promotable with an empty checklist).
     *
     * @return array{
     *   schema_version:string,
     *   status:string,
     *   refusal_matrix_ref:string,
     *   bb_final_output_target:string,
     *   promotion_steps:list<string>,
     *   flow_count:int,
     *   flows:array<string,array<string,mixed>>,
     *   all_profiles_valid:bool,
     *   none_promotable_by_default:bool
     * }
     */
    public function proposal(): array
    {
        $flows = [];
        $allValid = true;
        $nonePromotable = true;

        foreach ($this->flowIds() as $flowId) {
            $profile = $this->profile($flowId);
            $validation = $this->validateProfile($profile);
            // Default promotion check: empty checklist, draft skill -> blocked.
            $promotion = $this->promote($flowId);

            if (! $validation['valid']) {
                $allValid = false;
            }
            if ($promotion['promotable']) {
                $nonePromotable = false;
            }

            $flows[$flowId] = [
                'profile' => $profile,
                'validation' => $validation,
                'default_promotion' => $promotion,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => self::STATUS_PROPOSAL,
            'refusal_matrix_ref' => self::REFUSAL_MATRIX_REF,
            'bb_final_output_target' => self::BB_FINAL_OUTPUT_TARGET,
            'promotion_steps' => self::PROMOTION_STEPS,
            'flow_count' => count($flows),
            'flows' => $flows,
            'all_profiles_valid' => $allValid,
            'none_promotable_by_default' => $nonePromotable,
        ];
    }

    private function normalizeFlowId(string $flowId): string
    {
        return strtolower(trim($flowId));
    }

    private function normalizeSkillStatus(string $status): string
    {
        $key = strtolower(trim($status));

        return in_array($key, self::SKILL_STATUS_RANK, true) ? $key : 'draft';
    }

    private function skillRank(string $status): int
    {
        $rank = array_search($status, self::SKILL_STATUS_RANK, true);

        return $rank === false ? 0 : $rank;
    }
}
