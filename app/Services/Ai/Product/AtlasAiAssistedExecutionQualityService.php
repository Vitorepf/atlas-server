<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Context\AtlasCognitiveMemoryFabricService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRuntimeIntelligenceService;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;

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

    /** @var list<string> */
    public const REQUIRED_CONTROL_AREAS = [
        'aedpds_doctrine_gate',
        'aucri_acmf_context_memory',
        'areg_efficiency_governor',
        'aemor_outcome_memory',
        'dev_or_forge_execution_runtime',
        'evidence_receipts',
        'operational_readiness_surface',
    ];

    public function __construct(
        private readonly DevRuntimeIntelligenceService $devRuntime = new DevRuntimeIntelligenceService,
        private readonly ?AtlasExecutionDoctrineRuntimeService $executionDoctrine = null,
        private readonly ?AtlasExecutionDoctrineGateService $executionDoctrineGate = null,
        private readonly ?AtlasCognitiveMemoryFabricService $cognitiveMemory = null,
        private readonly ?AtlasRuntimeEfficiencyGovernorService $runtimeEfficiency = null,
        private readonly ?AtlasAemorRuntimeService $aemor = null,
    ) {}

    /**
     * Build a provider-free assisted execution envelope from a human request.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function buildEnvelope(array $input): array
    {
        $request = AiValueNormalizer::trimmedScalarStringOrNull($input['human_request'] ?? $input['input_text'] ?? $input['prompt'] ?? null)
            ?? 'Atlas assisted execution request';
        $workspace = AiValueNormalizer::trimmedScalarStringOrNull($input['workspace'] ?? $input['workspace_slug'] ?? null);
        $route = $this->routeFor($request, $input);
        $contract = $this->executionContract($request, $input, $route);
        $doctrine = $this->doctrine($request, $workspace, $route, $contract, $input);
        $doctrineGate = $this->doctrineGate($request, $workspace, $doctrine, $contract, $input);
        $contextMemory = $this->contextMemoryPlan($request, $contract, $doctrine, $input);
        $efficiency = $this->efficiencyDecision($request, $workspace, $route, $contract, $doctrine, $input);
        $outcomeMemory = $this->outcomeMemoryContract($request, $workspace, $route, $contract, $doctrine, $doctrineGate, $efficiency);

        $devPreview = null;
        if ($route['target'] === 'atlas_dev') {
            $devPreview = $this->devRuntime->preview([
                'run_id' => AiValueNormalizer::trimmedScalarStringOrNull($input['run_id'] ?? null) ?? 'assisted-execution-preview',
                'task_id' => AiValueNormalizer::trimmedScalarStringOrNull($input['task_id'] ?? null) ?? 'human-request-'.substr(MissionCanonicalHash::sha256($request), 0, 12),
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
        if (($doctrineGate['status'] ?? null) === 'blocked') {
            $blockers[] = [
                'id' => 'aedpds_gate_blocked',
                'reason' => 'AEDPDS doctrine gate blocked assisted execution.',
                'gate_blockers' => $doctrineGate['blockers'] ?? [],
                'required_next_actions' => $doctrineGate['required_next_actions'] ?? [],
            ];
        }
        if (($contextMemory['status'] ?? null) === 'blocked') {
            $blockers[] = [
                'id' => 'acmf_context_memory_blocked',
                'reason' => 'ACMF did not produce a provider-safe cognitive memory plan.',
            ];
        }
        if (($efficiency['status'] ?? null) === AtlasRuntimeEfficiencyGovernorService::STATUS_BLOCKED) {
            $blockers[] = [
                'id' => 'areg_blocked_execution_path',
                'reason' => 'AREG selected blocked_path for this assisted execution.',
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
                'surface' => AiValueNormalizer::trimmedScalarStringOrNull($input['surface_id'] ?? null) ?? 'atlas_ai',
            ],
            'route' => $route,
            'execution_contract' => $contract,
            'aedpds' => [
                'doctrine' => $doctrine,
                'gate' => $doctrineGate,
            ],
            'aucri_acmf' => $contextMemory,
            'areg' => $efficiency,
            'aemor_outcome_memory' => $outcomeMemory,
            'quality_pipeline' => [
                'required_steps' => self::REQUIRED_PIPELINE_STEPS,
                'required_control_areas' => self::REQUIRED_CONTROL_AREAS,
                'provider_may_run_without_context_gate' => false,
                'provider_may_run_without_aedpds_gate' => false,
                'provider_may_run_without_acmf_plan' => false,
                'provider_may_run_without_areg_decision' => false,
                'completion_requires_evidence' => true,
                'completion_requires_aemor_outcome' => true,
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
     * Close the post-execution learning loop for an assisted AI execution.
     *
     * By default this is a provider-free, write-free feedback receipt. Passing
     * `persist=true` records the AREG outcome and opens/closes an AEMOR episode
     * when the local tables exist.
     *
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordOutcomeFeedback(array $envelope, array $input = []): array
    {
        $evidenceRefs = AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['evidence_refs'] ?? []);
        if ($evidenceRefs === []) {
            $evidenceRefs = AiStringListNormalizer::uniqueTruthyTrimmedScalarValues(data_get($envelope, 'execution_contract.required_evidence', []));
        }

        $persist = (bool) ($input['persist'] ?? false);
        $status = AiValueNormalizer::trimmedScalarStringOrNull($input['status'] ?? null) ?? ($evidenceRefs === [] ? 'blocked' : 'succeeded');
        $qualityScore = $this->score($input['quality_score'] ?? ($status === 'succeeded' ? 0.90 : 0.45));
        $contextRoiScore = $this->score($input['context_roi_score'] ?? ($status === 'succeeded' ? 0.82 : 0.35));
        $blockers = $evidenceRefs === []
            ? [['id' => 'missing_evidence_refs', 'reason' => 'Assisted execution outcome feedback requires evidence refs.']]
            : [];

        $aregOutcome = $this->runtimeEfficiency()->recordOutcome([
            'decision_id' => AiValueNormalizer::trimmedScalarStringOrNull(data_get($envelope, 'areg.decision_id')),
            'status' => $status === 'succeeded' ? AtlasRuntimeEfficiencyGovernorService::STATUS_READY : AtlasRuntimeEfficiencyGovernorService::STATUS_WATCH,
            'outcome_type' => 'assisted_execution_feedback',
            'quality_score' => $qualityScore,
            'context_roi_score' => $contextRoiScore,
            'signals' => [
                'assisted_execution_status' => $status,
                'route' => data_get($envelope, 'route.target'),
                'flow_id' => data_get($envelope, 'route.flow_id'),
                'aedpds_gate_status' => data_get($envelope, 'aedpds.gate.status'),
                'areg_path' => data_get($envelope, 'areg.path'),
                'driver_effectiveness' => $input['driver_effectiveness'] ?? $this->driverEffectiveness($envelope, $status),
            ],
            'evidence_refs' => $evidenceRefs,
            'persist' => $persist,
        ]);

        $aemor = $this->aemorOutcomeFeedback($envelope, [
            'persist' => $persist,
            'status' => $status,
            'quality_score' => $qualityScore,
            'context_roi_score' => $contextRoiScore,
            'evidence_refs' => $evidenceRefs,
            'blockers' => $blockers,
            'summary' => AiValueNormalizer::trimmedScalarStringOrNull($input['summary'] ?? null) ?? 'Assisted AI execution outcome feedback closed.',
        ]);

        $payload = [
            'schema_version' => 'atlas.ai.assisted_execution_outcome_feedback.v1',
            'status' => $blockers === [] ? 'recorded' : 'blocked',
            'route' => data_get($envelope, 'route.target'),
            'flow_id' => data_get($envelope, 'route.flow_id'),
            'aedpds_gate_status' => data_get($envelope, 'aedpds.gate.status'),
            'areg_outcome' => $aregOutcome,
            'aemor_outcome' => $aemor,
            'driver_effectiveness' => $input['driver_effectiveness'] ?? $this->driverEffectiveness($envelope, $status),
            'evidence_refs' => $evidenceRefs,
            'blockers' => $blockers,
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => (bool) (($aregOutcome['writes'] ?? false) || ($aemor['writes'] ?? false)),
                'auto_promotes_memory' => false,
                'requires_aemor_judgment_for_learning_promotion' => true,
            ],
        ];
        $payload['feedback_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $route
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function doctrine(string $request, ?string $workspace, array $route, array $contract, array $input): array
    {
        return $this->executionDoctrine()->select([
            'task' => $request,
            'surface' => AiValueNormalizer::trimmedScalarStringOrNull($input['surface_id'] ?? null) ?? 'atlas_ai',
            'workspace' => $workspace,
            'task_type' => $contract['task_class'] === 'debug' ? 'bug' : 'feature',
            'flow_hint' => $route['flow_id'],
            'expected_files' => $contract['expected_files'],
            'domains' => $this->domainsFor($request),
            'code_changes_requested' => true,
            'ui_involved' => $this->containsAny($request, ['tela', 'ui', 'ux', 'frontend', 'mobile', 'desktop', 'layout', 'visual']),
            'api_involved' => $this->containsAny($request, ['api', 'endpoint', 'payload', 'webhook', 'contract', 'integra']),
            'database_involved' => $this->containsAny($request, ['migration', 'banco', 'database', 'db', 'query', 'schema', 'sql']),
            'security_involved' => $this->containsAny($request, ['login', 'auth', 'autentic', 'security', 'segurança', 'billing', 'pagamento', 'payment', 'provider']),
            'performance_involved' => $this->containsAny($request, ['performance', 'latência', 'latencia', 'custo', 'token', 'cache', 'lento']),
            'architecture_involved' => $route['target'] === 'atlas_forge' || count($contract['expected_files']) > 4,
            'complex_product' => $route['target'] === 'atlas_forge' || $this->containsAny($request, ['saas', 'ecommerce', 'empresa', 'produto complexo']),
            'missing_context' => $contract['context_refs'] === [],
            'senior_review_present' => $contract['review_refs'] !== [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $doctrine
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function doctrineGate(string $request, ?string $workspace, array $doctrine, array $contract, array $input): array
    {
        return $this->executionDoctrineGate()->evaluate([
            'task' => $request,
            'workspace' => $workspace,
            'doctrine' => $doctrine,
            'context_refs' => $contract['context_refs'],
            'acceptance_criteria' => $contract['acceptance_criteria'],
            'suggested_tests' => $contract['suggested_tests'],
            'contracts' => $contract['contract_refs'],
            'docs' => $contract['doc_refs'],
            'review' => $contract['review_refs'],
            'evidence_refs' => $contract['required_evidence'],
            'ux_expectations' => $contract['ux_expectations'],
            'risk_band' => AiValueNormalizer::trimmedScalarStringOrNull($input['risk_band'] ?? null),
        ]);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $doctrine
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function contextMemoryPlan(string $request, array $contract, array $doctrine, array $input): array
    {
        $items = [
            ['kind' => 'decision', 'ref' => 'assisted://aedpds/doctrine', 'tokens' => 800, 'bytes' => 1024 * 1024, 'heat' => 1.0, 'must_keep' => true],
            ['kind' => 'constraint', 'ref' => 'assisted://dev/context-gate', 'tokens' => 650, 'bytes' => 1024 * 1024, 'heat' => 0.98, 'must_keep' => true],
            ['kind' => 'test', 'ref' => 'assisted://verification/plan', 'tokens' => 500, 'bytes' => 1024 * 1024, 'heat' => 0.92, 'must_keep' => true],
        ];

        foreach ($contract['context_refs'] as $index => $ref) {
            $items[] = [
                'kind' => 'context_ref',
                'ref' => 'assisted://context/'.MissionCanonicalHash::sha256([$index, $ref]),
                'tokens' => 900,
                'bytes' => 2 * 1024 * 1024,
                'heat' => 0.86,
                'must_keep' => $index < 3,
                'rebuildable' => true,
            ];
        }

        $plan = $this->cognitiveMemory()->plan([
            'items' => $items,
            'raw_context' => $request,
            'risk_level' => (string) ($doctrine['risk_level'] ?? 'medium'),
            'memory_available_bytes' => (int) ($input['memory_available_bytes'] ?? 12 * 1073741824),
            'repeated_tokens' => (int) ($input['repeated_tokens'] ?? 1200),
        ]);

        return [
            'schema_version' => AtlasCognitiveMemoryFabricService::SCHEMA_VERSION,
            'status' => (string) ($plan['status'] ?? 'unknown'),
            'budget' => $plan['budget'] ?? [],
            'working_set' => $plan['working_set'] ?? [],
            'delta_receipt' => $plan['delta_receipt'] ?? [],
            'spillover_receipt' => $plan['spillover_receipt'] ?? [],
            'pressure_event' => $plan['pressure_event'] ?? [],
            'privacy_ref' => $plan['privacy_ref'] ?? [],
            'cognitive_memory_hash' => (string) ($plan['cognitive_memory_hash'] ?? ''),
            'raw_text_exposed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $route
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $doctrine
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function efficiencyDecision(string $request, ?string $workspace, array $route, array $contract, array $doctrine, array $input): array
    {
        $decision = $this->runtimeEfficiency()->govern([
            'prompt' => $request,
            'surface_id' => AiValueNormalizer::trimmedScalarStringOrNull($input['surface_id'] ?? null) ?? 'atlas_ai',
            'workspace' => $workspace,
            'domain' => 'programming',
            'flow_id' => $route['flow_id'],
            'risk_score' => $this->riskScoreFor((string) ($doctrine['risk_level'] ?? 'medium')),
            'evidence_refs' => $contract['required_evidence'],
            'context_refs' => $contract['context_refs'],
            'persist' => false,
        ]);

        return [
            'schema_version' => AtlasRuntimeEfficiencyGovernorService::SCHEMA_VERSION,
            'status' => (string) ($decision['status'] ?? 'unknown'),
            'path' => (string) ($decision['path'] ?? ''),
            'runtime_mode' => (string) ($decision['runtime_mode'] ?? ''),
            'risk_score' => (int) ($decision['risk_score'] ?? 0),
            'complexity_score' => (int) ($decision['complexity_score'] ?? 0),
            'context_minimum_pack' => $decision['context_minimum_pack'] ?? [],
            'layer_admissions' => $decision['layer_admissions'] ?? [],
            'verification_plan' => $decision['verification_plan'] ?? [],
            'decision_hash' => (string) ($decision['decision_hash'] ?? ''),
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $route
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $doctrine
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>  $efficiency
     * @return array<string,mixed>
     */
    private function outcomeMemoryContract(string $request, ?string $workspace, array $route, array $contract, array $doctrine, array $gate, array $efficiency): array
    {
        return [
            'schema_version' => AtlasAemorRuntimeService::OUTCOME_SCHEMA,
            'episode_schema_version' => AtlasAemorRuntimeService::EPISODE_SCHEMA,
            'status' => 'required_after_execution',
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $request]),
            'workspace_hash' => $workspace === null ? null : MissionCanonicalHash::sha256(['workspace' => $workspace]),
            'flow_id' => $route['flow_id'],
            'selected_drivers' => $doctrine['selected_primary_drivers'] ?? [],
            'gate_status' => $gate['status'] ?? 'unknown',
            'areg_path' => $efficiency['path'] ?? null,
            'required_evidence_refs' => $contract['required_evidence'],
            'required_outcome_fields' => [
                'status',
                'evidence_refs',
                'context_utility',
                'driver_effectiveness',
                'failure_signature_or_success_summary',
                'next_time_policy_delta',
            ],
            'claim_policy' => $this->aemorRuntime()->claimPolicy(),
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function aemorOutcomeFeedback(array $envelope, array $input): array
    {
        if (! (bool) ($input['persist'] ?? false)) {
            return [
                'schema_version' => AtlasAemorRuntimeService::OUTCOME_SCHEMA,
                'status' => 'ready_to_record',
                'episode_schema_version' => AtlasAemorRuntimeService::EPISODE_SCHEMA,
                'required_evidence_refs' => $input['evidence_refs'] ?? [],
                'writes' => false,
            ];
        }

        $episode = $this->aemorRuntime()->openEpisode([
            'objective' => data_get($envelope, 'execution_contract.objective', 'Assisted AI execution'),
            'workspace' => data_get($envelope, 'human_intake.workspace'),
            'surface_id' => data_get($envelope, 'human_intake.surface'),
            'domain' => 'programming',
            'flow_id' => data_get($envelope, 'route.flow_id'),
            'evidence_refs' => $input['evidence_refs'] ?? [],
            'source' => 'AtlasAiAssistedExecutionQualityService',
        ]);

        if (($episode['episode_id'] ?? null) === null) {
            return [
                'schema_version' => AtlasAemorRuntimeService::OUTCOME_SCHEMA,
                'status' => 'blocked',
                'blockers' => [['id' => 'aemor_episode_not_persisted', 'reason' => 'AEMOR tables are unavailable or episode did not persist.']],
                'writes' => false,
            ];
        }

        $outcome = $this->aemorRuntime()->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => $input['status'] === 'succeeded' ? 'succeeded' : 'failed',
            'summary' => $input['summary'] ?? 'Assisted AI execution outcome feedback closed.',
            'metrics' => [
                'quality_score' => $input['quality_score'] ?? null,
                'context_roi_score' => $input['context_roi_score'] ?? null,
                'areg_path' => data_get($envelope, 'areg.path'),
                'aedpds_gate_status' => data_get($envelope, 'aedpds.gate.status'),
            ],
            'context_utility' => [
                'source' => 'assisted_execution_feedback',
                'context_roi_score' => $input['context_roi_score'] ?? null,
                'selected_drivers' => data_get($envelope, 'aedpds.doctrine.selected_primary_drivers', []),
            ],
            'patch_outcome' => [
                'route' => data_get($envelope, 'route.target'),
                'flow_id' => data_get($envelope, 'route.flow_id'),
                'driver_effectiveness' => $this->driverEffectiveness($envelope, (string) ($input['status'] ?? 'unknown')),
            ],
            'blockers' => $input['blockers'] ?? [],
            'evidence_refs' => $input['evidence_refs'] ?? [],
        ]);

        return [
            ...$outcome,
            'episode' => $episode,
            'writes' => (bool) (($episode['writes'] ?? false) || ($outcome['outcome_id'] ?? null) !== null),
        ];
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,string>
     */
    private function driverEffectiveness(array $envelope, string $status): array
    {
        $effectiveness = [];
        foreach (AiStringListNormalizer::uniqueTruthyTrimmedScalarValues(data_get($envelope, 'aedpds.doctrine.selected_primary_drivers', [])) as $driver) {
            $effectiveness[$driver] = $status === 'succeeded' ? 'effective_pending_judgment' : 'needs_review';
        }

        return $effectiveness;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function routeFor(string $request, array $input): array
    {
        $lower = mb_strtolower($request);
        $forced = AiValueNormalizer::trimmedScalarStringOrNull($input['route'] ?? $input['target'] ?? null);
        $expectedFiles = AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['expected_files'] ?? []);
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
        $risk = AiValueNormalizer::trimmedScalarStringOrNull($input['risk_band'] ?? null) ?? ($isLogin ? 'high' : 'medium');
        $taskClass = $isBug ? 'debug' : 'feature';

        $contextRefs = AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['context_refs'] ?? []);
        if ($contextRefs === []) {
            $contextRefs = ['docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md'];
        }

        $expectedFiles = AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['expected_files'] ?? []);
        if ($expectedFiles === [] && $isLogin) {
            $expectedFiles = ['auth/login surface', 'auth/session service', 'login tests'];
        }

        $suggestedTests = AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['suggested_tests'] ?? []);
        if ($suggestedTests === [] && $isLogin) {
            $suggestedTests = ['php artisan test --filter=Login|Auth|Session'];
        } elseif ($suggestedTests === []) {
            $suggestedTests = ['php artisan test --filter=AtlasDev'];
        }

        $isUi = $route['target'] === 'atlas_forge'
            || $this->containsAny($request, ['tela', 'ui', 'ux', 'frontend', 'mobile', 'desktop', 'layout', 'visual', 'produto', 'saas', 'ecommerce', 'empresa']);
        $isApi = $this->containsAny($request, ['api', 'endpoint', 'payload', 'webhook', 'contract', 'integra']);
        $reviewRefs = AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['review_refs'] ?? []);
        if ($reviewRefs === [] && (bool) ($input['operator_approved'] ?? false)) {
            $reviewRefs = ['operator_approval://risk-review'];
        }
        $acceptanceCriteria = AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['acceptance_criteria'] ?? []);
        if ($acceptanceCriteria === []) {
            $acceptanceCriteria = [
                'Reproduzir ou explicar o bug antes do patch.',
                'Aplicar patch somente dentro do escopo declarado.',
                'Rodar teste focado ou registrar skip_reason verificavel.',
                'Persistir outcome memory e run certification.',
            ];
        }
        $requiredEvidence = AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['required_evidence'] ?? []);
        if ($requiredEvidence === []) {
            $requiredEvidence = ['spec', 'diff_or_reason', 'focused_tests', 'scope_guard', 'completion_gate'];
        }

        return [
            'objective' => $isBug ? 'Corrigir bug reportado: '.$request : 'Executar pedido assistido: '.$request,
            'task_class' => $taskClass,
            'risk_band' => $risk,
            'route_target' => $route['target'],
            'allowed_files' => AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['allowed_files'] ?? $expectedFiles),
            'forbidden_files' => AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['forbidden_files'] ?? ['vendor/', 'node_modules/', '.env']),
            'context_refs' => $contextRefs,
            'expected_files' => $expectedFiles,
            'suggested_tests' => $suggestedTests,
            'contract_refs' => AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['contract_refs'] ?? ($isApi ? ['api_or_payload_contract_required'] : [])),
            'doc_refs' => AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['doc_refs'] ?? []),
            'review_refs' => $reviewRefs,
            'ux_expectations' => AiStringListNormalizer::uniqueTruthyTrimmedScalarValues($input['ux_expectations'] ?? ($isUi ? ['preserve_or_improve_reported_screen_experience'] : [])),
            'acceptance_criteria' => $acceptanceCriteria,
            'required_evidence' => $requiredEvidence,
        ];
    }

    /**
     * @return list<string>
     */
    private function domainsFor(string $request): array
    {
        $domains = ['programming'];
        if ($this->containsAny($request, ['produto', 'saas', 'ecommerce', 'empresa', 'checkout', 'pagamento'])) {
            $domains[] = 'product';
        }
        if ($this->containsAny($request, ['login', 'auth', 'security', 'segurança', 'billing', 'pagamento'])) {
            $domains[] = 'security';
        }

        return array_values(array_unique($domains));
    }

    private function riskScoreFor(string $riskLevel): int
    {
        return match ($riskLevel) {
            'critical' => 10,
            'high' => 8,
            'medium' => 5,
            default => 3,
        };
    }

    private function score(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, round((float) $value, 4)));
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        $lower = mb_strtolower($text);

        foreach ($needles as $needle) {
            if (str_contains($lower, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function executionDoctrine(): AtlasExecutionDoctrineRuntimeService
    {
        return $this->executionDoctrine ?? app(AtlasExecutionDoctrineRuntimeService::class);
    }

    private function executionDoctrineGate(): AtlasExecutionDoctrineGateService
    {
        return $this->executionDoctrineGate ?? app(AtlasExecutionDoctrineGateService::class);
    }

    private function cognitiveMemory(): AtlasCognitiveMemoryFabricService
    {
        return $this->cognitiveMemory ?? app(AtlasCognitiveMemoryFabricService::class);
    }

    private function runtimeEfficiency(): AtlasRuntimeEfficiencyGovernorService
    {
        return $this->runtimeEfficiency ?? app(AtlasRuntimeEfficiencyGovernorService::class);
    }

    private function aemorRuntime(): AtlasAemorRuntimeService
    {
        return $this->aemor ?? app(AtlasAemorRuntimeService::class);
    }

}
