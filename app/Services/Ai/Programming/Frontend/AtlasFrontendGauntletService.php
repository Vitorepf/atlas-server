<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use RuntimeException;

final class AtlasFrontendGauntletService
{
    public const SCHEMA_VERSION = 'atlas.frontend.gauntlet.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = trim((string) ($input['workspace'] ?? ''));
        $frontendApp = trim((string) ($input['frontend_app'] ?? ''));
        $frontendAppScope = $this->frontendAppScope($workspace, $frontendApp);
        $surface = trim((string) ($input['surface'] ?? 'programming.frontend')) ?: 'programming.frontend';

        $contract = app(AtlasFrontendDesignRuntimeService::class)->contract([
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
            'acceptance_criteria' => (bool) ($input['acceptance_criteria'] ?? false),
            'asset_provenance' => (bool) ($input['asset_context'] ?? false),
            'benchmark_run' => (bool) ($input['benchmark_run'] ?? false),
        ]);
        $taskSpec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
            'frontend_app' => $frontendApp,
            'acceptance' => (bool) ($input['acceptance_criteria'] ?? false),
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'company_profile' => (bool) ($input['company_profile_ready'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
        ]);
        $gate = app(AtlasFrontendExecutionGateService::class)->evaluate([
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
            'frontend_app' => $frontendApp,
            'task_spec_hash' => (string) ($taskSpec['task_spec_hash'] ?? ''),
            'acceptance_criteria' => (bool) ($input['acceptance_criteria'] ?? false),
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'company_profile_ready' => (bool) ($input['company_profile_ready'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
            'test_plan' => (bool) ($input['test_plan'] ?? false),
            'visual_quality_plan' => (bool) ($input['visual_quality_plan'] ?? false),
            'evidence_plan' => (bool) ($input['evidence_plan'] ?? false),
            'senior_design_review' => (bool) ($input['senior_design_review'] ?? false),
        ]);
        $dossier = app(AtlasFrontendDesignDossierService::class)->inspect($workspace);
        $inventory = $this->safeInventory($workspace);
        $intake = $this->safeIntake($workspace);
        $blueprint = app(AtlasFrontendProductBlueprintService::class)->generate([
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
            'frontend_app' => $frontendApp,
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
        ]);
        $runtimeCertification = app(AtlasFrontendDesignRuntimeService::class)->certify();

        $phases = [
            $this->phase('runtime_contract', $contract['status'] ?? 'unknown', $contract['contract_hash'] ?? null, $contract['blockers'] ?? [], $contract['warnings'] ?? []),
            $this->phase('task_spec', $taskSpec['status'] ?? 'unknown', $taskSpec['task_spec_hash'] ?? null, $taskSpec['blockers'] ?? [], $taskSpec['warnings'] ?? []),
            $this->phase('pre_execution_gate', $gate['status'] ?? 'unknown', $gate['gate_hash'] ?? null, $gate['blockers'] ?? [], $gate['warnings'] ?? []),
            $this->phase('company_design_dossier', $dossier['status'] ?? 'unknown', $dossier['dossier_hash'] ?? null, $dossier['blockers'] ?? [], $dossier['warnings'] ?? []),
            $this->phase('product_blueprint', $blueprint['status'] ?? 'unknown', $blueprint['blueprint_hash'] ?? null, $blueprint['blockers'] ?? [], $blueprint['warnings'] ?? []),
            $this->phase('repo_intake', $intake['status'] ?? 'unknown', $intake['repo_intake_hash'] ?? null, $intake['blockers'] ?? [], $intake['warnings'] ?? []),
            $this->phase('design_system_inventory', $inventory['status'] ?? 'unknown', $inventory['inventory_hash'] ?? null, $inventory['blockers'] ?? [], $inventory['warnings'] ?? []),
            $this->phase('runtime_certification', $runtimeCertification['status'] ?? 'unknown', $runtimeCertification['certification_hash'] ?? null, $runtimeCertification['blockers'] ?? [], $runtimeCertification['warnings'] ?? []),
        ];

        $frontendAppScopeBlockers = (array) ($frontendAppScope['blockers'] ?? []);
        $blocked = $frontendAppScopeBlockers !== [] || collect($phases)->contains(fn (array $phase): bool => in_array($phase['status'], ['blocked', 'failed', 'missing'], true) || $phase['blocker_count'] > 0);
        $warnings = collect($phases)->contains(fn (array $phase): bool => in_array($phase['status'], ['partial', 'warning'], true) || $phase['warning_count'] > 0);
        $status = $blocked ? 'blocked' : ($warnings ? 'warning' : 'ready');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'source' => self::class,
            'operating_mode' => 'company_owned_local_repo_frontend_gauntlet',
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? null,
            'frontend_app_scope' => $frontendAppScope,
            'phase_results' => $phases,
            'required_next_actions' => array_values(array_unique(array_merge(
                $frontendAppScopeBlockers !== [] ? ['choose_valid_frontend_app_subscope_inside_selected_repo'] : [],
                $this->nextActions($gate, $dossier, $inventory, $blueprint, $intake),
            ))),
            'recommended_command_sequence' => $this->recommendedCommandSequence($workspace, $taskSpec['task_spec_hash'] ?? null, $frontendAppScope),
            'claim_policy' => [
                'provider_dispatch_allowed' => $status !== 'blocked' && (bool) ($gate['execution_allowed'] ?? false),
                'premium_frontend_claim_allowed' => $status === 'ready',
                'frontend_app_scope_is_relative_subdirectory' => ($frontendAppScope['status'] ?? null) === 'subscope_selected',
                'template_docs_do_not_count_as_context' => true,
                'world_best_claim_allowed' => false,
                'raw_customer_source_returned' => false,
            ],
        ];
        $payload['gauntlet_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,mixed>  $blockers
     * @param  array<int,mixed>  $warnings
     * @return array<string,mixed>
     */
    private function phase(string $id, mixed $status, mixed $hash, array $blockers, array $warnings): array
    {
        return [
            'id' => $id,
            'status' => is_string($status) ? $status : 'unknown',
            'hash' => is_string($hash) ? $hash : null,
            'blocker_count' => count($blockers),
            'warning_count' => count($warnings),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safeInventory(string $workspace): array
    {
        try {
            return app(AtlasFrontendDesignSystemInventoryService::class)->inspect($workspace);
        } catch (RuntimeException $exception) {
            return [
                'schema_version' => AtlasFrontendDesignSystemInventoryService::SCHEMA_VERSION,
                'status' => 'blocked',
                'inventory_hash' => null,
                'blockers' => [$exception->getMessage()],
                'warnings' => [],
            ];
        }
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
                'repo_intake_hash' => null,
                'blockers' => [$exception->getMessage()],
                'warnings' => [],
                'recommended_next_actions' => ['confirm_frontend_workspace_or_create_package_manifest'],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>  $dossier
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $blueprint
     * @return array<int,string>
     */
    private function nextActions(array $gate, array $dossier, array $inventory, array $blueprint, array $intake): array
    {
        $actions = array_values(array_filter((array) ($gate['required_next_actions'] ?? []), 'is_string'));
        if (($dossier['status'] ?? null) !== 'ready') {
            $actions[] = 'fill_company_design_dossier_docs';
        }
        if (in_array(($blueprint['status'] ?? null), ['blocked', 'warning'], true)) {
            $actions[] = 'generate_or_write_product_blueprint';
        }
        if (($inventory['status'] ?? null) !== 'ready') {
            $actions[] = 'run_or_improve_design_system_inventory';
        }
        if (($intake['status'] ?? null) !== 'ready') {
            array_push($actions, ...array_values(array_filter((array) ($intake['recommended_next_actions'] ?? []), 'is_string')));
        }

        return array_values(array_unique($actions));
    }

    /**
     * @return array<int,string>
     */
    private function recommendedCommandSequence(string $workspace, mixed $taskSpecHash, array $frontendAppScope): array
    {
        $workspaceArg = $workspace !== '' ? '--workspace='.escapeshellarg($workspace) : '--workspace=<local-company-repo>';
        $hashArg = is_string($taskSpecHash) ? '--task-spec-hash='.$taskSpecHash : '--task-spec-hash=<hash>';
        $frontendAppArg = ($frontendAppScope['status'] ?? null) === 'subscope_selected'
            ? ' --frontend-app='.(string) $frontendAppScope['relative_name']
            : '';

        return [
            'php artisan atlas:frontend:blueprint generate --task="<brief>" '.$workspaceArg.$frontendAppArg.' --json',
            'php artisan atlas:frontend:intake '.$workspaceArg.' --json --strict',
            'php artisan atlas:frontend:design-dossier inspect '.$workspaceArg.' --json --strict',
            'php artisan atlas:frontend:inventory inspect '.$workspaceArg.' --json',
            'php artisan atlas:frontend:spec --task="<brief>" '.$workspaceArg.$frontendAppArg.' --acceptance --json',
            'php artisan atlas:frontend:gate --task="<brief>" '.$workspaceArg.$frontendAppArg.' '.$hashArg.' --acceptance --test-plan --visual-quality-plan --evidence-plan --json --strict',
            'php artisan atlas:frontend:scenarios --task="<brief>" '.$workspaceArg.$frontendAppArg.' --acceptance --json --strict',
            'php artisan atlas:frontend:evidence-kit prepare --task="<brief>" '.$workspaceArg.$frontendAppArg.' --acceptance --output=<evidence-dir> --json --strict',
            'php artisan atlas:frontend:visual-quality template --output=<evidence-dir> --json',
            'php artisan atlas:frontend:quality-budget template --output=<evidence-dir> --json',
            'php artisan atlas:frontend:run-certify --visual-report=<report> --design-review-report=<report> --quality-budget-report=<report> --evidence-manifest=<manifest> --outcome-store=<jsonl> --json --strict',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function frontendAppScope(string $workspace, string $frontendApp): array
    {
        $raw = trim($frontendApp);
        $relative = trim(str_replace('\\', '/', $raw), '/');

        if ($relative === '' || $relative === '.') {
            return [
                'status' => 'repo_root',
                'relative_name' => null,
                'relative_name_hash' => null,
                'repo_workspace_remains_primary' => true,
                'raw_absolute_path_returned' => false,
                'blockers' => [],
            ];
        }

        if (str_contains($relative, '..') || str_starts_with($raw, '/') || str_contains($relative, '//')) {
            return [
                'status' => 'invalid_subscope',
                'relative_name' => null,
                'relative_name_hash' => hash('sha256', $relative),
                'repo_workspace_remains_primary' => true,
                'raw_absolute_path_returned' => false,
                'blockers' => ['frontend_app_scope_invalid_relative_frontend_app_subscope'],
            ];
        }

        if ($workspace !== '' && is_dir($workspace) && ! is_dir(rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
            return [
                'status' => 'missing_subscope',
                'relative_name' => $relative,
                'relative_name_hash' => hash('sha256', $relative),
                'repo_workspace_remains_primary' => true,
                'raw_absolute_path_returned' => false,
                'blockers' => ['frontend_app_scope_frontend_app_subscope_directory_missing'],
            ];
        }

        return [
            'status' => 'subscope_selected',
            'relative_name' => $relative,
            'relative_name_hash' => hash('sha256', $relative),
            'repo_workspace_remains_primary' => true,
            'raw_absolute_path_returned' => false,
            'blockers' => [],
        ];
    }
}
