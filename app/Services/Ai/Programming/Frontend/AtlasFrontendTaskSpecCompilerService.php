<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiTextMatcher;
use Illuminate\Support\Str;

final class AtlasFrontendTaskSpecCompilerService
{
    public const SCHEMA_VERSION = 'atlas.frontend.task_spec.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $surface = AtlasFrontendSurface::fromInput($input);
        $workspace = trim((string) ($input['workspace'] ?? ''));
        $frontendAppScope = AtlasFrontendAppScope::fromRequestedApp($workspace, $input['frontend_app'] ?? null, [
            'include_raw_absolute_path_returned' => true,
            'reject_double_slash' => true,
        ]);
        $hints = array_values(array_filter((array) ($input['hints'] ?? []), 'is_string'));
        $haystack = $this->normalize($task.' '.$surface.' '.implode(' ', $hints));

        $signals = $this->signals($haystack, $input);
        $taskTypes = $this->taskTypes($signals);
        $routes = $this->routes($signals, $input);
        $viewports = $this->viewports($signals);
        $states = $this->states($signals);
        $journeys = $this->journeys($signals, $routes);
        $acceptance = $this->acceptanceCriteria($signals, $taskTypes, $routes, $viewports, $states);
        $gates = $this->requiredGates($signals);
        $artifacts = $this->requiredArtifacts($signals);
        $tests = $this->requiredTests($signals);
        $blockers = $this->blockers($task, $signals, $input);
        $warnings = $this->warnings($signals, $input);

        $spec = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'source' => self::class,
            'surface' => $surface,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'frontend_app_scope' => $frontendAppScope,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'raw_task_returned' => false,
            'task_types' => $taskTypes,
            'ambiguity_level' => $this->ambiguityLevel($task, $signals, $input),
            'risk_level' => $this->riskLevel($signals),
            'signals' => $signals,
            'routes' => $routes,
            'viewports' => $viewports,
            'states' => $states,
            'user_journeys' => $journeys,
            'acceptance_criteria' => $acceptance,
            'required_gates' => $gates,
            'required_artifacts' => $artifacts,
            'required_tests' => $tests,
            'required_evidence' => [
                'task_spec_hash',
                'design_direction_or_reason',
                'design_system_inventory_or_company_profile',
                'visual_quality_report',
                'screenshots_by_route_viewport_state',
                'console_a11y_perf_receipts',
                'outcome_memory_record',
            ],
            'execution_policy' => [
                'allowed_to_plan' => $task !== '',
                'allowed_to_execute' => false,
                'execute_requires_frontend_execution_gate_pass' => true,
                'execute_requires_visual_quality_gate_pass' => true,
                'execute_requires_evidence_pack' => true,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $spec['task_spec_hash'] = MissionCanonicalHash::sha256($spec);

        return $spec;
    }

    /**
     * @return array<int,string>
     */
    public function canonicalSections(): array
    {
        return [
            'task_types',
            'routes',
            'viewports',
            'states',
            'user_journeys',
            'acceptance_criteria',
            'required_gates',
            'required_artifacts',
            'required_tests',
            'required_evidence',
            'execution_policy',
        ];
    }

    private function normalize(string $value): string
    {
        return Str::ascii(strtolower($value));
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,bool>
     */
    private function signals(string $haystack, array $input): array
    {
        return [
            'ui_bug' => $this->containsAny($haystack, ['bug', 'quebrado', 'overlap', 'sobrepoe', 'alinhamento', 'layout quebrado', 'visual bug']),
            'new_feature' => $this->containsAny($haystack, ['feature', 'nova tela', 'criar', 'implementar', 'adicionar', 'build']),
            'redesign' => $this->containsAny($haystack, ['redesign', 'recriar', 'melhorar design', 'modernizar', 'premium']),
            'refactor' => $this->containsAny($haystack, ['refactor', 'refatorar', 'migrar design system', 'componentizar']),
            'prototype' => (bool) ($input['prototype'] ?? false) || $this->containsAny($haystack, ['prototype', 'prototipo', 'wireframe', 'exploracao', 'variant', 'variante']),
            'live_mode' => (bool) ($input['live'] ?? false) || $this->containsAny($haystack, ['live', 'browser', 'selecionar elemento', 'accept', 'discard', 'preview']),
            'saas' => $this->containsAny($haystack, ['saas', 'dashboard', 'admin', 'b2b', 'empresa', 'enterprise']),
            'ecommerce' => $this->containsAny($haystack, ['ecommerce', 'checkout', 'produto', 'cart', 'carrinho', 'loja']),
            'mobile' => $this->containsAny($haystack, ['mobile', 'app', 'onboarding', 'ios', 'android']),
            'auth' => $this->containsAny($haystack, ['auth', 'login', 'signup', 'permissao', 'permission']),
            'billing' => $this->containsAny($haystack, ['billing', 'pagamento', 'assinatura', 'checkout']),
            'performance' => $this->containsAny($haystack, ['performance', 'lighthouse', 'latencia', 'bundle', 'cache', 'custo']),
            'brand_assets' => $this->containsAny($haystack, ['brand', 'marca', 'logo', 'asset', 'imagem', 'hero', 'foto', 'video']),
            'multi_company' => $this->containsAny($haystack, ['inumeras empresas', 'multiempresa', 'white label', 'cliente', 'clientes']),
            'broad_scope' => $this->containsAny($haystack, ['produto inteiro', 'todas as telas', 'design system', 'inumeras empresas', 'plataforma inteira']),
            'ambiguous_words' => $this->containsAny($haystack, ['bonito', 'melhor', 'moderno', 'premium', 'incrivel', 'impecavel']) && ! $this->containsAny($haystack, ['rota', 'screen', 'tela', 'criterio', 'acceptance']),
        ];
    }

    /**
     * @param  array<string,bool>  $signals
     * @return array<int,string>
     */
    private function taskTypes(array $signals): array
    {
        $types = [];
        foreach ([
            'ui_bug' => 'visual_bugfix',
            'new_feature' => 'frontend_feature',
            'redesign' => 'visual_redesign',
            'refactor' => 'frontend_refactor',
            'prototype' => 'clickable_prototype',
            'live_mode' => 'live_visual_iteration',
            'saas' => 'saas_frontend',
            'ecommerce' => 'ecommerce_frontend',
            'mobile' => 'mobile_frontend',
        ] as $signal => $type) {
            if ($signals[$signal]) {
                $types[] = $type;
            }
        }

        return $types === [] ? ['frontend_task'] : array_values(array_unique($types));
    }

    /**
     * @param  array<string,bool>  $signals
     * @param  array<string,mixed>  $input
     * @return array<int,string>
     */
    private function routes(array $signals, array $input): array
    {
        $routes = array_values(array_filter((array) ($input['routes'] ?? []), 'is_string'));
        if ($routes === []) {
            $routes[] = '/';
        }
        if ($signals['saas']) {
            array_push($routes, '/dashboard', '/settings');
        }
        if ($signals['ecommerce']) {
            array_push($routes, '/products/[slug]', '/cart', '/checkout');
        }
        if ($signals['mobile']) {
            array_push($routes, '/onboarding', '/home');
        }
        if ($signals['auth']) {
            array_push($routes, '/login', '/signup');
        }

        return array_values(array_unique($routes));
    }

    /**
     * @param  array<string,bool>  $signals
     * @return array<int,string>
     */
    private function viewports(array $signals): array
    {
        $viewports = ['desktop', 'tablet', 'mobile'];
        if ($signals['mobile']) {
            $viewports[] = 'small_mobile';
        }

        return array_values(array_unique($viewports));
    }

    /**
     * @param  array<string,bool>  $signals
     * @return array<int,string>
     */
    private function states(array $signals): array
    {
        $states = ['loading', 'empty', 'error', 'success', 'keyboard_focus'];
        if ($signals['auth'] || $signals['billing']) {
            $states[] = 'permission_denied';
        }
        if ($signals['ecommerce']) {
            $states[] = 'out_of_stock';
        }
        if ($signals['live_mode']) {
            $states[] = 'preview_variant';
            $states[] = 'accepted_variant';
            $states[] = 'discarded_variant';
        }

        return array_values(array_unique($states));
    }

    /**
     * @param  array<string,bool>  $signals
     * @param  array<int,string>  $routes
     * @return array<int,array<string,mixed>>
     */
    private function journeys(array $signals, array $routes): array
    {
        $journeys = [
            [
                'id' => 'primary_task_path',
                'routes' => array_slice($routes, 0, 3),
                'states' => ['loading', 'success', 'keyboard_focus'],
            ],
        ];
        if ($signals['ecommerce']) {
            $journeys[] = ['id' => 'browse_to_checkout', 'routes' => ['/products/[slug]', '/cart', '/checkout'], 'states' => ['success', 'error']];
        }
        if ($signals['saas']) {
            $journeys[] = ['id' => 'dashboard_to_settings', 'routes' => ['/dashboard', '/settings'], 'states' => ['empty', 'success']];
        }
        if ($signals['live_mode']) {
            $journeys[] = ['id' => 'pick_preview_accept_or_discard', 'routes' => array_slice($routes, 0, 1), 'states' => ['preview_variant', 'accepted_variant', 'discarded_variant']];
        }

        return $journeys;
    }

    /**
     * @param  array<string,bool>  $signals
     * @param  array<int,string>  $taskTypes
     * @param  array<int,string>  $routes
     * @param  array<int,string>  $viewports
     * @param  array<int,string>  $states
     * @return array<int,array<string,string>>
     */
    private function acceptanceCriteria(array $signals, array $taskTypes, array $routes, array $viewports, array $states): array
    {
        $criteria = [
            ['id' => 'ac_frontend_scope', 'criterion' => 'Implement the selected frontend task types without unrelated visual or architectural drift.', 'evidence' => 'changed_files_and_task_spec_hash'],
            ['id' => 'ac_routes', 'criterion' => 'Verify every selected route in every required viewport.', 'evidence' => 'visual_quality_report'],
            ['id' => 'ac_states', 'criterion' => 'Verify loading, empty, error, success and keyboard/focus states or document an explicit reason.', 'evidence' => 'state_receipts'],
            ['id' => 'ac_no_slop', 'criterion' => 'Pass anti-AI-slop and no text overlap checks.', 'evidence' => 'anti_slop_report'],
        ];
        if ($signals['brand_assets']) {
            $criteria[] = ['id' => 'ac_assets', 'criterion' => 'Use real/provenanced assets or explicit placeholder policy.', 'evidence' => 'asset_pack'];
        }
        if ($signals['performance']) {
            $criteria[] = ['id' => 'ac_performance', 'criterion' => 'Meet or explain frontend performance budget.', 'evidence' => 'performance_receipt'];
        }
        if ($signals['live_mode']) {
            $criteria[] = ['id' => 'ac_live_recovery', 'criterion' => 'Preview, accept, discard and recovery paths preserve source integrity.', 'evidence' => 'live_iteration_event_journal'];
        }

        return array_map(fn (array $criterion): array => $criterion + [
            'task_type_count' => (string) count($taskTypes),
            'route_count' => (string) count($routes),
            'viewport_count' => (string) count($viewports),
            'state_count' => (string) count($states),
        ], $criteria);
    }

    /**
     * @param  array<string,bool>  $signals
     * @return array<int,string>
     */
    private function requiredGates(array $signals): array
    {
        $gates = [
            'frontend_execution_gate',
            'visual_quality_gate',
            'anti_ai_slop_detector',
            'design_system_inventory_or_company_profile',
            'evidence_pack_verifier',
            'outcome_memory_record',
        ];
        if ($signals['broad_scope'] || $signals['multi_company'] || $signals['refactor']) {
            $gates[] = 'design_system_drift_gate';
            $gates[] = 'senior_design_review';
        }
        if ($signals['brand_assets']) {
            $gates[] = 'asset_pack_verifier';
        }
        if ($signals['auth'] || $signals['billing']) {
            $gates[] = 'security_privacy_review';
        }
        if ($signals['performance']) {
            $gates[] = 'performance_budget_gate';
        }
        if ($signals['live_mode']) {
            $gates[] = 'live_source_patch_boundary_gate';
        }

        return array_values(array_unique($gates));
    }

    /**
     * @param  array<string,bool>  $signals
     * @return array<int,string>
     */
    private function requiredArtifacts(array $signals): array
    {
        $artifacts = [
            'task_spec',
            'frontend_patch_or_prototype',
            'visual_quality_report',
            'screenshots_by_route_viewport_state',
            'console_a11y_perf_receipts',
        ];
        if ($signals['prototype'] || $signals['ambiguous_words']) {
            $artifacts[] = 'design_direction_options';
        }
        if ($signals['brand_assets']) {
            $artifacts[] = 'asset_pack';
        }
        if ($signals['live_mode']) {
            $artifacts[] = 'live_iteration_journal';
        }

        return array_values(array_unique($artifacts));
    }

    /**
     * @param  array<string,bool>  $signals
     * @return array<int,string>
     */
    private function requiredTests(array $signals): array
    {
        $tests = [
            'component_or_unit_tests_or_reason',
            'route_smoke_tests',
            'multi_viewport_visual_smoke',
            'keyboard_focus_a11y_check',
            'console_error_check',
        ];
        if ($signals['ecommerce'] || $signals['auth'] || $signals['billing']) {
            $tests[] = 'critical_user_flow_test';
        }
        if ($signals['performance']) {
            $tests[] = 'performance_budget_test';
        }
        if ($signals['live_mode']) {
            $tests[] = 'source_patch_recovery_test';
        }

        return array_values(array_unique($tests));
    }

    /**
     * @param  array<string,bool>  $signals
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,string>>
     */
    private function blockers(string $task, array $signals, array $input): array
    {
        $blockers = [];
        if ($task === '') {
            $blockers[] = ['id' => 'task_missing', 'reason' => 'A frontend task spec cannot be compiled without a task or brief.'];
        }
        if (($signals['ambiguous_words'] || $signals['broad_scope'] || $signals['multi_company']) && ! (bool) ($input['acceptance'] ?? false)) {
            $blockers[] = ['id' => 'acceptance_context_required', 'reason' => 'Broad or ambiguous frontend work needs explicit acceptance context before execution.'];
        }
        if (($signals['brand_assets']) && ! (bool) ($input['asset_context'] ?? false)) {
            $blockers[] = ['id' => 'asset_context_required', 'reason' => 'Brand or asset-heavy frontend work needs asset provenance or placeholder policy.'];
        }

        return $blockers;
    }

    /**
     * @param  array<string,bool>  $signals
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,string>>
     */
    private function warnings(array $signals, array $input): array
    {
        $warnings = [];
        if ($signals['ambiguous_words'] || $signals['broad_scope'] || $signals['multi_company']) {
            $warnings[] = ['id' => 'design_direction_selection_recommended', 'reason' => 'Ambiguous visual language should go through Atlas Frontend design directions.'];
        }
        if ($signals['multi_company'] && ! (bool) ($input['company_profile'] ?? false)) {
            $warnings[] = ['id' => 'company_profile_recommended', 'reason' => 'Multi-company frontend work should use a ready company design profile.'];
        }

        return $warnings;
    }

    /**
     * @param  array<string,bool>  $signals
     * @param  array<string,mixed>  $input
     */
    private function ambiguityLevel(string $task, array $signals, array $input): string
    {
        if ($task === '' || $signals['ambiguous_words'] || ($signals['multi_company'] && ! (bool) ($input['acceptance'] ?? false))) {
            return 'high';
        }
        if ($signals['broad_scope'] || $signals['multi_company'] || ! (bool) ($input['acceptance'] ?? false)) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<string,bool>  $signals
     */
    private function riskLevel(array $signals): string
    {
        if ($signals['auth'] || $signals['billing'] || $signals['broad_scope'] || $signals['multi_company']) {
            return 'high';
        }
        if ($signals['performance'] || $signals['ecommerce'] || $signals['refactor']) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        return AiTextMatcher::containsAnyNeedle($haystack, $needles);
    }
}
