<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

final class OperationsSection
{
    public function __construct(
        private readonly EditorialSupport $support = new EditorialSupport(),
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>  $candidateFeed
     * @param  array<string,mixed>  $reviewQueueState
     * @param  array<string,mixed>  $dependencyMatrix
     * @return array<string,mixed>
     */
    public function backlogIntake(array $posts, array $publishedSlugs, array $candidateFeed, array $reviewQueueState, array $dependencyMatrix): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $seen = [];
        $items = [];
        $blockedLadder = (int) data_get($dependencyMatrix, 'summary.blocked_post_count', 0) > 0;

        $appendCandidate = function (array $candidate, string $lane) use (&$items, &$seen, $posts, $publishedSet, $blockedLadder): void {
            $slug = (string) ($candidate['slug'] ?? '');
            if ($slug === '' || isset($seen[$slug])) {
                return;
            }

            $seen[$slug] = true;
            $items[] = $this->backlogIntakeItem($candidate, $lane, $posts, $publishedSet, $blockedLadder);
        };

        foreach ((array) data_get($reviewQueueState, 'candidates', []) as $candidate) {
            if (is_array($candidate)) {
                $appendCandidate($candidate, 'review_queue');
            }
        }

        foreach ((array) ($candidateFeed['candidates'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $appendCandidate($candidate, 'candidate_feed');
            }
        }

        $items = array_slice($items, 0, 12);

        return [
            'schema_version' => 'atlas.blog_editorial_backlog_intake.v1',
            'mode' => 'read_only_candidate_intake_p1',
            'status' => 'ready',
            'summary' => [
                'item_count' => count($items),
                'review_queue_items' => count(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'review_queue')),
                'candidate_feed_items' => count(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'candidate_feed')),
                'ready_for_review_count' => count(array_filter($items, fn (array $item): bool => (string) ($item['recommended_action'] ?? '') === 'accept_into_review_queue')),
                'hold_count' => count(array_filter($items, fn (array $item): bool => str_starts_with((string) ($item['recommended_action'] ?? ''), 'hold'))),
                'dependency_ladder_blocked' => $blockedLadder,
            ],
            'items' => $items,
            'rules' => [
                'Intake alimenta a lista de revisao; ele nao escreve backlog principal.',
                'Candidatos profundos esperam a escada atual destravar antes de promocao.',
                'Toda promocao continua append-only, com Vitor aprovando posicao e prerequisitos.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'promotes_candidate' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'requires_human_approval_to_promote' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @return array<string,mixed>
     */
    public function backlogIntakeItem(array $candidate, string $lane, array $posts, array $publishedSet, bool $blockedLadder): array
    {
        $afterSlug = (string) ($candidate['suggested_after_slug'] ?? '');
        if ($afterSlug === '') {
            $afterSlug = $this->support->lastPostSlug($posts);
        }

        $after = $this->support->plannedPostBySlug($posts, $afterSlug);
        $depth = $this->support->editorialDepthFromLevel((string) ($candidate['complexity_level'] ?? ''));
        $isQueued = $lane === 'review_queue';
        $duplicateReason = (string) ($candidate['duplicate_reason'] ?? '');
        $blockedByDepth = $blockedLadder && $depth >= 3;
        $recommendedAction = $this->backlogIntakeAction($isQueued, $duplicateReason, $blockedByDepth);

        return [
            'lane' => $lane,
            'slug' => (string) ($candidate['slug'] ?? ''),
            'title' => (string) ($candidate['title'] ?? ''),
            'source_type' => (string) ($candidate['source_type'] ?? ''),
            'source_ref' => (string) ($candidate['source_ref'] ?? ''),
            'collection' => (string) ($candidate['collection'] ?? ''),
            'series' => (string) ($candidate['series'] ?? ''),
            'complexity_level' => (string) ($candidate['complexity_level'] ?? ''),
            'depth' => $depth,
            'topics' => array_slice(array_values(array_filter((array) ($candidate['topics'] ?? []), 'is_string')), 0, 10),
            'suggested_after_slug' => $afterSlug !== '' ? $afterSlug : null,
            'suggested_after_order' => is_array($after) ? (int) ($after['order'] ?? 0) : null,
            'suggested_prerequisites' => array_values(array_filter([
                $afterSlug !== '' && ! isset($publishedSet[$afterSlug]) ? $afterSlug : null,
            ])),
            'recommended_action' => $recommendedAction,
            'reason' => $this->backlogIntakeReason($candidate, $recommendedAction, $afterSlug),
            'promotion_rule' => 'Aceitar na fila de revisao primeiro; promover para backlog principal so com aprovacao humana.',
        ];
    }

    /**
     * @param  array<string,mixed>  $sourceMap
     * @param  array<string,mixed>  $coverageMap
     * @param  array<string,mixed>  $candidateFeed
     * @param  array<string,mixed>  $reviewQueueState
     * @param  array<string,mixed>  $editorialRoadmap
     * @param  array<string,mixed>  $dependencyMatrix
     * @param  array<string,mixed>  $backlogIntake
     * @param  array<string,mixed>|null  $openBrainHandoff
     * @return array<string,mixed>
     */
    public function atlasSignalMesh(array $sourceMap, array $coverageMap, array $candidateFeed, array $reviewQueueState, array $editorialRoadmap, array $dependencyMatrix, array $backlogIntake, ?array $openBrainHandoff): array
    {
        $sources = (array) ($sourceMap['sources'] ?? []);
        $rows = [
            $this->atlasSignalMeshRow(
                'site_backlog',
                'Backlog publico',
                (string) data_get($sources, 'site_backlog.status', 'unknown'),
                'primary_public_sequence',
                'Define ordem, prerequisitos e a curva do leitor.',
                (int) data_get($sourceMap, 'archive_state.planned_posts', 0),
                'preserve_sequence'
            ),
            $this->atlasSignalMeshRow(
                'public_site_archive',
                'Arquivo publicado',
                (string) data_get($sources, 'public_site_archive.status', 'unknown'),
                'published_truth',
                'Prova o que o leitor ja pode ter visto publicamente.',
                (int) data_get($sourceMap, 'archive_state.public_archive_posts', 0),
                'reconcile_before_drafting'
            ),
            $this->atlasSignalMeshRow(
                'engineering_knowledge',
                'Docs canonicos',
                (string) data_get($sources, 'engineering_knowledge.status', 'unknown'),
                'canonical_reference',
                'Explica decisoes e conceitos do Atlas sem virar memoria paralela.',
                (int) data_get($sources, 'engineering_knowledge.row_count', 0),
                'use_as_evidence'
            ),
            $this->atlasSignalMeshRow(
                'code_intelligence',
                'Code Intelligence',
                (string) data_get($sources, 'code_intelligence.status', 'unknown'),
                'code_reality_reference',
                'Mostra o que existe no codigo antes de transformar em narrativa.',
                (int) data_get($sources, 'code_intelligence.module_count', 0) + (int) data_get($sources, 'code_intelligence.symbol_count', 0),
                'cross_check_claims'
            ),
            $this->atlasSignalMeshRow(
                'open_brain_context_pack',
                'Open Brain',
                (string) data_get($sources, 'open_brain_context_pack.status', 'unknown'),
                'provider_safe_context',
                'Prepara contexto auditavel para escrita sem vazar detalhes internos.',
                $openBrainHandoff !== null ? 1 : 0,
                $openBrainHandoff !== null ? 'execute_only_when_requested' : 'wait_for_target_post'
            ),
            $this->atlasSignalMeshRow(
                'vector_retrieval',
                'Vector retrieval',
                (string) data_get($sources, 'vector_retrieval.status', 'unknown'),
                'semantic_reference',
                'Pode informar similaridade via Open Brain, sem chamada direta nesta fase.',
                (int) data_get($coverageMap, 'summary.foundation_planned', 0),
                'keep_indirect_until_promoted'
            ),
            $this->atlasSignalMeshRow(
                'bounded_graph_context',
                'Grafo bounded',
                (string) data_get($sources, 'graph_retrieval.status', 'unknown'),
                'relationship_reference',
                'Sinais de dependencia e relacao ficam bounded ate P2 ser promovido.',
                (int) data_get($dependencyMatrix, 'summary.post_count', 0),
                'use_dependency_matrix_now'
            ),
            $this->atlasSignalMeshRow(
                'review_queue',
                'Fila de revisao',
                (string) data_get($reviewQueueState, 'status', 'missing'),
                'human_review_buffer',
                'Segura candidatos antes de qualquer promocao append-only.',
                (int) data_get($reviewQueueState, 'candidate_count', 0),
                (int) data_get($reviewQueueState, 'candidate_count', 0) > 0 ? 'review_or_promote' : 'accept_only_good_candidates'
            ),
            $this->atlasSignalMeshRow(
                'candidate_feed',
                'Feed de candidatos',
                (string) ($candidateFeed['status'] ?? 'unknown'),
                'idea_supply',
                'Sugere assuntos a partir dos read-models, sem alterar a lista.',
                (int) ($candidateFeed['candidate_count'] ?? 0),
                'triage_through_backlog_intake'
            ),
            $this->atlasSignalMeshRow(
                'reader_journey',
                'Jornada do leitor',
                (string) ($editorialRoadmap['status'] ?? 'unknown'),
                'sequence_explanation',
                'Converte ordem cronologica em fases compreensiveis.',
                (int) data_get($editorialRoadmap, 'summary.phase_count', 0),
                'protect_foundation_before_depth'
            ),
            $this->atlasSignalMeshRow(
                'backlog_intake',
                'Intake da lista',
                (string) ($backlogIntake['status'] ?? 'unknown'),
                'candidate_triage',
                'Decide se cada sinal deve entrar em revisao, esperar ou ser descartado.',
                (int) data_get($backlogIntake, 'summary.item_count', 0),
                'follow_recommended_action'
            ),
        ];

        $readyRows = array_values(array_filter($rows, fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['ready', 'available_contract', 'available_through_open_brain'], true)));
        $blockedRows = array_values(array_filter($rows, fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['future_governed', 'blocked', 'unavailable', 'missing'], true)));

        return [
            'schema_version' => 'atlas.blog_editorial_signal_mesh.v1',
            'mode' => 'read_only_atlas_signal_mesh_p1',
            'status' => 'ready',
            'summary' => [
                'source_count' => count($rows),
                'ready_source_count' => count($readyRows),
                'blocked_or_future_source_count' => count($blockedRows),
                'candidate_signal_count' => (int) ($candidateFeed['candidate_count'] ?? 0),
                'review_queue_count' => (int) data_get($reviewQueueState, 'candidate_count', 0),
                'intake_hold_count' => (int) data_get($backlogIntake, 'summary.hold_count', 0),
                'dependency_ladder_blocked' => (bool) data_get($backlogIntake, 'summary.dependency_ladder_blocked', false),
                'graph_posture' => (string) data_get($sources, 'graph_retrieval.status', 'unknown'),
                'next_safe_action' => (bool) data_get($backlogIntake, 'summary.dependency_ladder_blocked', false)
                    ? 'write_foundation_or_current_unlocked_post_before_deep_candidates'
                    : 'review_intake_candidates_without_reordering',
            ],
            'sources' => $rows,
            'rules' => [
                'A malha organiza sinais; ela nao cria uma nova memoria editorial.',
                'Backlog publico continua sendo autoridade de ordem.',
                'Graph/RAG global permanece bloqueado ate P2; sinais bounded so informam revisao.',
                'Toda entrada na lista passa por review queue e promocao humana.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
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
     * @return array<string,mixed>
     */
    public function atlasSignalMeshRow(string $id, string $label, string $status, string $authority, string $role, int $signalCount, string $nextAction): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'authority' => $authority,
            'role' => $role,
            'signal_count' => max(0, $signalCount),
            'can_suggest' => in_array($id, ['engineering_knowledge', 'code_intelligence', 'candidate_feed', 'bounded_graph_context', 'backlog_intake'], true),
            'can_write' => false,
            'can_publish' => false,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,mixed>  $sourceMap
     * @param  array<string,mixed>  $topicLedger
     * @param  array<string,mixed>  $dependencyMatrix
     * @return array<string,mixed>
     */
    public function publicKnowledgeMap(array $posts, array $sourceMap, array $topicLedger, array $dependencyMatrix): array
    {
        $reconciliation = (array) data_get($sourceMap, 'archive_reconciliation', []);
        $plannedPublished = array_values((array) ($reconciliation['planned_published'] ?? []));
        $externalPublished = array_values((array) ($reconciliation['external_published'] ?? []));
        $bridgeCandidates = array_values((array) ($reconciliation['bridge_candidates'] ?? []));
        $publishedRows = $this->publicKnowledgePublishedRows($plannedPublished, $externalPublished);
        $publishedSlugSet = array_fill_keys(array_values(array_filter(array_map(
            fn (array $row): string => (string) ($row['slug'] ?? ''),
            $publishedRows,
        ))), true);
        $assumableTopics = $this->publicKnowledgeAssumableTopics($topicLedger);
        $notYetAssumableTopics = $this->publicKnowledgeNotYetAssumableTopics($topicLedger);
        $currentUnlockedSlug = (string) data_get($dependencyMatrix, 'summary.current_unlocked_slug', '');
        $currentUnlockedRow = collect((array) ($dependencyMatrix['rows'] ?? []))
            ->first(fn (array $row): bool => (string) ($row['slug'] ?? '') === $currentUnlockedSlug);

        return [
            'schema_version' => 'atlas.blog_editorial_public_knowledge_map.v1',
            'mode' => 'read_only_public_reader_memory_p1',
            'status' => 'ready',
            'summary' => [
                'planned_posts' => count($posts),
                'public_archive_posts' => count($publishedRows),
                'planned_published_count' => count($plannedPublished),
                'external_published_count' => count($externalPublished),
                'bridge_candidate_count' => count($bridgeCandidates),
                'assumable_topic_count' => count($assumableTopics),
                'not_yet_assumable_topic_count' => count($notYetAssumableTopics),
                'current_unlocked_slug' => $currentUnlockedSlug !== '' ? $currentUnlockedSlug : null,
                'blocked_post_count' => (int) data_get($dependencyMatrix, 'summary.blocked_post_count', 0),
            ],
            'reader_contract' => [
                'current_unlocked_slug' => $currentUnlockedSlug !== '' ? $currentUnlockedSlug : null,
                'current_unlocked_title' => is_array($currentUnlockedRow) ? (string) ($currentUnlockedRow['title'] ?? '') : null,
                'can_assume' => array_slice(array_column($assumableTopics, 'topic'), 0, 12),
                'must_introduce_now' => is_array($currentUnlockedRow)
                    ? array_values((array) data_get($currentUnlockedRow, 'reader_contract.must_introduce', []))
                    : [],
                'must_not_assume_yet' => array_slice(array_column($notYetAssumableTopics, 'topic'), 0, 12),
                'rule' => 'Assuma apenas o que ja apareceu no arquivo publico; se o assunto ainda nao foi publicado, introduza antes de aprofundar.',
            ],
            'published_posts' => $publishedRows,
            'assumable_topics' => $assumableTopics,
            'not_yet_assumable_topics' => $notYetAssumableTopics,
            'bridge_candidates' => array_slice(array_map(
                fn (array $candidate): array => [
                    'published_slug' => (string) ($candidate['published_slug'] ?? ''),
                    'published_title' => (string) ($candidate['published_title'] ?? ''),
                    'matched_planned_slug' => (string) ($candidate['matched_planned_slug'] ?? ''),
                    'matched_planned_title' => (string) ($candidate['matched_planned_title'] ?? ''),
                    'matched_terms' => array_values(array_filter((array) ($candidate['matched_terms'] ?? []), 'is_string')),
                    'suggested_action' => (string) ($candidate['suggested_action'] ?? ''),
                ],
                $bridgeCandidates,
            ), 0, 8),
            'sequence_warnings' => array_values(array_filter(array_map(
                function (array $row) use ($publishedSlugSet): ?array {
                    $slug = (string) ($row['slug'] ?? '');
                    $readiness = (string) ($row['readiness'] ?? '');
                    if ($slug === '' || isset($publishedSlugSet[$slug]) || $readiness !== 'blocked_missing_prerequisites') {
                        return null;
                    }

                    return [
                        'slug' => $slug,
                        'title' => (string) ($row['title'] ?? ''),
                        'missing_prerequisites' => array_values((array) data_get($row, 'depends_on.missing_prerequisites', [])),
                        'reason' => 'Public reader does not have this foundation yet.',
                    ];
                },
                array_slice((array) ($dependencyMatrix['rows'] ?? []), 0, 12),
            ))),
            'rules' => [
                'O mapa representa conhecimento publico do leitor, nao memoria canonica interna do Atlas.',
                'Posts externos ao backlog podem ser contexto ou ponte, mas nao viram prerequisito automaticamente.',
                'Assuntos sem publicacao previa devem ser apresentados antes de qualquer versao profunda.',
                'A ordem do backlog continua sendo a autoridade de sequencia.',
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
     * @param  array<string,mixed>  $publishingPlan
     * @param  array<string,mixed>  $dependencyMatrix
     * @param  array<string,mixed>  $backlogIntake
     * @param  array<string,mixed>  $publicKnowledgeMap
     * @param  array<string,mixed>  $atlasSignalMesh
     * @return array<string,mixed>
     */
    public function agentOperatingQueue(array $publishingPlan, array $dependencyMatrix, array $backlogIntake, array $publicKnowledgeMap, array $atlasSignalMesh): array
    {
        $items = [];
        $todayLane = (array) ($publishingPlan['today_lane'] ?? []);
        $nextSlots = array_values((array) ($publishingPlan['next_slots'] ?? []));
        $dependencyRows = array_values((array) ($dependencyMatrix['rows'] ?? []));
        $intakeItems = array_values((array) ($backlogIntake['items'] ?? []));
        $currentUnlockedSlug = (string) data_get($dependencyMatrix, 'summary.current_unlocked_slug', '');
        $currentUnlockedRow = collect($dependencyRows)
            ->first(fn (array $row): bool => (string) ($row['slug'] ?? '') === $currentUnlockedSlug);

        if ((string) ($todayLane['slug'] ?? '') !== '') {
            $items[] = $this->agentOperatingQueueItem(
                'write_now',
                'prepare_foundation_draft_seed',
                (string) ($todayLane['slug'] ?? ''),
                is_array($currentUnlockedRow) ? (string) ($currentUnlockedRow['title'] ?? '') : null,
                is_array($currentUnlockedRow) ? (int) ($currentUnlockedRow['order'] ?? 0) : null,
                'ready_to_prepare',
                'Current unlocked planned post. Prepare the writing packet, do not publish automatically.',
                'publishing_plan.today_lane',
                true,
            );
        }

        foreach ($nextSlots as $slot) {
            if ((string) ($slot['slug'] ?? '') === '' || (string) ($slot['slug'] ?? '') === (string) ($todayLane['slug'] ?? '')) {
                continue;
            }

            if ((string) ($slot['status'] ?? '') !== 'ready_to_draft') {
                continue;
            }

            $items[] = $this->agentOperatingQueueItem(
                'prepare_next',
                'prepare_after_current_post_is_approved',
                (string) ($slot['slug'] ?? ''),
                (string) ($slot['title'] ?? ''),
                (int) ($slot['order'] ?? 0),
                (string) ($slot['status'] ?? 'ready_to_draft'),
                'Ready slot, but sequence still starts with the write_now item.',
                'publishing_plan.next_slots',
                true,
            );
        }

        foreach ($dependencyRows as $row) {
            if ((string) ($row['readiness'] ?? '') !== 'blocked_missing_prerequisites') {
                continue;
            }

            $items[] = $this->agentOperatingQueueItem(
                'hold',
                'do_not_write_until_prerequisites_are_public',
                (string) ($row['slug'] ?? ''),
                (string) ($row['title'] ?? ''),
                (int) ($row['order'] ?? 0),
                (string) ($row['readiness'] ?? 'blocked'),
                'Missing public prerequisites: '.implode(', ', array_values((array) data_get($row, 'depends_on.missing_prerequisites', []))),
                'editorial_dependency_matrix.rows',
                true,
            );
        }

        foreach ($intakeItems as $item) {
            $recommendedAction = (string) ($item['recommended_action'] ?? '');
            $lane = str_contains($recommendedAction, 'hold') ? 'hold' : 'review';

            $items[] = $this->agentOperatingQueueItem(
                $lane,
                $lane === 'hold' ? 'keep_candidate_out_of_main_backlog' : 'human_review_before_promotion',
                (string) ($item['slug'] ?? ''),
                (string) ($item['title'] ?? ''),
                null,
                $recommendedAction !== '' ? $recommendedAction : (string) ($item['lane'] ?? 'candidate'),
                (string) ($item['reason'] ?? 'Candidate requires human review before it can affect the public sequence.'),
                'backlog_intake.items',
                true,
            );
        }

        $items = array_values(array_filter($items, fn (array $item): bool => (string) ($item['slug'] ?? '') !== ''));
        $laneCounts = [
            'write_now' => count(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'write_now')),
            'prepare_next' => count(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'prepare_next')),
            'review' => count(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'review')),
            'hold' => count(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'hold')),
        ];

        return [
            'schema_version' => 'atlas.blog_editorial_agent_operating_queue.v1',
            'mode' => 'read_only_agent_blog_queue_p1',
            'status' => 'ready',
            'summary' => [
                'item_count' => count($items),
                'write_now_count' => $laneCounts['write_now'],
                'prepare_next_count' => $laneCounts['prepare_next'],
                'review_count' => $laneCounts['review'],
                'hold_count' => $laneCounts['hold'],
                'current_unlocked_slug' => $currentUnlockedSlug !== '' ? $currentUnlockedSlug : null,
                'next_action' => $laneCounts['write_now'] > 0
                    ? 'prepare_current_unlocked_post'
                    : 'review_dependency_or_candidate_lanes',
                'graph_posture' => (string) data_get($atlasSignalMesh, 'summary.graph_posture', 'future_governed'),
                'public_reader_known_topics' => (int) data_get($publicKnowledgeMap, 'summary.assumable_topic_count', 0),
            ],
            'lanes' => [
                'write_now' => array_values(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'write_now')),
                'prepare_next' => array_values(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'prepare_next')),
                'review' => array_values(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'review')),
                'hold' => array_values(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'hold')),
            ],
            'items' => $items,
            'agent_rules' => [
                'Leia write_now antes de qualquer rascunho; e a unica fila autorizada para preparacao imediata.',
                'Prepare_next so deve ser usado depois que o texto atual for revisado e aprovado por Vitor.',
                'Hold nunca vira texto profundo antes dos prerequisitos aparecerem publicamente.',
                'Review pode sugerir promocao, mas nao escreve backlog, fila de revisao ou publicacao.',
                'Graph/RAG continua futuro governado nesta fase.',
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
                'requires_human_approval_to_write' => true,
                'requires_human_approval_to_publish' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function agentOperatingQueueItem(string $lane, string $action, string $slug, ?string $title, ?int $order, string $status, string $reason, string $source, bool $humanGate): array
    {
        return [
            'lane' => $lane,
            'action' => $action,
            'slug' => $slug,
            'title' => $title ?: $slug,
            'order' => $order,
            'status' => $status,
            'reason' => $reason,
            'source' => $source,
            'human_gate' => $humanGate,
            'can_write_draft' => false,
            'can_publish' => false,
            'can_reorder' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $agentOperatingQueue
     * @param  array<string,mixed>|null  $writingPacket
     * @param  array<string,mixed>  $publicKnowledgeMap
     * @param  array<string,mixed>  $dependencyMatrix
     * @param  array<string,mixed>  $atlasSignalMesh
     * @param  array<string,mixed>|null  $openBrainHandoff
     * @return array<string,mixed>
     */
    public function agentHandoffPacket(array $agentOperatingQueue, ?array $writingPacket, array $publicKnowledgeMap, array $dependencyMatrix, array $atlasSignalMesh, ?array $openBrainHandoff): array
    {
        $currentItem = (array) data_get($agentOperatingQueue, 'lanes.write_now.0', []);
        if ($currentItem === []) {
            $currentItem = (array) data_get($agentOperatingQueue, 'items.0', []);
        }

        $currentSlug = (string) ($currentItem['slug'] ?? data_get($writingPacket, 'post.slug', ''));
        $currentTitle = (string) ($currentItem['title'] ?? data_get($writingPacket, 'post.title', $currentSlug));
        $dependencyRow = collect((array) ($dependencyMatrix['rows'] ?? []))
            ->first(fn (array $row): bool => (string) ($row['slug'] ?? '') === $currentSlug);

        return [
            'schema_version' => 'atlas.blog_editorial_agent_handoff_packet.v1',
            'mode' => 'read_only_agent_execution_brief_p1',
            'status' => $currentSlug !== '' ? 'ready' : 'waiting_for_unlocked_post',
            'mission' => [
                'agent_role' => 'blog_editorial_operator',
                'intent' => $currentSlug !== ''
                    ? 'prepare_the_current_unlocked_blog_work_without_publishing'
                    : 'review_editorial_blockers_before_any_writing',
                'current_slug' => $currentSlug !== '' ? $currentSlug : null,
                'current_title' => $currentTitle !== '' ? $currentTitle : null,
                'lane' => (string) ($currentItem['lane'] ?? 'review'),
                'action' => (string) ($currentItem['action'] ?? data_get($agentOperatingQueue, 'summary.next_action', 'review_context')),
                'language' => (string) data_get($writingPacket, 'writing_brief.language', 'pt-BR'),
                'human_gate' => true,
            ],
            'read_before_work' => [
                [
                    'ref' => 'operations_packet.agent_operating_queue',
                    'why' => 'Decide lane, action and what must stay held.',
                ],
                [
                    'ref' => 'operations_packet.public_knowledge_map',
                    'why' => 'Know what the public reader may already assume.',
                ],
                [
                    'ref' => 'operations_packet.editorial_dependency_matrix',
                    'why' => 'Confirm prerequisites and depth before writing.',
                ],
                [
                    'ref' => 'operations_packet.writing_packet',
                    'why' => 'Use only the private seed and writing brief, never as automatic publication.',
                ],
                [
                    'ref' => 'operations_packet.open_brain_handoff',
                    'why' => 'Request fresh provider-safe context when deeper Atlas context is needed.',
                ],
            ],
            'current_reader_contract' => [
                'can_assume' => array_slice((array) data_get($publicKnowledgeMap, 'reader_contract.can_assume', []), 0, 12),
                'must_introduce_now' => array_slice((array) data_get($publicKnowledgeMap, 'reader_contract.must_introduce_now', []), 0, 12),
                'must_not_assume_yet' => array_slice((array) data_get($publicKnowledgeMap, 'reader_contract.must_not_assume_yet', []), 0, 12),
                'dependency_missing_prerequisites' => is_array($dependencyRow)
                    ? array_values((array) data_get($dependencyRow, 'depends_on.missing_prerequisites', []))
                    : [],
                'rule' => (string) data_get($publicKnowledgeMap, 'reader_contract.rule', ''),
            ],
            'evidence_bundle' => [
                'writing_packet_schema' => (string) data_get($writingPacket, 'schema_version', ''),
                'concept_progression_schema' => (string) data_get($writingPacket, 'concept_progression_map.schema_version', ''),
                'draft_seed_schema' => (string) data_get($writingPacket, 'draft_seed.schema_version', ''),
                'source_posture' => [
                    'graph_posture' => (string) data_get($atlasSignalMesh, 'summary.graph_posture', 'future_governed'),
                    'next_safe_action' => (string) data_get($atlasSignalMesh, 'summary.next_safe_action', ''),
                    'source_count' => (int) data_get($atlasSignalMesh, 'summary.source_count', 0),
                ],
                'open_brain_command' => (string) data_get($openBrainHandoff, 'command', ''),
                'open_brain_invoked_by_this_command' => (bool) data_get($openBrainHandoff, 'invoked_by_this_command', false),
            ],
            'execution_checklist' => [
                'Start from the current lane only.',
                'Use Portuguese as the canonical original language.',
                'Introduce missing concepts before advanced claims.',
                'Keep English as optional translation, not the default source.',
                'Remove sensitive paths, prompts, traces and tokens.',
                'Return draft/recommendation for Vitor review; do not publish.',
            ],
            'agent_prompt_seed' => [
                'system_intent' => 'Voce esta ajudando Vitor Freire a operar o blog pessoal dele com ordem editorial, memoria publica do leitor e aprovacao humana.',
                'task' => $currentSlug !== ''
                    ? 'Prepare o proximo trabalho editorial para '.$currentTitle.' usando apenas os refs listados e respeitando a ordem do backlog.'
                    : 'Explique por que nenhum texto esta liberado e quais bloqueios precisam ser resolvidos.',
                'forbidden' => [
                    'publicar automaticamente',
                    'reordenar backlog',
                    'promover candidato sem humano',
                    'assumir assunto nao publicado',
                    'usar graph/RAG fora do contrato P2',
                ],
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
                'requires_human_approval_to_write' => true,
                'requires_human_approval_to_publish' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $plannedPublished
     * @param  array<int,array<string,mixed>>  $externalPublished
     * @return array<int,array<string,mixed>>
     */
    public function publicKnowledgePublishedRows(array $plannedPublished, array $externalPublished): array
    {
        $rows = [];
        foreach ($plannedPublished as $post) {
            if (! is_array($post)) {
                continue;
            }

            $rows[] = [
                'slug' => (string) ($post['slug'] ?? ''),
                'title' => (string) ($post['title'] ?? ''),
                'kind' => (string) ($post['kind'] ?? ''),
                'date' => (string) ($post['date'] ?? ''),
                'collection' => (string) ($post['collection'] ?? ''),
                'source' => 'planned_backlog',
                'planned_order' => (int) ($post['planned_order'] ?? 0),
                'terms' => $this->support->publishedTerms($post),
            ];
        }
        foreach ($externalPublished as $post) {
            if (! is_array($post)) {
                continue;
            }

            $rows[] = [
                'slug' => (string) ($post['slug'] ?? ''),
                'title' => (string) ($post['title'] ?? ''),
                'kind' => (string) ($post['kind'] ?? ''),
                'date' => (string) ($post['date'] ?? ''),
                'collection' => (string) ($post['collection'] ?? ''),
                'source' => 'external_archive',
                'planned_order' => null,
                'terms' => $this->support->publishedTerms($post),
            ];
        }

        usort($rows, fn (array $a, array $b): int => ((int) ($a['planned_order'] ?? PHP_INT_MAX) <=> (int) ($b['planned_order'] ?? PHP_INT_MAX))
            ?: strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''))
            ?: strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        return array_slice($rows, 0, 24);
    }

    /**
     * @param  array<string,mixed>  $topicLedger
     * @return array<int,array<string,mixed>>
     */
    public function publicKnowledgeAssumableTopics(array $topicLedger): array
    {
        return array_slice(array_values(array_map(
            fn (array $row): array => [
                'topic' => (string) ($row['topic'] ?? ''),
                'published_count' => (int) ($row['published_count'] ?? 0),
                'published_slugs' => array_values((array) ($row['published_slugs'] ?? [])),
                'planned_count' => (int) ($row['planned_count'] ?? 0),
                'next_action' => 'may_use_as_reader_context',
            ],
            array_filter(
                (array) ($topicLedger['rows'] ?? []),
                fn (array $row): bool => (int) ($row['published_count'] ?? 0) > 0,
            ),
        )), 0, 20);
    }

    /**
     * @param  array<string,mixed>  $topicLedger
     * @return array<int,array<string,mixed>>
     */
    public function publicKnowledgeNotYetAssumableTopics(array $topicLedger): array
    {
        return array_slice(array_values(array_map(
            fn (array $row): array => [
                'topic' => (string) ($row['topic'] ?? ''),
                'planned_count' => (int) ($row['planned_count'] ?? 0),
                'candidate_count' => (int) ($row['candidate_count'] ?? 0),
                'review_queue_count' => (int) ($row['review_queue_count'] ?? 0),
                'first_planned_order' => $row['first_planned_order'] ?? null,
                'next_action' => 'introduce_before_depth',
            ],
            array_filter(
                (array) ($topicLedger['rows'] ?? []),
                fn (array $row): bool => (int) ($row['published_count'] ?? 0) === 0
                    && ((int) ($row['planned_count'] ?? 0) > 0 || (int) ($row['candidate_count'] ?? 0) > 0 || (int) ($row['review_queue_count'] ?? 0) > 0),
            ),
        )), 0, 20);
    }

    public function backlogIntakeAction(bool $isQueued, string $duplicateReason, bool $blockedByDepth): string
    {
        if ($duplicateReason !== '') {
            return 'hold_duplicate';
        }
        if ($blockedByDepth) {
            return 'hold_until_dependency_ladder_clears';
        }
        if ($isQueued) {
            return 'review_for_append_only_promotion';
        }

        return 'accept_into_review_queue';
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    public function backlogIntakeReason(array $candidate, string $recommendedAction, string $afterSlug): string
    {
        if ($recommendedAction === 'hold_duplicate') {
            return 'Ja existe no backlog, publicado ou repetido na fila de revisao.';
        }
        if ($recommendedAction === 'hold_until_dependency_ladder_clears') {
            return 'Tema profundo demais para entrar enquanto a escada atual ainda tem prerequisitos pendentes.';
        }

        $why = trim((string) ($candidate['why'] ?? ''));
        $placement = $afterSlug !== '' ? ' Posicao sugerida: depois de '.$afterSlug.'.' : '';

        return ($why !== '' ? $why : 'Sinal existente do Atlas ainda nao representado na lista publica.').$placement;
    }
}
