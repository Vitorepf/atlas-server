<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiStringListNormalizer;

class AtlasProductTwinSimulationService
{
    public const SCHEMA_VERSION = 'atlas.product_twin_simulation.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function simulate(array $input): array
    {
        $truth = $this->truth($input);
        $delivery = is_array($input['delivery_contract'] ?? null) ? $input['delivery_contract'] : [];
        $patchManifest = is_array($input['patch_manifest'] ?? null) ? $input['patch_manifest'] : [];
        $route = (string) data_get($delivery, 'route', data_get($truth, 'execution_decomposition.route', 'atlas_dev'));
        $expectedRoute = (string) data_get($truth, 'execution_decomposition.route', $route);
        $requiredLenses = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'execution_lenses.required', []));
        $tests = $this->tests($truth, $delivery);
        $allowedFiles = AiStringListNormalizer::trimmedScalarValues(data_get($delivery, 'delivery_plan.scope_guard.allowed_files', []));
        $operations = $this->operations($patchManifest['operations'] ?? []);
        $touchedFiles = array_values(array_unique(array_map(
            static fn (array $operation): string => $operation['path'],
            $operations,
        )));
        $blockers = $this->blockers($truth, $route, $expectedRoute, $allowedFiles, $touchedFiles);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'simulated' : 'blocked',
            'mode' => 'provider_free_read_only',
            'route_fit' => [
                'expected_route' => $expectedRoute,
                'actual_route' => $route,
                'matches' => $route === $expectedRoute,
            ],
            'predicted_impact' => [
                'domain_objects' => AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'business_domain.objects', [])),
                'contracts' => [
                    'apis' => AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'contract_map.apis', [])),
                    'events' => AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'contract_map.events', [])),
                    'data_shapes' => AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'contract_map.data_shapes', [])),
                ],
                'files' => [
                    'allowed_files' => $allowedFiles,
                    'candidate_touched_files' => $touchedFiles,
                    'unbounded_patch' => $operations !== [] && $allowedFiles === [],
                ],
                'tests' => $tests,
                'ui' => $this->uiImpact($truth),
                'operations' => $this->operationalImpact($truth, $requiredLenses),
            ],
            'business_twin' => $this->businessTwin($truth, $input),
            'risk_forecast' => [
                'risk_band' => $this->riskBand($truth, $requiredLenses),
                'risk_factors' => $this->riskFactors($truth, $requiredLenses, $operations),
                'blockers' => $blockers,
                'confidence' => $blockers === [] ? 'medium' : 'low',
            ],
            'patch_candidate_analysis' => [
                'has_patch_candidate' => $operations !== [],
                'operation_count' => count($operations),
                'proposal_gate_required' => $operations !== [],
                'hash_guard_required' => $operations !== [],
                'rollback_required' => $operations !== [],
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'simulation_is_not_execution' => true,
            ],
        ];
        $payload['simulation_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function businessTwin(array $truth, array $input): array
    {
        $context = is_array($input['business_context'] ?? null) ? $input['business_context'] : [];
        $actors = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'business_domain.actors', []));
        $objects = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'business_domain.objects', []));
        $rules = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'business_domain.rules', []));
        $metrics = $this->metrics($truth, $context);
        $revenue = $this->revenue($truth, $context);
        $risks = $this->riskFactors($truth, AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'execution_lenses.required', [])), []);
        $priority = $this->priority($truth, $metrics, $revenue, $risks, $context);

        return [
            'schema_version' => 'atlas.product_business_twin.v1',
            'status' => ($truth['status'] ?? null) === 'ready' ? 'ready' : 'needs_product_truth',
            'user_model' => [
                'primary_actors' => $actors,
                'served_objects' => $objects,
                'user_promises' => AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'acceptance_universe.must_work', [])),
                'must_not_break' => AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'acceptance_universe.must_not_break', [])),
            ],
            'business_model' => [
                'rules' => $rules,
                'revenue' => $revenue,
                'metrics' => $metrics,
                'priority' => $priority,
            ],
            'decision_model' => [
                'risk_factors' => $risks,
                'priority_score' => $priority['score'],
                'priority_band' => $priority['band'],
                'recommended_route' => (string) data_get($truth, 'execution_decomposition.route', 'atlas_dev'),
                'blocks_completion_without' => array_values(array_unique(array_merge(
                    AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'missing_truth', [])),
                    $metrics['missing'],
                    $revenue['missing'],
                ))),
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'uses_observed_or_declared_business_context' => true,
                'does_not_claim_unobserved_revenue' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function metrics(array $truth, array $context): array
    {
        $declared = [];
        foreach ((array) ($context['metrics'] ?? []) as $key => $value) {
            if (! is_scalar($key) || (! is_scalar($value) && $value !== null)) {
                continue;
            }
            $metric = trim((string) $key);
            if ($metric !== '') {
                $declared[$metric] = $value;
            }
        }

        $northStar = is_scalar($context['north_star_metric'] ?? null)
            ? trim((string) $context['north_star_metric'])
            : null;
        if ($northStar === '' || $northStar === null) {
            $northStar = in_array('payment', AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'business_domain.objects', [])), true)
                ? 'successful_paid_checkout_rate'
                : 'task_success_rate';
        }

        return [
            'north_star_metric' => $northStar,
            'declared_metrics' => $declared,
            'required_metrics' => array_values(array_unique([$northStar, 'activation_or_task_success', 'regression_rate'])),
            'missing' => $declared === [] ? ['observed_product_metrics'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function revenue(array $truth, array $context): array
    {
        $observed = is_numeric($context['observed_revenue_usd'] ?? null)
            ? (float) $context['observed_revenue_usd']
            : null;
        $target = is_numeric($context['target_revenue_usd'] ?? null)
            ? (float) $context['target_revenue_usd']
            : null;
        $hasPaymentObject = in_array('payment', AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'business_domain.objects', [])), true);

        return [
            'model' => is_scalar($context['revenue_model'] ?? null) ? trim((string) $context['revenue_model']) : ($hasPaymentObject ? 'transactional' : 'unknown'),
            'observed_revenue_usd' => $observed,
            'target_revenue_usd' => $target,
            'revenue_sensitive_change' => $hasPaymentObject || $observed !== null || $target !== null,
            'missing' => $observed === null ? ['observed_revenue'] : [],
            'claim_policy' => [
                'synthetic_revenue_forbidden' => true,
                'observed_revenue_required_for_growth_claims' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $revenue
     * @param  list<string>  $risks
     * @param  array<string,mixed>  $context
     * @return array{score:int,band:string,drivers:list<string>}
     */
    private function priority(array $truth, array $metrics, array $revenue, array $risks, array $context): array
    {
        if (is_numeric($context['priority_score'] ?? null)) {
            $score = max(0, min(100, (int) $context['priority_score']));

            return [
                'score' => $score,
                'band' => $score >= 80 ? 'critical' : ($score >= 60 ? 'high' : ($score >= 35 ? 'medium' : 'low')),
                'drivers' => ['operator_declared_priority'],
            ];
        }

        $drivers = [];
        $score = 35;
        if (($truth['status'] ?? null) === 'ready') {
            $score += 10;
            $drivers[] = 'product_truth_ready';
        }
        if (($revenue['revenue_sensitive_change'] ?? false) === true) {
            $score += 20;
            $drivers[] = 'revenue_sensitive';
        }
        if (($metrics['missing'] ?? []) === []) {
            $score += 10;
            $drivers[] = 'metrics_observed';
        }
        if (in_array('security_or_permission_regression', $risks, true)) {
            $score += 15;
            $drivers[] = 'security_or_permission_risk';
        }
        if ((string) data_get($truth, 'product_intent.complexity') === 'high') {
            $score += 10;
            $drivers[] = 'high_complexity';
        }
        $score = min(100, $score);

        return [
            'score' => $score,
            'band' => $score >= 80 ? 'critical' : ($score >= 60 ? 'high' : ($score >= 35 ? 'medium' : 'low')),
            'drivers' => array_values(array_unique($drivers)),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function truth(array $input): array
    {
        if (is_array($input['product_truth'] ?? null)) {
            return $input['product_truth'];
        }

        return app(AtlasProductTruthCompilerService::class)->compile($input);
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $delivery
     * @return array{focused_tests:list<string>,fallback_tests:list<string>,skip_reason:?string}
     */
    private function tests(array $truth, array $delivery): array
    {
        $focused = AiStringListNormalizer::trimmedScalarValues(data_get($delivery, 'delivery_plan.tests', []));
        if ($focused === []) {
            $focused = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'proof_plan.tests', []));
        }

        return [
            'focused_tests' => $focused === [] ? ['focused_tests'] : $focused,
            'fallback_tests' => ['php artisan test tests/Feature/Ai/Product'],
            'skip_reason' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function uiImpact(array $truth): array
    {
        $lenses = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'execution_lenses.required', []));

        return array_intersect($lenses, ['ux_driven', 'pdd', 'bdd']) !== []
            ? ['visual_acceptance_required', 'mobile_desktop_surface_check']
            : [];
    }

    /**
     * @param  list<string>  $requiredLenses
     * @return list<string>
     */
    private function operationalImpact(array $truth, array $requiredLenses): array
    {
        $impact = ['outcome_memory_required'];
        if (in_array('observability_driven', $requiredLenses, true)) {
            $impact[] = 'telemetry_or_log_evidence_required';
        }
        if ((bool) data_get($truth, 'proof_plan.apfpr_required', false)) {
            $impact[] = 'apfpr_required_before_completion';
        }

        return array_values(array_unique($impact));
    }

    /**
     * @param  list<string>  $requiredLenses
     */
    private function riskBand(array $truth, array $requiredLenses): string
    {
        if (array_intersect($requiredLenses, ['security_driven', 'add', 'performance_driven']) !== []) {
            return 'high';
        }

        return data_get($truth, 'product_intent.complexity') === 'high' ? 'high' : 'standard';
    }

    /**
     * @param  list<string>  $requiredLenses
     * @param  list<array{path:string,content:string,expected_sha256:?string}>  $operations
     * @return list<string>
     */
    private function riskFactors(array $truth, array $requiredLenses, array $operations): array
    {
        $factors = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'architecture_constraints.risks', []));
        if (array_intersect($requiredLenses, ['cdd', 'api_first']) !== []) {
            $factors[] = 'contract_drift';
        }
        if ($operations !== []) {
            $factors[] = 'patch_side_effect';
        }

        return array_values(array_unique($factors));
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $touchedFiles
     * @return list<array<string,mixed>>
     */
    private function blockers(array $truth, string $route, string $expectedRoute, array $allowedFiles, array $touchedFiles): array
    {
        $blockers = [];
        if (($truth['status'] ?? null) !== 'ready') {
            $blockers[] = ['id' => 'product_truth_not_ready'];
        }
        if ($route !== $expectedRoute) {
            $blockers[] = ['id' => 'route_mismatch', 'expected' => $expectedRoute, 'actual' => $route];
        }
        foreach ($touchedFiles as $path) {
            if ($allowedFiles !== [] && ! in_array($path, $allowedFiles, true)) {
                $blockers[] = ['id' => 'candidate_touches_disallowed_file', 'path' => $path];
            }
        }

        return $blockers;
    }

    /**
     * @return list<array{path:string,content:string,expected_sha256:?string}>
     */
    private function operations(mixed $operations): array
    {
        if (! is_array($operations)) {
            return [];
        }

        $clean = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }
            $path = is_scalar($operation['path'] ?? null) ? trim((string) $operation['path']) : '';
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }
            $expected = is_scalar($operation['expected_sha256'] ?? null)
                ? trim((string) $operation['expected_sha256'])
                : null;
            $clean[] = [
                'path' => $path,
                'content' => (string) ($operation['content'] ?? ''),
                'expected_sha256' => $expected !== '' ? $expected : null,
            ];
        }

        return $clean;
    }

}
