<?php

declare(strict_types=1);

namespace App\Services\Ai\Router;

use Illuminate\Support\Str;

final class AtlasAiRouterService
{
    private AtlasAiIntentKernelService $intentKernel;

    public function __construct(?AtlasAiIntentKernelService $intentKernel = null)
    {
        $this->intentKernel = $intentKernel ?? new AtlasAiIntentKernelService;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function decide(array $data): AtlasAiRouterDecision
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $surfaceId = $this->string(data_get($payload, 'surface_id'))
            ?? $this->string(data_get($payload, 'app_surface'))
            ?? (string) ($data['source_type'] ?? 'app');
        $rawIntent = $this->string($data['input_text'] ?? null)
            ?? $this->string(data_get($payload, 'raw_intent'))
            ?? '';
        $workspace = $this->workspace($payload);
        $slash = $this->slashCommand($rawIntent, $payload);
        $task = $this->normalizedTask($payload);
        $mode = $this->normalizedMode($payload);
        $attachments = is_array(data_get($payload, 'attachments')) ? (array) data_get($payload, 'attachments') : [];
        $intent = $this->intentKernel->classify($data);

        if ($slash !== null) {
            return $this->decision(
                flowId: $slash['flow_id'],
                origin: 'slash_command',
                command: $slash['command_intent'],
                reason: 'slash_command:'.$slash['slash'],
                confidence: 'confirmed',
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: $rawIntent,
                alternatives: [],
            );
        }

        if ($surfaceId === 'atlas_code') {
            return $this->decision(
                flowId: AtlasAiRouterDecision::FLOW_FORGE,
                origin: 'operator_override',
                command: 'forge',
                reason: 'atlas_code_surface_requires_forge',
                confidence: 'confirmed',
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: $rawIntent,
                alternatives: [AtlasAiRouterDecision::FLOW_DEV],
            );
        }

        if ($mode === 'programming' && $task === 'plan') {
            return $this->decision(
                flowId: AtlasAiRouterDecision::FLOW_PLAN,
                origin: 'operator_override',
                command: 'plan',
                reason: $workspace !== null ? 'programming_plan_with_workspace' : 'programming_plan_without_workspace',
                confidence: 'confirmed',
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: $rawIntent,
                alternatives: $workspace !== null ? [AtlasAiRouterDecision::FLOW_DEV] : [AtlasAiRouterDecision::FLOW_RESEARCH],
                intent: $intent,
            );
        }

        if ($mode === 'programming' && in_array($task, ['dev', 'direct', 'debug', 'review'], true) && $workspace !== null) {
            $flow = match ($task) {
                'debug' => AtlasAiRouterDecision::FLOW_DEBUG,
                'review' => AtlasAiRouterDecision::FLOW_REVIEW,
                default => AtlasAiRouterDecision::FLOW_DEV,
            };

            return $this->decision(
                flowId: $flow,
                origin: 'operator_override',
                command: $task === 'plan' ? 'plan' : $task,
                reason: 'programming_mode_with_workspace',
                confidence: 'confirmed',
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: $rawIntent,
                alternatives: $flow === AtlasAiRouterDecision::FLOW_DEV ? [] : [AtlasAiRouterDecision::FLOW_DEV],
                intent: $intent,
            );
        }

        $haystack = Str::lower($rawIntent."\n".$this->attachmentText($attachments));

        if ($this->hasDiffOrPr($attachments, $haystack)) {
            return $this->decision(AtlasAiRouterDecision::FLOW_REVIEW, 'router_auto', 'review', 'diff_or_pr_attachment', 'strong', $surfaceId, $workspace, $rawIntent, [AtlasAiRouterDecision::FLOW_DEV], $intent);
        }

        if ($this->containsAny($haystack, ['stack trace', 'traceback', 'logs', 'log ', 'erro em producao', 'erro em produção', 'exception', 'observability'])) {
            return $this->decision(AtlasAiRouterDecision::FLOW_DEBUG, 'router_auto', 'debug', 'logs_or_stacktrace_signal', 'strong', $surfaceId, $workspace, $rawIntent, [AtlasAiRouterDecision::FLOW_RESEARCH], $intent);
        }

        if ($this->containsAny($haystack, ['obra ', 'multi-semana', 'multi semana', 'sistema inteiro', 'sistema todo', 'app inteiro', 'one shot enterprise', 'one-shot enterprise'])) {
            return $this->decision(AtlasAiRouterDecision::FLOW_FORGE, 'router_auto', 'promote_to_forge', 'obra_or_enterprise_scope_signal', 'strong', $surfaceId, $workspace, $rawIntent, [AtlasAiRouterDecision::FLOW_DEV], $intent);
        }

        if ($this->containsAny($haystack, ['planeje', 'planejar', 'plano', 'plan ', 'planning', 'roadmap', 'estruture', 'arquitetura antes', 'antes de implementar'])) {
            return $this->decision(
                AtlasAiRouterDecision::FLOW_PLAN,
                'router_auto',
                'plan',
                'plan_like_intent',
                'strong',
                $surfaceId,
                $workspace,
                $rawIntent,
                $workspace !== null ? [AtlasAiRouterDecision::FLOW_DEV] : [AtlasAiRouterDecision::FLOW_RESEARCH],
                $intent,
            );
        }

        if ($this->isPatchLike($haystack)) {
            if ($workspace !== null) {
                return $this->decision(AtlasAiRouterDecision::FLOW_DEV, 'router_auto', 'patch', 'patch_like_with_workspace', 'strong', $surfaceId, $workspace, $rawIntent, [], $intent);
            }

            return $this->decision(AtlasAiRouterDecision::FLOW_PLAN, 'router_auto', 'plan', 'patch_like_without_workspace_requires_plan', 'medium', $surfaceId, null, $rawIntent, [AtlasAiRouterDecision::FLOW_RESEARCH], $intent);
        }

        if ($this->containsAny($haystack, ['explique', 'explica', 'explain', 'resuma', 'summarize'])) {
            return $this->decision(
                AtlasAiRouterDecision::FLOW_EXPLAIN,
                'router_auto',
                'explain',
                $workspace !== null ? 'explain_like_workspace_bound' : 'explain_like_general',
                'strong',
                $surfaceId,
                $workspace,
                $rawIntent,
                $workspace !== null ? [AtlasAiRouterDecision::FLOW_REVIEW] : [AtlasAiRouterDecision::FLOW_CONVERSATION],
                $intent,
            );
        }

        if ($this->containsAny($haystack, ['pesquisa', 'pesquisar', 'research', 'fontes', 'referencias', 'referências', 'estado da arte', 'como funciona', 'how does', 'difference between', 'qual a diferença'])) {
            return $this->decision(AtlasAiRouterDecision::FLOW_RESEARCH, 'router_auto', 'research', 'research_like_intent', 'strong', $surfaceId, $workspace, $rawIntent, [AtlasAiRouterDecision::FLOW_CONVERSATION], $intent);
        }

        return $this->decision(AtlasAiRouterDecision::FLOW_CONVERSATION, 'router_auto', 'converse', 'fallback_conversation', 'low', $surfaceId, $workspace, $rawIntent, [], $intent);
    }

    private function decision(string $flowId, string $origin, string $command, string $reason, string $confidence, string $surfaceId, ?string $workspace, string $rawIntent, array $alternatives, array $intent = []): AtlasAiRouterDecision
    {
        return new AtlasAiRouterDecision(
            flowId: $flowId,
            flowOrigin: $origin,
            commandIntent: $command,
            routingReason: $reason,
            routingConfidence: $confidence,
            handoffPayload: [
                'surface_id' => $surfaceId,
                'workspace_present' => $workspace !== null,
                'workspace' => $workspace,
                'intent_summary' => Str::limit($rawIntent, 240, ''),
                'intent_kernel' => $intent,
            ],
            alternativeFlowIds: $alternatives,
        );
    }

    private function normalizedMode(array $payload): string
    {
        $mode = $this->string(data_get($payload, 'atlas_mode')) ?? $this->string(data_get($payload, 'current_mode')) ?? $this->string(data_get($payload, 'composer_mode'));

        return match ($mode) {
            'programming', 'dev', 'programacao', 'programação' => 'programming',
            default => 'general',
        };
    }

    private function normalizedTask(array $payload): string
    {
        $task = $this->string(data_get($payload, 'routing_task')) ?? $this->string(data_get($payload, 'composer_task')) ?? 'direct';

        return match ($task) {
            'dev', 'plan', 'debug', 'review' => $task,
            'repair' => 'debug',
            default => 'direct',
        };
    }

    private function workspace(array $payload): ?string
    {
        return $this->string(data_get($payload, 'workspace'))
            ?? $this->string(data_get($payload, 'tool_permissions.workspace'))
            ?? $this->string(data_get($payload, 'forge_workspace.workspace_path'));
    }

    private function slashCommand(string $rawIntent, array $payload): ?array
    {
        $slash = $this->string(data_get($payload, 'slash_command'));
        if ($slash === null && preg_match('/^\\s*\\/(dev|research|explain|debug|review|plan|forge|chat)\\b/i', $rawIntent, $matches)) {
            $slash = '/'.strtolower($matches[1]);
        }
        if ($slash === null) {
            return null;
        }

        return match (strtolower($slash)) {
            '/dev' => ['slash' => '/dev', 'flow_id' => AtlasAiRouterDecision::FLOW_DEV, 'command_intent' => 'dev'],
            '/research' => ['slash' => '/research', 'flow_id' => AtlasAiRouterDecision::FLOW_RESEARCH, 'command_intent' => 'research'],
            '/explain' => ['slash' => '/explain', 'flow_id' => AtlasAiRouterDecision::FLOW_EXPLAIN, 'command_intent' => 'explain'],
            '/debug' => ['slash' => '/debug', 'flow_id' => AtlasAiRouterDecision::FLOW_DEBUG, 'command_intent' => 'debug'],
            '/review' => ['slash' => '/review', 'flow_id' => AtlasAiRouterDecision::FLOW_REVIEW, 'command_intent' => 'review'],
            '/plan' => ['slash' => '/plan', 'flow_id' => AtlasAiRouterDecision::FLOW_PLAN, 'command_intent' => 'plan'],
            '/forge' => ['slash' => '/forge', 'flow_id' => AtlasAiRouterDecision::FLOW_FORGE, 'command_intent' => 'forge'],
            '/chat' => ['slash' => '/chat', 'flow_id' => AtlasAiRouterDecision::FLOW_CONVERSATION, 'command_intent' => 'converse'],
            default => null,
        };
    }

    private function isPatchLike(string $haystack): bool
    {
        return $this->containsAny($haystack, ['implemente', 'implementa', 'corrija', 'corrigir', 'refatore', 'refactor', 'mude ', 'altere ', 'crie ', 'adicione ', 'fix ', 'implement ', 'patch']);
    }

    private function hasDiffOrPr(array $attachments, string $haystack): bool
    {
        return $this->containsAny($haystack, ['diff', 'pull request', ' pr ', 'review do diff'])
            || collect($attachments)->contains(fn (mixed $attachment): bool => is_array($attachment)
                && $this->containsAny(Str::lower((string) ($attachment['kind'] ?? '').' '.(string) ($attachment['name'] ?? '')), ['diff', 'patch', 'pull request']));
    }

    private function attachmentText(array $attachments): string
    {
        return collect($attachments)
            ->filter(fn (mixed $attachment): bool => is_array($attachment))
            ->map(fn (array $attachment): string => (string) ($attachment['kind'] ?? '').' '.(string) ($attachment['name'] ?? ''))
            ->implode("\n");
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
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
