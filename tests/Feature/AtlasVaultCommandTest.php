<?php

namespace Tests\Feature;

use App\Services\Semantic\AtlasVaultManagedNoteService;
use App\Models\AtlasVaultSyncItem;
use App\Models\SemanticNote;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasVaultCommandTest extends TestCase
{
    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = sys_get_temp_dir().'/atlas-vault-command-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->vault);
        config()->set('atlas.semantic_memory.vault_path', $this->vault);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);

        parent::tearDown();
    }

    public function test_status_json_reports_vault_safety_and_counts(): void
    {
        File::ensureDirectoryExists($this->vault.'/Atlas/Memory');
        File::put($this->vault.'/Atlas/Memory/conflict.md', $this->managedMarkdown('mem_conflict', 'conflict'));

        $exit = Artisan::call('atlas:vault', ['action' => 'status', '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue(data_get($payload, 'vault.exists'));
        $this->assertSame(1, data_get($payload, 'vault.managed_notes_count'));
        $this->assertSame(1, data_get($payload, 'vault.conflicts_count'));
        $this->assertSame(1, data_get($payload, 'vault.invalid_notes_count'));
        $this->assertSame('managed_notes_only', data_get($payload, 'vault.safety_status.write_policy'));
        $this->assertContains('memory_entry', data_get($payload, 'vault.supported_note_types'));
        $this->assertContains('open-brain/audit', data_get($payload, 'vault.safety_status.supported_link_types'));
    }

    public function test_status_json_reports_sync_queue_summary_when_migrated(): void
    {
        $this->migrateMinimalVaultSyncItems();
        AtlasVaultSyncItem::query()->create([
            'direction' => 'vault_to_atlas',
            'operation' => 'import',
            'status' => 'blocked',
            'path' => 'Research/blocked.md',
            'content_hash' => hash('sha256', 'blocked'),
            'conflict_type' => 'privacy_review_required',
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [],
        ]);
        AtlasVaultSyncItem::query()->create([
            'direction' => 'atlas_to_vault',
            'operation' => 'export_semantic_note',
            'status' => 'reviewed',
            'path' => 'Atlas/SemanticNotes/reviewed.md',
            'content_hash' => hash('sha256', 'reviewed'),
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [],
            'reviewed_at' => now(),
            'resolved_at' => now(),
        ]);

        $exit = Artisan::call('atlas:vault', ['action' => 'status', '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue(data_get($payload, 'vault.sync_queue.migrated'));
        $this->assertSame(2, data_get($payload, 'vault.sync_queue.total'));
        $this->assertSame(1, data_get($payload, 'vault.sync_queue.open'));
        $this->assertSame(1, data_get($payload, 'vault.sync_queue.blocked'));
        $this->assertSame(1, data_get($payload, 'vault.sync_queue.conflicts'));
        $this->assertSame(1, data_get($payload, 'vault.sync_queue.reviewed'));
        $this->assertSame(1, data_get($payload, 'vault.sync_queue.resolved'));
        $this->assertSame(1, data_get($payload, 'vault.sync_queue.by_direction.vault_to_atlas'));
        $this->assertSame(1, data_get($payload, 'vault.sync_queue.by_direction.atlas_to_vault'));
    }

    public function test_status_json_counts_managed_note_with_invalid_timestamp_as_invalid(): void
    {
        File::ensureDirectoryExists($this->vault.'/Atlas/Memory');
        File::put(
            $this->vault.'/Atlas/Memory/invalid-timestamp.md',
            str_replace('updated_at: 2026-05-03T00:00:00Z', 'updated_at: tomorrow', $this->managedMarkdown('mem_invalid_time', 'managed')),
        );

        $exit = Artisan::call('atlas:vault', ['action' => 'status', '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'vault.managed_notes_count'));
        $this->assertSame(1, data_get($payload, 'vault.conflicts_count'));
        $this->assertSame(1, data_get($payload, 'vault.invalid_notes_count'));
    }

    public function test_status_json_counts_managed_note_with_unsafe_privacy_invariant_as_invalid(): void
    {
        File::ensureDirectoryExists($this->vault.'/Atlas/Memory');
        File::put(
            $this->vault.'/Atlas/Memory/secret-provider-safe.md',
            str_replace('privacy_class: normal', 'privacy_class: secret', $this->managedMarkdown('mem_secret', 'managed')),
        );

        $exit = Artisan::call('atlas:vault', ['action' => 'status', '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'vault.managed_notes_count'));
        $this->assertSame(1, data_get($payload, 'vault.conflicts_count'));
        $this->assertSame(1, data_get($payload, 'vault.invalid_notes_count'));
    }

    public function test_status_json_counts_managed_note_with_unsafe_redaction_invariant_as_invalid(): void
    {
        File::ensureDirectoryExists($this->vault.'/Atlas/Memory');
        File::put(
            $this->vault.'/Atlas/Memory/blocked-provider-safe.md',
            str_replace('redaction_status: clean', 'redaction_status: blocked', $this->managedMarkdown('mem_blocked', 'managed')),
        );

        $exit = Artisan::call('atlas:vault', ['action' => 'status', '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'vault.managed_notes_count'));
        $this->assertSame(1, data_get($payload, 'vault.conflicts_count'));
        $this->assertSame(1, data_get($payload, 'vault.invalid_notes_count'));
    }

    public function test_status_without_json_renders_human_summary(): void
    {
        $exit = Artisan::call('atlas:vault', ['action' => 'status']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('AtlasVault status', $output);
        $this->assertStringContainsString('Write policy: managed_notes_only', $output);
    }

    public function test_status_json_does_not_create_missing_vault_directory(): void
    {
        File::deleteDirectory($this->vault);

        $exit = Artisan::call('atlas:vault', ['action' => 'status', '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertFalse(data_get($payload, 'vault.exists'));
        $this->assertFalse(data_get($payload, 'vault.writable'));
        $this->assertSame(0, data_get($payload, 'vault.inspected_markdown_count'));
        $this->assertFalse(File::isDirectory($this->vault));
    }

    public function test_note_dry_run_does_not_write_file(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--dry-run' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md', data_get($payload, 'note.path'));
        $this->assertStringContainsString('<!-- ATLAS:MANAGED:START -->', data_get($payload, 'note.markdown'));
        $this->assertFalse(File::exists($this->vault.'/'.data_get($payload, 'note.path')));
    }

    public function test_note_dry_run_does_not_create_missing_vault_directory(): void
    {
        File::deleteDirectory($this->vault);

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--dry-run' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('create', data_get($payload, 'note.metadata.operation'));
        $this->assertFalse(data_get($payload, 'note.metadata.written'));
        $this->assertFalse(File::isDirectory($this->vault));
    }

    public function test_note_write_creates_missing_vault_directory_when_safe(): void
    {
        File::deleteDirectory($this->vault);

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue(File::isDirectory($this->vault));
        $this->assertTrue(File::exists($this->vault.'/'.data_get($payload, 'note.path')));
    }

    public function test_note_requires_exactly_one_execution_mode(): void
    {
        $withoutMode = Artisan::call('atlas:vault', $this->noteArgs(['--json' => true]));
        $withoutModePayload = json_decode(Artisan::output(), true);

        $withBoth = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--write' => true,
            '--json' => true,
        ]));
        $withBothPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $withoutMode);
        $this->assertStringContainsString('exactly one', data_get($withoutModePayload, 'error'));
        $this->assertSame(1, $withBoth);
        $this->assertStringContainsString('exactly one', data_get($withBothPayload, 'error'));
    }

    public function test_import_dry_run_stages_human_note_without_writing_queue(): void
    {
        File::ensureDirectoryExists($this->vault.'/Research');
        File::put($this->vault.'/Research/importable.md', $this->semanticMarkdown('Importable Vault Note'));

        $exit = Artisan::call('atlas:vault', [
            'action' => 'import',
            '--path' => 'Research/importable.md',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue(data_get($payload, 'ok'));
        $this->assertSame('candidate', data_get($payload, 'status'));
        $this->assertNull(data_get($payload, 'sync_item_id'));
    }

    public function test_import_dry_run_blocks_managed_note(): void
    {
        File::ensureDirectoryExists($this->vault.'/Atlas/Memory');
        File::put($this->vault.'/Atlas/Memory/managed.md', $this->managedMarkdown('mem_import_blocked', 'managed'));

        $exit = Artisan::call('atlas:vault', [
            'action' => 'import',
            '--path' => 'Atlas/Memory/managed.md',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertFalse(data_get($payload, 'ok'));
        $this->assertSame('blocked', data_get($payload, 'status'));
        $this->assertSame('atlas_managed_note_not_importable', data_get($payload, 'conflict_type'));
    }

    public function test_sync_dry_run_scans_vault_without_persistent_writes(): void
    {
        File::ensureDirectoryExists($this->vault.'/Research');
        File::put($this->vault.'/Research/sync.md', $this->semanticMarkdown('Sync Vault Note'));

        $exit = Artisan::call('atlas:vault', [
            'action' => 'sync',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue(data_get($payload, 'dry_run'));
        $this->assertSame(1, data_get($payload, 'inspected'));
        $this->assertSame(1, data_get($payload, 'candidates'));
    }

    public function test_export_semantic_dry_run_creates_managed_note_payload(): void
    {
        $this->migrateMinimalSemanticNotes();
        $note = SemanticNote::query()->create([
            'note_key' => 'note_export',
            'path' => 'Research/source.md',
            'title' => 'Exported Semantic Note',
            'type' => 'source_note',
            'status' => 'active',
            'confidence' => 'low',
            'maturity' => 'draft',
            'domains' => [],
            'summary' => 'Resumo exportavel',
            'body_excerpt' => 'Conteudo humano provider-safe.',
            'frontmatter' => [],
            'when_to_use' => [],
            'trigger_signals' => [],
            'do_not_use_when' => [],
            'postgres_refs' => [],
            'content_hash' => hash('sha256', 'export'),
            'validation_errors' => [],
            'metadata' => [],
        ]);

        $exit = Artisan::call('atlas:vault', [
            'action' => 'export-semantic',
            '--semantic-note' => $note->id,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue(data_get($payload, 'ok'));
        $this->assertSame('semantic_note', data_get($payload, 'note.frontmatter.atlas_type'));
        $this->assertStringContainsString('atlas://semantic-note/'.$note->id, data_get($payload, 'note.markdown'));
    }

    public function test_resolve_updates_persistent_sync_item(): void
    {
        $this->migrateMinimalVaultSyncItems();
        $item = AtlasVaultSyncItem::query()->create([
            'direction' => 'vault_to_atlas',
            'operation' => 'import',
            'status' => 'conflict',
            'path' => 'Research/conflict.md',
            'content_hash' => hash('sha256', 'conflict'),
            'conflict_type' => 'human_changes_outside_manual_or_managed_block',
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [],
        ]);

        $exit = Artisan::call('atlas:vault', [
            'action' => 'resolve',
            '--item' => $item->id,
            '--resolution' => 'archive',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('archived', data_get($payload, 'item.status'));
        $this->assertSame('archive', data_get($payload, 'item.metadata.resolution_action'));
    }

    public function test_note_dry_run_without_json_renders_human_summary(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--dry-run' => true]));
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('AtlasVault note ready', $output);
        $this->assertStringContainsString('Operation: create', $output);
        $this->assertStringContainsString('Dry run: yes', $output);
    }

    public function test_note_dry_run_normalizes_multiline_title(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--title' => "Titulo\nCom Quebra",
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("# Titulo Com Quebra\n", data_get($payload, 'note.markdown'));
    }

    public function test_note_rejects_content_with_reserved_managed_markers(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--content' => 'Conteudo '.AtlasVaultManagedNoteService::MANAGED_START,
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('reserved managed block markers', data_get($payload, 'error'));
    }

    public function test_note_write_creates_managed_note_inside_vault(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);
        $path = $this->vault.'/'.data_get($payload, 'note.path');

        $this->assertSame(0, $exit);
        $this->assertTrue(File::exists($path));
        $this->assertStringContainsString('atlas_id: test-memory', File::get($path));
        $this->assertStringContainsString('- Memory: atlas://memory/test-memory', File::get($path));
        $this->assertSame('create', data_get($payload, 'note.metadata.operation'));
        $this->assertNotEmpty(data_get($payload, 'note.metadata.proposed_content_hash'));
    }

    public function test_note_dry_run_accepts_safe_custom_markdown_path(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--path' => 'Atlas/Memory/custom/safe-note.md',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('Atlas/Memory/custom/safe-note.md', data_get($payload, 'note.path'));
        $this->assertFalse(File::exists($this->vault.'/Atlas/Memory/custom/safe-note.md'));
    }

    public function test_note_dry_run_normalizes_safe_custom_markdown_path(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--path' => 'Atlas\\Memory//custom//safe-note.md',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('Atlas/Memory/custom/safe-note.md', data_get($payload, 'note.path'));
        $this->assertFalse(File::exists($this->vault.'/Atlas/Memory/custom/safe-note.md'));
    }

    public function test_note_rejects_custom_path_without_markdown_extension(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--path' => 'Atlas/Memory/custom/safe-note.txt',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must end with .md', data_get($payload, 'error'));
    }

    public function test_note_rejects_absolute_custom_path(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--path' => '/tmp/outside.md',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('vault-relative', data_get($payload, 'error'));
    }

    public function test_note_rejects_custom_path_through_symlinked_directory_outside_vault(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('symlink is unavailable on this platform.');
        }

        $outside = sys_get_temp_dir().'/atlas-vault-outside-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($outside);
        $linked = $this->vault.'/LinkedOutside';
        if (! @symlink($outside, $linked)) {
            File::deleteDirectory($outside);
            $this->markTestSkipped('symlink creation failed on this platform.');
        }

        try {
            $exit = Artisan::call('atlas:vault', $this->noteArgs([
                '--dry-run' => true,
                '--path' => 'LinkedOutside/unsafe.md',
                '--json' => true,
            ]));
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('Unsafe vault path', data_get($payload, 'error'));
            $this->assertFalse(File::exists($outside.'/unsafe.md'));
        } finally {
            if (is_link($linked)) {
                unlink($linked);
            }
            File::deleteDirectory($outside);
        }
    }

    public function test_note_dry_run_accepts_extra_links_and_canonical_docs(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--link' => ['Task=atlas://task/task_456'],
            '--canonical-doc' => ['docs/engineering-knowledge-base/open-brain-context-injection.md'],
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('- Task: atlas://task/task_456', data_get($payload, 'note.markdown'));
        $this->assertStringContainsString(
            '- Canonical Doc: docs/engineering-knowledge-base/open-brain-context-injection.md',
            data_get($payload, 'note.markdown'),
        );
    }

    public function test_note_dry_run_accepts_secret_privacy_and_provider_unsafe_flag(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--privacy-class' => 'secret',
            '--provider-safe' => '0',
            '--redaction-status' => 'blocked',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('secret', data_get($payload, 'note.frontmatter.privacy_class'));
        $this->assertFalse(data_get($payload, 'note.frontmatter.provider_safe'));
        $this->assertSame('blocked', data_get($payload, 'note.frontmatter.redaction_status'));
    }

    public function test_note_rejects_invalid_provider_safe_boolean(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--provider-safe' => 'maybe',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid boolean option --provider-safe', data_get($payload, 'error'));
    }

    public function test_note_rejects_secret_privacy_when_provider_safe(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--privacy-class' => 'secret',
            '--provider-safe' => '1',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Secret AtlasVault notes cannot be provider_safe', data_get($payload, 'error'));
    }

    public function test_note_rejects_provider_safe_with_needs_review_redaction(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--provider-safe' => '1',
            '--redaction-status' => 'needs_review',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('blocked or needs_review redaction cannot be provider_safe', data_get($payload, 'error'));
    }

    public function test_note_rejects_invalid_privacy_class(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--privacy-class' => 'public',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid privacy_class', data_get($payload, 'error'));
    }

    public function test_note_rejects_malformed_extra_links(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--link' => ['atlas://task/task_456'],
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Extra links must use', data_get($payload, 'error'));
    }

    public function test_note_write_updates_only_managed_content_when_shell_is_unchanged(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';

        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--write' => true,
            '--content' => 'Conteudo gerado atualizado.',
            '--json' => true,
        ]));

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Conteudo gerado atualizado.', File::get($path));
        $this->assertStringContainsString('## Manual Notes', File::get($path));
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('update', data_get($payload, 'note.metadata.operation'));
        $this->assertNotEmpty(data_get($payload, 'note.metadata.existing_content_hash'));
    }

    public function test_update_preserves_manual_notes(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::put($path, str_replace(
            "## Manual Notes\n\nEspaco humano preservado.",
            "## Manual Notes\n\nAnotacao humana importante.",
            File::get($path),
        ));

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Anotacao humana importante.', File::get($path));
    }

    public function test_conflict_blocks_human_changes_outside_managed_block(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::put($path, str_replace('Resumo humano.', 'Resumo humano alterado fora do bloco.', File::get($path)));

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains('human_changes_outside_manual_or_managed_block', data_get($payload, 'note.conflicts'));
        $this->assertStringContainsString('Resumo humano alterado fora do bloco.', File::get($path));
    }

    public function test_conflict_without_json_renders_conflict_summary(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::put($path, str_replace('Resumo humano.', 'Resumo humano alterado fora do bloco.', File::get($path)));

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true]));
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('AtlasVault note blocked', $output);
        $this->assertStringContainsString('- human_changes_outside_manual_or_managed_block', $output);
    }

    public function test_missing_managed_markers_blocks_write_and_status_counts_conflict(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::put($path, str_replace(AtlasVaultManagedNoteService::MANAGED_START, '<!-- missing start -->', File::get($path)));

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains('missing_managed_block_markers', data_get($payload, 'note.conflicts'));
        $this->assertSame('conflict', data_get($payload, 'note.frontmatter.sync_status'));
        $this->assertSame('conflict_detected', data_get($payload, 'note.metadata.write_blocked_reason'));

        Artisan::call('atlas:vault', ['action' => 'status', '--json' => true]);
        $status = json_decode(Artisan::output(), true);
        $this->assertSame(1, data_get($status, 'vault.conflicts_count'));
        $this->assertSame(1, data_get($status, 'vault.invalid_notes_count'));
    }

    public function test_duplicate_managed_markers_block_write(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::put($path, str_replace(
            AtlasVaultManagedNoteService::MANAGED_END,
            AtlasVaultManagedNoteService::MANAGED_END."\n".AtlasVaultManagedNoteService::MANAGED_END,
            File::get($path),
        ));

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains('invalid_managed_block_marker_count', data_get($payload, 'note.conflicts'));
        $this->assertStringContainsString(
            AtlasVaultManagedNoteService::MANAGED_END."\n".AtlasVaultManagedNoteService::MANAGED_END,
            File::get($path),
        );
    }

    public function test_reversed_managed_markers_block_write(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::put($path, str_replace(
            AtlasVaultManagedNoteService::MANAGED_START."\nConteudo gerado pelo Atlas.\n".AtlasVaultManagedNoteService::MANAGED_END,
            AtlasVaultManagedNoteService::MANAGED_END."\nConteudo gerado pelo Atlas.\n".AtlasVaultManagedNoteService::MANAGED_START,
            File::get($path),
        ));

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains('invalid_managed_block_marker_order', data_get($payload, 'note.conflicts'));
    }

    public function test_existing_sync_status_conflict_blocks_write_until_review(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::put($path, str_replace('sync_status: managed', 'sync_status: conflict', File::get($path)));

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains('existing_sync_status_conflict', data_get($payload, 'note.conflicts'));
        $this->assertSame('conflict', data_get($payload, 'note.metadata.operation'));
        $this->assertStringContainsString('sync_status: conflict', File::get($path));
    }

    public function test_frontmatter_privacy_drift_blocks_write(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::put($path, str_replace('privacy_class: normal', 'privacy_class: private', File::get($path)));

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains('privacy_class_changed', data_get($payload, 'note.conflicts'));
        $this->assertStringContainsString('privacy_class: private', File::get($path));
    }

    public function test_frontmatter_source_id_drift_blocks_write_and_preserves_existing_file(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        $existing = str_replace('source_id: test-memory', 'source_id: another-memory', File::get($path));
        File::put($path, $existing);

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains('source_id_changed', data_get($payload, 'note.conflicts'));
        $this->assertSame('conflict_detected', data_get($payload, 'note.metadata.write_blocked_reason'));
        $this->assertSame($existing, File::get($path));
    }

    public function test_invalid_boolean_frontmatter_flags_block_write(): void
    {
        Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::put($path, str_replace('provider_safe: true', 'provider_safe: maybe', File::get($path)));

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains('invalid_provider_safe', data_get($payload, 'note.conflicts'));
        $this->assertStringContainsString('provider_safe: maybe', File::get($path));
    }

    public function test_unmanaged_file_is_not_overwritten(): void
    {
        $path = $this->vault.'/Atlas/Memory/memory_entry/teste-atlasvault-test-memory.md';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "# Human Note\n\nNao sobrescrever.");

        $exit = Artisan::call('atlas:vault', $this->noteArgs(['--write' => true, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains('existing_file_not_atlas_managed', data_get($payload, 'note.conflicts'));
        $this->assertSame("# Human Note\n\nNao sobrescrever.", File::get($path));
    }

    public function test_path_traversal_is_blocked(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--dry-run' => true,
            '--path' => '../outside.md',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('parent traversal', data_get($payload, 'error'));
    }

    public function test_unsupported_note_type_is_rejected_before_write(): void
    {
        $exit = Artisan::call('atlas:vault', $this->noteArgs([
            '--type' => 'unknown_type',
            '--dry-run' => true,
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unsupported Atlas vault note type', data_get($payload, 'error'));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function noteArgs(array $overrides = []): array
    {
        return array_merge([
            'action' => 'note',
            '--type' => 'memory_entry',
            '--id' => 'test-memory',
            '--title' => 'Teste AtlasVault',
            '--summary' => 'Resumo humano.',
            '--privacy-class' => 'normal',
            '--provider-safe' => '1',
            '--redaction-status' => 'clean',
        ], $overrides);
    }

    private function managedMarkdown(string $id, string $syncStatus): string
    {
        return <<<MD
---
atlas_id: {$id}
atlas_type: memory_entry
atlas_managed: true
sync_status: {$syncStatus}
source: atlas
source_type: atlas_memory_entry
source_id: {$id}
privacy_class: normal
provider_safe: true
redaction_status: clean
canonical: false
created_by: atlas
updated_at: 2026-05-03T00:00:00Z
---

# Teste

Resumo.

## Links Atlas

- Memory: atlas://memory/{$id}

<!-- ATLAS:MANAGED:START -->
Conteudo gerado pelo Atlas.
<!-- ATLAS:MANAGED:END -->

## Manual Notes

Manual.
MD;
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
summary: Nota humana importavel.
when_to_use:
  - Revisar conhecimento humano.
---

Corpo humano provider-safe para importacao local.
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
