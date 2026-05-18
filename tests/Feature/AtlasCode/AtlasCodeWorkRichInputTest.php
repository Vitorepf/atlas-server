<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Atlas Unified Rich Input adapter for Forge/Obras creation.
 *
 * Hard contract:
 *  - POST /atlas-code/works MUST keep accepting plain-text payloads
 *    (intent + objective only) — back-compat.
 *  - When `rich_input` is provided, the controller MUST persist a
 *    normalised metadata.rich_input + metadata.context_refs[] without
 *    flipping the Obra into `forge_work_intake_ready=true`.
 *  - No attachment payload (raw text content, secret-looking values)
 *    leaks back through the response; only hashes + sizes.
 */
class AtlasCodeWorkRichInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->text('definition_of_done')->nullable();
                $t->string('priority')->default('medium');
                $t->timestamp('last_touched_at')->nullable();
                $t->timestamp('next_review_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_projects');
        parent::tearDown();
    }

    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    public function test_plain_text_obra_creation_still_works_without_rich_input(): void
    {
        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/works', [
            'intent' => 'plano simples sem anexo',
            'objective' => 'criar obra apenas com texto',
            'domain' => 'programming',
        ]);

        $response->assertCreated()
            ->assertJsonPath('work.objective', 'criar obra apenas com texto')
            ->assertJsonPath('work.metadata.origin', 'atlas-code')
            ->assertJsonMissingPath('work.metadata.rich_input')
            ->assertJsonMissingPath('work.metadata.context_refs');
    }

    public function test_obra_creation_with_pdf_url_and_text_block_persists_rich_input(): void
    {
        $payload = [
            'intent' => 'analisar PDF do trabalho de pesquisa',
            'objective' => 'criar obra com material de referência',
            'domain' => 'programming',
            'rich_input' => [
                'uploaded_documents' => ['doc_pdf_a1B2c3'],
                'uploaded_images' => ['img_png_zZ9'],
                'url_attachments' => [
                    [
                        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                        'kind' => 'youtube',
                        'title' => 'video de referencia',
                        'author' => 'autor',
                        'duration_sec' => 213,
                        'thumbnail_url' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
                        'ref_id' => 'dQw4w9WgXcQ',
                    ],
                ],
                'text_blocks' => [
                    [
                        'file_name' => 'notas.md',
                        'mime_type' => 'text/markdown',
                        'language' => 'markdown',
                        'content' => "# Nota\n\nresumo do material colado pelo operador.",
                        'page_count' => 0,
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/works', $payload);

        $response->assertCreated();

        $metadata = $response->json('work.metadata');
        $this->assertIsArray($metadata);

        // Has-attachments flag + intake readiness invariant.
        $this->assertTrue($metadata['rich_input_has_attachments']);
        $this->assertArrayHasKey('forge_work_intake_ready', $metadata);
        $this->assertFalse(
            $metadata['forge_work_intake_ready'],
            'Attachments alone must not flip forge_work_intake_ready=true.',
        );

        // Normalised rich_input shape.
        $rich = $metadata['rich_input'];
        $this->assertSame('atlas.unified_rich_input.adapter.v1', $rich['schema_version']);
        $this->assertSame(['doc_pdf_a1B2c3'], $rich['uploaded_documents']);
        $this->assertSame(['img_png_zZ9'], $rich['uploaded_images']);
        $this->assertCount(1, $rich['url_attachments']);
        $this->assertSame('youtube', $rich['url_attachments'][0]['kind']);
        $this->assertSame('dQw4w9WgXcQ', $rich['url_attachments'][0]['ref_id']);
        $this->assertArrayHasKey('content_hash', $rich['url_attachments'][0], 'URL must carry deterministic content_hash');
        $this->assertCount(1, $rich['text_blocks']);
        $this->assertArrayHasKey('content_hash', $rich['text_blocks'][0]);
        $this->assertArrayNotHasKey('content', $rich['text_blocks'][0], 'Raw content body must NOT survive in metadata');
        $this->assertSame(48, $rich['text_blocks'][0]['content_length']);

        // Context refs are flat + dedup-friendly for Forge Intake.
        $this->assertIsArray($metadata['context_refs']);
        $this->assertContains('image_asset:img_png_zZ9', $metadata['context_refs']);
        $this->assertContains('document_asset:doc_pdf_a1B2c3', $metadata['context_refs']);
        $this->assertTrue(
            collect($metadata['context_refs'])->contains(fn (string $ref): bool => str_starts_with($ref, 'url:')),
            'context_refs must include a url:<hash> entry for the YouTube attachment.',
        );
        $this->assertTrue(
            collect($metadata['context_refs'])->contains(fn (string $ref): bool => str_starts_with($ref, 'text_block:')),
            'context_refs must include a text_block:<hash> entry.',
        );
    }

    public function test_obra_creation_rejects_invalid_uploaded_image_id_format(): void
    {
        $payload = [
            'intent' => 'tentar injetar id invalido',
            'objective' => 'rejeitar uploaded_image_ids inseguros',
            'rich_input' => [
                'uploaded_images' => ['bad../traversal'],
            ],
        ];

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/works', $payload);
        $response->assertStatus(422);
    }

    public function test_obra_creation_rejects_too_many_uploaded_images(): void
    {
        $imageIds = array_map(static fn (int $i): string => 'img_'.$i, range(1, 9));

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/works', [
            'intent' => 'tentar 9 imagens',
            'objective' => 'rejeitar limite excedido',
            'rich_input' => [
                'uploaded_images' => $imageIds,
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_url_content_hash_is_deterministic_for_same_input(): void
    {
        $payload = [
            'intent' => 'mesmo url duas vezes',
            'objective' => 'obra para verificar hash determinístico',
            'rich_input' => [
                'url_attachments' => [
                    [
                        'url' => 'https://example.com/article',
                        'ref_id' => 'article-1',
                    ],
                ],
            ],
        ];

        $first = $this->withHeaders($this->headers())->postJson('/atlas-code/works', $payload);
        $second = $this->withHeaders($this->headers())->postJson('/atlas-code/works', $payload);

        $first->assertCreated();
        $second->assertCreated();

        $hashFirst = $first->json('work.metadata.rich_input.url_attachments.0.content_hash');
        $hashSecond = $second->json('work.metadata.rich_input.url_attachments.0.content_hash');

        $this->assertSame($hashFirst, $hashSecond);
        $this->assertSame(64, strlen($hashFirst), 'content_hash must be hex sha256 (64 chars).');
    }

    public function test_empty_rich_input_does_not_pollute_metadata(): void
    {
        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/works', [
            'intent' => 'rich_input vazio',
            'objective' => 'criar obra com rich_input vazio',
            'rich_input' => [
                'uploaded_images' => [],
                'uploaded_documents' => [],
                'url_attachments' => [],
                'text_blocks' => [],
            ],
        ]);

        $response->assertCreated();
        $metadata = $response->json('work.metadata');
        // rich_input is dropped entirely when no attachments survive.
        $this->assertArrayNotHasKey('rich_input', $metadata);
        $this->assertArrayNotHasKey('context_refs', $metadata);
    }
}
