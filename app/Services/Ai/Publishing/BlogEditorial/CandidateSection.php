<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

final class CandidateSection
{
    public function __construct(
        private readonly EditorialSupport $support = new EditorialSupport(),
    ) {}

    /**
     * @return array<int,AtlasEngineeringKnowledgeItem>
     */
    public function knowledgeCandidateRows(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return [];
        }

        $terms = ['atlas', 'memory', 'memoria', 'context', 'contexto', 'agent', 'agente', 'local', 'governance', 'governanca', 'knowledge', 'conhecimento'];

        return AtlasEngineeringKnowledgeItem::query()
            ->active()
            ->where($this->support->termsMatcher($terms, ['slug', 'title', 'summary', 'canonical_path']))
            ->orderByDesc('priority')
            ->latest('indexed_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return array<int,AtlasEngineeringCodeModule>
     */
    public function moduleCandidateRows(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_modules')) {
            return [];
        }

        $terms = ['memory', 'context', 'agent', 'knowledge', 'publishing', 'open-brain', 'code-intelligence'];

        return AtlasEngineeringCodeModule::query()
            ->active()
            ->where($this->support->termsMatcher($terms, ['slug', 'name', 'root_path', 'description']))
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
    public function candidateBySlug(string $slug, array $posts): ?array
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
    public function candidateFromKnowledgeRow(AtlasEngineeringKnowledgeItem $item, array $posts): ?array
    {
        $title = $this->candidateTitle((string) $item->title);
        if ($title === '') {
            return null;
        }

        $topics = $this->support->terms([
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
    public function candidateFromModuleRow(AtlasEngineeringCodeModule $module, array $posts): ?array
    {
        $name = trim((string) ($module->name ?: $module->slug));
        if ($name === '') {
            return null;
        }

        $title = 'Por dentro de '.$name;
        $topics = $this->support->terms([
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

    public function candidateTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        $title = preg_replace('/^(Atlas|AP-\d+)\s*[-:]\s*/i', '', $title) ?? $title;

        return trim($title);
    }

    /**
     * @param  array<int,string>  $topics
     */
    public function candidateCollection(array $topics, string $fallback): string
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
    public function candidateSeries(array $topics): string
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
    public function candidateComplexity(string $title, array $topics): string
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

    public function candidateQuestion(string $title): string
    {
        return 'Por que "'.$title.'" importa para entender o Atlas?';
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $topics
     */
    public function suggestedAfterSlug(array $posts, array $topics): ?string
    {
        $best = null;
        $bestScore = -1;

        foreach ($posts as $post) {
            $postTopics = $this->support->terms($post);
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
    public function hasAny(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }
}
