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
 * Council (dual-review) enqueue path extracted VERBATIM from AiGatewayService
 * (GOD-DEBULK D3 split). Composed back into the facade as a trait, so every $this->
 * reference and the injected constructor dependencies resolve exactly as before.
 */
trait RunsGatewayCouncil
{
    private function enqueueCouncilInteraction(string $input, array $options, AiPrompt $prompt, array $privacy, AiThreadResolution $threadResolution, $session, $autoCompaction = null, $providerHandoff = null): AiTrace
    {
        $providers = $this->councilProviders($options);
        $now = now();

        return DB::transaction(function () use ($input, $options, $prompt, $providers, $now, $privacy, $threadResolution, $session, $autoCompaction, $providerHandoff): AiTrace {
            $lockedThread = $this->lockThreadForTrace($threadResolution);
            $lockedSession = $this->lockSessionForTrace($session);
            $traceModelResolution = $this->models->resolveWithSource('claude_codex', $options['model'] ?? null, $this->modelResolutionContext($options, $prompt, $input));
            $options = $this->optionsWithProgrammingModelGraphReceipt($options, 'claude_codex', $traceModelResolution['model'], $providers);
            $this->assertProgrammingModelGraphAllowsRuntime($options);
            $decisionReceipt = $this->decide->receiptForTrace($this->optionsWithPromptContracts($options, $prompt), 'claude_codex', $traceModelResolution['model']);

            foreach ($providers as $provider) {
                $providerModelResolution = $this->models->resolveWithSource($provider, $options['model'] ?? null, $this->modelResolutionContext($options, $prompt, $input));
                $this->budgets->assertAllows($provider, $providerModelResolution['model'], $options);
            }

            $trace = AiTrace::query()->create([
                'trace_key' => 'trace_'.Str::orderedUuid()->toString(),
                'thread_id' => $lockedThread->id,
                'session_id' => $lockedSession->id,
                'source_type' => $options['source_type'] ?? 'app',
                'source_id' => $options['source_id'] ?? null,
                'status' => 'queued',
                'operator_input' => $input,
                'intent' => $prompt->intent,
                'agent_slug' => $prompt->agentSlug,
                'provider' => 'claude_codex',
                'model' => $traceModelResolution['model'],
                'skill_versions' => $prompt->skillVersions,
                'context_refs' => $prompt->contextRefs,
                'prompt_hash' => hash('sha256', $prompt->prompt),
                'metadata' => [
                    'mode' => $options['mode'] ?? 'async',
                    'client_id' => $options['client_id'] ?? null,
                    'privacy' => $privacy,
                    'execution_policy' => 'dual_review',
                    'thread' => $threadResolution->toArray(),
                    'session' => $this->sessionMetadata($lockedSession),
                    'auto_compaction_id' => $autoCompaction?->id,
                    'provider_handoff_id' => $providerHandoff?->id,
                    ...$this->modelRuntimeMetadata($traceModelResolution),
                    'council_providers' => $providers,
                    'council_status' => 'queued',
                    'council_progress' => [
                        'queued' => count($providers),
                        'processing' => 0,
                        'succeeded' => 0,
                        'failed' => 0,
                    ],
                    'task_request' => $prompt->taskRequest,
                    'context_pack' => $prompt->contextPack,
                    'open_brain_injection' => $prompt->openBrainInjection,
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                    ...$this->programmingMetadata($options),
                    'decision_receipt' => $decisionReceipt,
                ],
            ]);

            foreach ($providers as $index => $provider) {
                $role = $provider === 'codex_cli' ? 'critical_reviewer' : 'primary_planner';
                $jobModelResolution = $this->models->resolveWithSource($provider, $options['model'] ?? null, $this->modelResolutionContext($options, $prompt, $input));
                $job = AiJob::query()->create([
                    'trace_id' => $trace->id,
                    'client_id' => $index === 0 ? ($options['client_id'] ?? null) : null,
                    'kind' => 'council',
                    'status' => 'queued',
                    'priority' => (int) ($options['priority'] ?? 50) + $index,
                    'agent_slug' => $prompt->agentSlug,
                    'provider' => $provider,
                    'model' => $jobModelResolution['model'],
                    'input_text' => $input,
                    'prompt' => $this->councilPrompt($prompt->prompt, $provider, $role),
                    'context_refs' => $prompt->contextRefs,
                    'payload' => array_merge($options['payload'] ?? [], [
                        'privacy' => $privacy,
                        'execution_policy' => 'dual_review',
                        'thread' => $threadResolution->toArray(),
                        'session' => $this->sessionMetadata($lockedSession),
                        'auto_compaction_id' => $autoCompaction?->id,
                        'provider_handoff_id' => $providerHandoff?->id,
                        ...$this->modelRuntimeMetadata($jobModelResolution),
                        'council_role' => $role,
                        'council_provider' => $provider,
                        'council_providers' => $providers,
                        'task_request' => $prompt->taskRequest,
                        'context_pack' => $prompt->contextPack,
                        'open_brain_injection' => $prompt->openBrainInjection,
                        'execution_plan' => $prompt->executionPlan,
                        'skills_activated' => $prompt->activatedSkills,
                        'decision_receipt' => $decisionReceipt,
                    ]),
                    'available_at' => $options['available_at'] ?? $now,
                    'max_attempts' => (int) ($options['max_attempts'] ?? config('atlas.ai.max_attempts', 1)),
                    'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 600)),
                    'metadata' => [
                        'intent' => $prompt->intent,
                        'skill_versions' => $prompt->skillVersions,
                        'privacy' => $privacy,
                        'execution_policy' => 'dual_review',
                        'thread' => $threadResolution->toArray(),
                        'session' => $this->sessionMetadata($lockedSession),
                        'auto_compaction_id' => $autoCompaction?->id,
                        'provider_handoff_id' => $providerHandoff?->id,
                        ...$this->modelRuntimeMetadata($jobModelResolution),
                        'council_role' => $role,
                        'task_request' => $prompt->taskRequest,
                        'context_pack' => $prompt->contextPack,
                        'open_brain_injection' => $prompt->openBrainInjection,
                        'execution_plan' => $prompt->executionPlan,
                        'skills_activated' => $prompt->activatedSkills,
                        'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                        ...$this->programmingMetadata($options),
                        'decision_receipt' => $decisionReceipt,
                    ],
                ]);

                $this->recordTelemetry('job_enqueued', $trace, $job, [
                    'surface' => 'server',
                    'runtime' => 'laravel',
                    'metadata' => [
                        'priority' => $job->priority,
                        'available_at' => $job->available_at?->toJSON(),
                        'max_attempts' => $job->max_attempts,
                        'execution_policy' => 'dual_review',
                        'council_role' => $role,
                        ...$this->modelRuntimeMetadata($jobModelResolution),
                    ],
                ]);

                $this->audit->record('ai_trace_queued', [
                    'subject_type' => 'ai_trace',
                    'subject_id' => $trace->id,
                    'summary' => "Interacao de IA em conselho enfileirada para {$prompt->agentSlug}.",
                    'evidence' => [
                        'agent_slug' => $prompt->agentSlug,
                        'provider' => $provider,
                        'model' => $jobModelResolution['model'],
                        ...$this->modelRuntimeMetadata($jobModelResolution),
                        'source_type' => $trace->source_type,
                        'source_id' => $trace->source_id,
                        'input_text' => $input,
                        'prompt_hash' => $trace->prompt_hash,
                        'council_role' => $role,
                        'task_type' => data_get($prompt->taskRequest, 'task_type'),
                        'risk_level' => data_get($prompt->taskRequest, 'risk_level'),
                        'workflow' => data_get($prompt->executionPlan, 'workflow'),
                        'skills_activated' => $prompt->activatedSkills,
                    ],
                    'privacy' => $privacy,
                    'refs' => [
                        'trace_id' => $trace->id,
                        'thread_id' => $lockedThread->id,
                        'session_id' => $lockedSession->id,
                        'job_id' => $job->id,
                        'source_id' => $trace->source_id,
                    ],
                ]);
            }

            $this->conversation->recordUserMessage($lockedThread, $trace, $input, [
                'source' => 'ai_gateway',
                'thread_resolution' => $threadResolution->toArray(),
                'execution_policy' => 'dual_review',
                'session_id' => $lockedSession->id,
                ...$this->attachmentMetadata($options),
            ]);

            $this->states->updateForUserInput($lockedThread, $lockedSession, $input, $this->optionsWithPromptContracts($options, $prompt));
            $this->snapshots->record($trace, $lockedSession, $prompt, $autoCompaction, $providerHandoff);

            $this->recordAtlasDecision($trace, $options, 'claude_codex', $traceModelResolution['model'], $prompt, $traceModelResolution);
            $firstJob = $trace->jobs()->oldest('created_at')->first();
            $this->recordTelemetry('trace_created', $trace, $firstJob, [
                'surface' => 'server',
                'runtime' => 'laravel',
                'metadata' => [
                    'source_type' => $trace->source_type,
                    'execution_policy' => 'dual_review',
                    'council_providers' => $providers,
                    ...$this->modelRuntimeMetadata($traceModelResolution),
                    'task_type' => data_get($prompt->taskRequest, 'task_type'),
                    'workflow' => data_get($prompt->executionPlan, 'workflow'),
                ],
            ]);
            $this->captureOperatorLearningFromTrace($trace, $input, $options);

            return $trace->load($this->traceRelations());
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @return array<int, string>
     */
    private function councilProviders(array $options): array
    {
        $requested = data_get($options, 'payload.council_providers');
        if (! is_array($requested)) {
            return self::COUNCIL_PROVIDERS;
        }

        $providers = array_values(array_intersect($requested, self::COUNCIL_PROVIDERS));

        return count($providers) >= 2 ? $providers : self::COUNCIL_PROVIDERS;
    }

    private function councilPrompt(string $basePrompt, string $provider, string $role): string
    {
        $roleInstruction = $role === 'critical_reviewer'
            ? 'Seu papel nesta rodada e revisar criticamente: encontre falhas, riscos, lacunas, premissas fracas, inconsistencias e pontos que o outro avaliador provavelmente deixaria passar.'
            : 'Seu papel nesta rodada e propor a leitura principal: estruture o caminho recomendado, explicite tradeoffs, ordem de execucao, criterios de verificacao e decisoes praticas.';

        $providerName = $provider === 'codex_cli' ? 'Codex' : 'Claude';

        return <<<PROMPT
{$basePrompt}

# Conselho Atlas: {$providerName}

Voce esta participando de uma rodada dupla Claude + Codex.
{$roleInstruction}

Regras desta rodada:
- Nao execute alteracoes externas.
- Nao trate sua resposta como decisao final isolada.
- Escreva para que o Atlas consiga comparar sua leitura com a do outro provedor.
- Seja especifico sobre riscos, verificacao e proximo passo.
- Se a tarefa pedir implementacao, descreva quem deveria executar e quais revisoes devem acontecer depois.

Formato recomendado:
1. Diagnostico
2. Recomendacao
3. Riscos e lacunas
4. Criterios de verificacao
5. Proximo passo
PROMPT;
    }
}
