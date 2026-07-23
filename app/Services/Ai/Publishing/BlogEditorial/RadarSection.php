<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

final class RadarSection
{
    public function __construct(
        private readonly EditorialSupport $support = new EditorialSupport(),
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @return array<string,mixed>
     */
    public function publicationFrontier(array $posts, array $publishedSet): array
    {
        $publishedUntil = 0;
        $next = null;

        foreach ($posts as $post) {
            $order = (int) ($post['order'] ?? 0);
            $slug = (string) ($post['slug'] ?? '');

            if ($order === $publishedUntil + 1 && isset($publishedSet[$slug])) {
                $publishedUntil = $order;
                continue;
            }

            if ($order > $publishedUntil && $next === null) {
                $next = $this->support->compactPostRef($post);
                break;
            }
        }

        return [
            'published_until_order' => $publishedUntil,
            'next_order' => $publishedUntil + 1,
            'next_planned_post' => $next,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @param  array<string,bool>  $plannedSet
     * @return array<int,array<string,mixed>>
     */
    public function blockedPostsForRadar(array $posts, array $publishedSet, array $plannedSet): array
    {
        $blocked = [];

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
                continue;
            }

            $blocked[] = [
                'order' => (int) ($post['order'] ?? 0),
                'slug' => $slug,
                'title' => (string) ($post['title'] ?? ''),
                'missing_prerequisites' => $missingPrerequisites,
                'unknown_prerequisites' => $unknownPrerequisites,
            ];
        }

        return $blocked;
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @return array<int,array<string,mixed>>
     */
    public function weekLanes(array $posts, array $publishedSet): array
    {
        $weeks = [];

        foreach ($posts as $post) {
            $week = (int) ($post['week'] ?? 0);
            $weeks[$week] ??= [
                'week' => $week,
                'theme' => (string) ($post['week_theme'] ?? ''),
                'first_order' => (int) ($post['order'] ?? 0),
                'last_order' => (int) ($post['order'] ?? 0),
                'planned_count' => 0,
                'published_count' => 0,
                'next_unpublished' => null,
                'collections' => [],
                'series' => [],
                'complexity_levels' => [],
                'last_slug' => '',
            ];

            $slug = (string) ($post['slug'] ?? '');
            $weeks[$week]['planned_count']++;
            $weeks[$week]['last_order'] = max((int) $weeks[$week]['last_order'], (int) ($post['order'] ?? 0));
            $weeks[$week]['last_slug'] = $slug;
            $weeks[$week]['collections'][(string) ($post['collection'] ?? 'unknown')] = (($weeks[$week]['collections'][(string) ($post['collection'] ?? 'unknown')] ?? 0) + 1);
            $weeks[$week]['series'][(string) ($post['series'] ?? 'unknown')] = (($weeks[$week]['series'][(string) ($post['series'] ?? 'unknown')] ?? 0) + 1);
            $weeks[$week]['complexity_levels'][(string) ($post['complexity_level'] ?? 'unknown')] = (($weeks[$week]['complexity_levels'][(string) ($post['complexity_level'] ?? 'unknown')] ?? 0) + 1);

            if (isset($publishedSet[$slug])) {
                $weeks[$week]['published_count']++;
            } elseif ($weeks[$week]['next_unpublished'] === null) {
                $weeks[$week]['next_unpublished'] = $this->support->compactPostRef($post);
            }
        }

        ksort($weeks);

        return array_values(array_map(function (array $week): array {
            return [
                'week' => (int) $week['week'],
                'theme' => (string) $week['theme'],
                'order_range' => [(int) $week['first_order'], (int) $week['last_order']],
                'planned_count' => (int) $week['planned_count'],
                'published_count' => (int) $week['published_count'],
                'complete' => (int) $week['planned_count'] > 0 && (int) $week['planned_count'] === (int) $week['published_count'],
                'next_unpublished' => $week['next_unpublished'],
                'dominant_collection' => $this->dominantKey((array) $week['collections']),
                'dominant_series' => $this->dominantKey((array) $week['series']),
                'complexity_levels' => (array) $week['complexity_levels'],
                'insertion_after_slug' => (string) $week['last_slug'],
            ];
        }, $weeks));
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @return array<int,array<string,mixed>>
     */
    public function sequenceLanes(array $posts, array $publishedSet): array
    {
        $lanes = [];

        foreach ($posts as $post) {
            $series = (string) ($post['series'] ?? 'unknown');
            $slug = (string) ($post['slug'] ?? '');
            $lanes[$series] ??= [
                'series' => $series,
                'collection' => (string) ($post['collection'] ?? ''),
                'first_order' => (int) ($post['order'] ?? 0),
                'last_order' => (int) ($post['order'] ?? 0),
                'planned_count' => 0,
                'published_count' => 0,
                'deep_count' => 0,
                'first_unpublished' => null,
            ];

            $lanes[$series]['planned_count']++;
            $lanes[$series]['last_order'] = max((int) $lanes[$series]['last_order'], (int) ($post['order'] ?? 0));

            if (isset($publishedSet[$slug])) {
                $lanes[$series]['published_count']++;
            } elseif ($lanes[$series]['first_unpublished'] === null) {
                $lanes[$series]['first_unpublished'] = $this->support->compactPostRef($post);
            }

            if (in_array((string) ($post['complexity_level'] ?? ''), ['L3', 'L4', 'L5'], true)) {
                $lanes[$series]['deep_count']++;
            }
        }

        usort($lanes, fn (array $a, array $b): int => ((int) $a['first_order'] <=> (int) $b['first_order']));

        return array_values($lanes);
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @param  array<int,array<string,mixed>>  $safeArcs
     * @return array<int,array<string,mixed>>
     */
    public function insertionWindows(array $posts, array $publishedSet, array $safeArcs): array
    {
        $windows = [];

        foreach ($this->weekLanes($posts, $publishedSet) as $week) {
            $windows[] = [
                'type' => 'after_week',
                'week' => (int) ($week['week'] ?? 0),
                'after_slug' => (string) ($week['insertion_after_slug'] ?? ''),
                'label' => 'After week '.((string) ($week['week'] ?? 0)).' - '.((string) ($week['theme'] ?? '')),
                'safe_for' => (bool) ($week['complete'] ?? false) ? 'extension_or_bridge' : 'only_if_it_supports_current_week',
                'rule' => 'Do not insert a deep topic here unless it depends only on concepts already introduced by this week.',
            ];
        }

        foreach ($safeArcs as $arc) {
            if (! (bool) ($arc['ready_after_first_month'] ?? false)) {
                continue;
            }

            $windows[] = [
                'type' => 'next_safe_arc',
                'arc' => (string) ($arc['arc'] ?? ''),
                'title' => (string) ($arc['title'] ?? ''),
                'first_post_slug' => (string) ($arc['first_post_slug'] ?? ''),
                'safe_for' => 'new_collection_start',
                'rule' => (string) ($arc['why_now'] ?? ''),
            ];
        }

        return array_slice($windows, 0, 12);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>
     */
    public function candidateRadarSummary(array $candidate, array $posts): array
    {
        $afterSlug = (string) ($candidate['suggested_after_slug'] ?? '');
        $after = $this->support->plannedPostBySlug($posts, $afterSlug);

        return [
            'slug' => (string) ($candidate['slug'] ?? ''),
            'title' => (string) ($candidate['title'] ?? ''),
            'source_type' => (string) ($candidate['source_type'] ?? ''),
            'source_ref' => (string) ($candidate['source_ref'] ?? ''),
            'complexity_level' => (string) ($candidate['complexity_level'] ?? ''),
            'collection' => (string) ($candidate['collection'] ?? ''),
            'series' => (string) ($candidate['series'] ?? ''),
            'suggested_after_slug' => $afterSlug,
            'suggested_after_order' => is_array($after) ? (int) ($after['order'] ?? 0) : null,
            'review_reason' => (string) ($candidate['why'] ?? ''),
            'promotion_rule' => 'Accept into review queue first; promote append-only only after Vitor approves the sequence position.',
        ];
    }

    /**
     * @param  array<string,mixed>  $frontier
     * @param  array<int,array<string,mixed>>  $blocked
     * @param  array<string,mixed>|null  $nextReadyPost
     * @param  array<int,array<string,mixed>>  $safeArcs
     * @return array<int,array<string,mixed>>
     */
    public function radarNextSteps(array $frontier, array $blocked, ?array $nextReadyPost, array $safeArcs): array
    {
        $steps = [];

        if (is_array($nextReadyPost)) {
            $steps[] = [
                'action' => 'prepare_writing_packet',
                'slug' => (string) ($nextReadyPost['slug'] ?? ''),
                'why' => 'This is the next unpublished post whose prerequisites are satisfied.',
            ];
        } elseif ($blocked !== []) {
            $steps[] = [
                'action' => 'repair_prerequisites',
                'slug' => (string) ($blocked[0]['slug'] ?? ''),
                'why' => 'The sequence has blocked posts before more candidates should be promoted.',
            ];
        }

        $readyArcs = array_values(array_filter(
            $safeArcs,
            fn (array $arc): bool => (bool) ($arc['ready_after_first_month'] ?? false),
        ));

        if ($readyArcs !== []) {
            $steps[] = [
                'action' => 'review_next_safe_arc',
                'arc' => (string) ($readyArcs[0]['arc'] ?? ''),
                'first_post_slug' => (string) ($readyArcs[0]['first_post_slug'] ?? ''),
                'why' => (string) ($readyArcs[0]['why_now'] ?? ''),
            ];
        }

        $steps[] = [
            'action' => 'keep_public_order',
            'next_sequence_order' => (int) ($frontier['next_order'] ?? 1),
            'why' => 'New posts should extend the reader ladder, not jump around because a deep internal topic is interesting.',
        ];

        return $steps;
    }

    /**
     * @param  array<string,int>  $counts
     */
    public function dominantKey(array $counts): string
    {
        if ($counts === []) {
            return 'unknown';
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  array<string,mixed>  $observed
     * @return array<string,mixed>
     */
    public function goldenCase(string $code, bool $passed, string $expectation, array $observed = []): array
    {
        return [
            'code' => $code,
            'passed' => $passed,
            'expectation' => $expectation,
            'observed' => $observed,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function conceptProgressionFixturePosts(): array
    {
        return [
            [
                'order' => 1,
                'title' => 'O que e o Atlas',
                'slug' => 'o-que-e-o-atlas',
                'complexity_level' => 'L0',
                'collection' => 'atlas',
                'series' => 'building-atlas',
                'topics' => ['atlas', 'produto'],
                'prerequisites' => [],
            ],
            [
                'order' => 2,
                'title' => 'Por que estou construindo o Atlas',
                'slug' => 'por-que-estou-construindo-o-atlas',
                'complexity_level' => 'L0',
                'collection' => 'atlas',
                'series' => 'building-atlas',
                'topics' => ['atlas', 'visao', 'processo'],
                'prerequisites' => ['o-que-e-o-atlas'],
            ],
            [
                'order' => 3,
                'title' => 'Memoria como ledger',
                'slug' => 'memoria-como-ledger',
                'complexity_level' => 'L3',
                'collection' => 'ia-pessoal',
                'series' => 'agent-memory',
                'topics' => ['memoria', 'ledger', 'governanca'],
                'prerequisites' => ['por-que-estou-construindo-o-atlas'],
            ],
            [
                'order' => 4,
                'title' => 'Graph RAG profundo no Atlas',
                'slug' => 'graph-rag-profundo-no-atlas',
                'complexity_level' => 'L5',
                'collection' => 'atlas',
                'series' => 'graph-rag',
                'topics' => ['graph-rag', 'python-runtime', 'embedding'],
                'prerequisites' => ['memoria-como-ledger'],
            ],
        ];
    }
}
