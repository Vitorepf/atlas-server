<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Forge-Native Rivals Dry-Run v1.
 *
 * Composes protocol + case manifest + preflight into a planned (but never
 * executed) Rivals battery. Validates that:
 *   - the protocol declares Atlas arm as Forge,
 *   - the case manifest is valid and Forge-bound,
 *   - the planned replay manifest is well-formed,
 *   - no provider call is dispatched.
 *
 * Schema: atlas.programming.forge_native_rivals_dry_run.v1
 */
class AtlasForgeNativeRivalsDryRunService
{
    public const SCHEMA_VERSION = 'atlas.programming.forge_native_rivals_dry_run.v1';

    public function __construct(
        private readonly AtlasForgeNativeRivalsProtocolService $protocol,
        private readonly AtlasForgeNativeRivalsCaseManifestService $caseManifest,
        private readonly AtlasForgeNativeRivalsPreflightService $preflight,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function dryRun(array $options = []): array
    {
        $caseId = is_string($options['case_id'] ?? null) && trim((string) $options['case_id']) !== ''
            ? trim((string) $options['case_id'])
            : null;
        $workspace = is_string($options['workspace'] ?? null) && trim((string) $options['workspace']) !== ''
            ? trim((string) $options['workspace'])
            : base_path();

        $manifestPacket = $this->caseManifest->manifest($caseId);
        $preflightPacket = $this->preflight->preflight([
            'workspace' => $workspace,
            'baseline_workspace' => $options['baseline_workspace'] ?? null,
            'case_id' => $caseId,
            'suite_id' => $options['suite_id'] ?? AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID,
            'provider_cost_approved' => false,
            'runbook_reviewed' => false,
            'intends_provider_battery' => false,
            'preset' => $options['preset'] ?? null,
            'atlas_model' => $options['atlas_model'] ?? null,
            'baseline_model' => $options['baseline_model'] ?? null,
            'gate_profile' => $options['gate_profile'] ?? null,
            'test_command' => $options['test_command'] ?? null,
            'case_ids' => $options['case_ids'] ?? null,
        ]);

        $protocol = $this->protocol->protocol();
        $protocolAtlasIsForge = (bool) data_get($protocol, 'atlas_arm.atlas_side_must_use_forge', false)
            && data_get($protocol, 'atlas_arm.runtime') === 'forge';
        $manifestValid = (bool) ($manifestPacket['valid'] ?? false);
        $atlasArmIsForge = (bool) data_get($preflightPacket, 'checks.case_manifest.atlas_arm_is_forge', false);
        $forgeRuntimePresent = data_get($preflightPacket, 'checks.forge_runtime.status') === 'passed'
            && data_get($preflightPacket, 'checks.forge_commands.status') === 'passed';
        $docsPresent = data_get($preflightPacket, 'checks.canonical_docs.status') === 'passed';

        $replayManifestPlanned = $this->planReplayManifest(is_array($manifestPacket['case'] ?? null) ? $manifestPacket['case'] : null, $caseId);

        $blockingReasons = [];
        if (! $protocolAtlasIsForge) {
            $blockingReasons[] = 'protocol_atlas_arm_not_forge';
        }
        if (! $manifestValid) {
            $blockingReasons[] = 'case_manifest_invalid';
        }
        if (! $atlasArmIsForge) {
            $blockingReasons[] = 'atlas_arm_not_forge';
        }
        if (! $forgeRuntimePresent) {
            $blockingReasons[] = 'forge_runtime_unavailable';
        }
        if (! $docsPresent) {
            $blockingReasons[] = 'canonical_docs_missing';
        }
        if (! ($replayManifestPlanned['valid'] ?? false)) {
            $blockingReasons[] = 'replay_manifest_invalid';
        }

        $status = $blockingReasons === [] ? 'dry_run_passed' : 'dry_run_blocked';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'protocol_id' => AtlasForgeNativeRivalsProtocolService::PROTOCOL_ID,
            'suite_id' => $options['suite_id'] ?? AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID,
            'generated_at' => now()->toJSON(),
            'status' => $status,
            'external_provider_call' => false,
            'provider_dispatched' => false,
            'provider_tokens_spent' => false,
            'atlas_side_must_use_forge' => true,
            'synthetic_scores_allowed' => false,
            'inputs' => [
                'case_id_requested' => $caseId,
                'case_id_resolved' => $manifestPacket['case_resolved'] ?? null,
                'workspace_hash' => hash('sha256', $workspace),
            ],
            'replay_manifest' => $replayManifestPlanned,
            'planned' => [
                'protocol' => [
                    'schema_version' => AtlasForgeNativeRivalsProtocolService::SCHEMA_VERSION,
                    'atlas_side_must_use_forge' => $protocolAtlasIsForge,
                ],
                'case_manifest' => [
                    'schema_version' => $manifestPacket['schema_version'] ?? null,
                    'valid' => $manifestValid,
                    'case' => $manifestPacket['case'] ?? null,
                ],
                'preflight_status' => $preflightPacket['status'] ?? null,
                'replay_manifest' => $replayManifestPlanned,
                'execution_plan' => $this->executionPlan(is_array($manifestPacket['case'] ?? null) ? $manifestPacket['case'] : null),
            ],
            'preflight' => $preflightPacket,
            'readiness_fingerprint' => $preflightPacket['readiness_fingerprint'] ?? null,
            'blocking_reasons' => $blockingReasons,
            'safety' => [
                'dry_run_dispatches_provider' => false,
                'dry_run_spends_provider_tokens' => false,
                'dry_run_is_a_claim' => false,
                'dry_run_promotes_completion' => false,
            ],
            'note' => 'Dry-run apenas valida o protocolo, manifest e planeja o replay manifest. Não roda provider externo e não substitui bateria paga. Resultado nunca é claim.',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $case
     * @return array<string,mixed>
     */
    private function planReplayManifest(?array $case, ?string $caseId): array
    {
        if ($case === null) {
            return [
                'schema_version' => 'atlas.programming.forge_native_rivals_replay_manifest.v1',
                'state' => 'planned',
                'valid' => false,
                'invalid_reasons' => ['case_not_found'],
                'case_id' => $caseId,
            ];
        }

        return [
            'schema_version' => 'atlas.programming.forge_native_rivals_replay_manifest.v1',
            'state' => 'planned',
            'valid' => true,
            'invalid_reasons' => [],
            'case_id' => $case['case_id'] ?? $caseId,
            'atlas_arm' => [
                'runtime' => $case['atlas_arm']['runtime'] ?? null,
                'command_template' => $case['atlas_arm']['command_template'] ?? null,
                'status_command_template' => $case['atlas_arm']['status_command_template'] ?? null,
                'review_command_template' => $case['atlas_arm']['review_command_template'] ?? null,
            ],
            'rival_arm' => [
                'runtime' => $case['rival_arm']['runtime'] ?? null,
                'command_template' => $case['rival_arm']['command_template'] ?? null,
                'baseline_id' => $case['rival_arm']['baseline_id'] ?? null,
            ],
            'acceptance_gates' => $case['acceptance_gates'] ?? [],
            'timeout_policy' => $case['timeout_policy'] ?? [],
            'evidence_requirements' => $case['evidence_requirements'] ?? [],
            'invalid_if' => $case['invalid_if'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $case
     * @return array<string,mixed>
     */
    private function executionPlan(?array $case): array
    {
        if ($case === null) {
            return [
                'state' => 'cannot_plan_without_case',
                'steps' => [],
            ];
        }

        return [
            'state' => 'planned_not_executed',
            'steps' => [
                [
                    'step' => 'workspace_snapshot',
                    'action' => 'Compute initial workspace hash for Atlas arm and rival arm.',
                    'mutates_state' => false,
                ],
                [
                    'step' => 'atlas_arm_forge_run',
                    'action' => (string) ($case['atlas_arm']['command_template'] ?? ''),
                    'runtime' => 'forge',
                    'mutates_state' => true,
                ],
                [
                    'step' => 'rival_arm_baseline_run',
                    'action' => (string) ($case['rival_arm']['command_template'] ?? ''),
                    'runtime' => $case['rival_arm']['runtime'] ?? null,
                    'mutates_state' => true,
                ],
                [
                    'step' => 'gate_check',
                    'action' => 'Apply acceptance gates to both arms.',
                    'gates' => $case['acceptance_gates'] ?? [],
                ],
                [
                    'step' => 'evidence_export',
                    'action' => 'Collect replay manifest, diffs, logs and timeline into an evidence pack.',
                    'requirements' => $case['evidence_requirements'] ?? [],
                ],
            ],
        ];
    }
}
