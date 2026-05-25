<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

final class AtlasFrontendProductBlueprintService
{
    public const SCHEMA_VERSION = 'atlas.frontend.product_blueprint.v1';

    public const DOCUMENT_SCHEMA_VERSION = 'atlas.frontend.product_blueprint_document.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function generate(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = trim((string) ($input['workspace'] ?? ''));
        $surface = trim((string) ($input['surface'] ?? 'programming.frontend')) ?: 'programming.frontend';
        $taskSpec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
            'acceptance' => true,
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
        ]);
        $directions = app(AtlasFrontendDesignDirectionAdvisorService::class)->advise([
            'task' => $task,
            'surface' => $surface,
        ]);
        $dossier = $workspace !== '' && File::isDirectory($workspace)
            ? app(AtlasFrontendDesignDossierService::class)->inspect($workspace)
            : ['status' => 'missing', 'dossier_hash' => null, 'blockers' => ['workspace_missing']];

        $blockers = $task === '' ? ['task_missing'] : [];
        $warnings = [];
        if (($dossier['status'] ?? null) !== 'ready') {
            $warnings[] = 'company_design_dossier_not_ready';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? ($warnings === [] ? 'ready' : 'warning') : 'blocked',
            'source' => self::class,
            'blueprint_type' => 'company_product_frontend_success_blueprint',
            'surface' => $surface,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? null,
            'dossier_hash' => $dossier['dossier_hash'] ?? null,
            'product_model' => $this->productModel((array) ($taskSpec['signals'] ?? [])),
            'ux_success_model' => $this->uxSuccessModel((array) ($taskSpec['signals'] ?? [])),
            'screen_blueprint' => $this->screenBlueprint((array) ($taskSpec['routes'] ?? []), (array) ($taskSpec['states'] ?? [])),
            'visual_strategy' => [
                'recommended_direction_id' => $directions['recommended_direction_id'] ?? 'operational_clarity',
                'direction_ids' => app(AtlasFrontendDesignDirectionAdvisorService::class)->directionIds(),
                'must_feed_design_review' => true,
            ],
            'acceptance_blueprint' => $taskSpec['acceptance_criteria'] ?? [],
            'evidence_map' => [
                'design_dossier',
                'task_spec_hash',
                'selected_design_direction',
                'visual_quality_report',
                'quality_budget_report',
                'design_5d_review',
                'evidence_pack',
                'outcome_memory_record',
            ],
            'doc_seed' => $this->docSeed((array) ($taskSpec['signals'] ?? [])),
            'claim_policy' => [
                'premium_frontend_work_requires_blueprint' => true,
                'blueprint_is_not_completion_evidence' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $payload['blueprint_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function writeDocument(array $input): array
    {
        $workspace = rtrim(trim((string) ($input['workspace'] ?? '')), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($workspace.'/docs/design');
        $blueprint = $this->generate($input);
        $path = $workspace.'/docs/design/atlas-frontend-product-blueprint.json';
        File::put($path, json_encode([
            'schema_version' => self::DOCUMENT_SCHEMA_VERSION,
            'blueprint' => $blueprint,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'schema_version' => self::DOCUMENT_SCHEMA_VERSION,
            'status' => 'written',
            'path_hash' => hash('sha256', 'docs/design/atlas-frontend-product-blueprint.json'),
            'document_hash' => hash_file('sha256', $path),
            'blueprint_hash' => $blueprint['blueprint_hash'] ?? null,
            'claim_policy' => [
                'written_blueprint_is_planning_evidence_not_completion' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function productModel(array $signals): array
    {
        $type = match (true) {
            (bool) ($signals['ecommerce'] ?? false) => 'ecommerce',
            (bool) ($signals['saas'] ?? false) => 'saas',
            (bool) ($signals['mobile'] ?? false) => 'mobile_app',
            default => 'software_product',
        };

        return [
            'product_type' => $type,
            'primary_audience' => $type === 'saas' ? 'operators_and_business_users' : 'end_users',
            'success_metric_families' => ['task_completion', 'trust', 'speed', 'conversion_or_activation', 'support_load_reduction'],
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function uxSuccessModel(array $signals): array
    {
        return [
            'jobs_to_be_done' => (bool) ($signals['ecommerce'] ?? false)
                ? ['find_product', 'understand_value', 'checkout_with_confidence']
                : ['understand_current_state', 'complete_primary_action', 'recover_from_error'],
            'non_negotiable_states' => ['loading', 'empty', 'error', 'success', 'keyboard_focus'],
            'risk_focus' => array_values(array_filter([
                (bool) ($signals['auth'] ?? false) ? 'auth_trust_and_permissions' : null,
                (bool) ($signals['billing'] ?? false) ? 'billing_clarity' : null,
                (bool) ($signals['performance'] ?? false) ? 'latency_and_bundle_budget' : null,
            ])),
        ];
    }

    /**
     * @param  array<int,mixed>  $routes
     * @param  array<int,mixed>  $states
     * @return array<int,array<string,mixed>>
     */
    private function screenBlueprint(array $routes, array $states): array
    {
        return array_values(array_map(fn (mixed $route): array => [
            'route' => is_string($route) ? $route : '/',
            'purpose' => $this->routePurpose(is_string($route) ? $route : '/'),
            'required_states' => array_values(array_filter($states, 'is_string')),
            'visual_acceptance' => ['clear_hierarchy', 'responsive_no_overlap', 'accessible_focus_path', 'no_generic_ai_slop'],
        ], $routes !== [] ? $routes : ['/']));
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,array<int,string>>
     */
    private function docSeed(array $signals): array
    {
        return [
            'product-experience-brief.md' => ['product promise', 'primary user', 'success metrics', 'top journeys'],
            'brand-system.md' => ['visual principles', 'tone', (bool) ($signals['brand_assets'] ?? false) ? 'asset provenance' : 'asset policy'],
            'ux-journeys.md' => ['happy path', 'empty state', 'error state', 'mobile path'],
            'design-system.md' => ['tokens', 'components', 'layout grid', 'interaction patterns'],
            'frontend-quality-policy.md' => ['viewports', 'a11y', 'performance budget', 'release evidence'],
        ];
    }

    private function routePurpose(string $route): string
    {
        return match (true) {
            str_contains($route, 'checkout') => 'complete_purchase_or_payment',
            str_contains($route, 'dashboard') => 'monitor_and_act_on_business_state',
            str_contains($route, 'settings') => 'configure_account_or_workspace',
            str_contains($route, 'onboarding') => 'activate_new_user',
            str_contains($route, 'login') || str_contains($route, 'signup') => 'authenticate_with_trust',
            default => 'complete_primary_product_task',
        };
    }
}
