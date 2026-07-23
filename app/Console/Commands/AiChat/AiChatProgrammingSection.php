<?php

namespace App\Console\Commands\AiChat;

use App\Console\Commands\AiChatCommand;
use App\Models\AiTrace;
use App\Services\Ai\Kernel\Pipeline\KernelPipelinePlanViolation;
use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use App\Services\Ai\Programming\ProgrammingExecutionRequest;
use App\Services\Ai\Programming\ProgrammingIterationPolicy;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;

/**
 * Programming executor dispatch, dev-plan and intent helpers split verbatim from AiChatCommand (GOD-DEBULK).
 */
class AiChatProgrammingSection
{
    public function __construct(private readonly AiChatCommand $command)
    {
    }

    public function shouldDispatchProgrammingExecutor(?array $programmingMessagePlan): bool
    {
        return data_get($programmingMessagePlan, 'executor_decision.executor') === 'engineering_harness';
    }

    /**
     * @param  array<string,mixed>|null  $programmingMessagePlan
     * @return array<string,mixed>|null
     */
    public function programmingDispatchContract(?array $programmingMessagePlan): ?array
    {
        return app(AtlasProgrammingOrchestrator::class)->dispatchContract($programmingMessagePlan);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $programmingMessagePlan
     */
    public function dispatchProgrammingExecutor(
        string $input,
        string $workspace,
        ?string $provider,
        ?string $model,
        string $mode,
        string $permissionMode,
        ?string $threadId,
        ?string $agentSlug,
        array $payload,
        array $programmingMessagePlan,
        float $startedAt,
    ): AiTrace {
        $result = app(AtlasProgrammingOrchestrator::class)
            ->executeWithHarness(ProgrammingExecutionRequest::fromArray($this->programmingExecutionRequestData(
                input: $input,
                workspace: $workspace,
                provider: $provider,
                model: $model,
                permissionMode: $permissionMode,
                payload: $payload,
                programmingMessagePlan: $programmingMessagePlan,
            )))
            ->toArray();

        $status = in_array($result['status'] ?? null, ['passed', 'partial'], true) ? 'succeeded' : 'failed';
        $response = $this->programmingExecutorResponseText($result);
        $dispatch = array_merge(
            (array) ($payload['programming_dispatch'] ?? []),
            [
                'status' => $status === 'succeeded' ? 'executed' : 'blocked',
                'trace_provider' => 'engineering_harness',
                'completed_at' => now()->toJSON(),
            ],
        );
        $metadata = AtlasSecurity::redactArray([
            'model_label' => $model,
            'programming_profile' => data_get($programmingMessagePlan, 'programming_profile'),
            'programming_session_plan' => $payload['programming_session_plan'] ?? null,
            'programming_dispatch' => $dispatch,
            'programming_message_plan' => $programmingMessagePlan,
            'programming_repair' => $payload['programming_repair'] ?? null,
            'programming_completion' => app(AtlasProgrammingOrchestrator::class)->harnessCompletionContract($result, $dispatch, $model),
            'programming_result' => $result,
            'dispatch' => [
                'executor' => 'engineering_harness',
                'source' => 'AtlasProgrammingOrchestrator',
                'mode' => $mode,
            ],
        ]);

        $attributes = [
            'trace_key' => 'trace_'.Str::orderedUuid()->toString(),
            'thread_id' => $threadId,
            'status' => $status,
            'operator_input' => $input,
            'agent_slug' => $agentSlug,
            'provider' => 'engineering_harness',
            'model' => $model,
            'response_hash' => hash('sha256', $response),
            'response_text' => $response,
            'latency_ms' => $this->command->elapsedMs($startedAt),
            'completed_at' => now(),
            'metadata' => $metadata,
        ];

        if (DatabaseTableAvailability::has('ai_traces')) {
            return AiTrace::query()->create($attributes);
        }

        return tap(new AiTrace, function (AiTrace $trace) use ($attributes): void {
            $trace->forceFill(['id' => (string) Str::orderedUuid()] + $attributes);
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $programmingMessagePlan
     * @return array<string,mixed>
     */
    public function programmingExecutionRequestData(
        string $input,
        string $workspace,
        ?string $provider,
        ?string $model,
        string $permissionMode,
        array $payload,
        array $programmingMessagePlan,
    ): array {
        $profile = data_get($programmingMessagePlan, 'programming_profile') === 'forge' ? 'forge' : 'dev';
        $overrides = (array) data_get($payload, 'dev_execution_plan.operator_options.harness_overrides', []);
        $executionProfile = (array) data_get($programmingMessagePlan, 'execution_profile', []);
        $complete = (bool) ($executionProfile['complete'] ?? ($profile === 'forge'));

        return [
            'profile' => $profile,
            'workspace' => $workspace,
            'task' => $input,
            'objective' => $input,
            'provider' => $provider,
            'model' => $model,
            'permission' => $permissionMode,
            'complete' => $complete,
            'auto_test' => (bool) ($executionProfile['auto_test'] ?? ($profile === 'forge')),
            'critical' => $profile === 'forge',
            'max_attempts' => ProgrammingIterationPolicy::forExecutionPolicy(
                $executionProfile['max_iterations'] ?? null,
                $complete,
                $profile === 'forge',
            ),
            'no_provider' => (bool) $this->command->option('no-run'),
            'test_command' => is_string($overrides['test_command'] ?? null) ? $overrides['test_command'] : null,
            'sandbox' => is_string($overrides['sandbox'] ?? null) ? $overrides['sandbox'] : null,
            'provider_runtime' => is_string($overrides['provider_runtime'] ?? null) ? $overrides['provider_runtime'] : null,
            'visual_e2e' => is_string($overrides['visual_e2e'] ?? null) ? $overrides['visual_e2e'] : null,
            'quality_scan' => is_string($overrides['quality_scan'] ?? null) ? $overrides['quality_scan'] : null,
            'harness_policy' => is_string($overrides['harness_policy'] ?? null) ? $overrides['harness_policy'] : null,
            'apply_isolated_patch' => (bool) ($overrides['apply_isolated_patch'] ?? true),
            'policy_contracts' => data_get($programmingMessagePlan, 'policy_contracts')
                ?: data_get($programmingMessagePlan, 'policy_profile.policy_contracts')
                ?: data_get($programmingMessagePlan, 'policy_profile.effective_policy.operational_contracts')
                ?: [],
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function programmingExecutorResponseText(array $result): string
    {
        $score = data_get($result, 'harness_payload.run.score');
        $evidence = implode(', ', array_map('strval', (array) ($result['evidence_refs'] ?? []))) ?: '-';
        $blocking = implode('; ', array_map('strval', (array) ($result['blocking_failures'] ?? []))) ?: '-';

        return implode("\n", [
            '# Atlas Programming Executor',
            '',
            '- Status: '.(string) ($result['status'] ?? 'unknown'),
            '- Executor: '.(string) ($result['executor'] ?? 'engineering_harness'),
            '- Task: '.(string) ($result['task_id'] ?? '-'),
            '- Engineering run: '.(string) data_get($result, 'harness_payload.run.id', '-'),
            '- Decision: '.(string) data_get($result, 'harness_payload.run.decision', 'unknown'),
            '- Score: '.($score === null ? '-' : (string) $score),
            '- Evidence: '.$evidence,
            '- Blocking failures: '.$blocking,
        ]);
    }

    public function workflowMode(string $mode): string
    {
        $mode = Str::of($mode)->lower()->trim()->value();

        return in_array($mode, ['direct', 'plan', 'review', 'dev', 'debug', 'research'], true) ? $mode : 'direct';
    }

    public function modeShortcut(string $line): ?string
    {
        if ($line === '/plan' || $line === '/review' || $line === '/debug' || $line === '/research' || $line === '/direct') {
            return ltrim($line, '/');
        }

        return null;
    }

    public function fixPromptInput(string $description): string
    {
        $description = trim($description);

        return $description !== ''
            ? "Corrija: {$description}"
            : 'Corrija o ultimo teste falho, bug ou quality gate detectado neste workspace. Primeiro inspecione o estado atual, depois aplique a menor correcao segura.';
    }

    /**
     * @param  array<string,mixed>  $devPlan
     * @return array<string,mixed>
     */
    public function programmingIntent(string $input, string $profile, array $devPlan): array
    {
        $explicitIntent = (string) (
            data_get($devPlan, 'operator_options.programming_intent')
            ?: data_get($devPlan, 'operator_options.intent')
            ?: data_get($devPlan, 'programming_intent')
            ?: ''
        );
        $text = strtolower(Str::ascii($input));
        $matchedRepair = $this->matchedIntentSignals($text, [
            'corrija', 'corrigir', 'conserte', 'consertar', 'arrume', 'arrumar',
            'fix', 'repair', 'bug', 'erro', 'error', 'falha', 'falhando',
            'teste falhando', 'test failing', 'quality gate', 'quebrado',
        ]);
        $matchedHarness = $this->matchedIntentSignals($text, [
            'forge', 'harness', 'fluxo inteiro', 'todo o fluxo', 'ponta a ponta',
            'end to end', 'e2e', 'banco', 'database', 'migration', 'migracao',
            'fila', 'queue', 'worker', 'ui', 'frontend', 'api', 'testes',
            'arquitetura', 'refatoracao grande', 'refatorar grande', 'complexo',
            'dificil', 'critico', 'producao', 'seguranca', 'permissao',
        ]);
        $layerCount = $this->programmingIntentLayerCount($text);
        $explicitRepair = in_array($explicitIntent, ['repair', 'fix', 'quality_repair'], true);
        $explicitHarness = in_array('forge', $matchedHarness, true) || in_array('harness', $matchedHarness, true);
        $forceHarness = $profile === 'forge'
            || $explicitHarness
            || $layerCount >= 3
            || count($matchedHarness) >= 4;

        return [
            'schema_version' => 1,
            'source' => 'atlas_dev_auto_intent',
            'kind' => match (true) {
                $forceHarness => 'harness',
                $explicitRepair || $matchedRepair !== [] => 'repair',
                default => 'implementation',
            },
            'force_harness' => $forceHarness,
            'repair_detected' => $explicitRepair || $matchedRepair !== [],
            'explicit_intent' => $explicitIntent !== '' ? $explicitIntent : null,
            'harness_detected' => $matchedHarness !== [] || $layerCount >= 3,
            'matched_repair_signals' => $matchedRepair,
            'matched_harness_signals' => $matchedHarness,
            'layer_count' => $layerCount,
            'profile' => $profile,
            'parent_plan_id' => data_get($devPlan, 'plan_id'),
        ];
    }

    /**
     * @param  array<int,string>  $signals
     * @return array<int,string>
     */
    private function matchedIntentSignals(string $text, array $signals): array
    {
        return collect($signals)
            ->filter(fn (string $signal): bool => str_contains($text, $signal))
            ->values()
            ->all();
    }

    private function programmingIntentLayerCount(string $text): int
    {
        return collect([
            ['banco', 'database', 'migration', 'migracao', 'schema'],
            ['api', 'endpoint', 'controller', 'service', 'job', 'worker', 'fila', 'queue'],
            ['ui', 'frontend', 'tela', 'componente', 'formulario'],
            ['teste', 'testes', 'test', 'e2e', 'lint', 'quality'],
            ['permissao', 'permission', 'auth', 'seguranca', 'security'],
        ])->filter(fn (array $signals): bool => collect($signals)->contains(
            fn (string $signal): bool => str_contains($text, $signal)
        ))->count();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function devExecutionPlanOption(): ?array
    {
        $raw = $this->command->option('dev-plan');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $aiPolicyOverride
     * @return array<string,mixed>|null
     */
    public function activeDevExecutionPlan(
        string $workspace,
        string $mode,
        string $input,
        ?string $provider,
        ?string $model,
        array $aiPolicyOverride = [],
    ): ?array {
        $declared = $this->devExecutionPlanOption();
        if ($declared !== null) {
            return $this->command->withKernelPipelinePlan($declared, $workspace, $input, 'declared_dev_plan');
        }

        if ($mode !== 'dev') {
            return null;
        }

        $plan = app(AtlasProgrammingOrchestrator::class)->sessionPlan($workspace, 'dev', [
            'task' => $input,
            'provider' => $provider,
            'model' => $model,
            'interactive' => true,
            'complete' => true,
            'auto_test' => (bool) $this->command->option('auto-test'),
            'max_iterations' => 3,
            'ai_policy_override' => $aiPolicyOverride,
        ]);
        data_set($plan, 'operator_options.input_mode', 'chat_dev_auto_plan');
        data_set($plan, 'operator_options.generated_by', 'AiChatCommand');

        return $this->command->withKernelPipelinePlan($plan, $workspace, $input, 'chat_dev_auto_plan');
    }

    public function kernelPipelineViolation(KernelPipelinePlanViolation $violation): int
    {
        $payload = AtlasSecurity::redactArray([
            'ok' => false,
            'phase' => 'preflight',
            'error' => 'atlas_kernel_pipeline_contract_violation',
            'message' => $violation->getMessage(),
            'violations' => $violation->errors,
        ]);

        if ((bool) $this->command->option('json')) {
            $this->command->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return AiChatCommand::FAILURE;
        }

        $this->command->error((string) $payload['message']);
        foreach ($payload['violations'] as $message) {
            $this->command->line('- '.(string) $message);
        }

        return AiChatCommand::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    public function openBrainPayload(string $mode, ?array $devPlan = null): array
    {
        $budget = $this->command->option('open-brain-budget');
        $budgetChars = is_scalar($budget) && trim((string) $budget) !== ''
            ? max(2000, (int) $budget)
            : null;
        $surface = $devPlan === null
            ? 'cli_chat'
            : (data_get($devPlan, 'resumed_at') ? 'cli_continue' : 'cli_dev');

        return array_filter([
            'mode' => (bool) $this->command->option('no-open-brain')
                ? 'off'
                : ((bool) $this->command->option('require-open-brain') ? 'required' : 'auto'),
            'surface' => $surface,
            'workflow_mode' => $mode,
            'budget_chars' => $budgetChars,
            'refresh' => (bool) $this->command->option('open-brain-refresh'),
            'provider_safe_only' => true,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function agentSlug(string $mode): ?string
    {
        if ($this->command->option('agent')) {
            return (string) $this->command->option('agent');
        }

        return match ($mode) {
            'dev', 'debug' => 'desenvolvedor',
            'research' => 'researcher-quick',
            default => null,
        };
    }
}
