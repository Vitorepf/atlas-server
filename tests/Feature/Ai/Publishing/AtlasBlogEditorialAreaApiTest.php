<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Publishing;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasBlogEditorialAreaApiTest extends TestCase
{
    private string $siteRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        $this->siteRoot = sys_get_temp_dir().'/atlas-blog-editorial-area-api-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->siteRoot.'/content/backlog');
        File::ensureDirectoryExists($this->siteRoot.'/src/data');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->siteRoot);

        parent::tearDown();
    }

    public function test_state_endpoint_exposes_read_only_blog_area_packet(): void
    {
        $this->writeBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas']);

        $response = $this
            ->withHeaders(['X-Atlas-Token' => 'test-token-with-enough-length-123'])
            ->getJson('/blog/editorial/state?site='.urlencode($this->siteRoot).'&candidate_limit=2');

        $response->assertOk();
        $response->assertJsonPath('schema_version', 'atlas.blog_editorial_area_state_api.v1');
        $response->assertJsonPath('mode', 'read_only_area_surface_p1');
        $response->assertJsonPath('area.name', 'blog_editorial_planning');
        $response->assertJsonPath('area.guardrails.read_only', true);
        $response->assertJsonPath('area.guardrails.writes_backlog', false);
        $response->assertJsonPath('area.guardrails.publishes_content', false);
        $response->assertJsonPath('area.guardrails.reorders_posts', false);
        $response->assertJsonPath('planner.summary.with_operating_state', true);
        $response->assertJsonPath('planner.summary.with_review_queue', true);
        $response->assertJsonPath('planner.summary.with_candidate_suggestions', true);
        $response->assertJsonPath('planner.operating_state.schema_version', 'atlas.blog_editorial_operating_state.v1');
        $response->assertJsonPath('planner.operating_state.next_post.slug', 'por-que-estou-construindo-o-atlas');
        $response->assertJsonPath('planner.operating_state.publication_frontier.next_sequence_order', 2);
        $response->assertJsonPath('planner.graph_rag_readiness.schema_version', 'atlas.blog_editorial_graph_rag_readiness.v1');
        $response->assertJsonPath('planner.guardrails.publishes_content', false);
    }

    public function test_state_endpoint_requires_atlas_token(): void
    {
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $this->getJson('/blog/editorial/state?site='.urlencode($this->siteRoot))
            ->assertUnauthorized();
    }

    private function writeBacklog(): void
    {
        File::put($this->siteRoot.'/content/backlog/blog-first-month.yaml', <<<'YAML'
name: "Primeiro mes do blog"
cadence: "5 posts por semana"
status: "planned"
purpose: "Criar base publica antes de assuntos tecnicos profundos."
publishing_days:
  - monday
  - tuesday
  - wednesday
  - thursday
  - friday
buffer_days:
  - saturday
  - sunday
rule: "A ordem importa mais que a data."
weeks:
  - week: 1
    theme: "Orientacao"
    goal: "A pessoa entende o Atlas antes de temas profundos."
    posts:
      - order: 1
        title: "O que e o Atlas"
        slug: "o-que-e-o-atlas"
        type: "essay"
        complexity_level: "L0"
        collection: "atlas"
        series: "building-atlas"
        reader_level: "beginner"
        goal: "Explicar o Atlas em linguagem simples."
        main_question: "O que estou construindo quando falo de Atlas?"
        prerequisites: []
        next_reading:
          - "por-que-estou-construindo-o-atlas"
        topics:
          - "atlas"
      - order: 2
        title: "Por que estou construindo o Atlas"
        slug: "por-que-estou-construindo-o-atlas"
        type: "essay"
        complexity_level: "L0"
        collection: "atlas"
        series: "building-atlas"
        reader_level: "beginner"
        goal: "Mostrar motivacao e direcao."
        main_question: "Que problema real me fez comecar o Atlas?"
        prerequisites:
          - "o-que-e-o-atlas"
        next_reading:
          - "o-problema-dos-assistentes-de-ia-hoje"
        topics:
          - "atlas"
      - order: 3
        title: "O problema dos assistentes de IA hoje"
        slug: "o-problema-dos-assistentes-de-ia-hoje"
        type: "essay"
        complexity_level: "L1"
        collection: "ia-pessoal"
        series: "building-atlas"
        reader_level: "beginner"
        goal: "Explicar por que assistentes atuais ainda sao genericos."
        main_question: "Por que os assistentes atuais continuam genericos?"
        prerequisites:
          - "por-que-estou-construindo-o-atlas"
        next_reading: []
        topics:
          - "ia-pessoal"
YAML);
    }

    /**
     * @param  array<int,string>  $slugs
     */
    private function writePublishedPosts(array $slugs): void
    {
        $items = collect($slugs)->map(function (string $slug): string {
            $title = str_replace('-', ' ', $slug);

            return <<<JS
  {
    slug: "{$slug}",
    kind: "essay",
    date: "2026-06-15",
    reading: 5,
    tags: ["atlas"],
    collection: "atlas",
    series: "building-atlas",
    original: "pt",
    pt: { title: "{$title}", excerpt: "Publicado." },
    en: { title: "{$title}", excerpt: "Published." },
  }
JS;
        })->implode(",\n");

        File::put($this->siteRoot.'/src/data/site.js', <<<JS
export const collections = [];
export const posts = [
{$items}
];
JS);
    }
}
