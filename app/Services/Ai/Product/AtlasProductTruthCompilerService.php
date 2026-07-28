<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasProductTruthCompilerService
{
    public const SCHEMA_VERSION = 'atlas.product_truth_contract.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $request = $this->string($input['human_request'] ?? $input['input_text'] ?? $input['prompt'] ?? null)
            ?? 'Atlas product delivery request';
        $lower = mb_strtolower($request);
        $intent = $this->productIntent($lower);
        $lenses = $this->executionLenses($lower, $intent);
        $domain = $this->businessDomain($lower, $intent);
        $acceptance = $this->acceptanceUniverse($lower, $intent);
        $contracts = $this->contractMap($lower, $intent);
        $architecture = $this->architectureConstraints($lower, $intent);
        $decomposition = $this->executionDecomposition($lower, $intent, $input);
        $proofPlan = $this->proofPlan($lenses, $intent, $decomposition);
        $questions = $this->humanQuestions($request, $intent, $lenses);

        $missingTruth = [];
        if ($this->goalIsTooVague($request)) {
            $missingTruth[] = 'specific_goal';
        }
        if (in_array('contract', $lenses['blocked_if_missing'], true) && $contracts['apis'] === [] && $contracts['events'] === []) {
            $missingTruth[] = 'contract_map';
        }
        if (in_array('risk_model', $lenses['blocked_if_missing'], true) && $architecture['risks'] === []) {
            $missingTruth[] = 'risk_model';
        }

        $status = $missingTruth === [] ? 'ready' : 'needs_context';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'compiled_from_human_request' => true,
            'confidence' => $missingTruth === [] ? 'high' : 'medium',
            'missing_truth' => $missingTruth,
            'product_intent' => $intent,
            'execution_lenses' => $lenses,
            'business_domain' => $domain,
            'acceptance_universe' => $acceptance,
            'contract_map' => $contracts,
            'architecture_constraints' => $architecture,
            'execution_decomposition' => $decomposition,
            'proof_plan' => $proofPlan,
            'human_questions' => $questions,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'external_superiority_claim' => false,
            ],
        ];
        $payload['truth_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function productIntent(string $lower): array
    {
        $kind = match (true) {
            str_contains($lower, 'bug') || str_contains($lower, 'erro') || str_contains($lower, 'quebr') => 'bug',
            str_contains($lower, 'empresa') || str_contains($lower, 'negocio') || str_contains($lower, 'negócio') => 'company',
            str_contains($lower, 'ecommerce') || str_contains($lower, 'e-commerce') || str_contains($lower, 'saas') || str_contains($lower, 'produto') => 'product',
            str_contains($lower, 'automat') || str_contains($lower, 'workflow') => 'automation',
            str_contains($lower, 'pesquis') || str_contains($lower, 'analise') || str_contains($lower, 'análise') => 'research',
            default => 'feature',
        };

        return [
            'kind' => $kind,
            'complexity' => in_array($kind, ['product', 'company', 'automation'], true) ? 'high' : ($kind === 'bug' ? 'low' : 'medium'),
            'human_goal' => $kind,
        ];
    }

    /**
     * @param  array<string,mixed>  $intent
     * @return array<string,list<string>>
     */
    private function executionLenses(string $lower, array $intent): array
    {
        $kind = (string) $intent['kind'];
        $required = match (true) {
            $kind === 'bug' && $this->isSecuritySensitive($lower) => ['tdd', 'bdd', 'security_driven', 'risk_driven'],
            $kind === 'bug' => ['tdd', 'bdd', 'risk_driven'],
            in_array($kind, ['product', 'company'], true) => ['ddd', 'atdd', 'cdd', 'add', 'fdd', 'security_driven'],
            // `api` com fronteira: casava dentro de "rapida" e "capital", e uma frase
            // como "resposta rapida" passava a exigir disciplina de api_first.
            preg_match('/\bapi\b/u', $lower) === 1 || str_contains($lower, 'webhook') || str_contains($lower, 'integr') => ['cdd', 'api_first', 'tdd', 'observability_driven'],
            str_contains($lower, 'tela') || preg_match('/\bux\b/u', $lower) === 1 || str_contains($lower, 'mobile') => ['ux_driven', 'pdd', 'atdd', 'bdd'],
            str_contains($lower, 'banco') || str_contains($lower, 'schema') || str_contains($lower, 'sync') => ['dbdd', 'data_driven', 'tdd', 'observability_driven'],
            str_contains($lower, 'performance') || str_contains($lower, 'lento') || str_contains($lower, 'index') => ['performance_driven', 'observability_driven', 'add', 'tdd'],
            default => ['atdd', 'tdd', 'risk_driven'],
        };

        $blocked = [];
        if (array_intersect($required, ['ddd', 'atdd', 'cdd', 'add']) !== []) {
            $blocked = ['acceptance', 'risk_model'];
        }
        if (array_intersect($required, ['cdd', 'api_first']) !== []) {
            $blocked[] = 'contract';
        }
        if (array_intersect($required, ['security_driven']) !== []) {
            $blocked[] = 'risk_model';
        }

        return [
            'required' => array_values(array_unique($required)),
            'optional' => array_values(array_diff(['pdd', 'performance_driven', 'documentation_driven'], $required)),
            'blocked_if_missing' => array_values(array_unique($blocked)),
        ];
    }

    /**
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    private function businessDomain(string $lower, array $intent): array
    {
        if ((string) $intent['kind'] === 'bug') {
            return [
                'actors' => ['user', 'system'],
                'objects' => $this->isSecuritySensitive($lower) ? ['session', 'credential', 'auth_state'] : ['affected_surface', 'runtime_state'],
                'rules' => ['existing behavior must not regress', 'fix must stay inside declared scope'],
            ];
        }

        if (str_contains($lower, 'ecommerce') || str_contains($lower, 'e-commerce')) {
            return [
                'actors' => ['buyer', 'merchant', 'admin', 'payment_provider'],
                'objects' => ['product', 'cart', 'order', 'payment', 'inventory', 'shipment'],
                'rules' => ['orders require payment state', 'inventory changes must be consistent', 'admin actions require permission'],
            ];
        }

        return [
            'actors' => ['operator', 'customer', 'system'],
            'objects' => ['request', 'workflow', 'delivery'],
            'rules' => ['delivery requires acceptance criteria', 'critical decisions require evidence'],
        ];
    }

    /**
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    private function acceptanceUniverse(string $lower, array $intent): array
    {
        $mustWork = ['declared happy path passes', 'focused verification command is defined'];
        $mustNotBreak = ['existing critical flow remains compatible', 'scope guard is respected'];

        if ((string) $intent['kind'] === 'bug') {
            $mustWork[] = 'reported failure no longer reproduces';
            $mustNotBreak[] = 'original behavior outside bug scope remains stable';
        }
        if (str_contains($lower, 'ecommerce') || str_contains($lower, 'pagamento') || str_contains($lower, 'payment')) {
            $mustWork[] = 'payment success, failure and webhook paths are specified';
            $mustNotBreak[] = 'no unauthorized order or payment mutation is possible';
        }

        return [
            'must_work' => array_values(array_unique($mustWork)),
            'must_not_break' => array_values(array_unique($mustNotBreak)),
            'definition_of_done' => ['tests run or skip_reason recorded', 'evidence captured', 'outcome memory updated'],
        ];
    }

    /**
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    private function contractMap(string $lower, array $intent): array
    {
        $needsContract = in_array((string) $intent['kind'], ['product', 'company', 'automation'], true)
            || preg_match('/\bapi\b/u', $lower) === 1
            || str_contains($lower, 'webhook')
            || str_contains($lower, 'integr');

        return [
            'apis' => $needsContract ? ['public_or_internal_api_contract'] : [],
            'events' => str_contains($lower, 'webhook') || str_contains($lower, 'pagamento') ? ['webhook_event_contract'] : [],
            'data_shapes' => $needsContract ? ['request_payload', 'response_payload', 'error_shape'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    private function architectureConstraints(string $lower, array $intent): array
    {
        $risks = ['false_completion'];
        if ($this->isSecuritySensitive($lower) || in_array((string) $intent['kind'], ['product', 'company'], true)) {
            $risks[] = 'security_or_permission_regression';
        }
        if (str_contains($lower, 'performance') || str_contains($lower, 'lento') || str_contains($lower, 'index')) {
            $risks[] = 'performance_regression';
        }

        return [
            'security' => $this->isSecuritySensitive($lower) ? ['auth_boundary', 'permission_check', 'sensitive_data_handling'] : [],
            'performance' => str_contains($lower, 'performance') || str_contains($lower, 'index') ? ['baseline_required', 'regression_threshold_required'] : [],
            'privacy' => ['do_not_expose_raw_sensitive_context'],
            'risks' => array_values(array_unique($risks)),
        ];
    }

    /**
     * @param  array<string,mixed>  $intent
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function executionDecomposition(string $lower, array $intent, array $input): array
    {
        $forced = $this->string($input['route'] ?? $input['target'] ?? null);
        $route = match (true) {
            $forced === 'atlas_forge' || $forced === 'forge' => 'atlas_forge',
            $forced === 'atlas_dev' || $forced === 'dev' => 'atlas_dev',
            in_array((string) $intent['kind'], ['product', 'company', 'automation'], true) => 'atlas_forge',
            str_contains($lower, 'obra') || str_contains($lower, 'milestone') || str_contains($lower, 'sistema inteiro') => 'atlas_forge',
            default => 'atlas_dev',
        };

        return [
            'route' => $route,
            'reason' => $route === 'atlas_forge'
                ? 'complex product or long-horizon delivery requires Forge'
                : 'bounded patch or feature can use Atlas Dev',
            'requires_apfpr' => in_array((string) $intent['kind'], ['product', 'company', 'automation'], true) || $this->isSecuritySensitive($lower),
        ];
    }

    /**
     * @param  array<string,list<string>>  $lenses
     * @param  array<string,mixed>  $intent
     * @param  array<string,mixed>  $decomposition
     * @return array<string,mixed>
     */
    private function proofPlan(array $lenses, array $intent, array $decomposition): array
    {
        $tests = ['focused_tests'];
        if (in_array('cdd', $lenses['required'], true) || in_array('api_first', $lenses['required'], true)) {
            $tests[] = 'contract_tests';
        }
        if (in_array('security_driven', $lenses['required'], true)) {
            $tests[] = 'security_regression_tests';
        }

        return [
            'tests' => array_values(array_unique($tests)),
            'gates' => ['context_gate', 'scope_guard', 'completion_gate', 'apfpr_challenge'],
            'evidence' => ['diff_or_plan', 'test_output_or_skip_reason', 'acceptance_mapping', 'outcome_memory'],
            'certification_required' => true,
            'apfpr_required' => (bool) ($decomposition['requires_apfpr'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $intent
     * @param  array<string,list<string>>  $lenses
     * @return array<string,list<string>>
     */
    private function humanQuestions(string $request, array $intent, array $lenses): array
    {
        $questions = [];
        if ($this->goalIsTooVague($request)) {
            $questions[] = 'Qual resultado concreto precisa existir no final?';
        }
        if (in_array((string) $intent['kind'], ['product', 'company'], true)) {
            $questions[] = 'Qual usuario principal e qual fluxo precisa funcionar primeiro?';
        }
        if (in_array('contract', $lenses['blocked_if_missing'], true)) {
            $questions[] = 'Existe contrato/API/webhook existente que precisa ser preservado?';
        }

        return ['minimum_required' => array_values(array_unique($questions))];
    }

    private function isSecuritySensitive(string $lower): bool
    {
        return str_contains($lower, 'login')
            || str_contains($lower, 'auth')
            || str_contains($lower, 'permiss')
            || str_contains($lower, 'pagamento')
            || str_contains($lower, 'payment')
            || str_contains($lower, 'billing');
    }

    private function goalIsTooVague(string $request): bool
    {
        return mb_strlen(trim($request)) < 8;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
