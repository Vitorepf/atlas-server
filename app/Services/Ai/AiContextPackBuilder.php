<?php

namespace App\Services\Ai;

use App\Models\AiMemoryDelta;
use App\Models\SemanticNote;
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
    ) {}

    public function build(string $input, AiTaskRequest $task, array $options = []): AiContextPack
    {
        $notes = $this->contextNotes($input, $options);
        $contextRefs = $notes->map(fn (SemanticNote $note): array => [
            'type' => 'semantic_note',
            'id' => $note->id,
            'path' => $note->path,
            'title' => $note->title,
            'score' => isset($note->score) ? round((float) $note->score, 4) : null,
        ])->values()->all();

        $taskData = $task->toArray();
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $conversation = $this->conversation->build($options);

        return new AiContextPack([
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
            'memory' => [
                'constitutional' => [],
                'semantic' => $this->semanticMemory($notes),
                'procedural' => $this->memoryDeltas($taskData['workspace'] ?? null, 'process'),
                'decisions' => [],
                'preferences' => $this->memoryDeltas($taskData['workspace'] ?? null, 'preference'),
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
    }

    private function contextNotes(string $input, array $options)
    {
        if (($options['include_semantic_context'] ?? true) === false) {
            return collect();
        }

        $limit = (int) ($options['context_note_limit'] ?? config('atlas.ai.context_note_limit', 5));
        if ($limit <= 0) {
            return collect();
        }

        return $this->search->search($input, [], $limit);
    }

    private function semanticMemory($notes): array
    {
        $excerptChars = (int) config('atlas.ai.context_excerpt_chars', 1200);

        return $notes->map(fn (SemanticNote $note): array => $this->semanticMemoryItem($note, $excerptChars))->values()->all();
    }

    private function semanticMemoryItem(SemanticNote $note, int $excerptChars): array
    {
        $excerpt = Str::limit((string) ($note->body_excerpt ?: $note->summary), $excerptChars, '...');
        $securityIssues = PromptInjectionScanner::scan($excerpt);
        $base = [
            'type' => $note->type,
            'id' => $note->id,
            'path' => $note->path,
            'title' => $note->title,
            'summary' => $note->summary,
            'score' => isset($note->score) ? round((float) $note->score, 4) : null,
            'origin' => 'semantic_search',
            'confidence' => isset($note->score) && (float) $note->score >= 0.72 ? 'high' : 'medium',
        ];

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
