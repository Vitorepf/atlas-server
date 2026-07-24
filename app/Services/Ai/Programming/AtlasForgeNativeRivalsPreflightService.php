<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Console\Commands\AtlasCodeForgeFastPathCommand;
use App\Console\Commands\AtlasCodeForgeFastPathStatusCommand;
use App\Console\Commands\AtlasCodeForgeReviewCommand;
use App\Console\Commands\AtlasForgeLiveExecuteCommand;
use App\Console\Commands\AtlasForgeRuntimeCertifyCommand;
use App\Services\Ai\Programming\Support\GitWorkspaceStateReader;

/**
 * Forge-Native Rivals Preflight v1.
 *
 * Diagnostic-only preflight that verifies whether a Forge-Native Rivals
 * battery is allowed to proceed. Never executes providers, never spends
 * tokens, never alters state. Returns a structured status that downstream
 * commands honor.
 *
 * Schema: atlas.programming.forge_native_rivals_preflight.v1
 */
class AtlasForgeNativeRivalsPreflightService
{
    public const SCHEMA_VERSION = 'atlas.programming.forge_native_rivals_preflight.v1';

    public function __construct(
        private readonly AtlasForgeNativeRivalsProtocolService $protocol,
        private readonly AtlasForgeNativeRivalsCaseManifestService $caseManifest,
        private readonly WorkspaceHygieneService $workspaceHygiene,
        private readonly RivalsForgeReadinessFingerprintService $fingerprint,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function preflight(array $options = []): array
    {
        $workspace = $this->resolveWorkspace($options['workspace'] ?? null);
        $baselineWorkspace = $this->resolveOptionalWorkspace($options['baseline_workspace'] ?? null);
        $suiteId = (string) ($options['suite_id'] ?? AtlasForgeNativeRivalsProtocolService::DEFAULT_SUITE_ID);
        $caseId = $options['case_id'] ?? null;
        $providerApproved = (bool) ($options['provider_cost_approved'] ?? false);
        $runbookReviewed = (bool) ($options['runbook_reviewed'] ?? false);
        $intendsProviderBattery = (bool) ($options['intends_provider_battery'] ?? false);

        $preset = is_string($options['preset'] ?? null) ? (string) $options['preset'] : null;
        $atlasModel = is_string($options['atlas_model'] ?? null) ? (string) $options['atlas_model'] : null;
        $baselineModel = is_string($options['baseline_model'] ?? null) ? (string) $options['baseline_model'] : null;
        $gateProfile = is_string($options['gate_profile'] ?? null) ? (string) $options['gate_profile'] : null;
        $testCommand = is_string($options['test_command'] ?? null) ? (string) $options['test_command'] : null;
        $caseIdsIntent = is_array($options['case_ids'] ?? null)
            ? $options['case_ids']
            : (is_string($caseId) ? [$caseId] : []);

        $readinessFingerprint = $this->fingerprint->compute([
            'suite_id' => $suiteId,
            'preset' => $preset,
            'atlas_model' => $atlasModel,
            'baseline_model' => $baselineModel,
            'atlas_workspace' => $workspace,
            'baseline_workspace' => $baselineWorkspace,
            'case_ids' => $caseIdsIntent,
            'gate_profile' => $gateProfile,
            'test_command' => $testCommand,
        ]);

        $workspaceCheck = $this->checkWorkspace($workspace);
        $baselineCheck = $this->checkBaselineWorkspace($workspace, $baselineWorkspace, $intendsProviderBattery);
        $forgeRuntimeCheck = $this->checkForgeRuntimeAvailability();
        $forgeCommandsCheck = $this->checkForgeCommandsAvailability();
        $docsCheck = $this->checkCanonicalDocs($workspace);
        $manifestCheck = $this->checkCaseManifest(is_string($caseId) ? $caseId : null);
        $approvalCheck = $this->checkOperatorApproval($providerApproved, $runbookReviewed, $intendsProviderBattery);

        $blockingReasons = [];
        $statusDecision = $this->decideStatus(
            $workspaceCheck,
            $baselineCheck,
            $forgeRuntimeCheck,
            $forgeCommandsCheck,
            $docsCheck,
            $manifestCheck,
            $approvalCheck,
            $intendsProviderBattery,
            $blockingReasons,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'protocol_id' => AtlasForgeNativeRivalsProtocolService::PROTOCOL_ID,
            'suite_id' => $suiteId,
            'generated_at' => now()->toJSON(),
            'status' => $statusDecision,
            'ready_for_dry_run' => $statusDecision === 'ready_for_dry_run' || $statusDecision === 'ready_for_provider_battery',
            'ready_for_provider_battery' => $statusDecision === 'ready_for_provider_battery',
            'intends_provider_battery' => $intendsProviderBattery,
            'atlas_side_must_use_forge' => true,
            'synthetic_scores_allowed' => false,
            'external_provider_call' => false,
            'inputs' => [
                'workspace' => $workspace,
                'baseline_workspace' => $baselineWorkspace,
                'case_id' => $caseId,
                'suite_id' => $suiteId,
                'provider_cost_approved' => $providerApproved,
                'runbook_reviewed' => $runbookReviewed,
                'intends_provider_battery' => $intendsProviderBattery,
            ],
            'checks' => [
                'workspace' => $workspaceCheck,
                'baseline_workspace' => $baselineCheck,
                'forge_runtime' => $forgeRuntimeCheck,
                'forge_commands' => $forgeCommandsCheck,
                'canonical_docs' => $docsCheck,
                'case_manifest' => $manifestCheck,
                'operator_approval' => $approvalCheck,
            ],
            'blocking_reasons' => $blockingReasons,
            'readiness_fingerprint' => $readinessFingerprint,
            'safety' => [
                'preflight_dispatches_provider' => false,
                'preflight_spends_provider_tokens' => false,
                'preflight_is_read_only' => true,
                'provider_dispatch_requires_explicit_cost_approval' => true,
                'provider_dispatch_requires_runbook_review' => true,
            ],
            'next_action' => $this->nextAction($statusDecision, $blockingReasons),
        ];
    }

    private function resolveWorkspace(mixed $raw): string
    {
        return is_string($raw) && trim($raw) !== '' ? trim($raw) : base_path();
    }

    private function resolveOptionalWorkspace(mixed $raw): ?string
    {
        $trimmed = is_string($raw) ? trim($raw) : '';

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkWorkspace(string $workspace): array
    {
        $git = GitWorkspaceStateReader::read($workspace);
        $isGit = (bool) ($git['is_git'] ?? false);
        $clean = (bool) ($git['clean'] ?? false);
        $bytecode = $this->workspaceHygiene->trackedPythonBytecode($workspace);
        $hasTrackedBytecode = (int) ($bytecode['tracked_count'] ?? 0) > 0;

        $status = 'blocked';
        if ($isGit && $clean && ! $hasTrackedBytecode) {
            $status = 'passed';
        } elseif ($isGit && $clean && $hasTrackedBytecode) {
            $status = 'blocked_tracked_python_bytecode';
        }

        return [
            'status' => $status,
            'is_git' => $isGit,
            'clean' => $clean,
            'dirty_count' => (int) ($git['dirty_count'] ?? 0),
            'dirty_files_sample' => (array) ($git['dirty_files_sample'] ?? []),
            'workspace_path' => $workspace,
            'workspace_hash' => hash('sha256', $workspace),
            'tracked_python_bytecode' => $bytecode,
            'tracked_python_bytecode_present' => $hasTrackedBytecode,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkBaselineWorkspace(string $atlasWorkspace, ?string $baselineWorkspace, bool $intendsProviderBattery): array
    {
        if ($baselineWorkspace === null) {
            return [
                'status' => $intendsProviderBattery ? 'blocked' : 'not_required_for_dry_run',
                'provided' => false,
                'separate_from_atlas_workspace' => null,
                'is_git' => null,
                'clean' => null,
                'blocking_reason' => $intendsProviderBattery ? 'baseline_workspace_not_provided' : null,
            ];
        }

        $separate = rtrim($baselineWorkspace, DIRECTORY_SEPARATOR) !== rtrim($atlasWorkspace, DIRECTORY_SEPARATOR);
        $git = GitWorkspaceStateReader::read($baselineWorkspace);

        $blockingReason = null;
        if (! $separate) {
            $blockingReason = 'baseline_workspace_collides_with_atlas_workspace';
        } elseif (! ($git['is_git'] ?? false)) {
            $blockingReason = 'baseline_workspace_is_not_git_worktree';
        } elseif (! ($git['clean'] ?? false)) {
            $blockingReason = 'baseline_workspace_dirty';
        }

        return [
            'status' => $blockingReason === null ? 'passed' : 'blocked',
            'provided' => true,
            'separate_from_atlas_workspace' => $separate,
            'is_git' => (bool) ($git['is_git'] ?? false),
            'clean' => (bool) ($git['clean'] ?? false),
            'dirty_count' => (int) ($git['dirty_count'] ?? 0),
            'workspace_path' => $baselineWorkspace,
            'workspace_hash' => hash('sha256', $baselineWorkspace),
            'blocking_reason' => $blockingReason,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkForgeRuntimeAvailability(): array
    {
        $services = [
            AtlasForgeRuntimeCertificationService::class,
            AtlasForgeLiveExecutionService::class,
            AtlasCodeForgeFastPathService::class,
            AtlasCodeForgeFastPathStatusService::class,
            AtlasCodeForgeReviewCompletionService::class,
        ];

        $missing = [];
        foreach ($services as $service) {
            if (! class_exists($service)) {
                $missing[] = $service;
            }
        }

        return [
            'status' => $missing === [] ? 'passed' : 'blocked',
            'required_services' => $services,
            'missing_services' => $missing,
            'atlas_side_must_use_forge' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkForgeCommandsAvailability(): array
    {
        $commands = [
            'atlas:forge:runtime-certify' => AtlasForgeRuntimeCertifyCommand::class,
            'atlas:forge:live-execute' => AtlasForgeLiveExecuteCommand::class,
            'atlas:code:forge-fast-path' => AtlasCodeForgeFastPathCommand::class,
            'atlas:code:forge-fast-path-status' => AtlasCodeForgeFastPathStatusCommand::class,
            'atlas:code:forge-review' => AtlasCodeForgeReviewCommand::class,
        ];

        $missing = [];
        $present = [];
        foreach ($commands as $signature => $class) {
            if (class_exists($class)) {
                $present[$signature] = $class;
            } else {
                $missing[] = $signature;
            }
        }

        return [
            'status' => $missing === [] ? 'passed' : 'blocked',
            'required_commands' => array_keys($commands),
            'present_commands' => array_keys($present),
            'missing_commands' => $missing,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCanonicalDocs(string $workspace): array
    {
        $root = rtrim($workspace, DIRECTORY_SEPARATOR);
        $required = AtlasForgeNativeRivalsProtocolService::REQUIRED_CANONICAL_DOCS;
        $missing = [];
        $present = [];
        foreach ($required as $relative) {
            $path = $root.DIRECTORY_SEPARATOR.$relative;
            if (is_file($path)) {
                $present[] = $relative;
            } else {
                $missing[] = $relative;
            }
        }

        return [
            'status' => $missing === [] ? 'passed' : 'blocked',
            'required_docs' => $required,
            'present_docs' => $present,
            'missing_docs' => $missing,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCaseManifest(?string $caseId): array
    {
        $manifest = $this->caseManifest->manifest($caseId);
        $valid = (bool) ($manifest['valid'] ?? false);
        $invalidReasons = (array) ($manifest['invalid_reasons'] ?? []);
        $caseResolved = $manifest['case_resolved'] ?? null;

        $case = $manifest['case'] ?? null;
        $atlasArmIsForge = is_array($case)
            && ($case['atlas_arm']['runtime'] ?? null) === 'forge'
            && ($case['atlas_arm']['atlas_side_must_use_forge'] ?? false) === true;

        return [
            'status' => $valid && $atlasArmIsForge ? 'passed' : 'blocked',
            'case_id_requested' => $caseId,
            'case_id_resolved' => $caseResolved,
            'available_cases' => (array) ($manifest['available_cases'] ?? []),
            'manifest_valid' => $valid,
            'atlas_arm_is_forge' => $atlasArmIsForge,
            'invalid_reasons' => $invalidReasons,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkOperatorApproval(bool $providerApproved, bool $runbookReviewed, bool $intendsProviderBattery): array
    {
        if (! $intendsProviderBattery) {
            return [
                'status' => 'not_required_for_dry_run',
                'provider_cost_approved' => $providerApproved,
                'runbook_reviewed' => $runbookReviewed,
                'intends_provider_battery' => false,
                'blocking_reason' => null,
            ];
        }

        $blocking = null;
        if (! $providerApproved) {
            $blocking = 'provider_cost_not_approved';
        } elseif (! $runbookReviewed) {
            $blocking = 'runbook_not_reviewed';
        }

        return [
            'status' => $blocking === null ? 'passed' : 'blocked',
            'provider_cost_approved' => $providerApproved,
            'runbook_reviewed' => $runbookReviewed,
            'intends_provider_battery' => true,
            'blocking_reason' => $blocking,
        ];
    }

    /**
     * @param  array<string,mixed>  $workspace
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $forgeRuntime
     * @param  array<string,mixed>  $forgeCommands
     * @param  array<string,mixed>  $docs
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $approval
     * @param  list<string>  $blockingReasons
     */
    private function decideStatus(
        array $workspace,
        array $baseline,
        array $forgeRuntime,
        array $forgeCommands,
        array $docs,
        array $manifest,
        array $approval,
        bool $intendsProviderBattery,
        array &$blockingReasons,
    ): string {
        $reasons = [];

        if (($forgeRuntime['status'] ?? null) !== 'passed') {
            $reasons[] = 'forge_runtime_unavailable';
        }
        if (($forgeCommands['status'] ?? null) !== 'passed') {
            $reasons[] = 'forge_commands_unavailable';
        }
        if (($docs['status'] ?? null) !== 'passed') {
            $reasons[] = 'canonical_docs_missing';
        }
        if (($manifest['status'] ?? null) !== 'passed') {
            $reasons[] = 'case_manifest_invalid';
            if (! ($manifest['atlas_arm_is_forge'] ?? false)) {
                $reasons[] = 'atlas_arm_not_forge';
            }
        }

        if (($workspace['status'] ?? null) !== 'passed') {
            if (($workspace['status'] ?? null) === 'blocked_tracked_python_bytecode') {
                $reasons[] = 'tracked_python_bytecode_in_workspace';
            } else {
                $reasons[] = 'workspace_dirty_or_not_git';
            }
        }
        if (($baseline['status'] ?? null) === 'blocked') {
            $reasons[] = (string) ($baseline['blocking_reason'] ?? 'baseline_workspace_invalid');
        }

        if ($intendsProviderBattery && ($approval['status'] ?? null) !== 'passed') {
            $reasons[] = (string) ($approval['blocking_reason'] ?? 'operator_approval_required');
        }

        $blockingReasons = array_values(array_unique($reasons));

        if (in_array('forge_runtime_unavailable', $blockingReasons, true)
            || in_array('forge_commands_unavailable', $blockingReasons, true)) {
            return 'blocked_missing_forge_runtime';
        }
        if (in_array('case_manifest_invalid', $blockingReasons, true) || in_array('atlas_arm_not_forge', $blockingReasons, true)) {
            return 'blocked_protocol_invalid';
        }
        if (in_array('canonical_docs_missing', $blockingReasons, true)) {
            return 'blocked_protocol_invalid';
        }
        if (in_array('tracked_python_bytecode_in_workspace', $blockingReasons, true)) {
            return 'blocked_tracked_python_bytecode';
        }
        if (in_array('workspace_dirty_or_not_git', $blockingReasons, true)) {
            return 'blocked_dirty_workspace';
        }
        if (in_array('baseline_workspace_collides_with_atlas_workspace', $blockingReasons, true)
            || in_array('baseline_workspace_is_not_git_worktree', $blockingReasons, true)
            || in_array('baseline_workspace_dirty', $blockingReasons, true)
            || in_array('baseline_workspace_not_provided', $blockingReasons, true)) {
            return 'blocked_missing_baseline_workspace';
        }
        if (in_array('provider_cost_not_approved', $blockingReasons, true)
            || in_array('runbook_not_reviewed', $blockingReasons, true)) {
            return 'blocked_requires_operator_approval';
        }

        return $intendsProviderBattery ? 'ready_for_provider_battery' : 'ready_for_dry_run';
    }

    /**
     * @param  list<string>  $blockingReasons
     */
    private function nextAction(string $status, array $blockingReasons): string
    {
        return match ($status) {
            'ready_for_dry_run' => 'Run `php artisan atlas:programming:rivals-forge-dry-run --case=<id> --json --strict` to plan a paired case without spending provider tokens.',
            'ready_for_provider_battery' => 'Operator approval registered; provider battery may be dispatched through governed runner. Forge-Native preflight does not run providers itself.',
            'blocked_missing_forge_runtime' => 'Install/repair Atlas Forge runtime services and commands before retrying preflight.',
            'blocked_protocol_invalid' => 'Fix protocol violations (case manifest must use Forge for the Atlas arm and reference canonical docs).',
            'blocked_dirty_workspace' => 'Clean the Atlas workspace (commit/stash) or use a separate clean worktree before retrying.',
            'blocked_tracked_python_bytecode' => 'Workspace tracks Python bytecode (.pyc/__pycache__). Every Python run regenerates those bytes and would mark the worktree dirty. Run the resolution_command shown in checks.workspace.tracked_python_bytecode and commit the cleanup before retrying preflight.',
            'blocked_missing_baseline_workspace' => 'Provide a clean baseline worktree distinct from the Atlas workspace via --baseline-workspace.',
            'blocked_requires_operator_approval' => 'Operator must review the runbook and confirm provider cost via --confirm-runbook-reviewed and --confirm-provider-cost.',
            default => 'Inspect blocking_reasons and re-run preflight: '.implode(', ', $blockingReasons),
        };
    }

    /**
     * @return array<string,mixed>
     */


}
