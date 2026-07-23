<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Support\DatabaseTableAvailability;

final class CoverageSection
{
    public function __construct(
        private readonly EditorialSupport $support = new EditorialSupport(),
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @return array<string,mixed>
     */
    public function sourceMap(array $posts, array $publishedSlugs = [], array $publishedPosts = []): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $plannedSlugs = array_values(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $posts,
        )));
        $plannedSet = array_fill_keys($plannedSlugs, true);
        $plannedPublishedCount = count(array_filter(
            $plannedSlugs,
            fn (string $slug): bool => isset($publishedSet[$slug]),
        ));
        $externalPublishedCount = count(array_filter(
            $publishedSlugs,
            fn (string $slug): bool => ! isset($plannedSet[$slug]),
        ));
        $readyCount = 0;
        $blockedCount = 0;

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug === '' || isset($publishedSet[$slug])) {
                continue;
            }

            $prerequisites = array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string'));
            $missingPrerequisites = array_values(array_filter(
                $prerequisites,
                fn (string $prerequisite): bool => ! isset($publishedSet[$prerequisite]),
            ));
            $unknownPrerequisites = array_values(array_filter(
                $prerequisites,
                fn (string $prerequisite): bool => ! isset($plannedSet[$prerequisite]) && ! isset($publishedSet[$prerequisite]),
            ));

            if ($missingPrerequisites === [] && $unknownPrerequisites === []) {
                $readyCount++;
            } else {
                $blockedCount++;
            }
        }

        $knowledgeAvailable = DatabaseTableAvailability::has('atlas_engineering_knowledge_items');
        $modulesAvailable = DatabaseTableAvailability::has('atlas_engineering_code_modules');
        $symbolsAvailable = DatabaseTableAvailability::has('atlas_engineering_code_symbols');
        $archiveReconciliation = $this->archiveReconciliation($posts, $publishedSlugs, $publishedPosts);

        return [
            'schema_version' => 'atlas.blog_editorial_source_map.v1',
            'mode' => 'read_only_source_readiness_p1',
            'status' => 'ready',
            'archive_state' => [
                'planned_posts' => count($posts),
                'planned_published_posts' => $plannedPublishedCount,
                'public_archive_posts' => count($publishedSlugs),
                'external_published_posts' => $externalPublishedCount,
                'ready_unpublished_posts' => $readyCount,
                'blocked_unpublished_posts' => $blockedCount,
                'rule' => 'Published and planned state must gate depth before Atlas context is used for new posts.',
            ],
            'archive_reconciliation' => $archiveReconciliation,
            'sources' => [
                'site_backlog' => [
                    'status' => 'ready',
                    'role' => 'primary_public_sequence',
                    'planned_count' => count($posts),
                    'writes_allowed' => false,
                ],
                'public_site_archive' => [
                    'status' => 'ready',
                    'role' => 'published_state',
                    'published_count' => count($publishedSlugs),
                    'planned_published_count' => $plannedPublishedCount,
                    'external_published_count' => $externalPublishedCount,
                    'metadata_available' => $publishedPosts !== [],
                    'writes_allowed' => false,
                ],
                'engineering_knowledge' => [
                    'status' => $knowledgeAvailable ? 'ready' : 'unavailable',
                    'role' => 'canonical_docs_reference',
                    'table' => 'atlas_engineering_knowledge_items',
                    'row_count' => $knowledgeAvailable ? AtlasEngineeringKnowledgeItem::query()->count() : 0,
                    'writes_allowed' => false,
                ],
                'code_intelligence' => [
                    'status' => ($modulesAvailable || $symbolsAvailable) ? 'ready' : 'unavailable',
                    'role' => 'code_reality_reference',
                    'module_count' => $modulesAvailable ? AtlasEngineeringCodeModule::query()->count() : 0,
                    'symbol_count' => $symbolsAvailable ? AtlasEngineeringCodeSymbol::query()->count() : 0,
                    'writes_allowed' => false,
                ],
                'open_brain_context_pack' => [
                    'status' => 'available_contract',
                    'role' => 'provider_safe_context_composition',
                    'invocation' => 'future_kernel_or_cli_handoff',
                    'invoked_by_this_command' => false,
                    'owner_doc' => 'docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md',
                ],
                'vector_retrieval' => [
                    'status' => 'available_through_open_brain',
                    'role' => 'semantic_similarity_reference',
                    'direct_invocation_allowed' => false,
                    'invoked_by_this_command' => false,
                ],
                'graph_retrieval' => [
                    'status' => 'future_governed',
                    'role' => 'relationship_dependency_context',
                    'blocked_until' => [
                        'AP-817 review',
                        'Kernel decision receipt',
                        'runtime-language-boundaries compliance',
                    ],
                    'invoked_by_this_command' => false,
                ],
            ],
            'post_source_directives' => array_values(array_map(
                fn (array $post): array => $this->postSourceDirective($post),
                $posts,
            )),
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'purpose' => 'Expose which Atlas sources can inform editorial planning without pretending graph/RAG is already enabled.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    public function postSourceDirective(array $post): array
    {
        $level = (string) ($post['complexity_level'] ?? '');
        $collection = (string) ($post['collection'] ?? '');
        $topics = array_values(array_filter((array) ($post['topics'] ?? []), 'is_string'));
        $requiresCodeReality = in_array($level, ['L2', 'L3', 'L4', 'L5'], true)
            || in_array($collection, ['atlas', 'arquitetura', 'architecture'], true)
            || array_intersect($topics, ['codigo', 'code', 'arquitetura', 'architecture', 'agentes', 'agents']) !== [];

        $requiredSources = [
            'site_backlog',
            'public_site_archive',
            'engineering_knowledge',
        ];

        if ($requiresCodeReality) {
            $requiredSources[] = 'code_intelligence';
        }

        $recommendedSources = [
            'open_brain_context_pack',
        ];

        if (in_array($level, ['L3', 'L4', 'L5'], true)) {
            $recommendedSources[] = 'vector_retrieval';
        }

        return [
            'slug' => (string) ($post['slug'] ?? ''),
            'order' => (int) ($post['order'] ?? 0),
            'complexity_level' => $level,
            'collection' => $collection,
            'context_query' => $this->support->contextQuery($post),
            'required_sources' => array_values(array_unique($requiredSources)),
            'recommended_sources' => array_values(array_unique($recommendedSources)),
            'deferred_sources' => [
                'graph_retrieval',
            ],
            'reason' => $requiresCodeReality
                ? 'Technical or Atlas-specific post needs code reality before deeper claims.'
                : 'Introductory post can stay anchored in public sequence and canonical docs.',
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @return array<string,mixed>
     */
    public function publicArchiveContextForPost(array $post, array $posts, array $publishedSlugs, array $publishedPosts): array
    {
        $slug = (string) ($post['slug'] ?? '');
        $reconciliation = $this->archiveReconciliation($posts, $publishedSlugs, $publishedPosts);
        $bridges = array_values(array_filter(
            (array) ($reconciliation['bridge_candidates'] ?? []),
            fn (array $candidate): bool => (string) ($candidate['matched_planned_slug'] ?? '') === $slug,
        ));
        $alreadyPublished = array_values(array_filter(
            (array) ($reconciliation['planned_published'] ?? []),
            fn (array $publishedPost): bool => (string) ($publishedPost['slug'] ?? '') === $slug,
        ));

        return [
            'schema_version' => 'atlas.blog_editorial_public_archive_context.v1',
            'status' => 'ready',
            'post_slug' => $slug,
            'already_published' => $alreadyPublished,
            'prior_public_artifacts' => $bridges,
            'duplicate_risk_count' => count(array_filter(
                $bridges,
                fn (array $candidate): bool => (string) ($candidate['suggested_action'] ?? '') === 'review_for_duplicate_or_rewrite',
            )),
            'linkable_artifact_count' => count(array_filter(
                $bridges,
                fn (array $candidate): bool => (string) ($candidate['suggested_action'] ?? '') === 'link_as_prior_artifact',
            )),
            'rule' => 'Use public archive matches as writing context; do not silently treat them as completed prerequisites or duplicate replacements.',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @return array<string,mixed>
     */
    public function archiveReconciliation(array $posts, array $publishedSlugs, array $publishedPosts): array
    {
        $plannedBySlug = [];
        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug !== '') {
                $plannedBySlug[$slug] = $post;
            }
        }

        $publishedBySlug = [];
        foreach ($publishedPosts as $publishedPost) {
            $slug = (string) ($publishedPost['slug'] ?? '');
            if ($slug !== '') {
                $publishedBySlug[$slug] = $publishedPost;
            }
        }

        if ($publishedBySlug === []) {
            foreach ($publishedSlugs as $slug) {
                $publishedBySlug[$slug] = ['slug' => $slug];
            }
        }

        $plannedPublished = [];
        $externalPublished = [];
        foreach ($publishedBySlug as $slug => $publishedPost) {
            if (isset($plannedBySlug[$slug])) {
                $plannedPublished[] = $this->publishedPostSummary($publishedPost, $plannedBySlug[$slug]);
                continue;
            }

            $externalPublished[] = $this->publishedPostSummary($publishedPost);
        }

        usort($plannedPublished, fn (array $a, array $b): int => ((int) ($a['planned_order'] ?? 9999)) <=> ((int) ($b['planned_order'] ?? 9999)));
        usort($externalPublished, fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        return [
            'schema_version' => 'atlas.blog_editorial_archive_reconciliation.v1',
            'status' => 'ready',
            'planned_published_count' => count($plannedPublished),
            'external_published_count' => count($externalPublished),
            'external_by_kind' => $this->countPublishedByField($externalPublished, 'kind'),
            'external_by_collection' => $this->countPublishedByField($externalPublished, 'collection'),
            'planned_published' => array_values($plannedPublished),
            'external_published' => array_values($externalPublished),
            'bridge_candidates' => $this->archiveBridgeCandidates($posts, $externalPublished),
            'rule' => 'External public posts are not treated as ordered prerequisites automatically; they can be linked as prior artifacts or reviewed for duplication risk.',
        ];
    }

    /**
     * @param  array<string,mixed>  $publishedPost
     * @param  array<string,mixed>|null  $plannedPost
     * @return array<string,mixed>
     */
    public function publishedPostSummary(array $publishedPost, ?array $plannedPost = null): array
    {
        $titlePt = (string) ($publishedPost['title_pt'] ?? '');
        $titleEn = (string) ($publishedPost['title_en'] ?? '');
        $title = $titlePt !== '' ? $titlePt : $titleEn;

        return [
            'slug' => (string) ($publishedPost['slug'] ?? ''),
            'title' => $title,
            'title_pt' => $titlePt,
            'title_en' => $titleEn,
            'kind' => (string) ($publishedPost['kind'] ?? ''),
            'date' => (string) ($publishedPost['date'] ?? ''),
            'collection' => (string) ($publishedPost['collection'] ?? ''),
            'series' => (string) ($publishedPost['series'] ?? ''),
            'tags' => array_values(array_filter((array) ($publishedPost['tags'] ?? []), 'is_string')),
            'planned_order' => $plannedPost !== null ? (int) ($plannedPost['order'] ?? 0) : null,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,array<string,mixed>>  $externalPublished
     * @return array<int,array<string,mixed>>
     */
    public function archiveBridgeCandidates(array $posts, array $externalPublished): array
    {
        $candidates = [];

        foreach ($externalPublished as $publishedPost) {
            $publishedTerms = $this->support->publishedTerms($publishedPost);
            if ($publishedTerms === []) {
                continue;
            }

            $best = null;
            foreach ($posts as $post) {
                $plannedTerms = $this->support->terms($post);
                $overlap = array_values(array_intersect($publishedTerms, $plannedTerms));
                $score = count($overlap);

                if ($score < 2) {
                    continue;
                }

                if ($best === null || $score > (int) $best['score']) {
                    $best = [
                        'score' => $score,
                        'matched_terms' => $overlap,
                        'planned_slug' => (string) ($post['slug'] ?? ''),
                        'planned_title' => (string) ($post['title'] ?? ''),
                        'planned_order' => (int) ($post['order'] ?? 0),
                    ];
                }
            }

            if ($best === null) {
                continue;
            }

            $candidates[] = [
                'published_slug' => (string) ($publishedPost['slug'] ?? ''),
                'published_title' => (string) ($publishedPost['title'] ?? ''),
                'published_date' => (string) ($publishedPost['date'] ?? ''),
                'matched_planned_slug' => $best['planned_slug'],
                'matched_planned_title' => $best['planned_title'],
                'matched_planned_order' => $best['planned_order'],
                'score' => $best['score'],
                'matched_terms' => $best['matched_terms'],
                'suggested_action' => $this->archiveBridgeAction((int) $best['score'], (string) ($publishedPost['slug'] ?? ''), (string) $best['planned_slug']),
            ];
        }

        usort($candidates, fn (array $a, array $b): int => ((int) $b['score'] <=> (int) $a['score'])
            ?: ((int) $a['matched_planned_order'] <=> (int) $b['matched_planned_order'])
            ?: strcmp((string) $a['published_slug'], (string) $b['published_slug']));

        return array_slice($candidates, 0, 20);
    }

    public function archiveBridgeAction(int $score, string $publishedSlug, string $plannedSlug): string
    {
        if ($publishedSlug === $plannedSlug) {
            return 'mark_as_already_published';
        }

        if ($score >= 4) {
            return 'review_for_duplicate_or_rewrite';
        }

        return 'link_as_prior_artifact';
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,int>
     */
    public function countPublishedByField(array $posts, string $field): array
    {
        $counts = [];
        foreach ($posts as $post) {
            $value = (string) ($post[$field] ?? '');
            if ($value === '') {
                $value = 'unknown';
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string,bool>  $plannedSet
     * @param  array<string,bool>  $publishedSet
     * @return array<int,array<string,mixed>>
     */
    public function foundationCoverage(array $plannedSet, array $publishedSet): array
    {
        $items = [
            ['key' => 'atlas_identity', 'slug' => 'o-que-e-o-atlas', 'level' => 'L0', 'label' => 'O que e o Atlas'],
            ['key' => 'builder_motivation', 'slug' => 'por-que-estou-construindo-o-atlas', 'level' => 'L0', 'label' => 'Por que o Atlas existe'],
            ['key' => 'assistant_problem', 'slug' => 'o-problema-dos-assistentes-de-ia-hoje', 'level' => 'L1', 'label' => 'Problema dos assistentes atuais'],
            ['key' => 'personal_system', 'slug' => 'a-diferenca-entre-chatbot-e-sistema-pessoal', 'level' => 'L1', 'label' => 'Chatbot vs sistema pessoal'],
            ['key' => 'atlas_boundaries', 'slug' => 'o-que-o-atlas-nao-e', 'level' => 'L0', 'label' => 'O que o Atlas nao e'],
            ['key' => 'context_need', 'slug' => 'por-que-ia-pessoal-precisa-conhecer-contexto', 'level' => 'L1', 'label' => 'Contexto como base'],
            ['key' => 'local_first', 'slug' => 'o-que-significa-local-first', 'level' => 'L1', 'label' => 'Local-first'],
            ['key' => 'privacy', 'slug' => 'por-que-privacidade-muda-tudo', 'level' => 'L1', 'label' => 'Privacidade'],
            ['key' => 'control', 'slug' => 'por-que-controle-importa-mais-que-conveniencia', 'level' => 'L1', 'label' => 'Controle'],
            ['key' => 'vocabulary', 'slug' => 'o-vocabulario-do-atlas', 'level' => 'L0', 'label' => 'Vocabulario publico'],
            ['key' => 'memory_problem', 'slug' => 'o-problema-da-memoria-em-ia', 'level' => 'L1', 'label' => 'Problema da memoria'],
            ['key' => 'remembering_too_much', 'slug' => 'por-que-lembrar-tudo-e-ruim', 'level' => 'L2', 'label' => 'Falha da memoria total'],
            ['key' => 'memory_vs_knowledge', 'slug' => 'a-diferenca-entre-conversa-memoria-e-conhecimento', 'level' => 'L2', 'label' => 'Conversa, memoria e conhecimento'],
            ['key' => 'memory_database', 'slug' => 'memoria-como-problema-de-banco-de-dados', 'level' => 'L3', 'label' => 'Memoria como banco de dados'],
            ['key' => 'memory_ledger', 'slug' => 'memoria-como-ledger', 'level' => 'L3', 'label' => 'Memoria como ledger'],
            ['key' => 'agents_intro', 'slug' => 'o-que-sao-agentes-no-atlas', 'level' => 'L1', 'label' => 'Agentes no Atlas'],
            ['key' => 'agent_limits', 'slug' => 'por-que-agentes-precisam-de-limites', 'level' => 'L2', 'label' => 'Limites para agentes'],
            ['key' => 'agent_mandates', 'slug' => 'o-que-e-um-mandato-de-agente', 'level' => 'L3', 'label' => 'Mandato de agente'],
            ['key' => 'sandbox_dry_run', 'slug' => 'por-que-dry-run-e-sandbox-importam', 'level' => 'L3', 'label' => 'Dry-run e sandbox'],
            ['key' => 'evolution', 'slug' => 'como-o-atlas-esta-evoluindo', 'level' => 'L1', 'label' => 'Evolucao do Atlas'],
        ];

        return array_map(fn (array $item): array => $item + [
            'planned' => isset($plannedSet[$item['slug']]),
            'published' => isset($publishedSet[$item['slug']]),
        ], $items);
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @return array<int,array<string,mixed>>
     */
    public function topicIndex(array $posts, array $publishedSet): array
    {
        $topics = [];

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            foreach ($this->support->terms($post) as $topic) {
                $topics[$topic] ??= [
                    'topic' => $topic,
                    'planned_count' => 0,
                    'published_count' => 0,
                    'first_order' => (int) ($post['order'] ?? 0),
                    'slugs' => [],
                ];

                $topics[$topic]['planned_count']++;
                if (isset($publishedSet[$slug])) {
                    $topics[$topic]['published_count']++;
                }
                $topics[$topic]['first_order'] = min((int) $topics[$topic]['first_order'], (int) ($post['order'] ?? 0));
                $topics[$topic]['slugs'][] = $slug;
            }
        }

        usort($topics, fn (array $a, array $b): int => ((int) $b['planned_count'] <=> (int) $a['planned_count'])
            ?: ((int) $a['first_order'] <=> (int) $b['first_order'])
            ?: strcmp((string) $a['topic'], (string) $b['topic']));

        return array_slice(array_values($topics), 0, 40);
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,int>
     */
    public function countByField(array $posts, string $field): array
    {
        $counts = [];
        foreach ($posts as $post) {
            $value = (string) ($post[$field] ?? '');
            if ($value === '') {
                $value = 'unknown';
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<int,array<string,mixed>>
     */
    public function deepSequenceWarnings(array $posts): array
    {
        $warnings = [];
        $seenSeriesFoundation = [];
        $seenCollectionFoundation = [];

        foreach ($posts as $post) {
            $level = (string) ($post['complexity_level'] ?? '');
            $series = (string) ($post['series'] ?? '');
            $collection = (string) ($post['collection'] ?? '');
            $isFoundation = in_array($level, ['L0', 'L1'], true);

            if ($isFoundation) {
                if ($series !== '') {
                    $seenSeriesFoundation[$series] = true;
                }
                if ($collection !== '') {
                    $seenCollectionFoundation[$collection] = true;
                }
            }

            if (! in_array($level, ['L3', 'L4', 'L5'], true)) {
                continue;
            }

            if ($series !== '' && ! isset($seenSeriesFoundation[$series]) && $collection !== '' && ! isset($seenCollectionFoundation[$collection])) {
                $warnings[] = [
                    'code' => 'deep_post_without_prior_foundation',
                    'slug' => (string) ($post['slug'] ?? ''),
                    'order' => (int) ($post['order'] ?? 0),
                    'level' => $level,
                    'series' => $series,
                    'collection' => $collection,
                ];
            }
        }

        return $warnings;
    }

    /**
     * @param  array<int,array<string,mixed>>  $foundation
     * @param  array<string,bool>  $plannedSet
     * @return array<int,array<string,mixed>>
     */
    public function nextSafeArcs(array $foundation, array $plannedSet): array
    {
        $foundationComplete = count(array_filter($foundation, fn (array $item): bool => ! (bool) $item['planned'])) === 0;
        $arcs = [
            [
                'arc' => 'knowledge_governance',
                'title' => 'Governanca de conhecimento',
                'first_post_slug' => 'governanca-de-conhecimento-no-atlas',
                'why_now' => 'Depois de memoria e agentes, o leitor pode entender como conhecimento entra, amadurece e expira.',
                'required_foundation' => ['memory_ledger', 'agent_mandates', 'sandbox_dry_run'],
            ],
            [
                'arc' => 'capture_inbox',
                'title' => 'Inbox de captura',
                'first_post_slug' => 'a-inbox-de-captura-do-atlas',
                'why_now' => 'Apos a base de memoria, faz sentido mostrar como informacao entra no sistema.',
                'required_foundation' => ['context_need', 'memory_vs_knowledge', 'memory_ledger'],
            ],
            [
                'arc' => 'architecture_deep_dive',
                'title' => 'Arquitetura do Atlas',
                'first_post_slug' => 'o-modelo-de-sessao-do-atlas',
                'why_now' => 'Arquitetura profunda so deve vir depois da linguagem publica de contexto, memoria e agentes.',
                'required_foundation' => ['vocabulary', 'memory_database', 'memory_ledger', 'agents_intro'],
            ],
            [
                'arc' => 'product_ux',
                'title' => 'Produto e UX',
                'first_post_slug' => 'por-que-ia-pessoal-precisa-de-ux-calma',
                'why_now' => 'A base tecnica permite discutir experiencia sem parecer marketing solto.',
                'required_foundation' => ['atlas_identity', 'atlas_boundaries', 'control'],
            ],
        ];

        $foundationByKey = [];
        foreach ($foundation as $item) {
            $foundationByKey[(string) $item['key']] = (bool) $item['planned'];
        }

        return array_map(function (array $arc) use ($foundationByKey, $foundationComplete, $plannedSet): array {
            $missing = array_values(array_filter(
                (array) $arc['required_foundation'],
                fn (string $key): bool => ! ($foundationByKey[$key] ?? false),
            ));

            return $arc + [
                'ready_after_first_month' => $foundationComplete && $missing === [] && ! isset($plannedSet[(string) $arc['first_post_slug']]),
                'missing_foundation' => $missing,
                'already_planned' => isset($plannedSet[(string) $arc['first_post_slug']]),
            ];
        }, $arcs);
    }
}
