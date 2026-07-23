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
 * Programming policy-contract projection + model-graph receipt extracted VERBATIM
 * from AiGatewayService (GOD-DEBULK D3 split). Pinned ap14 tool-tier tokens deliberately
 * stay on the facade (toolContractReceipt / executionTierWeight / normalizedExecutionTier /
 * normalizedToolPermissionMode / assertProgrammingToolContractsAllowRuntime).
 */
trait ProjectsGatewayProgrammingContracts
{
    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function programmingMetadata(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $messagePlan = is_array($payload['programming_message_plan'] ?? null)
            ? $payload['programming_message_plan']
            : [];
        $dispatch = is_array($payload['programming_dispatch'] ?? null)
            ? $payload['programming_dispatch']
            : [];

        return array_filter([
            'programming_profile' => data_get($payload, 'programming_profile'),
            'programming_session_plan' => data_get($payload, 'programming_session_plan'),
            'programming_message_plan' => data_get($payload, 'programming_message_plan'),
            'programming_dispatch' => data_get($payload, 'programming_dispatch'),
            'programming_repair' => data_get($payload, 'programming_repair'),
            'programming_profile_context' => data_get($payload, 'programming_profile_context')
                ?: data_get($messagePlan, 'policy_profile.profile_context')
                ?: data_get($dispatch, 'profile_context'),
            'programming_execution_policy' => data_get($payload, 'programming_execution_policy')
                ?: data_get($messagePlan, 'policy_profile.execution_policy')
                ?: data_get($dispatch, 'execution_policy'),
            'programming_policy_contracts' => $this->programmingPolicyContracts($options),
            'programming_policy_contract_receipt' => data_get($payload, 'programming_policy_contract_receipt'),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function programmingPolicyContracts(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $messagePlan = is_array($payload['programming_message_plan'] ?? null)
            ? $payload['programming_message_plan']
            : [];
        $dispatch = is_array($payload['programming_dispatch'] ?? null)
            ? $payload['programming_dispatch']
            : [];
        $contracts = data_get($payload, 'programming_policy_contracts')
            ?: data_get($messagePlan, 'policy_contracts')
            ?: data_get($messagePlan, 'policy_profile.policy_contracts')
            ?: data_get($messagePlan, 'policy_profile.effective_policy.operational_contracts')
            ?: data_get($dispatch, 'policy_contracts');

        return is_array($contracts) ? $contracts : [];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function programmingPolicyContractReceipt(array $options, AiPrompt $prompt): array
    {
        $contracts = $this->programmingPolicyContracts($options);
        if ($contracts === []) {
            return [];
        }

        $context = (array) data_get($contracts, 'context', []);
        $memory = (array) data_get($contracts, 'memory', []);
        $skills = (array) data_get($contracts, 'skills', []);
        $tools = (array) data_get($contracts, 'tools', []);
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $contextPackPresent = $prompt->contextPack !== [];
        $openBrainPresent = $prompt->openBrainInjection !== [];
        $activatedSkills = collect($prompt->activatedSkills)
            ->map(fn (mixed $skill): ?string => is_array($skill) && is_string($skill['name'] ?? null) ? strtolower(trim((string) $skill['name'])) : null)
            ->filter()
            ->values()
            ->all();
        $requiredSkills = collect((array) data_get($skills, 'required_bundles', []))
            ->map(fn (mixed $skill): string => strtolower(trim((string) $skill)))
            ->filter()
            ->values()
            ->all();
        $missingSkills = array_values(array_diff($requiredSkills, $activatedSkills));
        $requiresSkillTrace = (bool) data_get($skills, 'require_skill_trace', false);
        $requiresContextPack = (bool) data_get($context, 'require_context_pack', false);
        $includeMemory = (bool) data_get($context, 'include_memory', data_get($memory, 'scope') !== null);
        $openBrainStatus = is_scalar(data_get($prompt->openBrainInjection, 'status'))
            ? (string) data_get($prompt->openBrainInjection, 'status')
            : null;
        $memoryStatus = match (true) {
            ! $includeMemory => 'not_required',
            ! $openBrainPresent => 'missing',
            in_array($openBrainStatus, ['injected'], true) => 'satisfied',
            in_array($openBrainStatus, ['degraded'], true) => 'degraded',
            in_array($openBrainStatus, ['failed_closed', 'failed_open'], true) => 'failed',
            in_array($openBrainStatus, ['skipped'], true) => 'missing',
            default => 'pending',
        };

        return [
            'schema_version' => 1,
            'source' => 'ai_gateway_prompt_contract_projection',
            'context' => [
                'required' => $requiresContextPack,
                'status' => ! $requiresContextPack || $contextPackPresent ? 'satisfied' : 'missing',
                'context_pack_present' => $contextPackPresent,
                'context_pack_id' => data_get($prompt->contextPack, 'context_pack_id') ?: data_get($prompt->contextPack, 'id'),
                'context_pack_hash' => data_get($prompt->contextPack, 'hash'),
                'depth' => data_get($context, 'depth'),
            ],
            'memory' => [
                'required' => $includeMemory,
                'status' => $memoryStatus,
                'open_brain_present' => $openBrainPresent,
                'open_brain_status' => $openBrainStatus,
                'open_brain_reason' => data_get($prompt->openBrainInjection, 'reason'),
                'scope' => data_get($memory, 'scope'),
                'privacy_gate' => data_get($memory, 'privacy_gate'),
                'recall' => data_get($memory, 'recall', []),
            ],
            'skills' => [
                'required' => $requiresSkillTrace,
                'status' => ! $requiresSkillTrace || $missingSkills === [] ? 'satisfied' : 'partial',
                'required_bundles' => $requiredSkills,
                'activated' => $activatedSkills,
                'missing' => $missingSkills,
                'mode' => data_get($skills, 'mode'),
            ],
            'tools' => $this->toolContractReceipt($tools, $payload),
        ];
    }

    /**
     * @param  array<string,mixed>  $graph
     * @return array<string,mixed>
     */
    private function modelGraphContractReceipt(array $graph, string $provider, ?string $model, array $runtimeProviders = []): array
    {
        $nodes = collect((array) data_get($graph, 'nodes', []))
            ->filter(fn (mixed $node): bool => is_array($node) && is_string($node['provider'] ?? null))
            ->values();
        $matched = $nodes->first(function (mixed $node) use ($provider, $model): bool {
            if (! is_array($node) || ($node['provider'] ?? null) !== $provider) {
                return false;
            }

            $nodeModel = is_string($node['model'] ?? null) ? trim((string) $node['model']) : '';

            return $nodeModel === '' || $model === null || $nodeModel === $model;
        });
        $providerNode = $nodes->first(fn (mixed $node): bool => is_array($node) && ($node['provider'] ?? null) === $provider);
        $fallbackProviders = $nodes
            ->flatMap(fn (mixed $node): array => is_array($node) ? (array) ($node['fallback_order'] ?? []) : [])
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->unique()
            ->values()
            ->all();
        $providerAllowedAsFallback = in_array($provider, $fallbackProviders, true);
        $runtimeProviders = collect($runtimeProviders)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $allowedProviders = $nodes->pluck('provider')->filter()->unique()->values()->all();
        $runtimeProvidersAllowed = $runtimeProviders !== []
            && count(array_diff($runtimeProviders, array_values(array_unique([...$allowedProviders, ...$fallbackProviders])))) === 0;
        $status = match (true) {
            is_array($matched) => 'satisfied',
            $provider === 'claude_codex' && $runtimeProvidersAllowed => 'satisfied',
            is_array($providerNode) => 'provider_matched_model_drift',
            $providerAllowedAsFallback => 'fallback_provider',
            default => 'out_of_contract',
        };

        return [
            'schema_version' => 1,
            'source' => 'ai_gateway_model_graph_projection',
            'status' => $status,
            'graph' => data_get($graph, 'graph'),
            'preset' => data_get($graph, 'preset'),
            'strict_model_match' => (bool) data_get($graph, 'strict_model_match', false),
            'selected_provider' => $provider,
            'selected_model' => $model,
            'runtime_providers' => $runtimeProviders,
            'matched_node_id' => is_array($matched) ? ($matched['id'] ?? null) : ($runtimeProvidersAllowed ? 'council_runtime' : null),
            'matched_node_role' => is_array($matched) ? ($matched['role'] ?? null) : ($runtimeProvidersAllowed ? 'council' : null),
            'allowed_providers' => $allowedProviders,
            'fallback_providers' => $fallbackProviders,
        ];
    }

    private function optionsWithProgrammingModelGraphReceipt(array $options, string $provider, ?string $model, array $runtimeProviders = []): array
    {
        $contracts = $this->programmingPolicyContracts($options);
        $graph = (array) data_get($contracts, 'model_graph', []);
        if ($graph === []) {
            return $options;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $receipt = (array) ($payload['programming_policy_contract_receipt'] ?? []);
        $receipt['model_graph'] = $this->modelGraphContractReceipt($graph, $provider, $model, $runtimeProviders);
        $payload['programming_policy_contract_receipt'] = $receipt;
        $options['payload'] = $payload;

        return $options;
    }

    private function assertProgrammingModelGraphAllowsRuntime(array $options): void
    {
        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.model_graph');
        if (! is_array($receipt)) {
            return;
        }

        $provider = (string) ($receipt['selected_provider'] ?? 'unknown');
        $model = (string) ($receipt['selected_model'] ?? 'unknown');
        $allowed = implode(', ', array_map(fn (mixed $item): string => (string) $item, (array) ($receipt['allowed_providers'] ?? [])));

        if (($receipt['status'] ?? null) === 'out_of_contract') {
            throw new RuntimeException("atlas_model_graph_policy_violation: provider/model {$provider}/{$model} fora do model_graph permitido".($allowed !== '' ? " ({$allowed})" : '').'.');
        }

        if (($receipt['status'] ?? null) === 'provider_matched_model_drift' && (bool) ($receipt['strict_model_match'] ?? false)) {
            throw new RuntimeException("atlas_model_graph_policy_violation: modelo {$model} diverge do model_graph fixo para {$provider}.");
        }
    }

    private function assertProgrammingSkillContractsAllowRuntime(array $options): void
    {
        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.skills');
        if (! is_array($receipt) || ! (bool) ($receipt['required'] ?? false) || ($receipt['status'] ?? null) === 'satisfied') {
            return;
        }

        $missing = implode(', ', array_map(fn (mixed $item): string => (string) $item, (array) ($receipt['missing'] ?? [])));

        throw new RuntimeException('atlas_skill_contract_policy_violation: skill trace obrigatorio nao satisfeito'.($missing !== '' ? " ({$missing})" : '').'.');
    }

    private function assertProgrammingContextContractsAllowRuntime(array $options): void
    {
        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.context');
        if (! is_array($receipt) || ! (bool) ($receipt['required'] ?? false) || ($receipt['status'] ?? null) === 'satisfied') {
            return;
        }

        throw new RuntimeException('atlas_context_contract_policy_violation: context pack obrigatorio nao foi projetado no prompt.');
    }

    private function assertProgrammingMemoryContractsAllowRuntime(array $options): void
    {
        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.memory');
        if (! is_array($receipt) || ! (bool) ($receipt['required'] ?? false)) {
            return;
        }

        $status = (string) ($receipt['status'] ?? 'unknown');
        if (in_array($status, ['satisfied', 'degraded'], true)) {
            return;
        }

        throw new RuntimeException("atlas_memory_contract_policy_violation: Open Brain/memoria obrigatoria nao foi entregue ({$status}).");
    }
}
