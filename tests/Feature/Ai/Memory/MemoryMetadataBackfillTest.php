<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Brain\AtlasMemoryJournal;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class MemoryMetadataBackfillTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private string $journalPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->journalPath = sys_get_temp_dir().'/atlas-memory-backfill-journal-'.uniqid('', true).'.jsonl';
        config([
            'atlas.brain.journal.path' => $this->journalPath,
            'atlas.brain.journal.enabled' => true,
            'atlas.aurg.enabled' => false,
        ]);
        $this->createAtlasMemoryEntryTable();
    }

    protected function tearDown(): void
    {
        @unlink($this->journalPath);
        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_dry_run_is_default_and_does_not_mutate_metadata(): void
    {
        $entry = $this->memoryWithEmptyMetadata();

        Artisan::call('atlas:memory:backfill-metadata', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['apply']);
        $this->assertSame(1, $payload['would_update']);
        $this->assertSame([], AtlasMemoryEntry::query()->find($entry->id)->metadata);
    }

    public function test_apply_backfills_only_resolved_paths_and_domains_through_curate(): void
    {
        $entry = $this->memoryWithEmptyMetadata();

        Artisan::call('atlas:memory:backfill-metadata', ['--apply' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $fresh = AtlasMemoryEntry::query()->find($entry->id);
        $metadata = (array) $fresh->metadata;

        $this->assertTrue($payload['apply']);
        $this->assertSame(1, $payload['updated']);
        $this->assertSame('atlas:memory:backfill-metadata', $metadata['backfill_source'] ?? null);
        $this->assertNotEmpty($metadata['backfilled_at'] ?? null);
        $this->assertSame(['app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php'], $metadata['paths'] ?? null);
        $this->assertSame(['engineering'], $metadata['domains'] ?? null);
        $this->assertNotContains('app/Services/Ai/Reality/DoesNotExist.php', $metadata['paths'] ?? []);
        $this->assertNotEmpty($metadata['curation_history'] ?? [], 'registry curate should add curation history');

        $chain = (new AtlasMemoryJournal($this->journalPath))->verifyChain();
        $this->assertTrue($chain['ok']);
        $this->assertSame(1, $chain['count'], 'backfill must write through AtlasMemoryRegistryService::curate()');
    }

    private function memoryWithEmptyMetadata(): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'AURG ingestion metadata',
            'summary' => 'AURG ingestion metadata',
            'body' => implode(' ', [
                'The engineering domain owns app/Services/Ai/Reality/AtlasRealityGraphIngestionService.php.',
                'A stale citation app/Services/Ai/Reality/DoesNotExist.php must be omitted.',
            ]),
            'status' => 'active',
            'source_type' => 'test_fixture',
            'tags' => [],
            'metadata' => [],
            'recorded_at' => now(),
        ]);
    }
}
