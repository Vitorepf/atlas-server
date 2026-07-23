<?php

namespace App\Services\Ai\AiGateway;

use App\Models\AiCompaction;
use App\Models\AiDecision;
use App\Models\AiJob;
use App\Models\AiMessage;
use App\Models\AiRouterDecision;
use App\Models\AiSession;
use App\Models\AiSpecialistFlowExecution;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Models\Capture;
use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\Cli\AtlasFileAttachmentService;
use App\Services\Ai\ConversationOps\AiSessionManager;
use App\Services\Ai\ConversationOps\AiSessionStateService;
use App\Services\Ai\ConversationOps\AiThreadResolver;
use App\Services\Ai\Context\AiContextSnapshotRecorder;
use App\Services\Ai\Context\AiConversationRecorder;
use App\Services\Ai\Knowledge\YouTubeKnowledgeIngestionService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Mission\AiGatewayMissionBridge;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use App\Services\Ai\Policy\AiRuntimeBudgetService;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\Streaming\AiStreamRecorder;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Ai\ValueObjects\AiThreadResolution;
use App\Services\Ai\ValueObjects\AiPrompt;
use App\Services\AuditLogService;
use App\Services\CapturePrivacyService;
use App\Support\AiAttachmentPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use App\Services\Ai\FairClaudePolicy;

/**
 * Provider routing / eligibility + fair-mode provider gates extracted VERBATIM from
 * AiGatewayService (GOD-DEBULK D3 split). Trait composition preserves behaviour and
 * dependency access exactly.
 */
trait RoutesGatewayProvider
{
    private function providerFromOptions(array $options): string
    {
        if ($this->isFairModeOptions($options)) {
            return FairClaudePolicy::PROVIDER_LOCK;
        }

        $manualProvider = $this->decide->manualOverrideProvider($options);
        if ($manualProvider === 'claude_codex'
            || data_get($options, 'payload.execution_policy') === 'dual_review'
        ) {
            return $this->providerAllowedForInvocation('claude_codex', $options);
        }

        if (in_array($manualProvider, self::INVOCATION_PROVIDERS, true)) {
            return $this->providerAllowedForInvocation((string) $manualProvider, $options, explicitProvider: true);
        }

        $candidate = $this->decide->operationalDecision($options)->selectedProvider();

        // Auto mode must never land on a provider with no running worker (that is
        // exactly what stranded chats and surfaced as "Atlas não respondeu em
        // 120s"). Redirect any auto pick outside the live-worker set to the
        // default executive (Hermes). Explicit provider choices returned above are
        // untouched, so the operator can still force claude_cli/codex_cli/etc.
        if (! in_array($candidate, self::AUTO_LIVE_WORKER_PROVIDERS, true)) {
            $candidate = (string) config('atlas.ai.default_provider', 'hermes_cli');
        }

        return $this->providerAllowedForInvocation($candidate, $options, explicitProvider: false);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $decisionPayload
     * @return array<string,mixed>
     */
    private function providerGovernanceContract(array $payload, string $provider, array $decisionPayload, ?string $candidateProvider, ?string $fallbackReason): array
    {
        $decisionMode = (string) (data_get($payload, 'decision_mode') ?: data_get($decisionPayload, 'decision_mode') ?: 'atlas_decide');
        $manualOverride = $decisionMode !== 'atlas_decide';

        return [
            'schema_version' => 'atlas.provider_governance.v1',
            'surface' => data_get($payload, 'app_surface') ?: data_get($payload, 'surface') ?: 'unknown',
            'workflow_mode' => data_get($payload, 'atlas_workflow_mode') ?: data_get($payload, 'workflow_mode'),
            'decision_mode' => $decisionMode,
            'decision_authority' => $manualOverride ? 'operator_override' : 'atlas_decide',
            'model_selection_authority' => data_get($payload, 'model_selection_contract.authority') ?: 'atlas_decide',
            'operator_requested_provider' => data_get($payload, 'operator_requested_provider') ?: ($manualOverride ? data_get($payload, 'requested_provider') : 'auto'),
            'requested_provider' => data_get($payload, 'requested_provider'),
            'candidate_provider' => $candidateProvider,
            'execution_provider' => $provider,
            'selected_provider' => $provider,
            'fallback_provider' => $fallbackReason ? $provider : null,
            'fallback_reason' => $fallbackReason,
            'manual_override' => $manualOverride,
            'fair_mode' => $this->isFairModeOptions(['payload' => $payload]),
            'separation_contract' => [
                'atlas_decide_is_decision_layer' => ! $manualOverride,
                'provider_is_executor_only' => true,
                'provider_may_not_be_treated_as_atlas_identity' => true,
                'manual_override_must_remain_visible' => $manualOverride,
            ],
        ];
    }

    private function providerAllowedForInvocation(string $provider, array $options, bool $explicitProvider = false): string
    {
        $hasImageAttachments = $this->hasImageAttachments($options);

        if ($hasImageAttachments && $explicitProvider && ! $this->providerSupportsImageAttachments($provider)) {
            return $this->imageAttachmentFallbackProvider($options);
        }

        if ($hasImageAttachments && ! $explicitProvider && ! $this->providerSupportsImageAttachments($provider)) {
            return $this->imageAttachmentFallbackProvider($options);
        }

        if (! $explicitProvider && $provider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
            return $hasImageAttachments
                ? $this->imageAttachmentFallbackProvider($options)
                : $this->geminiFallbackProvider();
        }

        if (! $this->isAutomaticInvocation($options)) {
            if ((bool) ($this->runtimeSettings->providerConfig($provider)['allow_manual'] ?? true)) {
                return $provider;
            }

            throw new RuntimeException("Provider {$provider} esta bloqueado para uso manual nas Configuracoes do Atlas.");
        }

        if ($provider === 'claude_codex') {
            return $this->runtimeSettings->councilAllowAuto()
                ? $provider
                : $this->automaticFallbackProvider($options);
        }

        if ((bool) ($this->runtimeSettings->providerConfig($provider)['allow_auto'] ?? true)) {
            return $provider;
        }

        $fallback = $this->automaticFallbackProvider($options);

        return $hasImageAttachments && ! $this->providerSupportsImageAttachments($fallback)
            ? $this->imageAttachmentFallbackProvider($options)
            : $fallback;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function enforceFairModeProvider(array $payload, string $provider): array
    {
        if (! $this->fairClaude->isFairPayload($payload)) {
            return $payload;
        }

        if ($provider !== FairClaudePolicy::PROVIDER_LOCK) {
            $violation = $this->fairClaude->violation(
                message: 'Fair Claude mode requires provider claude_cli.',
                details: ['provider' => $provider],
            );

            throw new RuntimeException(FairClaudePolicy::ERROR_CODE.': '.$violation['message']);
        }

        return array_merge($payload, [
            'fair_mode' => $this->fairClaude->metadataFromPayload($payload),
            'decision_mode' => 'manual_override',
            'operator_requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'execution_policy' => null,
            'council_providers' => null,
            'council_disabled_by_fair_mode' => true,
            'atlas_decide' => array_merge(
                is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
                [
                    'disabled_by_fair_mode' => true,
                    'decision_mode' => 'manual_override',
                    'operator_requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
                    'requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
                    'candidate_provider' => FairClaudePolicy::PROVIDER_LOCK,
                    'selected_provider' => FairClaudePolicy::PROVIDER_LOCK,
                    'fallback_provider' => null,
                    'fallback_reason' => null,
                ],
            ),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function enforceFairModeModel(array $payload, string $provider, ?string $model): void
    {
        if (! $this->fairClaude->isFairPayload($payload)) {
            return;
        }

        $violation = $this->fairClaude->validateInvocation($provider, $model, $payload);
        if (! (bool) ($violation['ok'] ?? false)) {
            throw new RuntimeException(FairClaudePolicy::ERROR_CODE.': '.(string) ($violation['message'] ?? 'Fair Claude mode violation.'));
        }
    }

    private function automaticFallbackProvider(array $options = []): string
    {
        $default = $this->runtimeSettings->defaultProvider();
        if ($default !== 'claude_codex'
            && ! ($default === 'gemini_cli' && $this->geminiBlockedForInvocation($options))
            && (bool) ($this->runtimeSettings->providerConfig($default)['allow_auto'] ?? true)
        ) {
            return $default;
        }

        return 'hermes_cli';
    }

    private function geminiFallbackProvider(): string
    {
        return 'claude_cli';
    }

    private function hasImageAttachments(array $options): bool
    {
        $images = data_get($options, 'payload.attachments.images', []);
        if (is_array($images) && count($images) > 0) {
            return true;
        }

        $count = data_get($options, 'payload.visual_input.image_count', 0);

        return is_numeric($count) && (int) $count > 0;
    }

    private function providerSupportsImageAttachments(string $provider): bool
    {
        // Claude CLI only receives attachment paths/instructions in this runtime;
        // it does not get pixels as native visual input. Treating it as
        // image-capable made mobile uploads look attached in Atlas while Claude
        // could only see metadata. Keep image jobs on providers that pass actual
        // visual input to the model.
        return in_array($provider, ['codex_cli', 'gemini_cli', 'hermes_cli'], true);
    }

    private function imageAttachmentFallbackProvider(array $options): string
    {
        foreach (['codex_cli', 'gemini_cli'] as $provider) {
            if ($provider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
                continue;
            }

            if ((bool) ($this->runtimeSettings->providerConfig($provider)['allow_auto'] ?? true)) {
                return $provider;
            }
        }

        foreach (['codex_cli', 'gemini_cli'] as $provider) {
            if ($provider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
                continue;
            }

            if ((bool) ($this->runtimeSettings->providerConfig($provider)['allow_manual'] ?? true)) {
                return $provider;
            }
        }

        throw new RuntimeException('Nenhum provider com suporte a imagem esta habilitado nas Configuracoes do Atlas.');
    }

    private function isAutomaticInvocation(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        if ($this->fairClaude->isFairPayload($payload)) {
            return false;
        }
        $sourceType = $options['source_type'] ?? null;

        return data_get($payload, 'decision_mode') === 'atlas_decide'
            || (bool) data_get($payload, 'automatic', false)
            || in_array($sourceType, ['capture', 'scheduled', 'system'], true);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function isFairModeOptions(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return $this->fairClaude->isFairPayload($payload);
    }

    private function geminiBlockedForInvocation(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $workflowMode = strtolower(trim((string) data_get($payload, 'atlas_workflow_mode', '')));
        $taskType = strtolower(trim((string) data_get($payload, 'task_type', '')));
        $agent = strtolower(trim((string) ($options['agent_slug'] ?? data_get($payload, 'requested_agent', ''))));

        if (in_array($workflowMode, ['dev', 'debug', 'execute', 'quality_repair'], true)) {
            return true;
        }

        if (in_array($taskType, ['dev', 'debug', 'code', 'coding', 'programming', 'quality_repair'], true)) {
            return true;
        }

        return in_array($agent, ['desenvolvedor', 'developer', 'debugger'], true);
    }

    private function shouldRunCouncil(array $options): bool
    {
        if ($this->isFairModeOptions($options)) {
            return false;
        }

        $manualProvider = $this->decide->manualOverrideProvider($options);
        $requested = data_get($options, 'payload.execution_policy') === 'dual_review'
            || $manualProvider === 'claude_codex'
            || ($options['provider'] ?? null) === 'claude_codex';

        return $requested && $this->providerAllowedForInvocation('claude_codex', $options) === 'claude_codex';
    }
}
