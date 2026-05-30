<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry;

/**
 * Foundry · canonical schema registry (AP-A).
 *
 * Declares the four generation schemas (evolution_proposal, proposal_verdict,
 * evolution_outcome, roadmap) as constants ONLY. AP-A generates NOTHING: those
 * four strings are referenced by ZERO AP-A logic (machine-checked by the test
 * suite). They exist here so later, gated phases (AP-C/AP-E) can consume a
 * single canonical source. AP-A itself only USES the dossier / anchor /
 * verifier-verdict / false-anchor-rejection shapes.
 *
 * validateShape() is key-presence ONLY — it never produces, fills, drafts or
 * proposes any value.
 */
final class FoundrySchemas
{
    // ---- generation schemas (declared-for-later; UNUSED by AP-A logic) ----

    public const EVOLUTION_PROPOSAL = 'atlas.foundry.evolution_proposal.v1';

    public const PROPOSAL_VERDICT = 'atlas.foundry.proposal_verdict.v1';

    public const EVOLUTION_OUTCOME = 'atlas.foundry.evolution_outcome.v1';

    public const ROADMAP = 'atlas.foundry.roadmap.v1';

    // ---- shapes USED by AP-A (harvest + verify) ----

    public const DOSSIER = 'atlas.foundry.dossier.v1';

    public const ANCHOR = 'atlas.foundry.anchor.v1';

    public const VERIFIER_VERDICT = 'atlas.foundry.verifier_verdict.v1';

    public const FALSE_ANCHOR_REJECTION = 'atlas.foundry.false_anchor_rejection.v1';

    // ---- decide-only gate shape (AP-B; generates nothing) ----

    public const GATE_VERDICT = 'atlas.foundry.exhaustion_rarity_gate.v1';

    // ---- gated, proposal-only frontier orchestration result (AP-C) ----

    public const FRONTIER_GENERATOR_RESULT = 'atlas.foundry.frontier_generator_result.v1';

    /**
     * Generation schemas — declared for later phases, referenced by ZERO AP-A
     * logic. Machine-checked: no AP-A service token-scans to any of these.
     *
     * @var list<string>
     */
    public const GENERATION_SCHEMAS = [
        self::EVOLUTION_PROPOSAL,
        self::PROPOSAL_VERDICT,
        self::EVOLUTION_OUTCOME,
        self::ROADMAP,
    ];

    /** @var list<string> */
    public const USED_BY_AP_A = [
        self::DOSSIER,
        self::ANCHOR,
        self::VERIFIER_VERDICT,
        self::FALSE_ANCHOR_REJECTION,
    ];

    /**
     * Required top-level keys per schema (key-presence validation only).
     *
     * @var array<string,list<string>>
     */
    private const REQUIRED_KEYS = [
        self::EVOLUTION_PROPOSAL => [
            'proposal_id', 'horizon', 'title', 'thesis', 'evidence_refs',
            'why_it_multiplies', 'success_metric', 'rollback', 'risk_level',
            'dependencies', 'proposed_packets', 'provider_tier_required',
            'anti_pattern_self_check',
        ],
        self::PROPOSAL_VERDICT => [
            'proposal_id', 'verdict', 'lenses', 'majority_confirmed',
        ],
        self::EVOLUTION_OUTCOME => [
            'proposal_id', 'measure_cmd', 'baseline', 'post_value', 'improved',
            'tolerance_met', 'action', 'refuted_by_reality', 'measured_at',
        ],
        self::ROADMAP => [
            'roadmap_id', 'area_id', 'generated_at', 'version', 'capabilities',
        ],
        self::DOSSIER => [
            'schema_version', 'status', 'area_id', 'generated_at',
            'source_summary', 'anchor_count', 'anchors', 'cycle_receipts',
            'evidence_packs', 'plan_completion', 'blockers',
            'owner_reuse_matrix', 'claim_policy', 'dossier_hash',
        ],
        self::ANCHOR => [
            'anchor_id', 'anchor_type', 'anchor_source', 'source_path',
            'anchor_claim', 'resolved', 'integrity_status', 'anchor_hash',
        ],
        self::VERIFIER_VERDICT => [
            'schema_version', 'verdict', 'anchor_id', 'anchor_type',
            'checked_at', 'checks', 'matched_event_ids', 'verification_hash',
        ],
        self::FALSE_ANCHOR_REJECTION => [
            'schema_version', 'anchor_id', 'anchor_type', 'drop_reason',
            'claimed_value', 'recorded_at', 'verification_hash',
        ],
        self::GATE_VERDICT => [
            'schema_version', 'status', 'checks', 'exhaustion_depth',
            'consecutive_admissible_zero_cycles', 'window_n', 'stable_metrics',
            'budget_leg', 'rarity_leg', 'packets_count', 'premium_spend',
            'premium_ceiling', 'drop_reason', 'fallback_is_honest_stop',
            'claim_policy', 'gate_hash',
        ],
        self::FRONTIER_GENERATOR_RESULT => [
            'schema_version', 'status', 'area_id', 'reason', 'gate_status',
            'frontier_mode', 'generator_label', 'generator_status',
            'proposals_generated', 'survivors_count', 'drops', 'curation_inbox',
            'claim_policy', 'result_hash',
        ],
    ];

    public static function isKnown(string $schema): bool
    {
        return array_key_exists($schema, self::REQUIRED_KEYS);
    }

    /**
     * Key-presence validation ONLY. Reports missing required keys and any keys
     * present that are not part of the required set (unexpected_keys). It never
     * fills, drafts, generates or proposes a value.
     *
     * @param  array<string,mixed>  $payload
     * @return array{valid:bool,missing:list<string>,unexpected_keys:list<string>}
     */
    public static function validateShape(string $schema, array $payload): array
    {
        if (! self::isKnown($schema)) {
            return [
                'valid' => false,
                'missing' => ['__unknown_schema__'],
                'unexpected_keys' => [],
            ];
        }

        $required = self::REQUIRED_KEYS[$schema];
        $missing = [];
        foreach ($required as $key) {
            if (! array_key_exists($key, $payload)) {
                $missing[] = $key;
            }
        }

        $unexpected = [];
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, $required, true)) {
                $unexpected[] = (string) $key;
            }
        }
        sort($unexpected);

        return [
            'valid' => $missing === [],
            'missing' => $missing,
            'unexpected_keys' => array_values($unexpected),
        ];
    }
}
