<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

use App\Services\Ai\Context\AtlasGraphRetrievalNetworkService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

final class GraphSection
{
    public function graphRetrievalService(): AtlasGraphRetrievalNetworkService
    {
        return app(AtlasGraphRetrievalNetworkService::class);
    }

    /**
     * @param  array<string,mixed>  $post
     */
    public function editorialGraphObjective(array $post): string
    {
        return trim(implode(' ', array_filter([
            'blog editorial context',
            (string) ($post['title'] ?? ''),
            (string) ($post['main_question'] ?? ''),
            (string) ($post['collection'] ?? ''),
            (string) ($post['series'] ?? ''),
            implode(' ', array_values(array_filter((array) ($post['topics'] ?? []), 'is_string'))),
        ])));
    }

    /**
     * @return array<string,bool>
     */
    public function editorialGraphContextGuardrails(bool $invoked): array
    {
        return [
            'read_only' => true,
            'writes_backlog' => false,
            'writes_review_queue' => false,
            'writes_draft' => false,
            'publishes_content' => false,
            'uses_global_graph_rag' => false,
            'uses_python_runtime' => false,
            'invokes_bounded_graph_retrieval' => $invoked,
            'invokes_vector_runtime' => false,
            'providers_invoked' => false,
            'creates_parallel_memory_store' => false,
            'requires_human_approval_to_promote' => true,
        ];
    }

    /**
     * @return array<string,bool>
     */
    public function editorialGraphCandidateGuardrails(bool $invoked): array
    {
        return [
            'read_only' => true,
            'writes_backlog' => false,
            'writes_review_queue' => false,
            'writes_draft' => false,
            'publishes_content' => false,
            'may_reorder_backlog' => false,
            'may_promote_candidate' => false,
            'requires_human_approval_to_accept' => true,
            'requires_human_approval_to_promote' => true,
            'uses_global_graph_rag' => false,
            'uses_python_runtime' => false,
            'invokes_bounded_graph_retrieval' => $invoked,
            'invokes_vector_runtime' => false,
            'providers_invoked' => false,
            'creates_parallel_memory_store' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $graphContext
     * @param  array<int,array<string,mixed>>  $evidence
     * @return array<int,array<string,mixed>>
     */
    public function graphCandidateBlueprints(array $graphContext, array $evidence): array
    {
        $pathText = Str::lower(Str::ascii(implode(' ', array_values(array_filter(array_map(
            fn (array $item): string => (string) ($item['path'] ?? ''),
            $evidence,
        ))))));
        $targetSlug = (string) data_get($graphContext, 'post.slug', '');
        $targetTitle = (string) data_get($graphContext, 'post.title', '');
        $hasEditorialPlanning = str_contains($pathText, 'blogeditorial') || str_contains($targetSlug, 'atlas');

        return array_values(array_filter([
            $hasEditorialPlanning ? [
                'title' => 'Como o Atlas decide a ordem do blog',
                'slug' => 'como-o-atlas-decide-a-ordem-do-blog',
                'collection' => 'atlas',
                'series' => 'public-building-system',
                'complexity_level' => 'L2',
                'main_question' => 'Como transformar trabalho real em uma sequencia publica que qualquer pessoa consegue acompanhar?',
                'topics' => ['atlas', 'blog', 'planejamento-editorial', 'sequencia'],
                'why' => 'Bounded graph context found editorial planning code and docs; this can become a public explanation after the first foundation arc.',
            ] : null,
            [
                'title' => 'Como o Atlas encontra proximas pautas sem baguncar a ordem',
                'slug' => 'como-o-atlas-encontra-proximas-pautas-sem-baguncar-a-ordem',
                'collection' => 'atlas',
                'series' => 'public-building-system',
                'complexity_level' => 'L2',
                'main_question' => 'Como um sistema pode sugerir pautas novas sem pular os prerequisitos do leitor?',
                'topics' => ['atlas', 'grafo', 'backlog', 'ux-de-conteudo'],
                'why' => 'The graph evidence is useful as a topic signal, but the public sequence must stay append-only and human-reviewed.',
            ],
            [
                'title' => 'Por que um blog tecnico precisa de uma fila governada',
                'slug' => 'por-que-um-blog-tecnico-precisa-de-uma-fila-governada',
                'collection' => 'produto',
                'series' => 'public-building-system',
                'complexity_level' => 'L1',
                'main_question' => 'Por que publicar assuntos complexos fora de ordem destrói a experiencia do leitor?',
                'topics' => ['blog', 'ux-de-conteudo', 'governanca', 'atlas'],
                'why' => 'The current public backlog already encodes prerequisites; this deserves a public meta-post before deeper graph/RAG topics.',
            ],
            $targetTitle !== '' ? [
                'title' => 'Como preparar contexto antes de escrever sobre '.$targetTitle,
                'slug' => 'como-preparar-contexto-antes-de-escrever-sobre-'.$targetSlug,
                'collection' => 'atlas',
                'series' => 'public-building-system',
                'complexity_level' => 'L2',
                'main_question' => 'Como o Atlas decide que contexto ajuda uma postagem sem expor detalhes privados?',
                'topics' => ['atlas', 'contexto', 'seguranca', 'escrita'],
                'why' => 'The bounded graph context is attached to the next ready post as writing context, not as publication authority.',
            ] : null,
        ]));
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @param  array<int,array<string,mixed>>  $evidence
     * @return array<string,mixed>
     */
    public function graphCandidateFromBlueprint(array $blueprint, string $appendAfterSlug, array $evidence): array
    {
        $evidenceRefs = array_values(array_map(
            fn (array $item): array => [
                'node_type' => (string) ($item['node_type'] ?? ''),
                'path_hash' => (string) ($item['path_hash'] ?? ''),
                'flow_id' => (string) ($item['flow_id'] ?? ''),
                'score' => (float) ($item['score'] ?? 0),
                'confidence' => (float) ($item['confidence'] ?? 0),
                'reasons' => array_values((array) ($item['reasons'] ?? [])),
            ],
            array_slice($evidence, 0, 4),
        ));

        return [
            'source_type' => 'bounded_world_model_graph',
            'source_ref' => 'codebase_world_model_bounded',
            'title' => (string) $blueprint['title'],
            'slug' => (string) $blueprint['slug'],
            'collection' => (string) $blueprint['collection'],
            'series' => (string) $blueprint['series'],
            'complexity_level' => (string) $blueprint['complexity_level'],
            'main_question' => (string) $blueprint['main_question'],
            'suggested_after_slug' => $appendAfterSlug,
            'topics' => array_values(array_filter((array) $blueprint['topics'], 'is_string')),
            'why' => (string) $blueprint['why'],
            'evidence_refs' => $evidenceRefs,
            'promotion_rule' => 'Accept into review queue first; promote append-only only after Vitor approves the sequence position.',
            'safety' => [
                'requires_human_review' => true,
                'publish_private_paths' => false,
                'publish_internal_ids_or_traces' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $graphContext
     * @return array<string,mixed>
     */
    public function compactGraphContextForCandidates(array $graphContext): array
    {
        return [
            'status' => (string) ($graphContext['status'] ?? 'unknown'),
            'post' => $graphContext['post'] ?? null,
            'graph_retrieval_status' => (string) data_get($graphContext, 'graph_retrieval.status', 'unknown'),
            'graph_scope' => (string) data_get($graphContext, 'graph_retrieval.graph_scope', ''),
            'evidence_count' => (int) data_get($graphContext, 'graph_retrieval.evidence_set.evidence_count', 0),
            'bounded_traversal' => (bool) data_get($graphContext, 'graph_retrieval.traversal_receipt.bounded_traversal', false),
            'global_graph_retrieval_active' => (bool) data_get($graphContext, 'graph_retrieval.policy.global_graph_retrieval_active', false),
            'python_runtime_invoked' => (bool) data_get($graphContext, 'graph_retrieval.policy.python_runtime_invoked', false),
            'providers_invoked' => (bool) data_get($graphContext, 'graph_retrieval.policy.providers_invoked', false),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array<string,mixed>
     */
    public function graphCandidateSequencePolicy(array $posts, array $publishedSlugs, string $appendAfterSlug): array
    {
        return [
            'default_suggested_after_slug' => $appendAfterSlug,
            'planned_post_count' => count($posts),
            'published_post_count' => count($publishedSlugs),
            'rule' => 'Graph-derived candidates append after the current planned foundation arc unless Vitor explicitly promotes them elsewhere.',
            'reason' => 'The reader must receive orientation before deep graph, RAG, memory or implementation topics.',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function graphRagReadinessComponents(): array
    {
        $components = [
            [
                'component' => 'ap_811_code_graph_traversal',
                'status' => $this->repoPathExists('docs/ap/AP-811-atlas-code-graph-real-edges-traversal.md') ? 'available' : 'missing',
                'evidence' => 'docs/ap/AP-811-atlas-code-graph-real-edges-traversal.md',
                'role' => 'real code graph traversal contract',
            ],
            [
                'component' => 'ap_812_python_ai_data_runtime',
                'status' => $this->repoPathExists('docs/ap/AP-812-python-ai-data-code-graph-runtime.md') ? 'available' : 'missing',
                'evidence' => 'docs/ap/AP-812-python-ai-data-code-graph-runtime.md',
                'role' => 'runtime boundary for Python/data operations',
            ],
            [
                'component' => 'ap_815_cross_project_context_engine',
                'status' => $this->repoPathExists('docs/ap/AP-815-cross-project-context-engine.md') ? 'available' : 'missing',
                'evidence' => 'docs/ap/AP-815-cross-project-context-engine.md',
                'role' => 'cross-project context contract',
            ],
            [
                'component' => 'agrn_owner_doc',
                'status' => $this->repoPathExists('docs/engineering-knowledge-base/atlas-graph-retrieval-network.md') ? 'available' : 'missing',
                'evidence' => 'docs/engineering-knowledge-base/atlas-graph-retrieval-network.md',
                'role' => 'graph retrieval governance source',
            ],
            [
                'component' => 'runtime_language_boundaries',
                'status' => $this->repoPathExists('docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md') ? 'available' : 'missing',
                'evidence' => 'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
                'role' => 'kernel-first runtime boundary',
            ],
            [
                'component' => 'atlas_graph_retrieval_command',
                'status' => class_exists(\App\Console\Commands\AtlasGraphRetrievalNetworkCommand::class) ? 'available' : 'missing',
                'evidence' => 'app/Console/Commands/AtlasGraphRetrievalNetworkCommand.php',
                'role' => 'bounded graph retrieval CLI',
            ],
            [
                'component' => 'atlas_graph_retrieval_service',
                'status' => class_exists(\App\Services\Ai\Context\AtlasGraphRetrievalNetworkService::class) ? 'available' : 'missing',
                'evidence' => 'app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php',
                'role' => 'bounded graph retrieval service',
            ],
            [
                'component' => 'world_model_graph_ranker',
                'status' => class_exists(\App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker::class) ? 'available' : 'missing',
                'evidence' => 'app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php',
                'role' => 'Codebase World Model relation ranker',
            ],
            [
                'component' => 'graph_rank_runtime_client',
                'status' => class_exists(\App\Services\Ai\RuntimeBoundary\GraphRankRuntimeClient::class) ? 'available' : 'missing',
                'evidence' => 'app/Services/Ai/RuntimeBoundary/GraphRankRuntimeClient.php',
                'role' => 'runtime boundary client',
            ],
            [
                'component' => 'mandatory_rag_gate',
                'status' => class_exists(\App\Services\Ai\Programming\AtlasDev\Gate\MandatoryRagGate::class) ? 'available' : 'missing',
                'evidence' => 'app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php',
                'role' => 'non-trivial context gate pattern',
            ],
            [
                'component' => 'world_model_tables',
                'status' => $this->allTablesAvailable([
                    'ai_codebase_world_models',
                    'ai_codebase_world_model_nodes',
                    'ai_codebase_world_model_edges',
                ]) ? 'available' : 'missing',
                'evidence' => 'database/migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php',
                'role' => 'Codebase World Model storage',
            ],
            [
                'component' => 'mandatory_rag_gate_table',
                'status' => DatabaseTableAvailability::has('ai_mandatory_rag_gates') ? 'available' : 'missing',
                'evidence' => 'database/migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php',
                'role' => 'RAG gate audit storage',
            ],
        ];

        return array_values($components);
    }

    public function repoPathExists(string $relativePath): bool
    {
        return is_file(base_path($relativePath));
    }

    /**
     * @param  array<int,string>  $tables
     */
    public function allTablesAvailable(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! DatabaseTableAvailability::has($table)) {
                return false;
            }
        }

        return true;
    }
}
