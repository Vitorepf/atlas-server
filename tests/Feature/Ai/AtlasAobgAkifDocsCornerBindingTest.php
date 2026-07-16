<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasKnowledgeSourcePacket;
use App\Services\Ai\AtlasAobgWorkspaceOnboardingService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADN F5 — a ativação de workspace registra o canto de docs do repo no AKIF
 * (doc canônico: docs/engineering-knowledge-base/atlas-documentation-network.md).
 *
 * Contratos pinados:
 *  1. Workspace COM canto docs/engineering-knowledge-base → source packet
 *     `repo` com lineage (workspace_id + docs_root no metadata), nascido em
 *     quarentena (`received`), provider_safe.
 *  2. Workspace SEM canto → nada registrado (ausência é ausência).
 *  3. Idempotente: ativar N vezes → 1 packet (dedup por source_hash).
 */
class AtlasAobgAkifDocsCornerBindingTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_knowledge_source_packets');
        $migration = require database_path('migrations/2026_05_25_040000_create_atlas_knowledge_source_packets_table.php');
        $migration->up();

        $this->workspace = sys_get_temp_dir().'/adn-akif-'.uniqid();
        File::makeDirectory($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_workspace_with_docs_corner_registers_repo_source_packet(): void
    {
        File::makeDirectory($this->workspace.'/docs/engineering-knowledge-base', 0755, true);

        $result = app(AtlasAobgWorkspaceOnboardingService::class)
            ->registerDocsCornerInAkif($this->workspace, 'repo-teste');

        $this->assertTrue($result['ok']);

        $packet = AtlasKnowledgeSourcePacket::query()->first();
        $this->assertNotNull($packet);
        $this->assertSame('repo', $packet->source_type);
        $this->assertSame('received', $packet->ingestion_status);
        $this->assertTrue((bool) $packet->provider_safe);
        $this->assertSame('docs_corner.v1', data_get($packet->metadata, 'adn'));
        $this->assertSame('repo-teste', data_get($packet->metadata, 'workspace_id'));
        $this->assertSame('docs/engineering-knowledge-base', data_get($packet->metadata, 'docs_root'));
    }

    public function test_workspace_without_docs_corner_registers_nothing(): void
    {
        $result = app(AtlasAobgWorkspaceOnboardingService::class)
            ->registerDocsCornerInAkif($this->workspace, 'repo-sem-canto');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_docs_corner', $result['reason']);
        $this->assertSame(0, AtlasKnowledgeSourcePacket::query()->count());
    }

    public function test_binding_is_idempotent_across_activations(): void
    {
        File::makeDirectory($this->workspace.'/docs/engineering-knowledge-base', 0755, true);

        $service = app(AtlasAobgWorkspaceOnboardingService::class);
        $service->registerDocsCornerInAkif($this->workspace, 'repo-teste');
        $service->registerDocsCornerInAkif($this->workspace, 'repo-teste');

        $this->assertSame(1, AtlasKnowledgeSourcePacket::query()->count());
    }
}
