<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

use Illuminate\Support\Str;

final class PlanningSection
{
    public function __construct(
        private readonly EditorialSupport $support = new EditorialSupport(),
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>|null  $nextReadyPost
     * @param  array<int,array<string,mixed>>  $blockedPosts
     * @param  array<string,mixed>  $reviewQueueState
     * @param  array<string,mixed>  $backlogMeta
     * @return array<string,mixed>
     */
    public function publishingPlan(array $posts, array $publishedSlugs, ?array $nextReadyPost, array $blockedPosts, array $reviewQueueState, array $backlogMeta): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $blockedSet = array_fill_keys(array_values(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $blockedPosts,
        ))), true);
        $nextSlug = is_array($nextReadyPost) ? (string) ($nextReadyPost['slug'] ?? '') : '';
        $publishingDays = array_values(array_filter((array) ($backlogMeta['publishing_days'] ?? []), 'is_string'));
        $publishingDays = $publishingDays !== [] ? $publishingDays : ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $sortedPosts = collect($posts)
            ->sortBy(fn (array $post): int => (int) ($post['order'] ?? 0))
            ->values()
            ->all();

        $slots = [];
        foreach ($sortedPosts as $index => $post) {
            $slug = (string) ($post['slug'] ?? '');
            $order = (int) ($post['order'] ?? ($index + 1));
            $week = (int) ($post['week'] ?? max(1, (int) ceil($order / max(1, count($publishingDays)))));
            $slotIndex = $index % max(1, count($publishingDays));
            $day = (string) ($publishingDays[$slotIndex] ?? 'day');
            $isPublished = isset($publishedSet[$slug]);
            $isNext = $slug !== '' && $slug === $nextSlug;
            $isBlocked = isset($blockedSet[$slug]);

            $slots[] = [
                'order' => $order,
                'week' => $week,
                'slot_index' => $slotIndex + 1,
                'day' => $day,
                'label' => 'semana '.$week.' · '.$day,
                'slug' => $slug,
                'title' => (string) ($post['title'] ?? ''),
                'complexity_level' => (string) ($post['complexity_level'] ?? ''),
                'collection' => (string) ($post['collection'] ?? ''),
                'series' => (string) ($post['series'] ?? ''),
                'status' => $this->publishingSlotStatus($isPublished, $isNext, $isBlocked),
                'pipeline_stage' => $this->publishingSlotPipelineStage($isPublished, $isNext, $isBlocked),
                'human_action' => $this->publishingSlotHumanAction($isPublished, $isNext, $isBlocked),
                'prerequisites' => array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string')),
                'main_question' => (string) ($post['main_question'] ?? ''),
            ];
        }

        $nextSlots = array_values(array_filter(
            $slots,
            fn (array $slot): bool => ! in_array((string) ($slot['status'] ?? ''), ['published'], true),
        ));

        return [
            'schema_version' => 'atlas.blog_editorial_publishing_plan.v1',
            'mode' => 'read_only_calendar_pipeline_p1',
            'status' => 'ready',
            'cadence' => (string) ($backlogMeta['cadence'] ?? ''),
            'publishing_days' => $publishingDays,
            'buffer_days' => array_values(array_filter((array) ($backlogMeta['buffer_days'] ?? []), 'is_string')),
            'rule' => (string) ($backlogMeta['rule'] ?? 'A ordem importa mais que a data.'),
            'summary' => [
                'slot_count' => count($slots),
                'published_slots' => count(array_filter($slots, fn (array $slot): bool => ($slot['status'] ?? '') === 'published')),
                'ready_slots' => count(array_filter($slots, fn (array $slot): bool => ($slot['status'] ?? '') === 'ready_to_draft')),
                'blocked_slots' => count(array_filter($slots, fn (array $slot): bool => ($slot['status'] ?? '') === 'blocked_by_prerequisite')),
                'review_queue_candidates' => (int) data_get($reviewQueueState, 'candidate_count', 0),
            ],
            'today_lane' => [
                'action' => $nextSlug !== '' ? 'draft_next_ready_post' : 'repair_sequence_blockers',
                'slug' => $nextSlug !== '' ? $nextSlug : null,
                'rule' => 'Um post por dia pode funcionar se cada slot passar por seed privado, rascunho revisado e aprovacao humana antes de publicar.',
            ],
            'next_slots' => array_slice($nextSlots, 0, 10),
            'slots' => $slots,
            'pipeline_rules' => [
                'idea' => 'Candidatos entram na fila de revisao, nunca direto no calendario.',
                'seed' => 'O writing packet gera seed privado sem escrever arquivo.',
                'draft' => 'Rascunho completo e uma fase futura e precisa aprovacao humana.',
                'publish' => 'Publicacao continua manual ou explicitamente aprovada.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'requires_human_approval_to_publish' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @param  array<string,mixed>  $candidateFeed
     * @param  array<string,mixed>  $reviewQueueState
     * @param  array<string,mixed>  $coverageMap
     * @param  array<string,mixed>  $sourceMap
     * @return array<string,mixed>
     */
    public function topicLedger(array $posts, array $publishedSlugs, array $publishedPosts, array $candidateFeed, array $reviewQueueState, array $coverageMap, array $sourceMap): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $rows = $this->topicLedgerRows($posts, $publishedSet, $publishedPosts, $candidateFeed, $reviewQueueState);
        $foundationGaps = array_values(array_filter(
            (array) ($coverageMap['foundation_ladder'] ?? []),
            fn (array $item): bool => ! (bool) ($item['planned'] ?? false) || ! (bool) ($item['published'] ?? false),
        ));
        $opportunities = array_values(array_filter(
            $rows,
            fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['planned', 'candidate_only', 'gap'], true),
        ));

        return [
            'schema_version' => 'atlas.blog_editorial_topic_ledger.v1',
            'mode' => 'read_only_topic_coverage_p1',
            'status' => 'ready',
            'summary' => [
                'topic_count' => count($rows),
                'published_topic_count' => count(array_filter($rows, fn (array $row): bool => (int) ($row['published_count'] ?? 0) > 0)),
                'planned_topic_count' => count(array_filter($rows, fn (array $row): bool => (int) ($row['planned_count'] ?? 0) > 0)),
                'candidate_topic_count' => count(array_filter($rows, fn (array $row): bool => (int) ($row['candidate_count'] ?? 0) > 0)),
                'gap_count' => count(array_filter($rows, fn (array $row): bool => (string) ($row['status'] ?? '') === 'gap')),
                'foundation_gap_count' => count($foundationGaps),
            ],
            'rows' => $rows,
            'foundation_gaps' => array_slice($foundationGaps, 0, 10),
            'next_topic_opportunities' => array_slice($opportunities, 0, 8),
            'source_posture' => [
                'site_backlog' => (string) data_get($sourceMap, 'sources.site_backlog.status', 'unknown'),
                'public_archive' => (string) data_get($sourceMap, 'sources.public_site_archive.status', 'unknown'),
                'engineering_knowledge' => (string) data_get($sourceMap, 'sources.engineering_knowledge.status', 'unknown'),
                'code_intelligence' => (string) data_get($sourceMap, 'sources.code_intelligence.status', 'unknown'),
                'graph_retrieval' => (string) data_get($sourceMap, 'sources.graph_retrieval.status', 'unknown'),
            ],
            'rules' => [
                'A linha do tempo continua sendo a fonte de sequencia; o ledger so mostra cobertura por assunto.',
                'Assuntos candidatos precisam passar por revisao humana antes de entrar no backlog.',
                'Lacunas de fundacao devem ser resolvidas antes de posts profundos dependerem delas.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'requires_human_approval_to_promote' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @param  array<string,mixed>  $candidateFeed
     * @param  array<string,mixed>  $reviewQueueState
     * @return array<int,array<string,mixed>>
     */
    public function topicLedgerRows(array $posts, array $publishedSet, array $publishedPosts, array $candidateFeed, array $reviewQueueState): array
    {
        $topics = [];
        $record = function (string $topic, string $bucket, string $slug, string $title, ?int $order = null) use (&$topics): void {
            $topic = Str::slug(Str::ascii(Str::lower(trim($topic))));
            if ($topic === '') {
                return;
            }

            $topics[$topic] ??= [
                'topic' => $topic,
                'planned_count' => 0,
                'published_count' => 0,
                'candidate_count' => 0,
                'review_queue_count' => 0,
                'planned_slugs' => [],
                'published_slugs' => [],
                'candidate_slugs' => [],
                'review_queue_slugs' => [],
                'first_planned_order' => null,
                'example_title' => '',
            ];

            $countKey = $bucket.'_count';
            $slugKey = $bucket.'_slugs';
            $topics[$topic][$countKey] = (int) ($topics[$topic][$countKey] ?? 0) + 1;
            if ($slug !== '') {
                $topics[$topic][$slugKey] = array_values(array_unique([
                    ...(array) ($topics[$topic][$slugKey] ?? []),
                    $slug,
                ]));
            }
            if ($title !== '' && (string) ($topics[$topic]['example_title'] ?? '') === '') {
                $topics[$topic]['example_title'] = $title;
            }
            if ($bucket === 'planned' && $order !== null) {
                $current = $topics[$topic]['first_planned_order'];
                $topics[$topic]['first_planned_order'] = $current === null ? $order : min((int) $current, $order);
            }
        };

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            $title = (string) ($post['title'] ?? '');
            $order = (int) ($post['order'] ?? 0);
            foreach ($this->support->terms($post) as $topic) {
                $record($topic, 'planned', $slug, $title, $order);
                if (isset($publishedSet[$slug])) {
                    $record($topic, 'published', $slug, $title, $order);
                }
            }
        }

        foreach ($publishedPosts as $post) {
            $slug = (string) ($post['slug'] ?? $post['id'] ?? '');
            $title = (string) ($post['title'] ?? '');
            foreach ($this->support->terms($post) as $topic) {
                $record($topic, 'published', $slug, $title);
            }
        }

        foreach ((array) ($candidateFeed['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $slug = (string) ($candidate['slug'] ?? '');
            $title = (string) ($candidate['title'] ?? '');
            foreach ($this->support->terms($candidate) as $topic) {
                $record($topic, 'candidate', $slug, $title);
            }
        }

        foreach ((array) data_get($reviewQueueState, 'candidates', []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $slug = (string) ($candidate['slug'] ?? '');
            $title = (string) ($candidate['title'] ?? '');
            foreach ($this->support->terms($candidate) as $topic) {
                $record($topic, 'review_queue', $slug, $title);
            }
        }

        $rows = array_values(array_map(function (array $row): array {
            $row['status'] = $this->topicLedgerStatus($row);
            $row['next_action'] = $this->topicLedgerNextAction($row);

            return $row;
        }, $topics));

        usort($rows, fn (array $a, array $b): int => ((int) ($a['first_planned_order'] ?? PHP_INT_MAX) <=> (int) ($b['first_planned_order'] ?? PHP_INT_MAX))
            ?: ((int) ($b['published_count'] ?? 0) <=> (int) ($a['published_count'] ?? 0))
            ?: ((int) ($b['planned_count'] ?? 0) <=> (int) ($a['planned_count'] ?? 0))
            ?: strcmp((string) ($a['topic'] ?? ''), (string) ($b['topic'] ?? '')));

        return array_slice($rows, 0, 80);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    public function topicLedgerStatus(array $row): string
    {
        if ((int) ($row['published_count'] ?? 0) > 0) {
            return 'published';
        }
        if ((int) ($row['planned_count'] ?? 0) > 0) {
            return 'planned';
        }
        if ((int) ($row['review_queue_count'] ?? 0) > 0) {
            return 'in_review';
        }
        if ((int) ($row['candidate_count'] ?? 0) > 0) {
            return 'candidate_only';
        }

        return 'gap';
    }

    /**
     * @param  array<string,mixed>  $row
     */
    public function topicLedgerNextAction(array $row): string
    {
        return match ($this->topicLedgerStatus($row)) {
            'published' => 'use_as_reader_context',
            'planned' => 'draft_when_sequence_reaches_first_planned_post',
            'in_review' => 'decide_if_candidate_enters_backlog',
            'candidate_only' => 'review_candidate_before_promoting',
            default => 'define_foundational_post',
        };
    }

    /**
     * @param  array<string,mixed>  $publishingPlan
     * @param  array<string,mixed>  $topicLedger
     * @return array<string,mixed>
     */
    public function editorialRoadmap(array $publishingPlan, array $topicLedger): array
    {
        $phaseTemplates = $this->editorialRoadmapPhaseTemplates();
        $phases = [];

        foreach ($phaseTemplates as $template) {
            $phases[$template['key']] = $template + [
                'post_count' => 0,
                'published_count' => 0,
                'ready_count' => 0,
                'blocked_count' => 0,
                'planned_count' => 0,
                'posts' => [],
                'topics' => [],
                'status' => 'empty',
                'next_post' => null,
            ];
        }

        foreach ((array) ($publishingPlan['slots'] ?? []) as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $phaseKey = $this->editorialRoadmapPhaseKey($slot);
            $phase = $phases[$phaseKey] ?? $phases['expansao'];
            $status = (string) ($slot['status'] ?? 'planned_future');
            $post = [
                'order' => (int) ($slot['order'] ?? 0),
                'slug' => (string) ($slot['slug'] ?? ''),
                'title' => (string) ($slot['title'] ?? ''),
                'status' => $status,
                'pipeline_stage' => (string) ($slot['pipeline_stage'] ?? ''),
                'human_action' => (string) ($slot['human_action'] ?? ''),
            ];

            $phase['post_count']++;
            $phase['posts'][] = $post;
            $phase['topics'] = array_values(array_unique(array_merge(
                (array) ($phase['topics'] ?? []),
                $this->support->terms($slot),
            )));

            if ($status === 'published') {
                $phase['published_count']++;
            } elseif ($status === 'ready_to_draft') {
                $phase['ready_count']++;
            } elseif ($status === 'blocked_by_prerequisite') {
                $phase['blocked_count']++;
            } else {
                $phase['planned_count']++;
            }

            if ($phase['next_post'] === null && $status !== 'published') {
                $phase['next_post'] = $post;
            }

            $phases[$phaseKey] = $phase;
        }

        $phases = array_values(array_map(function (array $phase): array {
            $phase['topics'] = array_slice((array) ($phase['topics'] ?? []), 0, 8);
            $phase['posts'] = array_slice((array) ($phase['posts'] ?? []), 0, 12);
            $phase['status'] = $this->editorialRoadmapPhaseStatus($phase);

            return $phase;
        }, $phases));

        $activePhase = collect($phases)->first(fn (array $phase): bool => in_array((string) ($phase['status'] ?? ''), ['active', 'blocked', 'planned'], true));
        $nextTopicRows = array_slice((array) ($topicLedger['next_topic_opportunities'] ?? []), 0, 8);

        return [
            'schema_version' => 'atlas.blog_editorial_roadmap.v1',
            'mode' => 'read_only_reader_journey_p1',
            'status' => 'ready',
            'summary' => [
                'phase_count' => count($phases),
                'active_phase_key' => is_array($activePhase) ? (string) ($activePhase['key'] ?? '') : null,
                'active_phase_label' => is_array($activePhase) ? (string) ($activePhase['label'] ?? '') : null,
                'planned_posts' => (int) data_get($publishingPlan, 'summary.slot_count', 0),
                'published_posts' => (int) data_get($publishingPlan, 'summary.published_slots', 0),
                'topic_count' => (int) data_get($topicLedger, 'summary.topic_count', 0),
            ],
            'phases' => $phases,
            'next_topic_opportunities' => $nextTopicRows,
            'rules' => [
                'A jornada do leitor vai do raso ao profundo; fases explicam a intencao, nao substituem a ordem.',
                'Uma fase bloqueada exige publicar prerequisitos antes de aprofundar.',
                'Novas colecoes podem entrar depois, mas precisam de sua propria fundacao publica.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>  $publishingPlan
     * @param  array<string,mixed>  $editorialRoadmap
     * @return array<string,mixed>
     */
    public function editorialDependencyMatrix(array $posts, array $publishedSlugs, array $publishingPlan, array $editorialRoadmap): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $phaseBySlug = $this->editorialPhaseBySlug($editorialRoadmap);
        $slots = array_values(array_filter(
            (array) ($publishingPlan['slots'] ?? []),
            'is_array',
        ));
        usort($slots, fn (array $a, array $b): int => (int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0));

        $rows = [];
        $blockedCount = 0;
        $currentSlug = null;
        $foundationWarnings = 0;

        foreach ($slots as $index => $slot) {
            $slug = (string) ($slot['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $post = $this->support->plannedPostBySlug($posts, $slug) ?? $slot;
            $progression = $this->support->conceptProgressionMap($post, $posts, $publishedSlugs);
            $prerequisites = array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string'));
            $missingPrerequisites = array_values(array_filter(
                $prerequisites,
                fn (string $prerequisite): bool => ! isset($publishedSet[$prerequisite]),
            ));
            $status = (string) ($slot['status'] ?? 'planned_future');
            $readiness = $this->editorialDependencyReadiness($status, $missingPrerequisites);
            $depth = $this->support->editorialDepthFromLevel((string) ($post['complexity_level'] ?? $slot['complexity_level'] ?? ''));
            $priorCount = (int) data_get($progression, 'reader_state.planned_prior_count', 0);
            $phase = $phaseBySlug[$slug] ?? [
                'key' => $this->editorialRoadmapPhaseKey($slot),
                'label' => 'Expansao',
                'position' => 7,
            ];
            $warning = $depth >= 3 && $priorCount < 3;

            if ($readiness === 'blocked_missing_prerequisites') {
                $blockedCount++;
            }
            if ($currentSlug === null && $readiness === 'current_unlocked') {
                $currentSlug = $slug;
            }
            if ($warning) {
                $foundationWarnings++;
            }

            $rows[] = [
                'order' => (int) ($slot['order'] ?? $post['order'] ?? 0),
                'slug' => $slug,
                'title' => (string) ($post['title'] ?? $slot['title'] ?? ''),
                'status' => $status,
                'readiness' => $readiness,
                'complexity_level' => (string) ($post['complexity_level'] ?? $slot['complexity_level'] ?? ''),
                'depth' => $depth,
                'phase' => [
                    'key' => (string) ($phase['key'] ?? 'expansao'),
                    'label' => (string) ($phase['label'] ?? 'Expansao'),
                    'position' => (int) ($phase['position'] ?? 7),
                ],
                'depends_on' => [
                    'previous_slug' => isset($slots[$index - 1]) ? (string) ($slots[$index - 1]['slug'] ?? '') : null,
                    'next_slug' => isset($slots[$index + 1]) ? (string) ($slots[$index + 1]['slug'] ?? '') : null,
                    'explicit_prerequisites' => $prerequisites,
                    'missing_prerequisites' => $missingPrerequisites,
                ],
                'reader_contract' => [
                    'must_introduce' => array_slice((array) ($progression['current_terms'] ?? []), 0, 8),
                    'already_available' => array_slice((array) ($progression['introduced_terms'] ?? []), 0, 10),
                    'published_available' => array_slice((array) ($progression['published_prior_terms'] ?? []), 0, 10),
                    'avoid_until_later' => array_slice((array) ($progression['future_terms_to_avoid'] ?? []), 0, 10),
                    'rule' => 'O texto so deve exigir conceitos ja publicados, prerequisitos ou introduzidos no proprio texto.',
                ],
                'position_reason' => $this->editorialDependencyPositionReason($post, $phase, $missingPrerequisites, $warning),
                'depth_warning' => $warning ? 'advanced_topic_before_enough_foundation' : null,
            ];
        }

        return [
            'schema_version' => 'atlas.blog_editorial_dependency_matrix.v1',
            'mode' => 'read_only_prerequisite_ladder_p1',
            'status' => 'ready',
            'summary' => [
                'post_count' => count($rows),
                'current_unlocked_slug' => $currentSlug,
                'blocked_post_count' => $blockedCount,
                'foundation_warning_count' => $foundationWarnings,
                'phase_count' => (int) data_get($editorialRoadmap, 'summary.phase_count', 0),
            ],
            'rows' => array_slice($rows, 0, 80),
            'rules' => [
                'A matriz explica dependencias; ela nao altera a fila.',
                'Um assunto profundo precisa de fundacao publica ou prerequisitos explicitos antes de virar rascunho.',
                'Graph/RAG pode sugerir contexto, mas nao pode reordenar ou publicar sem promocao governada.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $editorialRoadmap
     * @return array<string,array<string,mixed>>
     */
    public function editorialPhaseBySlug(array $editorialRoadmap): array
    {
        $phaseBySlug = [];

        foreach ((array) ($editorialRoadmap['phases'] ?? []) as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            foreach ((array) ($phase['posts'] ?? []) as $post) {
                if (! is_array($post)) {
                    continue;
                }

                $slug = (string) ($post['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }

                $phaseBySlug[$slug] = [
                    'key' => (string) ($phase['key'] ?? ''),
                    'label' => (string) ($phase['label'] ?? ''),
                    'position' => (int) ($phase['position'] ?? 0),
                ];
            }
        }

        return $phaseBySlug;
    }

    /**
     * @param  array<int,string>  $missingPrerequisites
     */
    public function editorialDependencyReadiness(string $status, array $missingPrerequisites): string
    {
        if ($status === 'published') {
            return 'published_reference';
        }
        if ($missingPrerequisites !== []) {
            return 'blocked_missing_prerequisites';
        }
        if ($status === 'ready_to_draft') {
            return 'current_unlocked';
        }

        return 'planned_locked_by_sequence';
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<string,mixed>  $phase
     * @param  array<int,string>  $missingPrerequisites
     */
    public function editorialDependencyPositionReason(array $post, array $phase, array $missingPrerequisites, bool $warning): string
    {
        if ($missingPrerequisites !== []) {
            return 'Ainda depende de textos anteriores: '.implode(', ', $missingPrerequisites).'.';
        }

        if ($warning) {
            return 'Tema profundo detectado antes de fundacao suficiente; manter como alerta de revisao.';
        }

        $label = (string) ($phase['label'] ?? 'fase atual');
        $question = (string) ($post['main_question'] ?? '');

        return $question !== ''
            ? 'Pertence a '.$label.' porque responde: '.$question
            : 'Pertence a '.$label.' e deve preservar a progressao do leitor.';
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    public function editorialRoadmapPhaseTemplates(): array
    {
        return [
            ['key' => 'fundacao', 'position' => 1, 'label' => 'Fundacao', 'intent' => 'Apresentar quem e Vitor, o que e o Atlas e por que o leitor deveria se importar.'],
            ['key' => 'problema_contexto', 'position' => 2, 'label' => 'Problema e contexto', 'intent' => 'Explicar a falha dos assistentes genericos antes de propor uma arquitetura.'],
            ['key' => 'local_privacidade', 'position' => 3, 'label' => 'Local-first e controle', 'intent' => 'Construir confianca: privacidade, posse, limites e controle operacional.'],
            ['key' => 'memoria_conhecimento', 'position' => 4, 'label' => 'Memoria e conhecimento', 'intent' => 'Aprofundar memoria, ledger, conhecimento e esquecimento governado.'],
            ['key' => 'agentes_governanca', 'position' => 5, 'label' => 'Agentes e governanca', 'intent' => 'Mostrar agentes como sistemas com mandato, limites, sandbox e auditoria.'],
            ['key' => 'arquitetura_operacao', 'position' => 6, 'label' => 'Arquitetura e operacao', 'intent' => 'Abrir os bastidores tecnicos sem exigir que o leitor pule etapas.'],
            ['key' => 'expansao', 'position' => 7, 'label' => 'Expansao', 'intent' => 'Receber novos temas, colecoes e projetos quando tiverem fundacao suficiente.'],
        ];
    }

    /**
     * @param  array<string,mixed>  $slot
     */
    public function editorialRoadmapPhaseKey(array $slot): string
    {
        $slug = (string) ($slot['slug'] ?? '');
        $terms = $this->support->terms($slot);
        $text = Str::lower(Str::ascii(implode(' ', array_merge([$slug, (string) ($slot['title'] ?? '')], $terms))));
        $order = (int) ($slot['order'] ?? 0);
        $level = (string) ($slot['complexity_level'] ?? '');

        if ($order <= 2 || $level === 'L0' || str_contains($text, 'vocabulario') || str_contains($text, 'construindo')) {
            return 'fundacao';
        }
        if (str_contains($text, 'assistente') || str_contains($text, 'chatbot') || str_contains($text, 'contexto')) {
            return 'problema_contexto';
        }
        if (str_contains($text, 'local-first') || str_contains($text, 'privacidade') || str_contains($text, 'controle')) {
            return 'local_privacidade';
        }
        if (str_contains($text, 'memoria') || str_contains($text, 'ledger') || str_contains($text, 'conhecimento') || str_contains($text, 'esquecer')) {
            return 'memoria_conhecimento';
        }
        if (str_contains($text, 'agente') || str_contains($text, 'mandato') || str_contains($text, 'sandbox')) {
            return 'agentes_governanca';
        }
        if (str_contains($text, 'arquitetura') || str_contains($text, 'runtime') || str_contains($text, 'graph') || str_contains($text, 'rag') || str_contains($text, 'codigo')) {
            return 'arquitetura_operacao';
        }

        return 'expansao';
    }

    /**
     * @param  array<string,mixed>  $phase
     */
    public function editorialRoadmapPhaseStatus(array $phase): string
    {
        if ((int) ($phase['post_count'] ?? 0) === 0) {
            return 'empty';
        }
        if ((int) ($phase['ready_count'] ?? 0) > 0) {
            return 'active';
        }
        if ((int) ($phase['blocked_count'] ?? 0) > 0) {
            return 'blocked';
        }
        if ((int) ($phase['planned_count'] ?? 0) > 0) {
            return 'planned';
        }

        return 'complete';
    }

    public function publishingSlotStatus(bool $published, bool $next, bool $blocked): string
    {
        if ($published) {
            return 'published';
        }

        if ($next) {
            return 'ready_to_draft';
        }

        return $blocked ? 'blocked_by_prerequisite' : 'planned_future';
    }

    public function publishingSlotPipelineStage(bool $published, bool $next, bool $blocked): string
    {
        if ($published) {
            return 'public_archive';
        }

        if ($next) {
            return 'private_seed_ready';
        }

        return $blocked ? 'waiting_prerequisites' : 'scheduled_later';
    }

    public function publishingSlotHumanAction(bool $published, bool $next, bool $blocked): string
    {
        if ($published) {
            return 'monitor_archive_and_link_next_reading';
        }

        if ($next) {
            return 'review_seed_then_request_private_draft';
        }

        return $blocked ? 'publish_prerequisites_first' : 'keep_in_sequence';
    }
}
