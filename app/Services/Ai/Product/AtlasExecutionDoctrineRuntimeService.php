<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasExecutionDoctrineRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.aedpds.execution_doctrine.v1';

    public const DRIVERS = [
        'tdd',
        'bdd',
        'atdd',
        'fdd',
        'sdd',
        'cdd',
        'api_first',
        'documentation_driven',
        'readme_driven',
        'domain_driven_design',
        'model_driven',
        'database_driven',
        'prototype_driven',
        'ux_driven',
        'risk_driven',
        'architecture_driven',
        'security_driven',
        'performance_driven',
        'reliability_observability_driven',
        'data_evidence_driven',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function select(array $input): array
    {
        $task = $this->string($input['task'] ?? $input['human_request'] ?? $input['objective'] ?? $input['intent'] ?? $input['prompt'] ?? null)
            ?? 'Atlas delivery task';
        $lower = mb_strtolower($task);
        $surface = $this->surface($input['surface'] ?? $input['surface_id'] ?? null);
        $workspace = $this->string($input['workspace'] ?? $input['workspace_slug'] ?? $input['project'] ?? null);
        $taskType = $this->taskType($lower, $input);
        $signals = $this->signals($lower, $input, $taskType);
        $risk = $this->riskLevel($lower, $input, $signals);
        $ambiguity = $this->ambiguityLevel($task, $input, $signals);

        $primary = [];
        $secondary = ['risk_driven', 'data_evidence_driven'];
        $requiredContext = ['owner_or_relevant_context'];
        $requiredTests = [];
        $requiredContracts = [];
        $requiredDocs = [];
        $requiredReview = [];
        $requiredEvidence = ['aedpds_selection_receipt', 'gate_result', 'outcome_or_skip_reason'];
        $blockers = [];
        $warnings = [];

        if ($signals['bug'] || $signals['feature']) {
            $primary[] = 'atdd';
            $requiredContext[] = 'acceptance_criteria';
        }
        if ($signals['code']) {
            $primary[] = 'tdd';
            $requiredTests[] = 'focused_test_or_verification_command';
        }
        if ($signals['ui']) {
            $primary[] = 'ux_driven';
            $secondary[] = 'prototype_driven';
            $requiredContext[] = 'ux_expectation_or_prototype';
        }
        if ($signals['api']) {
            array_push($primary, 'cdd', 'api_first');
            $secondary[] = 'security_driven';
            $requiredContracts[] = 'api_or_payload_contract';
            $requiredTests[] = 'contract_tests';
        }
        if ($signals['database']) {
            $primary[] = 'database_driven';
            $secondary[] = 'risk_driven';
            $requiredContracts[] = 'schema_or_migration_contract';
            $requiredTests[] = 'migration_or_query_regression_tests';
            $requiredEvidence[] = 'rollback_plan';
        }
        if ($signals['security']) {
            array_push($primary, 'security_driven', 'risk_driven');
            $requiredReview[] = 'senior_security_or_runtime_review';
            $requiredTests[] = 'security_regression_tests';
        }
        if ($signals['performance']) {
            $primary[] = 'performance_driven';
            $secondary[] = 'reliability_observability_driven';
            $requiredEvidence[] = 'baseline_or_budget';
        }
        if ($signals['documentation']) {
            array_push($primary, 'documentation_driven', 'sdd');
            $requiredDocs[] = 'canonical_doc_update_or_docs_health';
            $requiredEvidence[] = 'docs_health_or_authority_check';
        }
        if ($signals['architecture']) {
            array_push($primary, 'architecture_driven', 'risk_driven');
            $requiredContext[] = 'architecture_owner_doc_or_boundary';
            $requiredEvidence[] = 'impact_analysis';
        }
        if ($signals['complex_product']) {
            array_push($primary, 'fdd', 'domain_driven_design', 'atdd', 'ux_driven', 'architecture_driven');
            $requiredContext[] = 'feature_slice_or_work_packets';
            $requiredContext[] = 'domain_model_or_business_rules';
        }
        if ($signals['readme']) {
            $primary[] = 'readme_driven';
            $requiredDocs[] = 'readme_usage_contract';
        }
        if ($signals['model']) {
            $primary[] = 'model_driven';
            $requiredContext[] = 'model_or_state_machine_spec';
            $requiredContracts[] = 'formal_or_semiformal_model_contract';
            $requiredEvidence[] = 'model_validation_or_generation_trace';
        }
        if ($signals['observability']) {
            $primary[] = 'reliability_observability_driven';
            $requiredEvidence[] = 'logs_traces_receipts_or_readiness_signal';
        }

        if ($primary === []) {
            $primary = $signals['code'] ? ['atdd', 'tdd', 'risk_driven'] : ['atdd', 'documentation_driven', 'risk_driven'];
        }

        if ($ambiguity === 'high') {
            $blockers[] = 'high_ambiguity_requires_clarification_or_context_gate';
        }
        if ($signals['missing_context']) {
            $blockers[] = 'missing_minimum_context';
        }
        if ($signals['security'] && ! $this->bool($input['senior_review_present'] ?? $input['review_present'] ?? false)) {
            $blockers[] = 'sensitive_change_requires_senior_review';
        }

        $escalation = $this->escalation($signals, $risk, $ambiguity, $blockers);
        $allowed = $blockers === [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'request_id' => $this->string($input['request_id'] ?? null),
            'trace_id' => $this->string($input['trace_id'] ?? null),
            'surface' => $surface,
            'workspace' => $workspace,
            'task_type' => $taskType,
            'user_intent_summary' => mb_substr($task, 0, 280),
            'ambiguity_level' => $ambiguity,
            'risk_level' => $risk,
            'selected_primary_drivers' => $this->drivers($primary),
            'selected_secondary_drivers' => $this->drivers($secondary),
            'required_artifacts' => $this->artifacts($signals),
            'required_context' => $this->unique($requiredContext),
            'required_tests' => $this->unique($requiredTests),
            'required_contracts' => $this->unique($requiredContracts),
            'required_docs' => $this->unique($requiredDocs),
            'required_review' => $this->unique($requiredReview),
            'required_evidence' => $this->unique($requiredEvidence),
            'required_gates' => $this->requiredGates($signals, $risk),
            'blockers' => $this->unique($blockers),
            'warnings' => $this->unique($warnings),
            'allowed_to_execute' => $allowed,
            'reason' => $this->reason($signals, $allowed, $escalation),
            'recommended_escalation' => $escalation,
            'signals' => $signals,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'llm_used_for_selection' => false,
            ],
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,bool>
     */
    private function signals(string $lower, array $input, string $taskType): array
    {
        $files = $this->list($input['files'] ?? $input['expected_files'] ?? []);
        $domains = $this->list($input['domains'] ?? []);

        return [
            'bug' => $taskType === 'bug',
            'feature' => in_array($taskType, ['feature', 'product', 'api', 'migration', 'refactor'], true),
            'code' => $this->bool($input['code_changes_requested'] ?? false) || $this->hasAny($lower, ['codigo', 'código', 'implementar', 'fix', 'patch', 'refactor', 'endpoint', 'migration', 'schema', 'api']),
            'ui' => $this->bool($input['ui_involved'] ?? false) || $this->hasAny($lower, ['ui', 'ux', 'tela', 'design', 'frontend', 'mobile', 'desktop', 'layout', 'visual']),
            'api' => $this->bool($input['api_involved'] ?? false) || $this->hasAny($lower, ['api', 'endpoint', 'payload', 'schema', 'webhook', 'contract', 'integra']),
            'database' => $this->bool($input['database_involved'] ?? false) || $this->hasAny($lower, ['migration', 'migrate', 'banco', 'database', 'db', 'query', 'schema', 'sql']),
            'security' => $this->bool($input['security_involved'] ?? false) || $this->hasAny($lower, ['auth', 'login', 'permission', 'permiss', 'security', 'segurança', 'billing', 'pagamento', 'payment', 'provider', 'runtime crítico', 'runtime critico']),
            'performance' => $this->bool($input['performance_involved'] ?? false) || $this->hasAny($lower, ['performance', 'latência', 'latencia', 'throughput', 'custo', 'cost', 'token', 'cache', 'lento']),
            'documentation' => $this->bool($input['docs_involved'] ?? false) || $this->hasAny($lower, ['documentação', 'documentacao', 'docs', 'canon', 'cartografia', 'governance', 'governança']),
            'architecture' => $this->bool($input['architecture_involved'] ?? false) || $this->hasAny($lower, ['arquitetura', 'architecture', 'refactor', 'múltiplos módulos', 'multiplos modulos', 'multiple modules']),
            'complex_product' => $this->bool($input['complex_product'] ?? false) || $this->hasAny($lower, ['saas', 'ecommerce', 'e-commerce', 'empresa', 'produto complexo', 'obra', 'forge']),
            'forge' => $this->bool($input['forge_involved'] ?? $input['use_forge'] ?? false) || $this->hasAny($lower, ['forge', 'obra', 'milestone', 'work packet']),
            'readme' => $this->hasAny($lower, ['readme', 'cli', 'package', 'sdk']),
            'model' => $this->hasAny($lower, ['model-driven', 'modelo formal', 'state machine', 'máquina de estado', 'maquina de estado']),
            'observability' => $this->hasAny($lower, ['log', 'trace', 'receipt', 'readiness', 'observability', 'observabilidade']),
            'many_files' => count($files) >= 6,
            'multiple_domains' => count($domains) >= 2,
            'repeated_failure' => (int) ($input['repeat_failures'] ?? 0) >= 2,
            'missing_context' => $this->bool($input['missing_context'] ?? false),
        ];
    }

    private function taskType(string $lower, array $input): string
    {
        $hint = $this->string($input['task_type'] ?? $input['flow_hint'] ?? null);
        if ($hint !== null) {
            return str_replace('-', '_', mb_strtolower($hint));
        }

        return match (true) {
            $this->hasAny($lower, ['bug', 'erro', 'quebr', 'fix']) => 'bug',
            $this->hasAny($lower, ['migration', 'schema', 'query']) => 'migration',
            $this->hasAny($lower, ['api', 'endpoint', 'webhook']) => 'api',
            $this->hasAny($lower, ['refactor', 'arquitetura', 'architecture']) => 'refactor',
            $this->hasAny($lower, ['docs', 'documentação', 'documentacao', 'cartografia', 'canon']) => 'documentation',
            $this->hasAny($lower, ['saas', 'ecommerce', 'empresa', 'produto complexo']) => 'product',
            default => 'feature',
        };
    }

    /**
     * @param  array<string,bool>  $signals
     */
    private function riskLevel(string $lower, array $input, array $signals): string
    {
        $hint = $this->string($input['risk_level'] ?? $input['risk'] ?? null);
        if (in_array($hint, ['low', 'medium', 'high', 'critical'], true)) {
            return $hint;
        }
        if ($signals['security'] || $signals['database'] || $signals['complex_product']) {
            return $signals['security'] && $signals['database'] ? 'critical' : 'high';
        }
        if ($signals['architecture'] || $signals['many_files'] || $signals['multiple_domains']) {
            return 'high';
        }
        if ($signals['code']) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<string,bool>  $signals
     */
    private function ambiguityLevel(string $task, array $input, array $signals): string
    {
        $hint = $this->string($input['ambiguity_level'] ?? null);
        if (in_array($hint, ['low', 'medium', 'high'], true)) {
            return $hint;
        }
        if (mb_strlen(trim($task)) < 12 || $signals['missing_context']) {
            return 'high';
        }
        if ($signals['many_files'] || $signals['multiple_domains']) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<string,bool>  $signals
     * @param  list<string>  $blockers
     */
    private function escalation(array $signals, string $risk, string $ambiguity, array $blockers): string
    {
        if ($ambiguity === 'high' || in_array('high_ambiguity_requires_clarification_or_context_gate', $blockers, true)) {
            return 'human_clarification';
        }
        if ($signals['forge'] || $signals['complex_product'] || $signals['many_files'] || $signals['multiple_domains'] || $signals['repeated_failure']) {
            return $risk === 'critical' ? 'forge_obra' : 'forge_work_packet';
        }
        if (in_array($risk, ['high', 'critical'], true)) {
            return 'dev_with_review';
        }

        return 'local_dev';
    }

    /**
     * @param  array<string,bool>  $signals
     * @return list<string>
     */
    private function artifacts(array $signals): array
    {
        $artifacts = ['acceptance_map', 'evidence_receipt'];
        if ($signals['code']) {
            $artifacts[] = 'test_plan';
        }
        if ($signals['api']) {
            $artifacts[] = 'contract_schema';
        }
        if ($signals['ui']) {
            $artifacts[] = 'ux_expectation';
        }
        if ($signals['database']) {
            $artifacts[] = 'rollback_plan';
        }
        if ($signals['model']) {
            $artifacts[] = 'model_contract';
        }
        if ($signals['observability']) {
            $artifacts[] = 'observability_readiness_evidence';
        }

        return $this->unique($artifacts);
    }

    /**
     * @param  array<string,bool>  $signals
     * @return list<string>
     */
    private function requiredGates(array $signals, string $risk): array
    {
        $gates = ['aedpds_gate', 'context_gate', 'evidence_gate'];
        if ($signals['code']) {
            $gates[] = 'test_impact_gate';
        }
        if ($signals['api']) {
            $gates[] = 'contract_gate';
        }
        if ($signals['ui']) {
            $gates[] = 'ux_gate';
        }
        if ($signals['model']) {
            $gates[] = 'model_contract_gate';
        }
        if ($signals['observability']) {
            $gates[] = 'observability_readiness_gate';
        }
        if (in_array($risk, ['high', 'critical'], true)) {
            $gates[] = 'risk_review_gate';
        }

        return $this->unique($gates);
    }

    /**
     * @param  array<string,bool>  $signals
     */
    private function reason(array $signals, bool $allowed, string $escalation): string
    {
        $detected = array_keys(array_filter($signals));

        return ($allowed ? 'selected_required_delivery_drivers' : 'blocked_until_required_context_or_review_exists')
            .'; signals='.implode(',', array_slice($detected, 0, 8))
            .'; escalation='.$escalation;
    }

    /**
     * @param  list<string>  $drivers
     * @return list<string>
     */
    private function drivers(array $drivers): array
    {
        return array_values(array_intersect($this->unique($drivers), self::DRIVERS));
    }

    private function surface(mixed $value): string
    {
        $surface = $this->string($value) ?? 'atlas_ai';
        if (in_array($surface, ['atlas_dev', 'atlas_forge'], true)) {
            return $surface;
        }

        return in_array($surface, ['atlas_ai', 'atlas_dev', 'atlas_forge', 'dev', 'forge', 'cartografia', 'control_plane'], true)
            ? str_replace(['dev', 'forge'], ['atlas_dev', 'atlas_forge'], $surface)
            : $surface;
    }

    private function hasAny(string $lower, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (in_array($needle, ['ui', 'ux'], true)) {
                if (preg_match('/(^|[^a-z0-9])'.preg_quote($needle, '/').'([^a-z0-9]|$)/u', $lower) === 1) {
                    return true;
                }

                continue;
            }
            if ($needle === 'log') {
                if (preg_match('/(^|[^a-z0-9])logs?([^a-z0-9]|$)/u', $lower) === 1) {
                    return true;
                }

                continue;
            }
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $value,
        ))) : [];
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function unique(array $items): array
    {
        return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
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
