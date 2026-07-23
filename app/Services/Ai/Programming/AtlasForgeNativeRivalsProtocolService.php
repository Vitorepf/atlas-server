<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;


/**
 * Forge-Native Rivals Protocol v1.
 *
 * Canon contract: Atlas arm of any Rivals battery MUST execute through Forge.
 * Non-Forge Atlas runs are invalid for Rivals scoring. The rival arm is an
 * isolated external baseline that may be claude_code_baseline, codex_baseline,
 * external_baseline ou manual_baseline.
 *
 * This service is purely declarative — it returns the protocol document and
 * never executes providers. Dry-run, preflight, and case manifest services
 * consume this protocol to enforce the contract end-to-end.
 *
 * Schema: atlas.programming.forge_native_rivals_protocol.v1
 * Doc: docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md
 */
class AtlasForgeNativeRivalsProtocolService
{
    public const SCHEMA_VERSION = 'atlas.programming.forge_native_rivals_protocol.v1';

    public const PROTOCOL_ID = 'atlas-forge-native-rivals-clean-battery-v1';

    public const DEFAULT_SUITE_ID = 'atlas-fair-claude-v1';

    /** @var list<string> */
    public const ATLAS_REQUIRED_COMMANDS = [
        'atlas:code:forge-fast-path',
        'atlas:code:forge-fast-path-status',
        'atlas:code:forge-review',
    ];

    /** @var list<string> */
    public const ALLOWED_RIVAL_RUNTIMES = [
        'external_baseline',
        'claude_code_baseline',
        'codex_baseline',
        'manual_baseline',
    ];

    /** @var list<string> */
    public const REQUIRED_CANONICAL_DOCS = [
        'docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md',
        'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
        'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md',
    ];

    /**
     * @return array<string,mixed>
     */
    public function protocol(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'protocol_id' => self::PROTOCOL_ID,
            'suite_id' => self::DEFAULT_SUITE_ID,
            'goal' => 'Measure Atlas Forge runtime against an isolated external rival baseline with protocol-valid paired cases. No claim may pass without same case, same initial state, same acceptance gates, clean workspaces, explicit provider approval, replay manifest and evidence pack.',
            'atlas_arm' => [
                'runtime' => 'forge',
                'required_commands' => self::ATLAS_REQUIRED_COMMANDS,
                'atlas_side_must_use_forge' => true,
                'forbidden_runtimes' => [
                    'local_ad_hoc_runtime',
                    'chat_runtime_only',
                    'manual_atlas_runtime',
                    'non_forge_atlas_runtime',
                ],
                'invalid_if_not_forge' => true,
            ],
            'rival_arm' => [
                'allowed_runtimes' => self::ALLOWED_RIVAL_RUNTIMES,
                'must_be_isolated_from_atlas_workspace' => true,
                'provider_or_baseline_lock_required' => true,
            ],
            'invariants' => [
                'same_case_required' => true,
                'same_initial_state_required' => true,
                'same_acceptance_gates_required' => true,
                'same_timeout_policy_required' => true,
                'same_quality_scope_required' => true,
                'clean_workspace_required' => true,
                'separate_baseline_workspace_required' => true,
                'replay_manifest_required' => true,
                'evidence_pack_required' => true,
                'human_intervention_accounting_required' => true,
                'provider_cost_approval_required' => true,
                'synthetic_scores_allowed' => false,
                'atlas_side_must_use_forge' => true,
            ],
            'invalid_if' => [
                'atlas_not_forge',
                'no_same_initial_state',
                'missing_acceptance_gates',
                'missing_replay_manifest',
                'provider_fallback_unapproved',
                'dirty_workspace',
                'baseline_workspace_collides_with_atlas_workspace',
                'synthetic_score_admitted',
            ],
            'separation_policy' => [
                'separated_from_external_rivals_certification' => true,
                'forge_native_rivals_certification_does_not_promote_completion' => true,
                'external_rivals_certification_remains_blocked_until_real_battery_valid' => true,
                'dry_run_is_not_a_claim' => true,
            ],
            'commands' => [
                'preflight' => 'php artisan atlas:programming:rivals-forge-preflight --json --strict',
                'dry_run' => 'php artisan atlas:programming:rivals-forge-dry-run --case=<case_id> --json --strict',
                'atlas_arm_fast_path' => 'php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict',
                'atlas_arm_status' => 'php artisan atlas:code:forge-fast-path-status --obra=<uuid> --run=<run> --json --strict',
                'atlas_arm_review' => 'php artisan atlas:code:forge-review --obra=<uuid> --run=<run> --json --strict',
            ],
            'safety' => [
                'protocol_call_dispatches_provider' => false,
                'protocol_call_spends_tokens' => false,
                'protocol_is_read_only' => true,
            ],
            'note' => 'Forge-Native Rivals Protocol is the canonical envelope every Rivals case must satisfy before any provider battery may run. Dry-run validates planning; preflight validates state; real battery still requires operator approval and is governed by external_rivals_certification.',
        ];
    }
}
