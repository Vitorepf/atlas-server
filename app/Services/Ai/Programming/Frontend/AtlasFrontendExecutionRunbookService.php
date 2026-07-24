<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use RuntimeException;

final class AtlasFrontendExecutionRunbookService
{
    use FrontendPrefixHelper;

    public const SCHEMA_VERSION = 'atlas.frontend.execution_runbook.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = trim((string) ($input['workspace'] ?? ''));
        $surface = AtlasFrontendSurface::fromInput($input);
        $frontendAppScope = AtlasFrontendAppScope::fromRequestedApp($workspace, (string) ($input['frontend_app'] ?? ''), [
            'schema_version' => 'atlas.frontend.execution_runbook.frontend_app_scope.v1',
            'include_raw_absolute_path_returned' => true,
            'include_root_blockers' => true,
            'dot_is_repo_root' => true,
            'reject_double_slash' => true,
            'invalid_blocker' => 'invalid_relative_frontend_app_subscope',
            'missing_blocker' => 'frontend_app_subscope_directory_missing',
            'workspace_required_status' => 'workspace_unavailable',
            'workspace_required_blocker' => 'workspace_required_to_validate_frontend_app_subscope',
            'require_package_manifest' => true,
            'missing_package_manifest_blocker' => 'frontend_app_subscope_package_manifest_missing',
        ]);
        $frontendApp = $frontendAppScope['status'] === 'subscope_selected'
            ? (string) $frontendAppScope['relative_name']
            : null;
        $evidenceOutput = $this->evidenceOutput($input['evidence_output'] ?? null, $task, $workspace);
        $writeEvidenceKit = (bool) ($input['write_evidence_kit'] ?? true);

        $intake = $this->safeIntake($workspace);
        $workOrder = app(AtlasFrontendWorkOrderService::class)->compile($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);
        $evidenceKitInput = [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
            'output' => $evidenceOutput,
            'acceptance_criteria' => (bool) ($input['acceptance_criteria'] ?? false),
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'company_profile_ready' => (bool) ($input['company_profile_ready'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
        ];
        $evidenceKit = $writeEvidenceKit
            ? app(AtlasFrontendEvidenceKitService::class)->prepare($evidenceKitInput)
            : $this->evidenceKitProjection($evidenceKitInput, $evidenceOutput);

        $blockers = array_values(array_unique(array_merge(
            $this->prefix('repo', (array) ($intake['blockers'] ?? [])),
            $this->prefix('frontend_app_scope', (array) ($frontendAppScope['blockers'] ?? [])),
            $this->prefix('work_order', (array) ($workOrder['blockers'] ?? [])),
            $this->prefix('evidence_kit', (array) ($evidenceKit['blockers'] ?? [])),
        )));
        $warnings = array_values(array_unique(array_merge(
            $this->prefix('repo', (array) ($intake['warnings'] ?? [])),
            $this->prefix('work_order', (array) ($workOrder['warnings'] ?? [])),
            $this->prefix('evidence_kit', (array) ($evidenceKit['warnings'] ?? [])),
        )));

        $steps = $this->steps($intake, $workOrder, $evidenceKit, $workspace, $evidenceOutput, $frontendApp);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'source' => self::class,
            'runbook_type' => 'company_frontend_repo_execution_runbook',
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'task_spec_hash' => $evidenceKit['task_spec_hash'] ?? null,
            'write_evidence_kit' => $writeEvidenceKit,
            'frontend_app_scope' => $frontendAppScope,
            'repo_operating_map' => [
                'package_manager' => $intake['package_manager'] ?? 'unknown',
                'framework' => data_get($intake, 'framework.primary'),
                'default_url' => data_get($intake, 'framework.default_url'),
                'test_commands' => data_get($intake, 'repo_map.test_commands', []),
                'build_commands' => data_get($intake, 'repo_map.build_commands', []),
                'quality_commands' => data_get($intake, 'repo_map.quality_commands', []),
            ],
            'runbook_steps' => $steps,
            'step_count' => count($steps),
            'required_next_actions' => $blockers === []
                ? ['review_runbook_then_execute_commands_in_repo_with_receipts']
                : array_values(array_unique(array_merge(
                    (array) ($intake['recommended_next_actions'] ?? []),
                    (array) ($workOrder['required_next_actions'] ?? []),
                    (array) ($evidenceKit['required_next_actions'] ?? []),
                ))),
            'claim_policy' => [
                'runbook_is_not_execution_evidence' => true,
                'read_only_runbook_does_not_write_evidence_kit' => ! $writeEvidenceKit,
                'commands_must_be_run_in_operator_repo' => true,
                'frontend_app_scope_is_relative_subdirectory' => true,
                'completion_requires_run_certification_and_handoff' => true,
                'public_distribution_requires_publication_attestation' => true,
                'public_distribution_claim_requires_verified_receipt' => true,
                'public_distribution_step_is_not_required_for_customer_handoff' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $payload['runbook_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    private function evidenceOutput(mixed $value, string $task, string $workspace): string
    {
        $output = is_string($value) ? trim($value) : '';
        if ($output !== '' && $output !== '<evidence-dir>') {
            return $output;
        }

        return storage_path('app/atlas/frontend-evidence-kit/'.hash('sha256', $task.'|'.$workspace));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function evidenceKitProjection(array $input, string $evidenceOutput): array
    {
        $scenarioMatrix = app(AtlasFrontendScenarioMatrixService::class)->compile($input);
        $blockers = [];
        if (($scenarioMatrix['status'] ?? null) !== 'ready') {
            $blockers[] = 'scenario_matrix_not_ready';
        }

        $payload = [
            'schema_version' => AtlasFrontendEvidenceKitService::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'kit_type' => 'read_only_frontend_execution_evidence_collection_projection',
            'task_spec_hash' => $scenarioMatrix['task_spec_hash'] ?? null,
            'scenario_matrix_hash' => $scenarioMatrix['scenario_matrix_hash'] ?? null,
            'output_hash' => hash('sha256', $evidenceOutput),
            'collection_commands' => $this->collectionCommands($evidenceOutput),
            'claim_policy' => [
                'evidence_kit_projection_is_not_completion_evidence' => true,
                'templates_not_written' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => ['evidence_kit_not_written_read_only'],
        ];
        $payload['evidence_kit_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function collectionCommands(string $output): array
    {
        $outputArg = $this->quote($output);

        return [
            'php artisan atlas:frontend:scenarios --task="<intent>" --workspace=<local-company-repo> --acceptance --json --strict',
            'php artisan atlas:frontend:evidence-kit prepare --task="<intent>" --workspace=<local-company-repo> --acceptance --output='.$outputArg.' --json --strict',
            'php artisan atlas:frontend:visual-quality inspect --report='.$outputArg.'/visual-quality-report.json --json --strict',
            'php artisan atlas:frontend:quality-budget inspect --report='.$outputArg.'/quality-budget-report.json --json --strict',
            'php artisan atlas:frontend:review inspect --report='.$outputArg.'/design-review-report.json --json --strict',
            'php artisan atlas:frontend:evidence verify --manifest='.$outputArg.'/evidence/evidence-pack.json --root='.$outputArg.'/evidence --json',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safeIntake(string $workspace): array
    {
        try {
            return app(AtlasFrontendRepoIntakeService::class)->inspect($workspace);
        } catch (RuntimeException $exception) {
            return [
                'schema_version' => AtlasFrontendRepoIntakeService::SCHEMA_VERSION,
                'status' => 'blocked',
                'package_manager' => 'unknown',
                'blockers' => [$exception->getMessage()],
                'warnings' => [],
                'recommended_next_actions' => ['confirm_frontend_workspace_or_create_package_manifest'],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $intake
     * @param  array<string,mixed>  $workOrder
     * @param  array<string,mixed>  $evidenceKit
     * @return array<int,array<string,mixed>>
     */
    private function steps(array $intake, array $workOrder, array $evidenceKit, string $workspace, string $evidenceOutput, ?string $frontendApp): array
    {
        $packageManager = (string) ($intake['package_manager'] ?? 'unknown');
        $testCommands = array_values(array_filter((array) data_get($intake, 'repo_map.test_commands', []), 'is_string'));
        $buildCommands = array_values(array_filter((array) data_get($intake, 'repo_map.build_commands', []), 'is_string'));
        $qualityCommands = array_values(array_filter((array) data_get($intake, 'repo_map.quality_commands', []), 'is_string'));
        $devCommands = array_values(array_filter((array) data_get($intake, 'framework.dev_command_candidates', []), 'is_string'));

        return [
            $this->step('context_preflight', 10, 'Prepare and verify company frontend context before provider edits.', [
                'php artisan atlas:frontend:enterprise-bootstrap inspect --task="<intent>" '.$this->workspaceArg($workspace).' --json --strict',
                'php artisan atlas:frontend:work-order --task="<intent>" '.$this->workspaceArg($workspace).' --acceptance --test-plan --visual-quality-plan --evidence-plan --json --strict',
            ], ['enterprise_bootstrap_hash', 'work_order_hash']),
            $this->step('repo_install_and_dev_server', 20, 'Install dependencies and start local preview using repo-native commands.', array_values(array_filter([
                $this->repoNativeCommand($this->installCommand($packageManager), $frontendApp),
                $this->repoNativeCommand($devCommands[0] ?? null, $frontendApp),
            ])), ['install_receipt_or_reason', 'local_preview_url']),
            $this->step('implementation_quality_gates', 30, 'Run repo-native quality, tests and build before visual certification.', array_map(fn (string $command): string => $this->repoNativeCommand($command, $frontendApp), array_values(array_merge($qualityCommands, $testCommands, $buildCommands))), ['test_receipt', 'build_receipt', 'quality_receipt']),
            $this->step('visual_evidence_collection', 40, 'Prepare and fill measured frontend evidence artifacts.', array_values(array_filter(array_merge([
                'php artisan atlas:frontend:evidence-kit prepare --task="<intent>" '.$this->workspaceArg($workspace).' --acceptance --output='.$this->quote($evidenceOutput).' --json --strict',
            ], (array) ($evidenceKit['collection_commands'] ?? [])), 'is_string')), ['visual_quality_report', 'quality_budget_report', 'design_review_report', 'evidence_pack']),
            $this->step('certification_and_handoff', 50, 'Certify the run and compile customer-safe handoff.', [
                'php artisan atlas:frontend:run-certify --provider-packet=<provider-packet> --visual-report='.$this->quote($evidenceOutput.'/visual-quality-report.json').' --design-review-report='.$this->quote($evidenceOutput.'/design-review-report.json').' --quality-budget-report='.$this->quote($evidenceOutput.'/quality-budget-report.json').' --evidence-manifest='.$this->quote($evidenceOutput.'/evidence/evidence-pack.json').' --outcome-store='.$this->quote($evidenceOutput.'/outcomes.jsonl').' --json --strict',
                'php artisan atlas:frontend:handoff compile --run-certification=<run-certification-report> --evidence-manifest='.$this->quote($evidenceOutput.'/evidence/evidence-pack.json').' --json --strict',
            ], ['run_certification_hash', 'handoff_hash']),
            $this->step('private_benchmark_and_optional_publication_proof', 60, 'Prepare private competitive benchmark proof; publication receipt is optional audit evidence and does not authorize public superiority claims.', [
                'php artisan atlas:frontend:proof build --output=<bundle> --json',
                'php artisan atlas:frontend:publish receipt-template --bundle=<bundle> --output=<bundle> --json',
                'php artisan atlas:frontend:publish attest --bundle=<bundle> --receipt=<receipt> --json',
                'php artisan atlas:frontend:publish verify --bundle=<bundle> --receipt=<receipt> --json --strict',
                'php artisan atlas:frontend:private-benchmark-plan --rival-evidence=<dir> --bundle=<bundle> --publication-receipt=<receipt> --json --strict',
            ], ['product_proof_bundle_hash', 'publication_attestation_hash', 'optional_publication_receipt', 'private_benchmark_plan_hash']),
        ];
    }

    /**
     * @param  array<int,string>  $commands
     * @param  array<int,string>  $evidence
     * @return array<string,mixed>
     */
    private function step(string $id, int $sequence, string $objective, array $commands, array $evidence): array
    {
        return [
            'id' => $id,
            'sequence' => $sequence,
            'objective' => $objective,
            'commands' => $commands,
            'required_evidence' => $evidence,
            'claim_policy' => [
                'step_done_requires_receipt' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    private function installCommand(string $packageManager): ?string
    {
        return match ($packageManager) {
            'pnpm' => 'pnpm install --frozen-lockfile',
            'yarn' => 'yarn install --frozen-lockfile',
            'bun' => 'bun install --frozen-lockfile',
            'npm' => 'npm ci',
            default => null,
        };
    }

    private function repoNativeCommand(?string $command, ?string $frontendApp): ?string
    {
        if ($command === null || trim($command) === '') {
            return null;
        }
        if ($frontendApp === null) {
            return $command;
        }

        return 'cd '.$this->quote($frontendApp).' && '.$command;
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    private function workspaceArg(string $workspace): string
    {
        return $workspace !== '' ? '--workspace='.$this->quote($workspace) : '--workspace=<local-company-repo>';
    }

    private function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
