<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use RuntimeException;

final class AtlasFrontendExecutionRunbookService
{
    public const SCHEMA_VERSION = 'atlas.frontend.execution_runbook.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = trim((string) ($input['workspace'] ?? ''));
        $surface = trim((string) ($input['surface'] ?? 'programming.frontend')) ?: 'programming.frontend';
        $evidenceOutput = $this->evidenceOutput($input['evidence_output'] ?? null, $task, $workspace);

        $intake = $this->safeIntake($workspace);
        $workOrder = app(AtlasFrontendWorkOrderService::class)->compile($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);
        $evidenceKit = app(AtlasFrontendEvidenceKitService::class)->prepare([
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
            'output' => $evidenceOutput,
            'acceptance_criteria' => (bool) ($input['acceptance_criteria'] ?? false),
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'company_profile_ready' => (bool) ($input['company_profile_ready'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
        ]);

        $blockers = array_values(array_unique(array_merge(
            $this->prefix('repo', (array) ($intake['blockers'] ?? [])),
            $this->prefix('work_order', (array) ($workOrder['blockers'] ?? [])),
            $this->prefix('evidence_kit', (array) ($evidenceKit['blockers'] ?? [])),
        )));
        $warnings = array_values(array_unique(array_merge(
            $this->prefix('repo', (array) ($intake['warnings'] ?? [])),
            $this->prefix('work_order', (array) ($workOrder['warnings'] ?? [])),
            $this->prefix('evidence_kit', (array) ($evidenceKit['warnings'] ?? [])),
        )));

        $steps = $this->steps($intake, $workOrder, $evidenceKit, $workspace, $evidenceOutput);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'source' => self::class,
            'runbook_type' => 'company_frontend_repo_execution_runbook',
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'task_spec_hash' => $evidenceKit['task_spec_hash'] ?? null,
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
                'commands_must_be_run_in_operator_repo' => true,
                'completion_requires_run_certification_and_handoff' => true,
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
    private function steps(array $intake, array $workOrder, array $evidenceKit, string $workspace, string $evidenceOutput): array
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
                $this->installCommand($packageManager),
                $devCommands[0] ?? null,
            ])), ['install_receipt_or_reason', 'local_preview_url']),
            $this->step('implementation_quality_gates', 30, 'Run repo-native quality, tests and build before visual certification.', array_values(array_merge($qualityCommands, $testCommands, $buildCommands)), ['test_receipt', 'build_receipt', 'quality_receipt']),
            $this->step('visual_evidence_collection', 40, 'Prepare and fill measured frontend evidence artifacts.', array_values(array_filter(array_merge([
                'php artisan atlas:frontend:evidence-kit prepare --task="<intent>" '.$this->workspaceArg($workspace).' --acceptance --output='.$this->quote($evidenceOutput).' --json --strict',
            ], (array) ($evidenceKit['collection_commands'] ?? [])), 'is_string')), ['visual_quality_report', 'quality_budget_report', 'design_review_report', 'evidence_pack']),
            $this->step('certification_and_handoff', 50, 'Certify the run and compile customer-safe handoff.', [
                'php artisan atlas:frontend:run-certify --visual-report='.$this->quote($evidenceOutput.'/visual-quality-report.json').' --design-review-report='.$this->quote($evidenceOutput.'/design-review-report.json').' --quality-budget-report='.$this->quote($evidenceOutput.'/quality-budget-report.json').' --evidence-manifest='.$this->quote($evidenceOutput.'/evidence/evidence-pack.json').' --outcome-store='.$this->quote($evidenceOutput.'/outcomes.jsonl').' --json --strict',
                'php artisan atlas:frontend:handoff compile --run-certification=<run-certification-report> --evidence-manifest='.$this->quote($evidenceOutput.'/evidence/evidence-pack.json').' --json --strict',
            ], ['run_certification_hash', 'handoff_hash']),
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

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    private function prefix(string $prefix, array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => is_string($item))
            ->map(fn (string $item): string => $prefix.'_'.$item)
            ->values()
            ->all();
    }

    private function workspaceArg(string $workspace): string
    {
        return $workspace !== '' ? '--workspace='.$this->quote($workspace) : '--workspace=<local-company-repo>';
    }

    private function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
