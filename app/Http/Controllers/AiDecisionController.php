<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiDecisionResource;
use App\Models\AiDecision;
use App\Services\Ai\AiProviderModelResolver;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\AtlasDecideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiDecisionController extends Controller
{
    private const INVOCATION_PROVIDERS = ['claude_cli', 'codex_cli', 'gemini_cli'];

    public function index(Request $request): JsonResponse
    {
        $decisions = AiDecision::query()
            ->with('routerDecision')
            ->when($request->query('trace_id'), fn ($query, $traceId) => $query->where('trace_id', $traceId))
            ->when($request->query('provider'), fn ($query, $provider) => $query->where('selected_provider', $provider))
            ->when($request->query('decision_mode'), fn ($query, $mode) => $query->where('decision_mode', $mode))
            ->when($request->query('task_type'), fn ($query, $taskType) => $query->where('task_type', $taskType))
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get();

        return response()->json([
            'decisions' => AiDecisionResource::collection($decisions)->resolve(),
        ]);
    }

    public function show(AiDecision $decision): JsonResponse
    {
        return response()->json([
            'decision' => (new AiDecisionResource($decision->load(['trace', 'routerDecision'])))->resolve(),
        ]);
    }

    public function preview(
        Request $request,
        AtlasDecideService $decide,
        AtlasAiRuntimeSettings $settings,
        AiProviderModelResolver $models,
    ): JsonResponse {
        $data = $request->validate([
            'input_text' => ['required', 'string', 'max:50000'],
            'provider' => ['nullable', 'string', 'in:claude_cli,codex_cli,gemini_cli,claude_codex,auto'],
            'model' => ['nullable', 'string', 'max:120'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'agent_slug' => ['nullable', 'string', 'max:80'],
            'mode' => ['nullable', 'string', 'max:80'],
            'payload' => ['nullable', 'array'],
        ]);

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $provider = $data['provider'] ?? null;
        if ($provider === 'auto') {
            $payload['operator_requested_provider'] = 'auto';
            $provider = null;
        }

        $options = $decide->normalizeOptions([
            'input_text' => $data['input_text'],
            'provider' => $provider,
            'model' => $data['model'] ?? null,
            'source_type' => $data['source_type'] ?? 'manual',
            'agent_slug' => $data['agent_slug'] ?? null,
            'mode' => $data['mode'] ?? null,
            'payload' => $payload,
        ]);

        $candidate = $decide->candidateProvider($options, $settings->defaultProvider());
        $selectedProvider = $this->providerAllowedForPreview($candidate, $options, $decide, $settings);
        $modelResolution = $models->resolveWithSource($selectedProvider, $data['model'] ?? null);
        $fallbackReason = $candidate !== $selectedProvider
            ? $this->fallbackReason($candidate, $selectedProvider, $options, $decide, $settings)
            : null;
        $options = $this->optionsWithSelectionMetadata($options, $candidate, $selectedProvider, $fallbackReason);
        $receipt = $decide->receiptForTrace($options, $selectedProvider, $modelResolution['model']);
        $plan = $decide->decisionPlan($options, $selectedProvider, $modelResolution['model']);

        return response()->json([
            'decision' => [
                ...$receipt,
                ...$plan,
                'policy_version' => (string) data_get($options, 'payload.atlas_decide.policy_version', 'atlas-decide-v1'),
                'route_mode' => (string) (data_get($options, 'payload.atlas_workflow_mode') ?: ($options['mode'] ?? 'direct')),
                'confidence_score' => $this->confidenceScore($decide, $options, $selectedProvider),
                'candidate_provider' => $candidate,
                'fallback_provider' => $candidate !== $selectedProvider ? $selectedProvider : null,
                'fallback_reason' => $fallbackReason,
                'candidates' => $this->candidates($settings, $models, $selectedProvider, $options, $decide),
                'constraints' => $this->constraints($settings, $options, $selectedProvider, $decide),
                'metrics_snapshot' => [
                    'default_provider' => $settings->defaultProvider(),
                    'default_tier' => $settings->defaultTier(),
                    'model_identity_source' => $modelResolution['source'],
                    'model_tier' => $modelResolution['model_tier'],
                    'source_type' => $options['source_type'] ?? null,
                ],
            ],
        ]);
    }

    private function optionsWithSelectionMetadata(array $options, string $candidateProvider, string $selectedProvider, ?string $fallbackReason): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $payload['atlas_decide'] = array_merge(
            is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
            [
                'candidate_provider' => $candidateProvider,
                'selected_provider' => $selectedProvider,
                'fallback_provider' => $fallbackReason ? $selectedProvider : null,
                'fallback_reason' => $fallbackReason,
            ],
        );
        $options['payload'] = $payload;

        return $options;
    }

    private function fallbackReason(
        string $candidateProvider,
        string $selectedProvider,
        array $options,
        AtlasDecideService $decide,
        AtlasAiRuntimeSettings $settings,
    ): string {
        if ($candidateProvider === $selectedProvider) {
            return 'none';
        }

        if ($candidateProvider === 'gemini_cli' && $decide->isProgrammingLikeTask($options)) {
            return 'gemini_blocked_for_dev_like_task';
        }

        if ($decide->manualOverrideProvider($options) === null
            && ! (bool) ($settings->providerConfig($candidateProvider)['allow_auto'] ?? true)
        ) {
            return 'candidate_auto_disabled';
        }

        if ($decide->manualOverrideProvider($options) !== null
            && ! (bool) ($settings->providerConfig($candidateProvider)['allow_manual'] ?? true)
        ) {
            return 'candidate_manual_disabled';
        }

        return 'provider_gate_fallback';
    }

    private function providerAllowedForPreview(
        string $candidate,
        array $options,
        AtlasDecideService $decide,
        AtlasAiRuntimeSettings $settings,
    ): string {
        $manualProvider = $decide->manualOverrideProvider($options);

        if ($manualProvider !== null) {
            return (bool) ($settings->providerConfig($candidate)['allow_manual'] ?? true)
                ? $candidate
                : $this->manualFallbackProvider($candidate, $settings);
        }

        if ($candidate === 'gemini_cli' && $decide->isProgrammingLikeTask($options)) {
            return (string) ($settings->providerConfig('gemini_cli')['fallback_provider'] ?? 'claude_cli');
        }

        if ((bool) ($settings->providerConfig($candidate)['allow_auto'] ?? true)) {
            return $candidate;
        }

        return $this->automaticFallbackProvider($settings, $options, $decide);
    }

    private function manualFallbackProvider(string $provider, AtlasAiRuntimeSettings $settings): string
    {
        $default = $settings->defaultProvider();
        if ($default !== $provider && $default !== 'claude_codex' && (bool) ($settings->providerConfig($default)['allow_manual'] ?? true)) {
            return $default;
        }

        return $provider === 'claude_cli' ? 'codex_cli' : 'claude_cli';
    }

    private function automaticFallbackProvider(AtlasAiRuntimeSettings $settings, array $options, AtlasDecideService $decide): string
    {
        $default = $settings->defaultProvider();
        if ($default !== 'claude_codex'
            && ! ($default === 'gemini_cli' && $decide->isProgrammingLikeTask($options))
            && (bool) ($settings->providerConfig($default)['allow_auto'] ?? true)
        ) {
            return $default;
        }

        return 'claude_cli';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function candidates(
        AtlasAiRuntimeSettings $settings,
        AiProviderModelResolver $models,
        string $selectedProvider,
        array $options,
        AtlasDecideService $decide,
    ): array {
        $manualProvider = $decide->manualOverrideProvider($options);

        return collect(self::INVOCATION_PROVIDERS)
            ->map(fn (string $provider): array => [
                'provider' => $provider,
                'selected' => $provider === $selectedProvider,
                'manual_override' => $manualProvider === $provider,
                'allow_auto' => (bool) ($settings->providerConfig($provider)['allow_auto'] ?? true),
                'allow_manual' => (bool) ($settings->providerConfig($provider)['allow_manual'] ?? true),
                'model' => $models->resolveWithSource($provider)['model'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function constraints(
        AtlasAiRuntimeSettings $settings,
        array $options,
        string $selectedProvider,
        AtlasDecideService $decide,
    ): array {
        return [
            'selected_provider_allow_auto' => (bool) ($settings->providerConfig($selectedProvider)['allow_auto'] ?? true),
            'selected_provider_allow_manual' => (bool) ($settings->providerConfig($selectedProvider)['allow_manual'] ?? true),
            'gemini_blocked_for_dev_like_task' => $decide->isProgrammingLikeTask($options),
            'needs_long_context_or_multimodal_provider' => $decide->needsLongContextOrMultimodalProvider($options),
            'budget_enabled' => (bool) data_get($settings->effective(), 'budget.enabled', false),
        ];
    }

    private function confidenceScore(AtlasDecideService $decide, array $options, string $provider): int
    {
        if ($decide->manualOverrideProvider($options)) {
            return 100;
        }

        if ($provider === 'gemini_cli' && $decide->needsLongContextOrMultimodalProvider($options)) {
            return 88;
        }

        if ($provider === 'codex_cli' && $decide->isProgrammingLikeTask($options)) {
            return 86;
        }

        return 74;
    }
}
