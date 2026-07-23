<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class EditorialSupport
{
    public function editorialDepthFromLevel(string $level): int
    {
        return match ($level) {
            'L0' => 0,
            'L1' => 1,
            'L2' => 2,
            'L3' => 3,
            'L4' => 4,
            default => 1,
        };
    }

    /**
     * Closure orWhere(coluna like %termo%) por termo×coluna — clone único dos 5 readers.
     *
     * @param  array<int,string>  $terms
     * @param  array<int,string>  $columns
     */
    public function termsMatcher(array $terms, array $columns): \Closure
    {
        return function (Builder $query) use ($terms, $columns): void {
            foreach ($terms as $term) {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', "%{$term}%");
                }
            }
        };
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    public function terms(array $post): array
    {
        $raw = implode(' ', array_filter([
            (string) ($post['title'] ?? ''),
            (string) ($post['main_question'] ?? ''),
            (string) ($post['collection'] ?? ''),
            (string) ($post['series'] ?? ''),
            implode(' ', array_filter((array) ($post['topics'] ?? []), 'is_string')),
        ]));

        $stop = array_fill_keys([
            'atlas', 'para', 'porque', 'como', 'quando', 'onde', 'quem', 'que',
            'uma', 'por', 'com', 'sem', 'dos', 'das', 'the', 'and', 'what',
            'why', 'how', 'from', 'into', 'sobre', 'sistema',
        ], true);

        return collect(preg_split('/[^a-zA-Z0-9_\\-]+/', Str::ascii(Str::lower($raw))) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B-_"))
            ->filter(fn (string $term): bool => strlen($term) >= 3 && ! isset($stop[$term]))
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $post
     */
    public function contextQuery(array $post): string
    {
        return trim(implode(' ', array_filter([
            (string) ($post['title'] ?? ''),
            (string) ($post['main_question'] ?? ''),
            implode(' ', array_filter((array) ($post['topics'] ?? []), 'is_string')),
        ])));
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array{slugs:array<string,bool>,titles:array<string,bool>}
     */
    public function existingEditorialIndex(array $posts, array $publishedSlugs, array $queuedSlugs = []): array
    {
        $slugs = array_fill_keys(array_values(array_filter(array_merge($publishedSlugs, $queuedSlugs))), true);
        $titles = [];

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug !== '') {
                $slugs[$slug] = true;
            }

            $title = $this->normalizedTitle((string) ($post['title'] ?? ''));
            if ($title !== '') {
                $titles[$title] = true;
            }
        }

        return [
            'slugs' => $slugs,
            'titles' => $titles,
        ];
    }

    public function normalizedTitle(string $title): string
    {
        return Str::slug(Str::ascii(Str::lower($title)));
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    public function publishedTerms(array $post): array
    {
        return $this->terms([
            'title' => trim(implode(' ', array_filter([
                (string) ($post['title'] ?? ''),
                (string) ($post['title_pt'] ?? ''),
                (string) ($post['title_en'] ?? ''),
                (string) ($post['slug'] ?? ''),
            ]))),
            'main_question' => trim(implode(' ', array_filter([
                (string) ($post['kind'] ?? ''),
                (string) ($post['collection'] ?? ''),
                (string) ($post['series'] ?? ''),
            ]))),
            'topics' => array_values(array_filter((array) ($post['tags'] ?? []), 'is_string')),
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    public function plannedPostBySlug(array $posts, string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        foreach ($posts as $post) {
            if ((string) ($post['slug'] ?? '') === $slug) {
                return $post;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     */
    public function firstReadySlug(array $posts, array $publishedSlugs): ?string
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $plannedSet = array_fill_keys(array_values(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $posts,
        ))), true);

        $sorted = $posts;
        usort($sorted, fn (array $a, array $b): int => ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0)));

        foreach ($sorted as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug === '' || isset($publishedSet[$slug])) {
                continue;
            }

            $missing = array_values(array_filter(
                array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string')),
                fn (string $prerequisite): bool => ! isset($publishedSet[$prerequisite])
            ));
            $unknown = array_values(array_filter(
                array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string')),
                fn (string $prerequisite): bool => ! isset($plannedSet[$prerequisite]) && ! isset($publishedSet[$prerequisite])
            ));

            if ($missing === [] && $unknown === []) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    public function compactPostRef(array $post): array
    {
        return [
            'order' => (int) ($post['order'] ?? 0),
            'title' => (string) ($post['title'] ?? ''),
            'slug' => (string) ($post['slug'] ?? ''),
            'complexity_level' => (string) ($post['complexity_level'] ?? ''),
            'main_question' => (string) ($post['main_question'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array<string,mixed>
     */
    public function conceptProgressionMap(array $post, array $posts, array $publishedSlugs = []): array
    {
        $slug = (string) ($post['slug'] ?? '');
        $order = (int) ($post['order'] ?? 0);
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $currentTerms = $this->terms($post);
        $currentTermSet = array_fill_keys($currentTerms, true);
        $priorTerms = [];
        $publishedPriorTerms = [];
        $prerequisiteTerms = [];
        $futureTerms = [];
        $futureExamples = [];
        $prerequisites = array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string'));
        $prerequisiteSet = array_fill_keys($prerequisites, true);
        $priorCount = 0;
        $publishedPriorCount = 0;
        $futureCount = 0;

        foreach ($posts as $candidate) {
            $candidateSlug = (string) ($candidate['slug'] ?? '');
            $candidateOrder = (int) ($candidate['order'] ?? 0);
            $candidateTerms = $this->terms($candidate);

            if ($candidateOrder > 0 && $candidateOrder < $order) {
                $priorCount++;

                foreach ($candidateTerms as $term) {
                    $priorTerms[$term] = true;
                }
            }

            if (isset($publishedSet[$candidateSlug]) && ($candidateOrder === 0 || $candidateOrder < $order)) {
                $publishedPriorCount++;

                foreach ($candidateTerms as $term) {
                    $publishedPriorTerms[$term] = true;
                }
            }

            if (isset($prerequisiteSet[$candidateSlug])) {
                foreach ($candidateTerms as $term) {
                    $prerequisiteTerms[$term] = true;
                }
            }

            if ($candidateOrder > $order) {
                $futureCount++;

                foreach ($candidateTerms as $term) {
                    if (isset($currentTermSet[$term]) || isset($priorTerms[$term]) || isset($prerequisiteTerms[$term])) {
                        continue;
                    }

                    $futureTerms[$term] = true;
                    $futureExamples[$term] ??= [
                        'term' => $term,
                        'source_slug' => $candidateSlug,
                        'source_order' => $candidateOrder,
                        'source_title' => (string) ($candidate['title'] ?? ''),
                    ];
                }
            }
        }

        $introducedTerms = array_values(array_unique(array_merge(array_keys($priorTerms), array_keys($publishedPriorTerms), array_keys($prerequisiteTerms))));
        sort($introducedTerms);

        $allowedTerms = array_values(array_unique(array_merge($introducedTerms, $currentTerms)));
        sort($allowedTerms);

        $futureTermsToAvoid = array_values(array_filter(
            array_keys($futureTerms),
            fn (string $term): bool => ! in_array($term, $allowedTerms, true),
        ));
        sort($futureTermsToAvoid);

        return [
            'schema_version' => 'atlas.blog_editorial_concept_progression.v1',
            'mode' => 'read_only_sequence_guard_p1',
            'current_slug' => $slug,
            'current_order' => $order,
            'current_terms' => $currentTerms,
            'introduced_terms' => $introducedTerms,
            'published_prior_terms' => array_values(array_keys($publishedPriorTerms)),
            'prerequisite_terms' => array_values(array_keys($prerequisiteTerms)),
            'allowed_terms' => $allowedTerms,
            'future_terms_to_avoid' => array_slice($futureTermsToAvoid, 0, 24),
            'premature_topic_examples' => array_slice(array_values(array_filter(
                $futureExamples,
                fn (array $example): bool => in_array((string) ($example['term'] ?? ''), $futureTermsToAvoid, true),
            )), 0, 8),
            'reader_state' => [
                'planned_prior_count' => $priorCount,
                'published_prior_count' => $publishedPriorCount,
                'prerequisite_count' => count($prerequisites),
                'future_post_count' => $futureCount,
            ],
            'rule' => 'Explain only the current layer plus concepts already introduced by prior or prerequisite posts; name future concepts only as teasers, never as required knowledge.',
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     */
    public function lastPostSlug(array $posts): string
    {
        $last = collect($posts)->sortByDesc(fn (array $post): int => (int) ($post['order'] ?? 0))->first();

        return is_array($last) ? (string) ($last['slug'] ?? '') : '';
    }
}
