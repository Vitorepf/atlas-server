<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class AtlasFrontendEnterpriseBootstrapService
{
    public const SCHEMA_VERSION = 'atlas.frontend.enterprise_bootstrap.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = rtrim(trim((string) ($input['workspace'] ?? '')), DIRECTORY_SEPARATOR);
        $frontendApp = trim((string) ($input['frontend_app'] ?? ''));
        $write = (bool) ($input['write'] ?? false);
        $surface = trim((string) ($input['surface'] ?? 'programming.frontend')) ?: 'programming.frontend';

        $writeResult = $write
            ? $this->writeBootstrapArtifacts($workspace, $task, $surface, $input)
            : [
                'status' => 'not_requested',
                'written_artifacts' => [],
                'blockers' => [],
                'warnings' => ['write_not_requested'],
            ];

        $dossier = app(AtlasFrontendDesignDossierService::class)->inspect($workspace);
        $blueprint = app(AtlasFrontendProductBlueprintService::class)->generate($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);
        $intake = $this->safeIntake($workspace);
        $gauntlet = app(AtlasFrontendGauntletService::class)->run($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);
        $workOrder = app(AtlasFrontendWorkOrderService::class)->compile($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);

        $blockers = array_values(array_unique(array_merge(
            (array) ($writeResult['blockers'] ?? []),
            $this->prefixedBlockers('dossier', (array) ($dossier['blockers'] ?? [])),
            $this->prefixedBlockers('repo', (array) ($intake['blockers'] ?? [])),
            $this->prefixedBlockers('gauntlet', (array) ($gauntlet['blockers'] ?? [])),
            $this->prefixedBlockers('work_order', (array) ($workOrder['blockers'] ?? [])),
            (array) ($blueprint['blockers'] ?? []),
        )));
        $warnings = array_values(array_unique(array_merge(
            (array) ($writeResult['warnings'] ?? []),
            $this->prefixedBlockers('dossier', (array) ($dossier['warnings'] ?? [])),
            $this->prefixedBlockers('repo', (array) ($intake['warnings'] ?? [])),
            $this->prefixedBlockers('gauntlet', (array) ($gauntlet['warnings'] ?? [])),
            $this->prefixedBlockers('work_order', (array) ($workOrder['warnings'] ?? [])),
            (array) ($blueprint['warnings'] ?? []),
        )));
        $ready = $blockers === []
            && ($dossier['status'] ?? null) === 'ready'
            && ($intake['status'] ?? null) === 'ready'
            && in_array($gauntlet['status'] ?? null, ['ready', 'warning'], true)
            && ($workOrder['status'] ?? null) === 'ready';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $ready ? 'ready' : 'blocked',
            'source' => self::class,
            'bootstrap_type' => 'company_owned_local_repo_premium_frontend_bootstrap',
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'frontend_app_scope' => $workOrder['frontend_app_scope'] ?? $gauntlet['frontend_app_scope'] ?? [
                'status' => 'repo_root',
                'relative_name' => null,
                'relative_name_hash' => null,
            ],
            'company_work_mode' => $this->companyWorkMode($task, $workspace),
            'write_result' => $writeResult,
            'readiness' => [
                'workspace_exists' => $workspace !== '' && File::isDirectory($workspace),
                'design_dossier_ready' => ($dossier['status'] ?? null) === 'ready',
                'product_blueprint_ready' => in_array($blueprint['status'] ?? null, ['ready', 'warning'], true),
                'repo_intake_ready' => ($intake['status'] ?? null) === 'ready',
                'gauntlet_ready' => in_array($gauntlet['status'] ?? null, ['ready', 'warning'], true),
                'work_order_ready' => ($workOrder['status'] ?? null) === 'ready',
                'provider_dispatch_allowed' => (bool) data_get($workOrder, 'dispatch_policy.provider_dispatch_allowed'),
                'premium_frontend_claim_allowed' => $ready,
                'world_best_claim_allowed' => false,
            ],
            'hash_refs' => [
                'dossier_hash' => $dossier['dossier_hash'] ?? null,
                'blueprint_hash' => $blueprint['blueprint_hash'] ?? null,
                'repo_intake_hash' => $intake['repo_intake_hash'] ?? null,
                'gauntlet_hash' => $gauntlet['gauntlet_hash'] ?? null,
                'work_order_hash' => $workOrder['work_order_hash'] ?? null,
            ],
            'required_docs' => app(AtlasFrontendDesignDossierService::class)->requiredDocuments(),
            'required_next_actions' => $this->nextActions($write, $dossier, $blueprint, $intake, $gauntlet, $workOrder),
            'recommended_command_sequence' => $this->recommendedCommandSequence($workspace, $frontendApp),
            'claim_policy' => [
                'enterprise_bootstrap_is_not_completion_evidence' => true,
                'missing_docs_must_be_filled_by_operator_or_product_owner' => true,
                'template_docs_do_not_count_as_ready_context' => true,
                'provider_dispatch_requires_ready_work_order' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $payload['enterprise_bootstrap_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function writeBootstrapArtifacts(string $workspace, string $task, string $surface, array $input): array
    {
        if ($workspace === '' || ! File::isDirectory($workspace)) {
            return [
                'status' => 'blocked',
                'written_artifacts' => [],
                'blockers' => ['workspace_missing'],
                'warnings' => [],
            ];
        }

        $dossier = app(AtlasFrontendDesignDossierService::class)->writeTemplate($workspace);
        $written = ['design_dossier_template'];
        $warnings = [];
        if ($task !== '') {
            $blueprint = app(AtlasFrontendProductBlueprintService::class)->writeDocument($input + [
                'task' => $task,
                'workspace' => $workspace,
                'surface' => $surface,
            ]);
            $written[] = 'product_blueprint_document';
        } else {
            $blueprint = null;
            $warnings[] = 'task_missing_blueprint_document_not_written';
        }

        return [
            'status' => 'written',
            'written_artifacts' => $written,
            'dossier_template_hash' => $dossier['template_hash'] ?? null,
            'blueprint_document_hash' => is_array($blueprint) ? ($blueprint['document_hash'] ?? null) : null,
            'blockers' => [],
            'warnings' => $warnings,
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
                'repo_intake_hash' => null,
                'blockers' => [$exception->getMessage()],
                'warnings' => [],
                'recommended_next_actions' => ['confirm_frontend_workspace_or_create_package_manifest'],
            ];
        }
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    private function prefixedBlockers(string $prefix, array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => is_string($item))
            ->map(fn (string $item): string => $prefix.'_'.$item)
            ->values()
            ->all();
    }

    private function companyWorkMode(string $task, string $workspace): string
    {
        $haystack = strtolower($task.' '.$workspace);

        return match (true) {
            str_contains($haystack, 'blackink') => 'existing_company_blackink_refinement',
            str_contains($haystack, 'refinar') => 'existing_company_refinar_refinement',
            str_contains($haystack, 'saas') || str_contains($haystack, 'novo') || str_contains($haystack, 'new') => 'new_saas_or_product_creation',
            str_contains($haystack, 'redesign') || str_contains($haystack, 'premium') => 'existing_company_premium_redesign',
            default => 'company_frontend_product_work',
        };
    }

    /**
     * @param  array<string,mixed>  $dossier
     * @param  array<string,mixed>  $blueprint
     * @param  array<string,mixed>  $intake
     * @param  array<string,mixed>  $gauntlet
     * @param  array<string,mixed>  $workOrder
     * @return array<int,string>
     */
    private function nextActions(bool $write, array $dossier, array $blueprint, array $intake, array $gauntlet, array $workOrder): array
    {
        $actions = [];
        if (! $write && ($dossier['status'] ?? null) !== 'ready') {
            $actions[] = 'run_enterprise_bootstrap_write_and_fill_design_docs';
        }
        if (($dossier['status'] ?? null) !== 'ready') {
            $actions[] = 'fill_company_frontend_design_dossier';
        }
        if (($blueprint['status'] ?? null) === 'blocked') {
            $actions[] = 'provide_frontend_product_task_before_blueprint';
        }
        array_push($actions, ...array_values(array_filter((array) ($intake['recommended_next_actions'] ?? []), 'is_string')));
        array_push($actions, ...array_values(array_filter((array) ($gauntlet['required_next_actions'] ?? []), 'is_string')));
        array_push($actions, ...array_values(array_filter((array) ($workOrder['required_next_actions'] ?? []), 'is_string')));

        return array_values(array_unique($actions));
    }

    /**
     * @return array<int,string>
     */
    private function recommendedCommandSequence(string $workspace, string $frontendApp): array
    {
        $workspaceArg = $workspace !== '' ? '--workspace='.escapeshellarg($workspace) : '--workspace=<local-company-repo>';
        $relativeFrontendApp = trim(str_replace('\\', '/', $frontendApp), '/');
        $frontendAppArg = $relativeFrontendApp !== '' ? ' --frontend-app='.$relativeFrontendApp : '';

        return [
            'php artisan atlas:frontend:enterprise-bootstrap write --task="<intent>" '.$workspaceArg.' --json',
            'php artisan atlas:frontend:design-dossier inspect '.$workspaceArg.' --json --strict',
            'php artisan atlas:frontend:intake '.$workspaceArg.' --json --strict',
            'php artisan atlas:frontend:work-order --task="<intent>" '.$workspaceArg.$frontendAppArg.' --acceptance --test-plan --visual-quality-plan --evidence-plan --json --strict',
        ];
    }
}
