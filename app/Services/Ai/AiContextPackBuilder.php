<?php

namespace App\Services\Ai;

use App\Models\AiMemoryDelta;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use App\Models\SemanticNote;
use App\Services\Ai\Context\AtlasContextIdRemapService;
use App\Services\Ai\Context\ContextPackMemoryInput;
use App\Services\Ai\Context\ContextRetrievalRouter;
use App\Services\Ai\Context\SemanticContextInput;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Security\PromptInjectionScanner;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Semantic\SemanticSearchService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiContextPackBuilder
{
    public function __construct(
        private readonly SemanticSearchService $search,
        private readonly AiConversationContextBuilder $conversation,
        private readonly AtlasMemoryRegistryService $memoryRegistry,
        ?AtlasMemoryPrivacyService $memoryPrivacy = null,
        ?AtlasMemorySourcePrivacyPolicy $sourcePrivacy = null,
        ?AtlasVerbatimMemoryService $verbatimMemory = null,
        ?AtlasMemoryContextComposer $memoryComposer = null,
        ?KernelSloProbe $slo = null,
        ?ContextPackMemoryInput $memoryInput = null,
        ?SemanticContextInput $semanticInput = null,
        ?ContextRetrievalRouter $retrievalRouter = null,
        ?AtlasContextIdRemapService $idRemap = null,
    ) {
        $this->memoryPrivacy = $memoryPrivacy ?? app(AtlasMemoryPrivacyService::class);
        $this->sourcePrivacy = $sourcePrivacy ?? app(AtlasMemorySourcePrivacyPolicy::class);
        $this->verbatimMemory = $verbatimMemory ?? app(AtlasVerbatimMemoryService::class);
        $this->memoryComposer = $memoryComposer ?? app(AtlasMemoryContextComposer::class);
        $this->slo = $slo ?? app(KernelSloProbe::class);
        $this->memoryInput = $memoryInput ?? app(ContextPackMemoryInput::class);
        $this->semanticInput = $semanticInput ?? app(SemanticContextInput::class);
        $this->retrievalRouter = $retrievalRouter ?? app(ContextRetrievalRouter::class);
        $this->idRemap = $idRemap ?? app(AtlasContextIdRemapService::class);
    }

    private AtlasMemoryPrivacyService $memoryPrivacy;

    private AtlasMemorySourcePrivacyPolicy $sourcePrivacy;

    private AtlasVerbatimMemoryService $verbatimMemory;

    private AtlasMemoryContextComposer $memoryComposer;

    private KernelSloProbe $slo;

    private ContextPackMemoryInput $memoryInput;

    private SemanticContextInput $semanticInput;

    private ContextRetrievalRouter $retrievalRouter;

    private AtlasContextIdRemapService $idRemap;

    public function build(string $input, AiTaskRequest $task, array $options = []): AiContextPack
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return $this->slo->measure('context.compose', fn (): AiContextPack => $this->buildUnmeasured($input, $task, $options), [
            'tenant_id' => data_get($payload, 'tenant_id', 'default'),
            'operator_id' => data_get($payload, 'operator_id', 'system'),
            'envelope_id' => data_get($payload, 'envelope_id', data_get($payload, 'thread_id', 'context_compose')),
            'receipt_id' => data_get($payload, 'receipt_id'),
            'trace_id' => data_get($payload, 'trace_id'),
            'correlation_id' => data_get($payload, 'correlation_id', data_get($payload, 'thread_id', 'context_compose')),
            'domain' => data_get($payload, 'domain', data_get($payload, 'profile_context.domain')),
            'flow' => data_get($payload, 'flow', data_get($payload, 'profile_context.flow')),
            'surface_id' => data_get($payload, 'surface_id', data_get($payload, 'app_surface')),
            'provider' => data_get($payload, 'selected_provider', data_get($payload, 'provider')),
            'model' => data_get($payload, 'selected_model', data_get($payload, 'model')),
        ]);
    }

    private function buildUnmeasured(string $input, AiTaskRequest $task, array $options = []): AiContextPack
    {
        $notes = $this->contextNotes($input, $options);
        $contextRefs = $notes->map(function (SemanticNote $note): array {
            $privacy = $this->semanticNotePrivacy($note);

            return [
                'type' => 'semantic_note',
                'id' => $note->id,
                'path' => $note->path,
                'title' => data_get($privacy, 'fields.title') ?? $note->title,
                'score' => isset($note->score) ? round((float) $note->score, 4) : null,
                'privacy_class' => $privacy['privacy_class'],
                'external_ai_allowed' => $privacy['external_ai_allowed'],
                'redaction_status' => $privacy['redaction_status'],
            ];
        })->values()->all();

        $taskData = $task->toArray();
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $conversation = $this->conversation->build($options);
        $retrievalPlan = $this->retrievalRouter->plan($input, $task, $payload, $options);
        $registryMemory = $this->registryMemory($taskData, $payload, $conversation, $options);
        $registryRefs = $registryMemory->map(fn (AtlasMemoryEntry $entry): array => [
            'type' => 'atlas_memory_entry',
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'priority' => $entry->priority,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
        ])->values()->all();
        $verbatimRecall = $this->verbatimRecall($taskData, $payload, $conversation, $options);
        $verbatimRefs = $verbatimRecall->map(fn (AtlasVerbatimMemory $memory): array => [
            'type' => 'atlas_verbatim_memory',
            'id' => $memory->id,
            'verbatim_type' => $memory->verbatim_type,
            'scope_type' => $memory->scope_type,
            'scope_id' => $memory->scope_id,
            'privacy_class' => $memory->privacy_class,
            'external_ai_allowed' => $memory->external_ai_allowed,
            'redaction_status' => $memory->redaction_status,
            'source_type' => $memory->source_type,
            'source_id' => $memory->source_id,
        ])->values()->all();
        $contextRefs = array_values([...$contextRefs, ...$registryRefs, ...$verbatimRefs]);
        $registryItems = $this->registryMemoryItems($registryMemory);
        $verbatimItems = $this->verbatimMemoryItems($verbatimRecall, $options);
        $semanticItems = $this->semanticMemory($notes);
        $recallItems = $this->memoryComposer->compose($registryItems, $verbatimItems, $semanticItems, $options);

        $pack = new AiContextPack([
            'schema_version' => 1,
            'task' => [
                'type' => $taskData['task_type'],
                'desired_mode' => $taskData['desired_mode'],
                'objective' => $taskData['operator_input_excerpt'],
                'success_criteria' => $this->successCriteria($task),
                'risk_level' => $taskData['risk_level'],
                'domain' => $taskData['domain'],
                'intent' => $taskData['intent'],
            ],
            'surface' => [
                'kind' => $taskData['surface'],
                'workspace' => $taskData['workspace'],
                'source_type' => $taskData['source']['type'] ?? null,
                'source_id' => $taskData['source']['id'] ?? null,
            ],
            'operator' => [
                'relevant_preferences' => [],
                'active_goals' => [],
                'cognitive_state' => 'unknown',
            ],
            'conversation' => [
                'thread_id' => $conversation['thread_id'],
                'thread_title' => $conversation['thread_title'],
                'thread_summary' => $conversation['thread_summary'],
                'source' => $conversation['source'],
                'context_window' => $conversation['context_window'] ?? null,
                'recent_turns' => $conversation['recent_turns'],
                'instruction' => $conversation['instruction'],
            ],
            'continuity' => [
                'active_state' => $conversation['active_state'],
                'latest_compaction' => $conversation['latest_compaction'],
                'latest_provider_handoff' => $conversation['latest_provider_handoff'],
            ],
            'project_state' => [
                'repo' => data_get($payload, 'repo'),
                'branch' => data_get($payload, 'branch'),
                'dirty_files' => array_values((array) data_get($payload, 'dirty_files', [])),
                'relevant_files' => array_values((array) data_get($payload, 'relevant_files', [])),
                'commands' => (array) data_get($payload, 'commands', []),
            ],
            'retrieval' => $retrievalPlan,
            'memory' => [
                'constitutional' => [],
                'recall' => $recallItems,
                'registry' => $registryItems,
                'verbatim' => $verbatimItems,
                'semantic' => $semanticItems,
                'procedural' => $this->memoryDeltas($taskData['workspace'] ?? null, 'process'),
                'decisions' => $this->registryMemoryByType($registryItems, 'decision'),
                'preferences' => $this->memoryDeltas($taskData['workspace'] ?? null, 'preference'),
                'registry_preferences' => $this->registryMemoryByType($registryItems, 'preference'),
                'technical_context' => $this->registryMemoryByType($registryItems, 'technical_context'),
                'deltas' => $this->memoryDeltas($taskData['workspace'] ?? null),
            ],
            'evidence' => [
                'sources' => array_values((array) data_get($payload, 'sources', [])),
                'tool_outputs' => [],
                'previous_traces' => [],
            ],
            'constraints' => [
                'must_do' => $this->mustDo($task),
                'must_not_do' => $this->mustNotDo($task),
                'privacy_class' => $taskData['privacy_class'],
            ],
            'gates' => [
                'required_checks' => [],
                'human_approval_required' => in_array($task->riskLevel(), ['high', 'irreversible'], true),
            ],
            'excluded_context' => $this->excludedContext($options),
            'open_questions' => $this->openQuestions($notes, $options),
        ], $contextRefs);

        // Atlas Cognition Operating System — Absorcao 1 (Integer ID Mapping).
        // Phase 1: persiste mapping internal_id -> real_uuid por context_pack_id.
        // Phase 2 (2026-05-25): forward substitution — quando feature flag
        // habilitada e mapping nao vazio, reconstroi AiContextPack passando o
        // ContextIdRemap como 3o param. Resultado: toPromptSection() renderiza
        // "[N]" no lugar de UUIDs em source_id fields, expondo provider-safe
        // labels ao LLM. UUIDs reais ficam apenas internos ao Atlas; parser
        // reverso recupera para Decision Receipt e Evidence Ledger downstream.
        $packArray = $pack->toArray();
        $contextPackId = (string) data_get($packArray, 'manifest.context_pack_id', '');
        if ($contextPackId !== '') {
            $remap = $this->idRemap->remap($contextRefs, $contextPackId);
            if (! $remap->isEmpty()) {
                $pack = new AiContextPack($packArray, $contextRefs, $remap);
            }
        }

        return $pack;
    }

    private function registryMemory(array $taskData, array $payload, array $conversation, array $options)
    {
        if (($options['include_memory_registry'] ?? true) === false) {
            return collect();
        }

        $limit = $this->memoryInput->memoryRegistryLimit($options['memory_registry_limit'] ?? null);
        if ($limit <= 0) {
            return collect();
        }

        return $this->memoryRegistry->relevantForContext([
            'project_id' => data_get($payload, 'project_id'),
            'task_id' => data_get($payload, 'task_id'),
            'engineering_run_id' => data_get($payload, 'engineering_run_id', data_get($payload, 'run_id')),
            'session_id' => data_get($payload, 'session_id', $conversation['active_state']['session_id'] ?? null),
            'user_id' => data_get($payload, 'user_id'),
            'workspace' => $taskData['workspace'] ?? null,
        ], [
            'types' => array_values(array_filter((array) data_get($payload, 'memory_types', []), 'is_string')),
        ], $limit)
            ->filter(fn (AtlasMemoryEntry $entry): bool => $this->memoryPrivacy->providerAllowed($entry))
            ->values();
    }

    private function verbatimRecall(array $taskData, array $payload, array $conversation, array $options)
    {
        if (($options['include_verbatim_recall'] ?? true) === false) {
            return collect();
        }

        $limit = $this->memoryInput->verbatimRecallLimit($options['verbatim_recall_limit'] ?? null);
        if ($limit <= 0) {
            return collect();
        }

        return $this->verbatimMemory->relevantForContext([
            'project_id' => data_get($payload, 'project_id'),
            'task_id' => data_get($payload, 'task_id'),
            'engineering_run_id' => data_get($payload, 'engineering_run_id', data_get($payload, 'run_id')),
            'session_id' => data_get($payload, 'session_id', $conversation['active_state']['session_id'] ?? null),
            'user_id' => data_get($payload, 'user_id'),
            'workspace' => $taskData['workspace'] ?? null,
        ], [
            'types' => array_values(array_filter((array) data_get($payload, 'verbatim_types', []), 'is_string')),
        ], $limit)
            ->filter(fn (AtlasVerbatimMemory $memory): bool => $memory->external_ai_allowed === true && trim((string) $memory->redacted_text) !== '')
            ->values();
    }

    private function registryMemoryItems($entries): array
    {
        return $entries->map(fn (AtlasMemoryEntry $entry): array => [
            'id' => $entry->id,
            'type' => $entry->memory_type,
            'scope' => $entry->scope_id ? $entry->scope_type.':'.$entry->scope_id : $entry->scope_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'title' => $this->memoryPrivacy->providerTitle($entry),
            'summary' => $this->memoryPrivacy->providerSummary($entry),
            'body' => Str::limit($this->memoryPrivacy->providerBody($entry), $this->memoryInput->memoryRegistryExcerptChars(), '...'),
            'importance' => $entry->importance,
            'priority' => $entry->priority,
            'confidence' => $entry->confidence,
            'privacy_class' => $entry->privacy_class ?? data_get($entry->metadata, 'privacy.class'),
            'redaction_status' => $entry->redaction_status ?? data_get($entry->metadata, 'privacy.redaction_status'),
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'source_label' => $entry->source_label,
            'content_hash' => $entry->content_hash,
            'recorded_at' => $entry->recorded_at?->toJSON(),
            'last_used_at' => $entry->last_used_at?->toJSON(),
            'governance_checked_at' => $entry->governance_checked_at?->toJSON(),
            'privacy_reviewed_at' => $entry->privacy_reviewed_at?->toJSON(),
            'reason' => $this->memoryReason($entry),
        ])->values()->all();
    }

    private function verbatimMemoryItems($memories, array $options): array
    {
        $budget = $this->memoryInput->verbatimRecallBudgetChars($options['verbatim_recall_budget_chars'] ?? null);
        $itemChars = $this->memoryInput->verbatimRecallItemChars($options['verbatim_recall_item_chars'] ?? null);

        if ($budget <= 0 || $itemChars <= 0) {
            return [];
        }

        $items = [];
        foreach ($memories as $memory) {
            if (! $memory instanceof AtlasVerbatimMemory) {
                continue;
            }

            $available = min($budget, $itemChars);
            if ($available < 40) {
                break;
            }

            $text = trim((string) $memory->redacted_text);
            $snippet = Str::length($text) > $available
                ? Str::limit($text, max(1, $available - 3), '...')
                : $text;
            if ($snippet === '') {
                continue;
            }

            $securityIssues = PromptInjectionScanner::scan($snippet);
            $blocked = $securityIssues !== [];
            $blockedMessage = '[BLOCKED: verbatim recall contained potential prompt injection - '.data_get($securityIssues, '0.message', 'suspicious content').']';
            $safeSnippet = match (true) {
                ! $blocked => $snippet,
                Str::length($blockedMessage) > $available => Str::limit($blockedMessage, max(1, $available - 3), '...'),
                default => $blockedMessage,
            };

            $items[] = [
                'id' => $memory->id,
                'type' => $memory->verbatim_type,
                'scope' => $memory->scope_id ? $memory->scope_type.':'.$memory->scope_id : $memory->scope_type,
                'scope_type' => $memory->scope_type,
                'scope_id' => $memory->scope_id,
                'title' => $memory->title,
                'summary' => $memory->summary,
                'snippet' => $safeSnippet,
                'privacy_class' => $memory->privacy_class,
                'redaction_status' => $memory->redaction_status,
                'source_type' => $memory->source_type,
                'source_id' => $memory->source_id,
                'source_label' => $memory->source_label,
                'content_hash' => $memory->content_hash,
                'redacted_hash' => $memory->redacted_hash,
                'recorded_at' => $memory->recorded_at?->toJSON(),
                'reason' => $this->verbatimReason($memory),
                'blocked' => $blocked,
                'security_issues' => $blocked ? $securityIssues : [],
            ];

            $budget -= Str::length($safeSnippet);
        }

        return $items;
    }

    private function registryMemoryByType(array $items, string $type): array
    {
        return array_values(array_filter($items, fn (array $item): bool => ($item['type'] ?? null) === $type));
    }

    private function memoryReason(AtlasMemoryEntry $entry): string
    {
        return match ($entry->scope_type) {
            'global' => 'memoria global ativa',
            'project' => 'memoria ligada ao projeto atual',
            'task' => 'memoria ligada a tarefa atual',
            'engineering_run' => 'aprendizado ligado ao run de engenharia',
            'workspace' => 'memoria ligada ao workspace atual',
            'session' => 'memoria ligada a sessao atual',
            'user' => 'preferencia/contexto ligado ao usuario',
            default => 'memoria ativa do registry central',
        };
    }

    private function verbatimReason(AtlasVerbatimMemory $memory): string
    {
        return match ($memory->scope_type) {
            'global' => 'recall verbatim global aprovado para provider',
            'project' => 'recall verbatim ligado ao projeto atual',
            'task' => 'recall verbatim ligado a tarefa atual',
            'engineering_run' => 'recall verbatim ligado ao run de engenharia',
            'workspace' => 'recall verbatim ligado ao workspace atual',
            'session' => 'recall verbatim ligado a sessao atual',
            'user' => 'recall verbatim ligado ao usuario',
            default => 'recall verbatim aprovado para provider',
        };
    }

    private function contextNotes(string $input, array $options)
    {
        if (($options['include_semantic_context'] ?? true) === false) {
            return collect();
        }

        $limit = $this->semanticInput->contextNoteLimit($options['context_note_limit'] ?? null);
        if ($limit <= 0) {
            return collect();
        }

        return $this->search->search($input, [], $limit);
    }

    private function semanticMemory($notes): array
    {
        $excerptChars = $this->semanticInput->contextExcerptChars();

        return $notes->map(fn (SemanticNote $note): array => $this->semanticMemoryItem($note, $excerptChars))->values()->all();
    }

    private function semanticMemoryItem(SemanticNote $note, int $excerptChars): array
    {
        $privacy = $this->semanticNotePrivacy($note);
        $excerpt = Str::limit((string) (data_get($privacy, 'fields.body') ?: data_get($privacy, 'fields.summary') ?: ''), $excerptChars, '...');
        $securityIssues = PromptInjectionScanner::scan($excerpt);
        $base = [
            'type' => $note->type,
            'id' => $note->id,
            'path' => $note->path,
            'title' => data_get($privacy, 'fields.title') ?? $note->title,
            'summary' => data_get($privacy, 'fields.summary') ?? $note->summary,
            'score' => isset($note->score) ? round((float) $note->score, 4) : null,
            'origin' => 'semantic_search',
            'confidence' => isset($note->score) && (float) $note->score >= 0.72 ? 'high' : 'medium',
            'privacy_class' => $privacy['privacy_class'],
            'external_ai_allowed' => $privacy['external_ai_allowed'],
            'redaction_status' => $privacy['redaction_status'],
            'privacy_reason' => $privacy['reason'],
            'source_type' => 'semantic_note',
            'source_id' => $note->id,
            'source_label' => $note->path,
            'content_hash' => is_scalar(data_get($note->metadata, 'content_hash')) ? (string) data_get($note->metadata, 'content_hash') : hash('sha256', implode('|', [
                (string) $note->path,
                (string) $note->title,
                (string) $note->summary,
                (string) $note->body_excerpt,
            ])),
            'recorded_at' => $note->updated_at?->toJSON() ?? $note->created_at?->toJSON(),
        ];

        if (! $privacy['provider_safe']) {
            return $base + [
                'excerpt' => '[BLOCKED: semantic note excluded by Atlas source privacy policy - '.$privacy['reason'].']',
                'blocked' => true,
                'blocked_reason' => 'source_privacy_policy',
            ];
        }

        if ($securityIssues === []) {
            return $base + [
                'excerpt' => $excerpt,
            ];
        }

        return $base + [
            'excerpt' => '[BLOCKED: semantic note contained potential prompt injection - '.data_get($securityIssues, '0.message', 'suspicious content').']',
            'blocked' => true,
            'security_issues' => $securityIssues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function semanticNotePrivacy(SemanticNote $note): array
    {
        return $this->sourcePrivacy->project('semantic_note', [
            'title' => $note->title,
            'summary' => $note->summary,
            'body_excerpt' => $note->body_excerpt,
            'path' => $note->path,
            'frontmatter' => $note->frontmatter ?? [],
            'metadata' => $note->metadata ?? [],
            'domains' => $note->domains ?? [],
        ]);
    }

    private function memoryDeltas(?string $workspace, ?string $type = null): array
    {
        if (! $workspace || ! Schema::hasTable('ai_memory_deltas')) {
            return [];
        }

        $workspace = realpath($workspace) ?: $workspace;
        $scopes = ['global', 'project:atlas', 'workspace:'.$workspace];

        return AiMemoryDelta::query()
            ->where('status', 'accepted')
            ->whereIn('scope', $scopes)
            ->when($type, fn ($query) => $query->where('type', $type))
            ->where(function ($query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', now());
            })
            ->latest('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (AiMemoryDelta $delta): array => [
                'id' => $delta->id,
                'type' => $delta->type,
                'claim' => $delta->claim,
                'scope' => $delta->scope,
                'confidence' => $delta->confidence,
                'evidence' => $delta->evidence,
                'use_when' => $delta->use_when,
                'do_not_use_when' => $delta->do_not_use_when,
            ])
            ->values()
            ->all();
    }

    private function successCriteria(AiTaskRequest $task): array
    {
        return match ($task->taskType()) {
            'review' => ['Achados acionaveis aparecem antes do resumo.', 'Riscos e testes faltantes sao explicitados.'],
            'dev', 'debug' => ['Mudanca ou diagnostico tem escopo claro.', 'Testes executados ou motivo de nao execucao sao informados.'],
            'research' => ['Fatos importantes citam fonte ou lacuna.', 'Evidencia e inferencia ficam separadas.'],
            'decision' => ['Opcoes, tradeoffs, reversibilidade e criterio humano ficam claros.'],
            'memory' => ['Origem, escopo, confianca e validade da memoria ficam explicitos.'],
            default => ['Resposta util, direta e coerente com a identidade Atlas.'],
        };
    }

    private function mustDo(AiTaskRequest $task): array
    {
        $rules = ['Usar portugues brasileiro claro.', 'Declarar lacunas quando contexto for insuficiente.'];

        if ($task->taskType() === 'decision') {
            $rules[] = 'Preservar autoria humana e explicitar tradeoffs.';
        }

        if ($task->taskType() === 'memory') {
            $rules[] = 'Nao promover memoria como verdade sem evidencia.';
        }

        return $rules;
    }

    private function mustNotDo(AiTaskRequest $task): array
    {
        $rules = ['Nao inventar fatos.', 'Nao tratar provider como identidade do Atlas.'];

        if (in_array($task->riskLevel(), ['high', 'irreversible'], true)) {
            $rules[] = 'Nao sugerir acao irreversivel sem human gate.';
        }

        return $rules;
    }

    private function excludedContext(array $options): array
    {
        if (($options['include_semantic_context'] ?? true) === false) {
            return ['semantic_memory_disabled_for_this_request'];
        }

        return [];
    }

    private function openQuestions($notes, array $options): array
    {
        if (($options['include_semantic_context'] ?? true) !== false && $notes->isEmpty()) {
            return ['Nenhuma memoria semantica relevante foi recuperada; declarar lacuna se isso afetar a resposta.'];
        }

        return [];
    }
}
