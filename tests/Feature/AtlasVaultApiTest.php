<?php

namespace Tests\Feature;

use App\Models\AtlasVaultSyncItem;
use App\Models\SemanticNote;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasVaultApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->vault = sys_get_temp_dir().'/atlas-vault-api-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->vault);
        config()->set('atlas.semantic_memory.vault_path', $this->vault);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);

        parent::tearDown();
    }

    public function test_status_requires_atlas_token(): void
    {
        $this->getJson('/ai/vault/status')->assertUnauthorized();
    }

    public function test_status_returns_vault_payload(): void
    {
        $this->getJson('/ai/vault/status', $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('vault.exists', true)
            ->assertJsonPath('vault.safety_status.write_policy', 'managed_notes_only')
            ->assertJsonPath('vault.sync_queue.open', 0);
    }

    public function test_status_returns_sync_queue_summary(): void
    {
        $this->migrateMinimalVaultSyncItems();
        AtlasVaultSyncItem::query()->create([
            'direction' => 'vault_to_atlas',
            'operation' => 'import',
            'status' => 'conflict',
            'path' => 'Research/api-status-conflict.md',
            'content_hash' => hash('sha256', 'api-status-conflict'),
            'conflict_type' => 'manual_review_required',
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [],
        ]);
        AtlasVaultSyncItem::query()->create([
            'direction' => 'atlas_to_vault',
            'operation' => 'export_semantic_note',
            'status' => 'dismissed',
            'path' => 'Atlas/SemanticNotes/api-status-dismissed.md',
            'content_hash' => hash('sha256', 'api-status-dismissed'),
            'conflict_type' => 'previously_dismissed_conflict',
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [],
            'reviewed_at' => now(),
            'resolved_at' => now(),
        ]);

        $this->getJson('/ai/vault/status', $this->headers)
            ->assertOk()
            ->assertJsonPath('vault.sync_queue.migrated', true)
            ->assertJsonPath('vault.sync_queue.total', 2)
            ->assertJsonPath('vault.sync_queue.open', 1)
            ->assertJsonPath('vault.sync_queue.conflicts', 1)
            ->assertJsonPath('vault.sync_queue.historical_conflicts', 2)
            ->assertJsonPath('vault.sync_queue.by_status.conflict', 1);
    }

    public function test_import_dry_run_api_stages_human_note(): void
    {
        File::ensureDirectoryExists($this->vault.'/Research');
        File::put($this->vault.'/Research/api-import.md', $this->semanticMarkdown('API Import Note'));

        $this->postJson('/ai/vault/import', [
            'path' => 'Research/api-import.md',
            'dry_run' => true,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'candidate')
            ->assertJsonPath('dry_run', true);
    }

    public function test_import_api_requires_exactly_one_mode(): void
    {
        $this->postJson('/ai/vault/import', [
            'path' => 'Research/api-import.md',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Use exactly one of dry_run or write.');
    }

    public function test_import_api_returns_json_error_for_missing_file(): void
    {
        $this->postJson('/ai/vault/import', [
            'path' => 'Research/missing-import.md',
            'dry_run' => true,
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Vault file not found: Research/missing-import.md');
    }

    public function test_import_api_returns_json_error_for_unsafe_path(): void
    {
        $this->postJson('/ai/vault/import', [
            'path' => '../outside.md',
            'dry_run' => true,
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'AtlasVault sync path must be a safe vault-relative markdown path.');
    }

    public function test_export_semantic_dry_run_api_returns_managed_note(): void
    {
        $this->migrateMinimalSemanticNotes();
        $note = SemanticNote::query()->create([
            'note_key' => 'api_export',
            'path' => 'Research/api-source.md',
            'title' => 'API Export Note',
            'type' => 'source_note',
            'status' => 'active',
            'confidence' => 'low',
            'maturity' => 'draft',
            'domains' => [],
            'summary' => 'Resumo via API',
            'body_excerpt' => 'Conteudo provider-safe.',
            'frontmatter' => [],
            'when_to_use' => [],
            'trigger_signals' => [],
            'do_not_use_when' => [],
            'postgres_refs' => [],
            'content_hash' => hash('sha256', 'api-export'),
            'validation_errors' => [],
            'metadata' => [],
        ]);

        $this->postJson('/ai/vault/export-semantic', [
            'semantic_note_id' => $note->id,
            'dry_run' => true,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('note.frontmatter.atlas_type', 'semantic_note');
    }

    public function test_export_semantic_api_returns_json_404_for_missing_note(): void
    {
        $this->migrateMinimalSemanticNotes();

        $this->postJson('/ai/vault/export-semantic', [
            'semantic_note_id' => '00000000-0000-4000-8000-000000000001',
            'dry_run' => true,
        ], $this->headers)
            ->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Semantic note not found.');
    }

    public function test_sync_api_requires_exactly_one_mode(): void
    {
        $this->postJson('/ai/vault/sync', [
            'limit' => 1,
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Use exactly one of dry_run or write.');
    }

    public function test_conflict_resolve_api_updates_item(): void
    {
        $this->migrateMinimalVaultSyncItems();
        $item = AtlasVaultSyncItem::query()->create([
            'direction' => 'vault_to_atlas',
            'operation' => 'import',
            'status' => 'conflict',
            'path' => 'Research/api-conflict.md',
            'content_hash' => hash('sha256', 'api-conflict'),
            'conflict_type' => 'manual_review_required',
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [],
        ]);

        $this->postJson("/ai/vault/conflicts/{$item->id}/resolve", [
            'resolution' => 'dismiss',
            'reason' => "Duplicated human note\nreviewed in vault",
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item.status', 'dismissed')
            ->assertJsonPath('item.metadata.resolution_action', 'dismiss')
            ->assertJsonPath('item.metadata.resolution_reason', 'Duplicated human note reviewed in vault');
    }

    public function test_conflicts_api_filters_review_queue_items(): void
    {
        $this->migrateMinimalVaultSyncItems();
        AtlasVaultSyncItem::query()->create([
            'direction' => 'vault_to_atlas',
            'operation' => 'import',
            'status' => 'blocked',
            'path' => 'Research/api-blocked-import.md',
            'content_hash' => hash('sha256', 'api-blocked-import'),
            'conflict_type' => 'privacy_review_required',
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [],
        ]);
        AtlasVaultSyncItem::query()->create([
            'direction' => 'atlas_to_vault',
            'operation' => 'export_semantic_note',
            'status' => 'conflict',
            'path' => 'Atlas/SemanticNotes/api-conflict-export.md',
            'content_hash' => hash('sha256', 'api-conflict-export'),
            'conflict_type' => 'stale_export',
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [],
        ]);

        $this->getJson('/ai/vault/conflicts?status=conflict&direction=atlas_to_vault&operation=export_semantic_note', $this->headers)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('filters.statuses.0', 'conflict')
            ->assertJsonPath('filters.direction', 'atlas_to_vault')
            ->assertJsonPath('filters.operation', 'export_semantic_note')
            ->assertJsonPath('items.0.path', 'Atlas/SemanticNotes/api-conflict-export.md');
    }

    public function test_conflicts_api_rejects_unknown_filter_values(): void
    {
        $this->migrateMinimalVaultSyncItems();

        $this->getJson('/ai/vault/conflicts?direction=unknown', $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Unsupported AtlasVault conflicts direction filter: unknown');
    }

    public function test_conflicts_api_rejects_array_filter_values(): void
    {
        $this->migrateMinimalVaultSyncItems();

        $this->getJson('/ai/vault/conflicts?direction[]=atlas_to_vault', $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'AtlasVault conflicts filter must be a string.');
    }

    public function test_conflict_item_api_returns_sync_item_detail(): void
    {
        $this->migrateMinimalVaultSyncItems();
        $item = AtlasVaultSyncItem::query()->create([
            'direction' => 'atlas_to_vault',
            'operation' => 'export_semantic_note',
            'status' => 'conflict',
            'path' => 'Atlas/SemanticNotes/api-item-detail.md',
            'content_hash' => hash('sha256', 'api-item-detail'),
            'conflict_type' => 'stale_export',
            'frontmatter_json' => ['atlas_managed' => true],
            'links_json' => [['label' => 'Semantic Note', 'uri' => 'atlas://semantic-note/note_456']],
            'metadata' => ['privacy' => ['privacy_class' => 'normal']],
        ]);

        $this->getJson("/ai/vault/conflicts/{$item->id}", $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item.id', $item->id)
            ->assertJsonPath('item.path', 'Atlas/SemanticNotes/api-item-detail.md')
            ->assertJsonPath('item.status', 'conflict')
            ->assertJsonPath('allowed_resolution_actions.3', 'regenerate');
    }

    public function test_conflict_item_api_returns_json_404_for_missing_item(): void
    {
        $this->migrateMinimalVaultSyncItems();

        $this->getJson('/ai/vault/conflicts/missing-item-id', $this->headers)
            ->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'AtlasVault sync item not found.');
    }

    public function test_conflict_item_api_returns_json_422_when_queue_table_is_not_migrated(): void
    {
        Schema::dropIfExists('atlas_vault_sync_items');

        $this->getJson('/ai/vault/conflicts/00000000-0000-0000-0000-000000000001', $this->headers)
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'atlas_vault_sync_items table is not migrated.');
    }

    public function test_conflict_resolve_api_returns_json_404_for_missing_item(): void
    {
        $this->migrateMinimalVaultSyncItems();

        $this->postJson('/ai/vault/conflicts/missing-item-id/resolve', [
            'resolution' => 'dismiss',
            'reason' => 'Missing item smoke test',
        ], $this->headers)
            ->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'AtlasVault sync item not found.');
    }

    public function test_conflict_resolve_api_returns_json_422_when_queue_table_is_not_migrated(): void
    {
        Schema::dropIfExists('atlas_vault_sync_items');

        $this->postJson('/ai/vault/conflicts/00000000-0000-0000-0000-000000000001/resolve', [
            'resolution' => 'dismiss',
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'atlas_vault_sync_items table is not migrated.');
    }

    public function test_conflict_resolve_api_regenerates_semantic_export(): void
    {
        $this->migrateMinimalVaultSyncItems();
        $this->migrateMinimalSemanticNotes();
        $note = SemanticNote::query()->create([
            'note_key' => 'api_regenerate',
            'path' => 'Research/api-regenerate.md',
            'title' => 'API Regenerate Export',
            'type' => 'source_note',
            'status' => 'active',
            'confidence' => 'low',
            'maturity' => 'draft',
            'domains' => [],
            'summary' => 'Resumo regeneravel via API',
            'body_excerpt' => 'Conteudo regenerado pela API.',
            'frontmatter' => [],
            'when_to_use' => [],
            'trigger_signals' => [],
            'do_not_use_when' => [],
            'postgres_refs' => [],
            'content_hash' => hash('sha256', 'api-regenerate'),
            'validation_errors' => [],
            'metadata' => [],
        ]);
        $item = AtlasVaultSyncItem::query()->create([
            'direction' => 'atlas_to_vault',
            'operation' => 'export_semantic_note',
            'status' => 'conflict',
            'path' => 'Atlas/SemanticNotes/api-regenerate-export-'.$note->id.'.md',
            'source_type' => 'semantic_note',
            'source_id' => $note->id,
            'semantic_note_id' => $note->id,
            'content_hash' => hash('sha256', 'api-old-conflict'),
            'conflict_type' => 'stale_export',
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [],
        ]);

        $response = $this->postJson("/ai/vault/conflicts/{$item->id}/resolve", [
            'resolution' => 'regenerate',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item.status', 'regenerated')
            ->assertJsonPath('item.metadata.regeneration.ok', true);

        $path = $this->vault.'/'.data_get($response->json(), 'item.metadata.regeneration.path');
        $this->assertTrue(File::exists($path));
        $this->assertStringContainsString('Conteudo regenerado pela API.', File::get($path));
    }

    private function semanticMarkdown(string $title): string
    {
        $id = strtolower(str_replace(' ', '-', $title));

        return <<<MD
---
id: {$id}
type: source_note
title: {$title}
status: active
summary: Nota humana importavel via API.
when_to_use:
  - Revisar via API local.
---

Corpo humano provider-safe.
MD;
    }

    private function migrateMinimalVaultSyncItems(): void
    {
        if (Schema::hasTable('atlas_vault_sync_items')) {
            return;
        }

        Schema::create('atlas_vault_sync_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('direction');
            $table->string('operation');
            $table->string('status')->default('pending');
            $table->string('path')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->uuid('semantic_note_id')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('conflict_type')->nullable();
            $table->text('summary')->nullable();
            $table->json('frontmatter_json')->default('{}');
            $table->json('links_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    private function migrateMinimalSemanticNotes(): void
    {
        if (Schema::hasTable('semantic_notes')) {
            return;
        }

        Schema::create('semantic_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('note_key')->unique();
            $table->string('path')->unique();
            $table->string('title');
            $table->string('type');
            $table->string('status');
            $table->string('confidence')->default('low');
            $table->string('maturity')->default('draft');
            $table->json('domains')->default('[]');
            $table->text('summary')->nullable();
            $table->text('body_excerpt')->nullable();
            $table->json('frontmatter')->default('{}');
            $table->json('when_to_use')->default('[]');
            $table->json('trigger_signals')->default('[]');
            $table->json('do_not_use_when')->default('[]');
            $table->json('postgres_refs')->default('[]');
            $table->string('content_hash', 64);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_activated_at')->nullable();
            $table->timestamp('last_practiced_at')->nullable();
            $table->unsignedInteger('activation_count')->default(0);
            $table->float('usefulness_avg')->nullable();
            $table->json('validation_errors')->default('[]');
            $table->json('metadata')->default('{}');
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
