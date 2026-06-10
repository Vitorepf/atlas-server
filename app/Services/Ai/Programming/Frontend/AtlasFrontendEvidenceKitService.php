<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

final class AtlasFrontendEvidenceKitService
{
    public const SCHEMA_VERSION = 'atlas.frontend.evidence_kit.v1';

    public const MANIFEST_SCHEMA_VERSION = 'atlas.frontend.evidence_kit_manifest.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepare(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = trim((string) ($input['workspace'] ?? ''));
        $frontendApp = AtlasFrontendAppScope::relativeName($input['frontend_app'] ?? null);
        $surface = AtlasFrontendSurface::fromInput($input);
        $output = rtrim(trim((string) ($input['output'] ?? '')), DIRECTORY_SEPARATOR);
        if ($output === '') {
            $output = storage_path('app/atlas/frontend-evidence-kit');
        }

        File::ensureDirectoryExists($output);
        File::ensureDirectoryExists($output.'/evidence');

        $scenarioMatrix = app(AtlasFrontendScenarioMatrixService::class)->compile([
            'task' => $task,
            'workspace' => $workspace,
            'frontend_app' => $frontendApp ?? '',
            'surface' => $surface,
            'acceptance_criteria' => (bool) ($input['acceptance_criteria'] ?? $input['acceptance'] ?? false),
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'company_profile_ready' => (bool) ($input['company_profile_ready'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
        ]);
        $taskSpecHash = is_string($scenarioMatrix['task_spec_hash'] ?? null)
            ? (string) $scenarioMatrix['task_spec_hash']
            : '<sha256-64-hex>';
        $frontendAppScope = (array) ($scenarioMatrix['frontend_app_scope'] ?? [
            'status' => 'repo_root',
            'relative_name' => null,
            'relative_name_hash' => null,
            'repo_workspace_remains_primary' => true,
        ]);
        $validatedFrontendApp = $this->validatedFrontendApp($frontendAppScope);

        $written = [];
        $written[] = $this->writeJson($output.'/scenario-matrix.json', $scenarioMatrix);
        $written[] = $this->writeJson($output.'/visual-quality-report.json', $this->visualQualityReportTemplate($taskSpecHash, $frontendAppScope));
        $written[] = $this->writeJson($output.'/quality-budget-report.json', $this->qualityBudgetReportTemplate($taskSpecHash, $frontendAppScope));
        $written[] = $this->writeJson($output.'/design-review-report.json', $this->designReviewReportTemplate($taskSpecHash, $frontendAppScope));
        $written[] = $this->writeJson($output.'/evidence/evidence-pack.json', $this->evidencePackTemplate($taskSpecHash, $frontendAppScope));
        $written[] = $this->writeJson($output.'/outcome-record-template.json', app(AtlasFrontendOutcomeMemoryService::class)->template());

        $blockers = [];
        if (($scenarioMatrix['status'] ?? null) !== 'ready') {
            $blockers[] = 'scenario_matrix_not_ready';
        }
        if (! (bool) ($input['acceptance_criteria'] ?? $input['acceptance'] ?? false)) {
            $blockers[] = 'scenario_matrix_not_ready';
        }
        $blockers = array_values(array_unique($blockers));

        $manifest = [
            'schema_version' => self::MANIFEST_SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready_for_evidence_collection' : 'blocked',
            'task_spec_hash' => $taskSpecHash,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'frontend_app_scope' => $frontendAppScope,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'kit_artifacts' => $written,
            'collection_commands' => $this->collectionCommands($output, $validatedFrontendApp),
            'claim_policy' => [
                'evidence_kit_is_not_evidence' => true,
                'templates_must_be_replaced_with_measured_artifacts' => true,
                'completion_requires_run_certification' => true,
                'handoff_requires_matching_evidence_pack' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => ['prepared_templates_are_not_completion_evidence'],
        ];
        $manifest['manifest_hash'] = MissionCanonicalHash::sha256($manifest);
        $manifestRef = $this->writeJson($output.'/evidence-kit-manifest.json', $manifest);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'source' => self::class,
            'kit_type' => 'frontend_execution_evidence_collection_kit',
            'output_hash' => hash('sha256', $output),
            'task_spec_hash' => $taskSpecHash,
            'scenario_matrix_hash' => $scenarioMatrix['scenario_matrix_hash'] ?? null,
            'frontend_app_scope' => $frontendAppScope,
            'manifest' => $manifestRef,
            'kit_artifacts' => $written,
            'required_next_actions' => $blockers === []
                ? ['collect_measured_visual_quality_budget_review_and_evidence_artifacts', 'run_atlas_frontend_run_certify']
                : ['fix_task_spec_or_acceptance_before_collecting_evidence'],
            'collection_commands' => $this->collectionCommands($output, $validatedFrontendApp),
            'claim_policy' => [
                'evidence_kit_is_not_completion_evidence' => true,
                'safe_to_share_with_operator' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => ['prepared_templates_are_not_completion_evidence'],
        ];
        $payload['evidence_kit_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function writeJson(string $path, array $payload): array
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'path_hash' => hash('sha256', $this->relativeName($path)),
            'relative_name' => $this->relativeName($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function visualQualityReportTemplate(string $taskSpecHash, array $frontendAppScope): array
    {
        $gate = app(AtlasFrontendVisualQualityGateService::class);

        return [
            'schema_version' => AtlasFrontendVisualQualityGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'frontend_app_scope' => $frontendAppScope,
            'routes' => ['/'],
            'viewports' => $gate->requiredViewports(),
            'checks' => array_fill_keys($gate->requiredChecks(), 'replace_with_measured_pass_or_reason'),
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => '<sha256-64-hex>',
            ], $gate->requiredArtifactKinds()),
            'notes' => 'Evidence kit scaffold only. Replace placeholders with measured refs and hashes.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qualityBudgetReportTemplate(string $taskSpecHash, array $frontendAppScope): array
    {
        $gate = app(AtlasFrontendQualityBudgetGateService::class);

        return [
            'schema_version' => AtlasFrontendQualityBudgetGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'frontend_app_scope' => $frontendAppScope,
            'viewports' => $gate->requiredViewports(),
            'metrics' => collect($gate->budgets())->mapWithKeys(fn (array $budget, string $id): array => [$id => $budget['max']])->all(),
            'operator_approved_exception' => false,
            'notes' => 'Evidence kit scaffold only. Replace values with measured browser/runtime numbers.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function designReviewReportTemplate(string $taskSpecHash, array $frontendAppScope): array
    {
        $review = app(AtlasFrontendDesignReviewService::class);

        return [
            'schema_version' => AtlasFrontendDesignReviewService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'frontend_app_scope' => $frontendAppScope,
            'dimensions' => collect($review->requiredDimensions())->mapWithKeys(fn (string $dimension): array => [
                $dimension => [
                    'score' => 8,
                    'rationale' => 'Replace with concise evidence-backed rationale.',
                    'evidence_refs' => ['receipt://replace-me'],
                ],
            ])->all(),
            'evidence_refs' => ['receipt://visual-quality', 'receipt://anti-slop'],
            'notes' => 'Evidence kit scaffold only. Replace with real senior/5D review evidence.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidencePackTemplate(string $taskSpecHash, array $frontendAppScope): array
    {
        $verifier = app(AtlasFrontendEvidencePackVerifierService::class);

        return [
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'replace-with-run-id',
            'case_id' => 'company_frontend_execution',
            'system' => 'atlas_frontend',
            'task_spec_hash' => $taskSpecHash,
            'frontend_app_scope' => $frontendAppScope,
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => '<sha256-64-hex>',
            ], $verifier->requiredArtifactKinds()),
            'notes' => 'Evidence kit scaffold only. Replace artifact refs and hashes before verification.',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function collectionCommands(string $output, ?string $frontendApp): array
    {
        $outputArg = escapeshellarg($output);
        $frontendAppArg = $frontendApp !== null ? ' --frontend-app='.$frontendApp : '';

        return [
            'php artisan atlas:frontend:scenarios --task="<intent>" --workspace=<local-company-repo>'.$frontendAppArg.' --acceptance --json --strict',
            'php artisan atlas:frontend:visual-quality inspect --report='.$outputArg.'/visual-quality-report.json --json --strict',
            'php artisan atlas:frontend:quality-budget inspect --report='.$outputArg.'/quality-budget-report.json --json --strict',
            'php artisan atlas:frontend:review inspect --report='.$outputArg.'/design-review-report.json --json --strict',
            'php artisan atlas:frontend:evidence verify --manifest='.$outputArg.'/evidence/evidence-pack.json --root='.$outputArg.'/evidence --json',
            'php artisan atlas:frontend:run-certify --provider-packet=<provider-packet> --visual-report='.$outputArg.'/visual-quality-report.json --design-review-report='.$outputArg.'/design-review-report.json --quality-budget-report='.$outputArg.'/quality-budget-report.json --evidence-manifest='.$outputArg.'/evidence/evidence-pack.json --outcome-store='.$outputArg.'/outcomes.jsonl --json --strict',
        ];
    }

    private function relativeName(string $path): string
    {
        return basename(dirname($path)).'/'.basename($path);
    }

    /**
     * @param  array<string,mixed>  $frontendAppScope
     */
    private function validatedFrontendApp(array $frontendAppScope): ?string
    {
        if (($frontendAppScope['status'] ?? null) !== 'subscope_selected') {
            return null;
        }

        $relative = $frontendAppScope['relative_name'] ?? null;

        return is_string($relative) && $relative !== '' ? $relative : null;
    }
}
