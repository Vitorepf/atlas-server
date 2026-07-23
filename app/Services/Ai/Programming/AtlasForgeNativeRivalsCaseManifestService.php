<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;


/**
 * Forge-Native Rivals Case Manifest v1.
 *
 * Each case is a paired contract between Atlas (Forge runtime) and a rival
 * baseline. The manifest is purely declarative — it never executes providers.
 * It is consumed by the preflight, dry-run, and (in the future) the real
 * battery runner to enforce the canonical protocol.
 *
 * Schema: atlas.programming.forge_native_rivals_case_manifest.v1
 */
class AtlasForgeNativeRivalsCaseManifestService
{
    public const SCHEMA_VERSION = 'atlas.programming.forge_native_rivals_case_manifest.v1';

    public const DEFAULT_CASE_ID = 'atlas-fair-claude-baseline-case-01';

    public function __construct(
        private readonly AtlasForgeNativeRivalsProtocolService $protocol,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function manifest(?string $caseId = null): array
    {
        $cases = $this->defaultCases();
        $selected = $caseId !== null && $caseId !== ''
            ? collect($cases)->firstWhere('case_id', $caseId)
            : $cases[0];

        $valid = is_array($selected);
        $invalidReasons = $valid ? $this->validateCase($selected) : ['case_not_found'];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'suite_id' => AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID,
            'protocol_id' => AtlasForgeNativeRivalsProtocolService::PROTOCOL_ID,
            'generated_at' => now()->toJSON(),
            'case_requested' => $caseId,
            'case_resolved' => $valid ? $selected['case_id'] : null,
            'case' => $valid ? $selected : null,
            'available_cases' => array_map(static fn (array $case): string => (string) $case['case_id'], $cases),
            'valid' => $valid && $invalidReasons === [],
            'invalid_reasons' => $invalidReasons,
            'atlas_side_must_use_forge' => true,
            'external_provider_call' => false,
            'protocol_envelope' => [
                'schema_version' => AtlasForgeNativeRivalsProtocolService::SCHEMA_VERSION,
                'protocol_id' => AtlasForgeNativeRivalsProtocolService::PROTOCOL_ID,
            ],
            'note' => 'Manifest é declarativo; ele nunca executa Atlas ou rival. Validação real do estado roda no preflight; planejamento sem provider no dry-run.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function manifestForSuite(string $suiteId): array
    {
        if ($suiteId !== AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'suite_id' => $suiteId,
                'protocol_id' => AtlasForgeNativeRivalsProtocolService::PROTOCOL_ID,
                'available_cases' => [],
                'valid' => false,
                'invalid_reasons' => ['suite_not_registered'],
                'atlas_side_must_use_forge' => true,
                'external_provider_call' => false,
                'note' => 'Only atlas-fair-claude-v1 is registered as a Forge-Native Rivals suite at this stage.',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'suite_id' => $suiteId,
            'protocol_id' => AtlasForgeNativeRivalsProtocolService::PROTOCOL_ID,
            'cases' => $this->defaultCases(),
            'valid' => true,
            'invalid_reasons' => [],
            'atlas_side_must_use_forge' => true,
            'external_provider_call' => false,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public const DEFAULT_QUICK_TEST_COMMAND = "php artisan test --filter='AtlasForgeNativeRivalsTest::test_quick_canary_fixture_passes_under_three_seconds'";

    public const DEFAULT_FULL_TEST_COMMAND = "php artisan test --filter='RivalsForge|AtlasRivals|ForgeNativeRivals|FairClaudePolicy'";

    private function defaultCases(): array
    {
        return [
            [
                'case_id' => self::DEFAULT_CASE_ID,
                'objective' => 'Aplicar patch fixture governado em uma Obra com gates de qualidade e teste sem violar boundary Forge.',
                'quick_test_command' => self::DEFAULT_QUICK_TEST_COMMAND,
                'full_test_command' => self::DEFAULT_FULL_TEST_COMMAND,
                'initial_snapshot_hash' => null,
                'initial_snapshot_hash_required' => true,
                'allowed_files_scope' => [
                    'app/Services/Ai/Programming/**',
                    'tests/Feature/Ai/Programming/**',
                    'tests/Unit/Ai/Programming/**',
                ],
                'acceptance_gates' => [
                    'forge_runtime_certified',
                    'forge_live_execution_passed',
                    'patch_verifier_passed',
                    'test_impact_passed',
                    'repair_loop_resolved_or_human_review',
                    'changed_only_quality_scan_passed',
                ],
                'timeout_policy' => [
                    'wall_clock_seconds_max' => 1800,
                    'per_stage_seconds_max' => 600,
                    'hard_kill_after_seconds' => 2400,
                ],
                'quality_scope' => [
                    'changed_only_required' => true,
                    'repo_wide_debt_cannot_decide_winner' => true,
                ],
                'atlas_arm' => [
                    'runtime' => 'forge',
                    'command_template' => 'php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict',
                    'status_command_template' => 'php artisan atlas:code:forge-fast-path-status --obra=<uuid> --run=<run> --json --strict',
                    'review_command_template' => 'php artisan atlas:code:forge-review --obra=<uuid> --run=<run> --json --strict',
                    'required_commands' => AtlasForgeNativeRivalsProtocolService::ATLAS_REQUIRED_COMMANDS,
                    'atlas_side_must_use_forge' => true,
                ],
                'rival_arm' => [
                    'runtime' => 'claude_code_baseline',
                    'baseline_id' => 'claude-code-cli-baseline',
                    'command_template' => 'claude-code apply --case=<case_id> --workspace=<separate-clean-baseline-workspace>',
                    'manual_instructions_url' => null,
                    'provider_or_baseline_lock_required' => true,
                    'must_be_isolated_from_atlas_workspace' => true,
                ],
                'evidence_requirements' => [
                    'replay_manifest' => true,
                    'patch_diff' => true,
                    'test_run_log' => true,
                    'quality_scan_log' => true,
                    'timeline_with_timestamps' => true,
                    'human_intervention_accounting' => true,
                    'workspace_hash_before' => true,
                    'workspace_hash_after' => true,
                ],
                'replay_requirements' => [
                    'replay_manifest_required' => true,
                    'replay_must_reproduce_atlas_arm_result' => true,
                    'replay_must_reproduce_rival_arm_result' => true,
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
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function validateCase(array $case): array
    {
        $invalid = [];
        if (($case['atlas_arm']['runtime'] ?? null) !== 'forge') {
            $invalid[] = 'atlas_not_forge';
        }
        if (! ($case['atlas_arm']['atlas_side_must_use_forge'] ?? false)) {
            $invalid[] = 'atlas_arm_forge_flag_missing';
        }
        $atlasCommandTemplate = (string) ($case['atlas_arm']['command_template'] ?? '');
        if (! str_contains($atlasCommandTemplate, 'atlas:code:forge-fast-path')) {
            $invalid[] = 'atlas_arm_command_template_not_forge';
        }
        if (! is_array($case['acceptance_gates'] ?? null) || $case['acceptance_gates'] === []) {
            $invalid[] = 'missing_acceptance_gates';
        }
        if (! ($case['replay_requirements']['replay_manifest_required'] ?? false)) {
            $invalid[] = 'missing_replay_manifest';
        }
        if (! in_array(($case['rival_arm']['runtime'] ?? null), AtlasForgeNativeRivalsProtocolService::ALLOWED_RIVAL_RUNTIMES, true)) {
            $invalid[] = 'rival_arm_runtime_not_allowed';
        }
        if (! ($case['rival_arm']['must_be_isolated_from_atlas_workspace'] ?? false)) {
            $invalid[] = 'rival_arm_not_isolated_from_atlas_workspace';
        }

        return $invalid;
    }
}
