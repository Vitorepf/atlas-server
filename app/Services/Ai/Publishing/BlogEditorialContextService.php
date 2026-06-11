<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class BlogEditorialContextService
{
    public const SCHEMA_VERSION = 'atlas.blog_editorial_context.v1';

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function acceptCandidate(string $siteRoot, string $candidateSlug, array $posts, array $publishedSlugs = [], array $options = []): array
    {
        $siteRoot = rtrim($siteRoot, '/');
        $write = (bool) ($options['write'] ?? false);
        $relativePath = (string) ($options['review_queue'] ?? 'content/backlog/blog-candidates.yaml');
        $targetPath = $siteRoot.'/'.$relativePath;
        $existing = $this->existingEditorialIndex($posts, $publishedSlugs);
        if (isset($existing['slugs'][$candidateSlug])) {
            return [
                'schema_version' => 'atlas.blog_editorial_candidate_acceptance.v1',
                'status' => 'failed',
                'error' => 'candidate_already_in_backlog',
                'candidate_slug' => $candidateSlug,
                'target_path' => $targetPath,
                'write' => $write,
            ];
        }

        $candidate = $this->candidateBySlug($candidateSlug, $posts);

        if (! is_array($candidate)) {
            return [
                'schema_version' => 'atlas.blog_editorial_candidate_acceptance.v1',
                'status' => 'failed',
                'error' => 'candidate_not_found',
                'candidate_slug' => $candidateSlug,
                'target_path' => $targetPath,
                'write' => $write,
            ];
        }

        $entry = $this->reviewQueueEntry($candidate);
        $snippet = $this->reviewQueueSnippet($entry);
        $alreadyQueued = is_file($targetPath)
            && str_contains((string) file_get_contents($targetPath), 'slug: "'.$this->escapeYamlString((string) $candidate['slug']).'"');

        if ($write && ! $alreadyQueued) {
            File::ensureDirectoryExists(dirname($targetPath));
            if (! is_file($targetPath)) {
                File::put($targetPath, "name: \"Blog candidate review queue\"\nstatus: \"review\"\ncandidates:\n");
            }
            File::append($targetPath, $snippet);
        }

        return [
            'schema_version' => 'atlas.blog_editorial_candidate_acceptance.v1',
            'status' => $alreadyQueued ? 'already_queued' : 'ready',
            'mode' => $write ? 'explicit_write_review_queue_p1' : 'dry_run_review_queue_p1',
            'candidate_slug' => $candidateSlug,
            'target_path' => $targetPath,
            'write' => $write,
            'already_queued' => $alreadyQueued,
            'candidate' => $candidate,
            'review_queue_entry' => $entry,
            'yaml_snippet' => $snippet,
            'guardrails' => [
                'writes_main_backlog' => false,
                'writes_review_queue' => $write && ! $alreadyQueued,
                'requires_human_approval_to_promote' => true,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array<string,mixed>
     */
    public function candidateSuggestions(array $posts, array $publishedSlugs = [], int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        $existing = $this->existingEditorialIndex($posts, $publishedSlugs);
        $candidates = [];

        foreach ($this->knowledgeCandidateRows($limit * 3) as $row) {
            $candidate = $this->candidateFromKnowledgeRow($row, $posts);
            if ($candidate === null || isset($existing['slugs'][$candidate['slug']])) {
                continue;
            }
            if (isset($existing['titles'][$this->normalizedTitle((string) $candidate['title'])])) {
                continue;
            }

            $existing['slugs'][$candidate['slug']] = true;
            $existing['titles'][$this->normalizedTitle((string) $candidate['title'])] = true;
            $candidates[] = $candidate;

            if (count($candidates) >= $limit) {
                break;
            }
        }

        if (count($candidates) < $limit) {
            foreach ($this->moduleCandidateRows($limit * 2) as $row) {
                $candidate = $this->candidateFromModuleRow($row, $posts);
                if ($candidate === null || isset($existing['slugs'][$candidate['slug']])) {
                    continue;
                }
                if (isset($existing['titles'][$this->normalizedTitle((string) $candidate['title'])])) {
                    continue;
                }

                $existing['slugs'][$candidate['slug']] = true;
                $existing['titles'][$this->normalizedTitle((string) $candidate['title'])] = true;
                $candidates[] = $candidate;

                if (count($candidates) >= $limit) {
                    break;
                }
            }
        }

        return [
            'schema_version' => 'atlas.blog_editorial_candidates.v1',
            'mode' => 'read_only_review_queue_p1',
            'status' => 'ready',
            'candidate_count' => count($candidates),
            'candidates' => array_values($candidates),
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'requires_human_approval' => true,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'source_of_truth' => 'existing_atlas_engineering_knowledge_and_code_intelligence_read_models',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    public function contextForPost(array $post, int $limit = 5): array
    {
        $limit = max(1, min(12, $limit));
        $terms = $this->terms($post);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'read_only_governed_p1',
            'post_slug' => (string) ($post['slug'] ?? ''),
            'context_query' => $this->contextQuery($post),
            'terms' => $terms,
            'knowledge_refs' => $this->knowledgeRefs($terms, $limit),
            'code_refs' => $this->codeRefs($terms, $limit),
            'coverage_signals' => $this->coverageSignals($post),
            'safety_review' => [
                'requires_human_review' => true,
                'publish_private_paths' => false,
                'publish_internal_ids_or_traces' => false,
                'publish_provider_prompts' => false,
                'rule' => 'Use references to understand the subject; do not expose private implementation details by default.',
            ],
            'guardrails' => [
                'read_only' => true,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'source_of_truth' => 'existing_atlas_engineering_knowledge_and_code_intelligence_read_models',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    private function knowledgeRefs(array $terms, int $limit): array
    {
        if ($terms === [] || ! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return [];
        }

        return AtlasEngineeringKnowledgeItem::query()
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('summary', 'like', "%{$term}%")
                        ->orWhere('canonical_path', 'like', "%{$term}%")
                        ->orWhere('body_excerpt', 'like', "%{$term}%");
                }
            })
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
    private function codeRefs(array $terms, int $limit): array
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
    private function moduleRefs(array $terms, int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_modules')) {
            return [];
        }

        return AtlasEngineeringCodeModule::query()
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('root_path', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                }
            })
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
    private function symbolRefs(array $terms, int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            return [];
        }

        return AtlasEngineeringCodeSymbol::query()
            ->with('module')
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('symbol_name', 'like', "%{$term}%")
                        ->orWhere('file_path', 'like', "%{$term}%")
                        ->orWhere('signature', 'like', "%{$term}%");
                }
            })
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
    private function coverageSignals(array $post): array
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
     * @return array<int,string>
     */
    private function terms(array $post): array
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
    private function contextQuery(array $post): string
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
    private function existingEditorialIndex(array $posts, array $publishedSlugs): array
    {
        $slugs = array_fill_keys(array_values(array_filter($publishedSlugs)), true);
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

    /**
     * @return array<int,AtlasEngineeringKnowledgeItem>
     */
    private function knowledgeCandidateRows(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return [];
        }

        $terms = ['atlas', 'memory', 'memoria', 'context', 'contexto', 'agent', 'agente', 'local', 'governance', 'governanca', 'knowledge', 'conhecimento'];

        return AtlasEngineeringKnowledgeItem::query()
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('summary', 'like', "%{$term}%")
                        ->orWhere('canonical_path', 'like', "%{$term}%");
                }
            })
            ->orderByDesc('priority')
            ->latest('indexed_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return array<int,AtlasEngineeringCodeModule>
     */
    private function moduleCandidateRows(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_modules')) {
            return [];
        }

        $terms = ['memory', 'context', 'agent', 'knowledge', 'publishing', 'open-brain', 'code-intelligence'];

        return AtlasEngineeringCodeModule::query()
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('root_path', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                }
            })
            ->orderByDesc('symbol_count')
            ->orderBy('slug')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    private function candidateBySlug(string $slug, array $posts): ?array
    {
        foreach ($this->knowledgeCandidateRows(500) as $row) {
            $candidate = $this->candidateFromKnowledgeRow($row, $posts);
            if (is_array($candidate) && (string) ($candidate['slug'] ?? '') === $slug) {
                return $candidate;
            }
        }

        foreach ($this->moduleCandidateRows(500) as $row) {
            $candidate = $this->candidateFromModuleRow($row, $posts);
            if (is_array($candidate) && (string) ($candidate['slug'] ?? '') === $slug) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    private function candidateFromKnowledgeRow(AtlasEngineeringKnowledgeItem $item, array $posts): ?array
    {
        $title = $this->candidateTitle((string) $item->title);
        if ($title === '') {
            return null;
        }

        $topics = $this->terms([
            'title' => $item->title,
            'main_question' => $item->summary,
            'topics' => array_merge((array) $item->tags_json, [(string) $item->category]),
        ]);

        return [
            'source_type' => 'engineering_knowledge',
            'source_ref' => (string) $item->canonical_path,
            'title' => $title,
            'slug' => Str::slug($title),
            'collection' => $this->candidateCollection($topics, (string) $item->category),
            'series' => $this->candidateSeries($topics),
            'complexity_level' => $this->candidateComplexity($title, $topics),
            'main_question' => $this->candidateQuestion($title),
            'suggested_after_slug' => $this->suggestedAfterSlug($posts, $topics),
            'topics' => $topics,
            'why' => 'Canonical Atlas knowledge not yet represented in the public backlog.',
            'safety' => [
                'requires_human_review' => true,
                'publish_private_paths' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    private function candidateFromModuleRow(AtlasEngineeringCodeModule $module, array $posts): ?array
    {
        $name = trim((string) ($module->name ?: $module->slug));
        if ($name === '') {
            return null;
        }

        $title = 'Por dentro de '.$name;
        $topics = $this->terms([
            'title' => $title,
            'main_question' => (string) $module->description,
            'topics' => array_merge((array) $module->tags_json, [(string) $module->layer]),
        ]);

        return [
            'source_type' => 'code_module',
            'source_ref' => (string) ($module->root_path ?: $module->slug),
            'title' => $title,
            'slug' => Str::slug($title),
            'collection' => $this->candidateCollection($topics, (string) $module->layer),
            'series' => $this->candidateSeries($topics),
            'complexity_level' => 'L3',
            'main_question' => 'O que esse modulo revela sobre a arquitetura do Atlas?',
            'suggested_after_slug' => $this->suggestedAfterSlug($posts, $topics),
            'topics' => $topics,
            'why' => 'Indexed code module has enough implementation signal to become a public architecture note after prerequisites.',
            'safety' => [
                'requires_human_review' => true,
                'publish_private_paths' => false,
            ],
        ];
    }

    private function candidateTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        $title = preg_replace('/^(Atlas|AP-\d+)\s*[-:]\s*/i', '', $title) ?? $title;

        return trim($title);
    }

    /**
     * @param  array<int,string>  $topics
     */
    private function candidateCollection(array $topics, string $fallback): string
    {
        if ($this->hasAny($topics, ['memory', 'memoria', 'agent', 'agente', 'context', 'contexto'])) {
            return 'ia-pessoal';
        }
        if ($this->hasAny($topics, ['architecture', 'arquitetura', 'code', 'runtime'])) {
            return 'arquitetura';
        }

        return Str::slug($fallback !== '' ? $fallback : 'atlas');
    }

    /**
     * @param  array<int,string>  $topics
     */
    private function candidateSeries(array $topics): string
    {
        if ($this->hasAny($topics, ['memory', 'memoria', 'ledger'])) {
            return 'agent-memory';
        }
        if ($this->hasAny($topics, ['agent', 'agente', 'mandate', 'mandato'])) {
            return 'agent-governance';
        }

        return 'building-atlas';
    }

    /**
     * @param  array<int,string>  $topics
     */
    private function candidateComplexity(string $title, array $topics): string
    {
        $text = Str::lower(Str::ascii($title.' '.implode(' ', $topics)));
        if (str_contains($text, 'runtime') || str_contains($text, 'architecture') || str_contains($text, 'arquitetura')) {
            return 'L3';
        }
        if (str_contains($text, 'memory') || str_contains($text, 'memoria') || str_contains($text, 'governance')) {
            return 'L2';
        }

        return 'L1';
    }

    private function candidateQuestion(string $title): string
    {
        return 'Por que "'.$title.'" importa para entender o Atlas?';
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $topics
     */
    private function suggestedAfterSlug(array $posts, array $topics): ?string
    {
        $best = null;
        $bestScore = -1;

        foreach ($posts as $post) {
            $postTopics = $this->terms($post);
            $score = count(array_intersect($topics, $postTopics));
            $order = (int) ($post['order'] ?? 0);
            if ($score > $bestScore || ($score === $bestScore && $order > (int) ($best['order'] ?? 0))) {
                $best = $post;
                $bestScore = $score;
            }
        }

        return is_array($best) ? (string) ($best['slug'] ?? '') ?: null : null;
    }

    /**
     * @param  array<int,string>  $haystack
     * @param  array<int,string>  $needles
     */
    private function hasAny(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }

    private function normalizedTitle(string $title): string
    {
        return Str::slug(Str::ascii(Str::lower($title)));
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function reviewQueueEntry(array $candidate): array
    {
        return [
            'status' => 'accepted_for_review',
            'accepted_at' => now()->toJSON(),
            'title' => (string) ($candidate['title'] ?? ''),
            'slug' => (string) ($candidate['slug'] ?? ''),
            'collection' => (string) ($candidate['collection'] ?? ''),
            'series' => (string) ($candidate['series'] ?? ''),
            'complexity_level' => (string) ($candidate['complexity_level'] ?? ''),
            'main_question' => (string) ($candidate['main_question'] ?? ''),
            'suggested_after_slug' => (string) ($candidate['suggested_after_slug'] ?? ''),
            'source_type' => (string) ($candidate['source_type'] ?? ''),
            'source_ref' => (string) ($candidate['source_ref'] ?? ''),
            'topics' => array_values(array_filter((array) ($candidate['topics'] ?? []), 'is_string')),
            'why' => (string) ($candidate['why'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function reviewQueueSnippet(array $entry): string
    {
        $lines = [
            '  - status: "'.$this->escapeYamlString((string) $entry['status']).'"',
            '    accepted_at: "'.$this->escapeYamlString((string) $entry['accepted_at']).'"',
            '    title: "'.$this->escapeYamlString((string) $entry['title']).'"',
            '    slug: "'.$this->escapeYamlString((string) $entry['slug']).'"',
            '    collection: "'.$this->escapeYamlString((string) $entry['collection']).'"',
            '    series: "'.$this->escapeYamlString((string) $entry['series']).'"',
            '    complexity_level: "'.$this->escapeYamlString((string) $entry['complexity_level']).'"',
            '    main_question: "'.$this->escapeYamlString((string) $entry['main_question']).'"',
            '    suggested_after_slug: "'.$this->escapeYamlString((string) $entry['suggested_after_slug']).'"',
            '    source_type: "'.$this->escapeYamlString((string) $entry['source_type']).'"',
            '    source_ref: "'.$this->escapeYamlString((string) $entry['source_ref']).'"',
            '    topics:',
        ];

        foreach ((array) $entry['topics'] as $topic) {
            $lines[] = '      - "'.$this->escapeYamlString((string) $topic).'"';
        }

        $lines[] = '    why: "'.$this->escapeYamlString((string) $entry['why']).'"';

        return implode("\n", $lines)."\n";
    }

    private function escapeYamlString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
