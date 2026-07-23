<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

final class WritingSection
{
    public function __construct(
        private readonly ?AtlasOpenBrainService $openBrain = null,
        private readonly EditorialSupport $support = new EditorialSupport(),
    ) {}

    /**
     * @param  array<string,mixed>  $handoff
     * @return array<string,mixed>
     */
    public function executeOpenBrainHandoff(array $handoff): array
    {
        try {
            $result = $this->openBrainService()->contextPack([
                'objective' => (string) ($handoff['objective'] ?? ''),
                'task_type' => 'research',
                'desired_mode' => 'direct',
                'agent' => 'orquestrador',
                'intent' => 'blog_editorial_context_export',
                'requester' => 'atlas-blog-editorial-plan',
                'payload' => is_array($handoff['payload'] ?? null) ? $handoff['payload'] : [],
            ], 'blog_editorial_plan');
        } catch (\Throwable $exception) {
            return [
                'schema_version' => 'atlas.blog_editorial_open_brain_execution.v1',
                'status' => 'failed',
                'invoked_by_this_command' => true,
                'error' => 'open_brain_context_failed',
                'message' => $exception->getMessage(),
                'guardrails' => [
                    'raw_context_pack_returned' => false,
                    'publishes_content' => false,
                    'uses_graph_rag' => false,
                    'uses_python_runtime' => false,
                    'creates_parallel_memory_store' => false,
                ],
            ];
        }

        $contextRefs = array_values(array_filter((array) ($result['context_refs'] ?? []), 'is_array'));

        return [
            'schema_version' => 'atlas.blog_editorial_open_brain_execution.v1',
            'status' => (bool) ($result['ok'] ?? false) ? 'ready' : 'failed',
            'invoked_by_this_command' => true,
            'context_pack_hash' => (string) ($result['context_pack_hash'] ?? ''),
            'summary' => [
                'context_refs_count' => (int) data_get($result, 'summary.context_refs_count', 0),
                'memory_refs_count' => (int) data_get($result, 'summary.memory_refs_count', 0),
                'recall_count' => (int) data_get($result, 'summary.recall_count', 0),
                'semantic_count' => (int) data_get($result, 'summary.semantic_count', 0),
                'provider_safe' => (bool) data_get($result, 'summary.provider_safe', false),
            ],
            'safety' => [
                'provider_safe_only' => (bool) data_get($result, 'safety.provider_safe_only', false),
                'raw_content_exposed' => (bool) data_get($result, 'safety.raw_content_exposed', true),
                'raw_content_persisted' => (bool) data_get($result, 'safety.raw_content_persisted', true),
                'audit_persisted' => (bool) data_get($result, 'safety.audit_persisted', false),
                'context_pack_hash_persisted' => (bool) data_get($result, 'safety.context_pack_hash_persisted', false),
            ],
            'audit' => [
                'persisted' => is_array($result['audit'] ?? null),
                'surface' => (string) data_get($result, 'audit.surface', ''),
                'requester' => (string) data_get($result, 'audit.requester', ''),
                'action' => (string) data_get($result, 'audit.action', ''),
                'status' => (string) data_get($result, 'audit.status', ''),
                'provider_safe' => (bool) data_get($result, 'audit.provider_safe', false),
            ],
            'context_refs' => array_slice(array_map(
                fn (array $ref): array => [
                    'type' => (string) ($ref['type'] ?? ''),
                    'title' => (string) ($ref['title'] ?? $ref['memory_type'] ?? ''),
                    'path' => (string) ($ref['path'] ?? ''),
                    'privacy_class' => (string) ($ref['privacy_class'] ?? ''),
                    'external_ai_allowed' => (bool) ($ref['external_ai_allowed'] ?? true),
                    'redaction_status' => (string) ($ref['redaction_status'] ?? ''),
                ],
                $contextRefs,
            ), 0, 8),
            'guardrails' => [
                'raw_context_pack_returned' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    public function openBrainHandoffForPost(array $post, int $contextLimit = 5): array
    {
        $contextLimit = max(1, min(12, $contextLimit));
        $slug = (string) ($post['slug'] ?? '');
        $title = (string) ($post['title'] ?? '');
        $mainQuestion = (string) ($post['main_question'] ?? '');
        $topics = array_values(array_filter((array) ($post['topics'] ?? []), 'is_string'));
        $objective = trim("Prepare provider-safe Atlas blog context for {$title} ({$slug}). Main question: {$mainQuestion}");
        $payload = [
            'schema_version' => 'atlas.blog_editorial_open_brain_payload.v1',
            'post' => [
                'order' => (int) ($post['order'] ?? 0),
                'title' => $title,
                'slug' => $slug,
                'complexity_level' => (string) ($post['complexity_level'] ?? ''),
                'collection' => (string) ($post['collection'] ?? ''),
                'series' => (string) ($post['series'] ?? ''),
                'main_question' => $mainQuestion,
                'topics' => $topics,
                'prerequisites' => array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string')),
                'next_reading' => array_values(array_filter((array) ($post['next_reading'] ?? []), 'is_string')),
            ],
            'editorial_constraints' => [
                'language' => 'pt-BR',
                'canonical_language' => 'pt-BR',
                'write_depth_rule' => 'Do not introduce concepts before the backlog sequence allows them.',
                'human_approval_required' => true,
                'do_not_publish' => true,
                'do_not_generate_full_article' => true,
            ],
            'source_policy' => [
                'intent' => 'blog_editorial_context_export',
                'provider_safe_only' => true,
                'prefer_existing_knowledge_read_models' => true,
                'prefer_code_intelligence_for_implementation_claims' => true,
                'allow_vector_retrieval' => true,
                'allow_graph_retrieval' => false,
                'context_limit' => $contextLimit,
            ],
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return [
            'schema_version' => 'atlas.blog_editorial_open_brain_handoff.v1',
            'status' => 'available_contract',
            'mode' => 'audited_context_export_handoff_p1',
            'invoked_by_this_command' => false,
            'objective' => $objective,
            'command' => '/opt/homebrew/bin/php artisan atlas:open-brain:context '
                .escapeshellarg($objective)
                .' --task-type=research'
                .' --desired-mode=direct'
                .' --agent=orquestrador'
                .' --intent=blog_editorial_context_export'
                .' --requester=atlas-blog-editorial-plan'
                .' --payload-json='.escapeshellarg($payloadJson)
                .' --json',
            'payload' => $payload,
            'expected_sources' => [
                'atlas_memory_registry',
                'engineering_knowledge',
                'code_intelligence',
                'vector_retrieval_when_provider_safe',
            ],
            'deferred_sources' => [
                'graph_retrieval',
                'python_ai_data_runtime',
                'automatic_publication',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'requires_human_approval_to_publish' => true,
            ],
        ];
    }

    public function openBrainService(): AtlasOpenBrainService
    {
        return $this->openBrain ?? app(AtlasOpenBrainService::class);
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    public function knowledgeRefs(array $terms, int $limit): array
    {
        if ($terms === [] || ! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return [];
        }

        return AtlasEngineeringKnowledgeItem::query()
            ->active()
            ->where($this->support->termsMatcher($terms, ['slug', 'title', 'summary', 'canonical_path', 'body_excerpt']))
            ->orderByDesc('priority')
            ->latest('indexed_at')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasEngineeringKnowledgeItem $item): array => [
                'type' => 'engineering_knowledge',
                'slug' => $item->slug,
                'title' => $item->title,
                'category' => $item->category,
                'canonical_path' => $item->canonical_path,
                'summary' => Str::limit((string) $item->summary, 220, ''),
                'reason' => 'matched_editorial_terms',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<string,mixed>
     */
    public function codeRefs(array $terms, int $limit): array
    {
        if ($terms === []) {
            return [
                'modules' => [],
                'symbols' => [],
            ];
        }

        return [
            'modules' => $this->moduleRefs($terms, $limit),
            'symbols' => $this->symbolRefs($terms, $limit),
        ];
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    public function moduleRefs(array $terms, int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_modules')) {
            return [];
        }

        return AtlasEngineeringCodeModule::query()
            ->active()
            ->where($this->support->termsMatcher($terms, ['slug', 'name', 'root_path', 'description']))
            ->orderByDesc('symbol_count')
            ->orderBy('slug')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasEngineeringCodeModule $module): array => [
                'type' => 'code_module',
                'slug' => $module->slug,
                'name' => $module->name,
                'layer' => $module->layer,
                'root_path' => $module->root_path,
                'docs_status' => $module->docs_status,
                'symbol_count' => $module->symbol_count,
                'reason' => 'matched_editorial_terms',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    public function symbolRefs(array $terms, int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            return [];
        }

        return AtlasEngineeringCodeSymbol::query()
            ->with('module')
            ->active()
            ->where($this->support->termsMatcher($terms, ['symbol_name', 'file_path', 'signature']))
            ->orderBy('file_path')
            ->orderBy('line_start')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasEngineeringCodeSymbol $symbol): array => [
                'type' => 'code_symbol',
                'symbol_type' => $symbol->symbol_type,
                'symbol_name' => $symbol->symbol_name,
                'file_path' => $symbol->file_path,
                'line_start' => $symbol->line_start,
                'line_end' => $symbol->line_end,
                'module' => $symbol->module?->slug,
                'docs_status' => $symbol->docs_status,
                'reason' => 'matched_editorial_terms',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    public function coverageSignals(array $post): array
    {
        $level = (string) ($post['complexity_level'] ?? '');

        return [
            'reader_level' => (string) ($post['reader_level'] ?? ''),
            'complexity_level' => $level,
            'needs_foundation' => in_array($level, ['L2', 'L3', 'L4'], true),
            'collection' => (string) ($post['collection'] ?? ''),
            'series' => (string) ($post['series'] ?? ''),
            'prerequisite_count' => count((array) ($post['prerequisites'] ?? [])),
            'editorial_rule' => 'Advanced subjects must preserve the public learning chain from surface-level framing to deeper implementation.',
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,array<string,mixed>>  $posts
     * @return array{previous:?array<string,mixed>,next:?array<string,mixed>}
     */
    public function neighborPosts(array $post, array $posts): array
    {
        $order = (int) ($post['order'] ?? 0);
        $previous = null;
        $next = null;

        foreach ($posts as $candidate) {
            $candidateOrder = (int) ($candidate['order'] ?? 0);
            if ($candidateOrder === $order - 1) {
                $previous = $this->support->compactPostRef($candidate);
            }
            if ($candidateOrder === $order + 1) {
                $next = $this->support->compactPostRef($candidate);
            }
        }

        return [
            'previous' => $previous,
            'next' => $next,
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<int,string>
     */
    public function futureTopicsToAvoid(array $post, array $posts): array
    {
        return array_slice((array) ($this->support->conceptProgressionMap($post, $posts)['future_terms_to_avoid'] ?? []), 0, 12);
    }

    /**
     * @param  array<string,mixed>  $post
     */
    public function readerPromise(array $post): string
    {
        $level = (string) ($post['complexity_level'] ?? '');

        return match ($level) {
            'L0' => 'Ao final, a pessoa entende o mapa basico sem precisar conhecer a arquitetura.',
            'L1' => 'Ao final, a pessoa entende o problema e por que ele importa.',
            'L2' => 'Ao final, a pessoa entende por que a solucao obvia falha.',
            'L3' => 'Ao final, a pessoa entende a decisao de design e seus tradeoffs.',
            'L4' => 'Ao final, a pessoa entende como a decisao aparece na implementacao.',
            default => 'Ao final, a pessoa entende uma ideia clara e sabe qual texto ler depois.',
        };
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    public function outlineForPost(array $post): array
    {
        $level = (string) ($post['complexity_level'] ?? '');

        return match ($level) {
            'L0' => [
                'Abrir com a situacao em linguagem simples.',
                'Definir o conceito principal sem jargao.',
                'Mostrar por que isso importa para uma pessoa real.',
                'Dizer o que fica fora deste texto.',
                'Fechar apontando a proxima leitura.',
            ],
            'L1' => [
                'Nomear o problema com um exemplo concreto.',
                'Mostrar por que a resposta comum ainda nao resolve.',
                'Conectar o problema ao Atlas sem entrar fundo em arquitetura.',
                'Explicar a tensao principal.',
                'Fechar com a pergunta que o proximo texto responde.',
            ],
            'L2' => [
                'Apresentar a solucao ingenua.',
                'Mostrar onde ela parece funcionar.',
                'Mostrar onde ela quebra.',
                'Extrair o principio de design que nasce dessa falha.',
                'Preparar o leitor para a decisao tecnica posterior.',
            ],
            'L3', 'L4' => [
                'Recapitular a base ja publicada em poucas linhas.',
                'Apresentar a decisao ou arquitetura.',
                'Explicar tradeoffs e limites.',
                'Mostrar como Atlas pensa nisso sem vazar detalhes privados.',
                'Fechar com consequencia pratica e proxima leitura.',
            ],
            default => [
                'Abrir com a pergunta principal.',
                'Desenvolver uma ideia por vez.',
                'Manter exemplos concretos.',
                'Fechar com continuidade editorial.',
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    public function mustInclude(array $post): array
    {
        return array_values(array_filter([
            (string) ($post['main_question'] ?? '') !== '' ? 'Responder explicitamente: '.((string) $post['main_question']) : null,
            (string) ($post['goal'] ?? '') !== '' ? 'Cumprir objetivo editorial: '.((string) $post['goal']) : null,
            'Um exemplo concreto ligado ao uso real do Atlas.',
            'Uma frase de transicao para a proxima leitura.',
        ]));
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    public function mustNotInclude(array $post): array
    {
        $level = (string) ($post['complexity_level'] ?? '');
        $items = [
            'Nao expor paths locais, tokens, prompts, traces ou detalhes privados.',
            'Nao transformar o texto em pitch de startup.',
            'Nao publicar codigo ou arquitetura sensivel sem revisao humana.',
        ];

        if (in_array($level, ['L0', 'L1'], true)) {
            $items[] = 'Nao antecipar implementacao profunda, ledger, graph/RAG ou mandatos se isso ainda nao foi introduzido.';
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<string,mixed>  $writingBrief
     * @param  array<string,mixed>  $conceptProgressionMap
     * @param  array<string,mixed>  $publicArchiveContext
     * @return array<string,mixed>
     */
    public function draftSeedForPost(array $post, array $writingBrief, array $conceptProgressionMap, array $publicArchiveContext): array
    {
        $title = (string) ($post['title'] ?? 'Texto sem titulo');
        $slug = (string) ($post['slug'] ?? '');
        $question = (string) ($writingBrief['primary_question'] ?? $post['main_question'] ?? '');
        $promise = (string) ($writingBrief['reader_promise'] ?? $this->readerPromise($post));
        $outline = array_values(array_filter((array) ($writingBrief['outline'] ?? []), 'is_string'));
        $allowedTerms = array_slice(array_values(array_filter((array) ($conceptProgressionMap['allowed_terms'] ?? []), 'is_string')), 0, 8);
        $futureTermsToAvoid = array_slice(array_values(array_filter((array) ($conceptProgressionMap['future_terms_to_avoid'] ?? []), 'is_string')), 0, 8);
        $duplicateRiskCount = (int) ($publicArchiveContext['duplicate_risk_count'] ?? 0);
        $linkableArtifactCount = (int) ($publicArchiveContext['linkable_artifact_count'] ?? 0);

        return [
            'schema_version' => 'atlas.blog_editorial_private_draft_seed.v1',
            'mode' => 'private_review_seed_p1',
            'status' => 'ready',
            'post_slug' => $slug,
            'language' => 'pt-BR',
            'title_options' => array_values(array_unique(array_filter([
                $title,
                $question !== '' ? $this->titleFromQuestion($question) : null,
                $title !== '' ? $title.': uma explicacao simples' : null,
            ]))),
            'working_thesis' => $question !== ''
                ? 'Este texto responde, sem pressa e sem jargao: '.$question
                : 'Este texto apresenta uma ideia do Atlas em uma camada segura para o leitor atual.',
            'lede_seed' => [
                'purpose' => 'Abrir o texto com contexto humano antes de entrar em arquitetura.',
                'paragraph_prompt' => $this->openingPromptForPost($post, $question),
                'avoid' => 'Nao comecar com buzzwords, claims grandiosos ou detalhes internos.',
            ],
            'section_seeds' => array_map(
                fn (string $item, int $index): array => [
                    'order' => $index + 1,
                    'heading_hint' => $this->headingHintFromOutline($item, $index),
                    'purpose' => $item,
                    'paragraph_prompt' => $this->sectionPromptForOutlineItem($post, $item, $index, $allowedTerms),
                ],
                $outline,
                array_keys($outline),
            ),
            'closing_seed' => [
                'purpose' => 'Fechar com continuidade editorial, nao com venda.',
                'paragraph_prompt' => $this->closingPromptForPost($post, $promise),
                'next_reading' => array_values(array_filter((array) ($post['next_reading'] ?? []), 'is_string')),
            ],
            'concept_boundaries' => [
                'allowed_terms' => $allowedTerms,
                'future_terms_to_avoid' => $futureTermsToAvoid,
                'rule' => 'Se um termo futuro for citado, ele deve aparecer como teaser, nao como conhecimento exigido.',
            ],
            'archive_awareness' => [
                'duplicate_risk_count' => $duplicateRiskCount,
                'linkable_artifact_count' => $linkableArtifactCount,
                'rule' => $duplicateRiskCount > 0
                    ? 'Comparar com artefatos publicos anteriores antes de transformar este seed em rascunho.'
                    : 'Pode seguir sem risco publico de duplicacao detectado neste preflight.',
            ],
            'review_checklist' => [
                'O texto responde a pergunta central em linguagem simples.',
                'A ordem conceitual respeita os prerequisitos publicados.',
                'Nenhum path local, token, prompt, trace ou detalhe sensivel aparece.',
                'Assuntos futuros nao viram dependencia para entender este post.',
                'A versao final ainda precisa de aprovacao humana antes de publicar.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_draft' => false,
                'publishes_content' => false,
                'generates_full_article' => false,
                'requires_human_review' => true,
            ],
        ];
    }

    public function titleFromQuestion(string $question): string
    {
        $title = trim($question, " \t\n\r\0\x0B?");

        if ($title === '') {
            return 'Texto do Atlas';
        }

        return mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1);
    }

    /**
     * @param  array<string,mixed>  $post
     */
    public function openingPromptForPost(array $post, string $question): string
    {
        $title = (string) ($post['title'] ?? 'este texto');

        if ($question !== '') {
            return 'Comece explicando por que "'.$title.'" importa antes de responder diretamente: '.$question;
        }

        return 'Comece explicando por que "'.$title.'" importa para alguem que ainda nao conhece a arquitetura do Atlas.';
    }

    public function headingHintFromOutline(string $outlineItem, int $index): string
    {
        $clean = trim($outlineItem, ". \t\n\r\0\x0B");

        return match ($index) {
            0 => 'O ponto de partida',
            1 => 'A ideia principal',
            2 => 'Por que isso importa',
            3 => 'O limite deste texto',
            4 => 'Para onde isso leva',
            default => $clean !== '' ? $clean : 'Secao '.($index + 1),
        };
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,string>  $allowedTerms
     */
    public function sectionPromptForOutlineItem(array $post, string $outlineItem, int $index, array $allowedTerms): string
    {
        $termHint = $allowedTerms !== []
            ? 'Pode usar termos ja liberados como: '.implode(', ', array_slice($allowedTerms, 0, 4)).'.'
            : 'Use exemplos simples antes de nomear conceitos tecnicos.';

        return 'Secao '.($index + 1).': '.$outlineItem.' '.$termHint;
    }

    /**
     * @param  array<string,mixed>  $post
     */
    public function closingPromptForPost(array $post, string $promise): string
    {
        $nextReading = array_values(array_filter((array) ($post['next_reading'] ?? []), 'is_string'));

        if ($nextReading !== []) {
            return $promise.' Feche preparando naturalmente a proxima leitura: '.$nextReading[0].'.';
        }

        return $promise.' Feche com uma consequencia pratica e sem promessa de produto.';
    }
}
