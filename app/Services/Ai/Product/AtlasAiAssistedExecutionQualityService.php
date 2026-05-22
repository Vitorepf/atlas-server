<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRuntimeIntelligenceService;

class AtlasAiAssistedExecutionQualityService
{
    public const SCHEMA_VERSION = 'atlas.ai.assisted_execution_quality.v1';

    /** @var list<string> */
    public const REQUIRED_PIPELINE_STEPS = [
        'human_intake',
        'route_selection',
        'task_packet',
        'context_gate',
        'spec_contract',
        'test_impact',
        'scope_guard',
        'simulation',
        'provider_prompt_projection',
        'execution_or_escalation',
        'failure_capsule_or_success',
        'repair_loop',
        'completion_gate',
        'outcome_memory',
        'run_certification',
    ];

    public function __construct(
        private readonly DevRuntimeIntelligenceService $devRuntime = new DevRuntimeIntelligenceService,
    ) {}

    /**
     * Build a provider-free assisted execution envelope from a human request.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function buildEnvelope(array $input): array
    {
        $request = $this->string($input['human_request'] ?? $input['input_text'] ?? $input['prompt'] ?? null)
            ?? 'Atlas assisted execution request';
        $workspace = $this->string($input['workspace'] ?? $input['workspace_slug'] ?? null);
        $route = $this->routeFor($request, $input);
        $contract = $this->executionContract($request, $input, $route);

        $devPreview = null;
        if ($route['target'] === 'atlas_dev') {
            $devPreview = $this->devRuntime->preview([
                'run_id' => $this->string($input['run_id'] ?? null) ?? 'assisted-execution-preview',
                'task_id' => $this->string($input['task_id'] ?? null) ?? 'human-request-'.substr(MissionCanonicalHash::sha256($request), 0, 12),
                'objective' => $contract['objective'],
                'task_class' => $contract['task_class'],
                'risk_band' => $contract['risk_band'],
                'workspace_slug' => $workspace,
                'allowed_files' => $contract['allowed_files'],
                'forbidden_files' => $contract['forbidden_files'],
                'context_refs' => $contract['context_refs'],
                'expected_files' => $contract['expected_files'],
                'suggested_tests' => $contract['suggested_tests'],
                'acceptance_criteria' => $contract['acceptance_criteria'],
                'required_evidence' => $contract['required_evidence'],
                'source' => 'AtlasAiAssistedExecutionQualityService',
            ]);
        }

        $blockers = [];
        if ($workspace === null) {
            $blockers[] = [
                'id' => 'workspace_required',
                'reason' => 'Assisted programming execution needs a selected workspace.',
            ];
        }
        if ($route['target'] === 'atlas_dev' && ($devPreview['provider_safe'] ?? false) !== true) {
            $blockers[] = [
                'id' => 'dev_context_not_provider_safe',
                'reason' => 'Dev context gate did not approve provider execution.',
                'missing' => $devPreview['context_gate']['missing'] ?? [],
            ];
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready_for_assisted_execution' : 'needs_context',
            'human_intake' => [
                'request' => $request,
                'workspace' => $workspace,
                'surface' => $this->string($input['surface_id'] ?? null) ?? 'atlas_ai',
            ],
            'route' => $route,
            'execution_contract' => $contract,
            'quality_pipeline' => [
                'required_steps' => self::REQUIRED_PIPELINE_STEPS,
                'provider_may_run_without_context_gate' => false,
                'completion_requires_evidence' => true,
                'failed_run_requires_failure_capsule' => true,
                'large_or_uncertain_work_escalates_to_forge' => true,
            ],
            'dev_runtime_preview' => $devPreview,
            'blockers' => $blockers,
        ];
        $payload['assisted_execution_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function routeFor(string $request, array $input): array
    {
        $lower = mb_strtolower($request);
        $forced = $this->string($input['route'] ?? $input['target'] ?? null);
        $expectedFiles = $this->stringList($input['expected_files'] ?? []);
        $longWork = str_contains($lower, 'obra')
            || str_contains($lower, 'milestone')
            || str_contains($lower, 'sistema inteiro')
            || count($expectedFiles) > 6;

        if ($forced === 'forge' || $forced === 'atlas_forge' || $longWork) {
            return [
                'target' => 'atlas_forge',
                'flow_id' => 'programming.forge',
                'reason' => 'Long-horizon, multi-file or explicitly Forge-scoped work requires Obra.',
            ];
        }

        return [
            'target' => 'atlas_dev',
            'flow_id' => str_contains($lower, 'revis') ? 'programming.review' : (str_contains($lower, 'bug') || str_contains($lower, 'erro') ? 'programming.repair' : 'programming.dev'),
            'reason' => 'Human request fits short assisted Dev execution.',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $route
     * @return array<string,mixed>
     */
    private function executionContract(string $request, array $input, array $route): array
    {
        $lower = mb_strtolower($request);
        $isBug = str_contains($lower, 'bug') || str_contains($lower, 'erro') || str_contains($lower, 'quebr');
        $isLogin = str_contains($lower, 'login') || str_contains($lower, 'auth') || str_contains($lower, 'autentic');
        $risk = $this->string($input['risk_band'] ?? null) ?? ($isLogin ? 'high' : 'medium');
        $taskClass = $isBug ? 'debug' : 'feature';

        $contextRefs = $this->stringList($input['context_refs'] ?? []);
        if ($contextRefs === []) {
            $contextRefs = ['docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md'];
        }

        $expectedFiles = $this->stringList($input['expected_files'] ?? []);
        if ($expectedFiles === [] && $isLogin) {
            $expectedFiles = ['auth/login surface', 'auth/session service', 'login tests'];
        }

        $suggestedTests = $this->stringList($input['suggested_tests'] ?? []);
        if ($suggestedTests === [] && $isLogin) {
            $suggestedTests = ['php artisan test --filter=Login|Auth|Session'];
        } elseif ($suggestedTests === []) {
            $suggestedTests = ['php artisan test --filter=AtlasDev'];
        }

        return [
            'objective' => $isBug ? 'Corrigir bug reportado: '.$request : 'Executar pedido assistido: '.$request,
            'task_class' => $taskClass,
            'risk_band' => $risk,
            'route_target' => $route['target'],
            'allowed_files' => $this->stringList($input['allowed_files'] ?? $expectedFiles),
            'forbidden_files' => $this->stringList($input['forbidden_files'] ?? ['vendor/', 'node_modules/', '.env']),
            'context_refs' => $contextRefs,
            'expected_files' => $expectedFiles,
            'suggested_tests' => $suggestedTests,
            'acceptance_criteria' => $this->stringList($input['acceptance_criteria'] ?? [
                'Reproduzir ou explicar o bug antes do patch.',
                'Aplicar patch somente dentro do escopo declarado.',
                'Rodar teste focado ou registrar skip_reason verificavel.',
                'Persistir outcome memory e run certification.',
            ]),
            'required_evidence' => $this->stringList($input['required_evidence'] ?? ['spec', 'diff_or_reason', 'focused_tests', 'scope_guard', 'completion_gate']),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $value,
        ))));
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
