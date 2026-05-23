<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

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
        $requiredLenses = $this->list(data_get($truth, 'execution_lenses.required', []));
        $tests = $this->tests($truth, $delivery);
        $allowedFiles = $this->list(data_get($delivery, 'delivery_plan.scope_guard.allowed_files', []));
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
                'domain_objects' => $this->list(data_get($truth, 'business_domain.objects', [])),
                'contracts' => [
                    'apis' => $this->list(data_get($truth, 'contract_map.apis', [])),
                    'events' => $this->list(data_get($truth, 'contract_map.events', [])),
                    'data_shapes' => $this->list(data_get($truth, 'contract_map.data_shapes', [])),
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
        $focused = $this->list(data_get($delivery, 'delivery_plan.tests', []));
        if ($focused === []) {
            $focused = $this->list(data_get($truth, 'proof_plan.tests', []));
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
        $lenses = $this->list(data_get($truth, 'execution_lenses.required', []));

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
        $factors = $this->list(data_get($truth, 'architecture_constraints.risks', []));
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

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
            $value,
        ), static fn (?string $item): bool => $item !== null && $item !== ''));
    }
}
