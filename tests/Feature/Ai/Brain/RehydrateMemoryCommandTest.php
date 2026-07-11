<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Brain;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Brain\AtlasMemoryJournal;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * D1 — re-hydrate stub memories to real bodies, reversibly (SIS8 journal-first).
 */
class RehydrateMemoryCommandTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private string $mapPath;

    private string $journalPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapPath = sys_get_temp_dir().'/atlas-rehydrate-'.uniqid('', true).'.json';
        $this->journalPath = sys_get_temp_dir().'/atlas-rehydrate-journal-'.uniqid('', true).'.jsonl';
        config([
            'atlas.brain.journal.path' => $this->journalPath,
            'atlas.brain.journal.enabled' => true,
            'atlas.aurg.enabled' => false,
        ]);
        $this->createAtlasMemoryEntryTable();
    }

    protected function tearDown(): void
    {
        @unlink($this->mapPath);
        @unlink($this->journalPath);
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    private function seedStub(): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Docs canônicos governam',
            'summary' => 'Docs canônicos governam',
            'body' => 'leia o doc canônico. [recuperado de projeção provider-safe — corpo pode estar truncado; wiper incident]',
            'redacted_body' => 'leia o doc canônico. [recuperado de projeção provider-safe — corpo pode estar truncado; wiper incident]',
            'status' => 'active',
            'priority' => 50,
            'source_type' => 'test_fixture',
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function writeMap(string $id, string $body, int $priority, array $extra = []): void
    {
        file_put_contents($this->mapPath, (string) json_encode([
            'entries' => [array_merge([
                'id' => $id,
                'body' => $body,
                'priority' => $priority,
                'confidence' => 0.9,
            ], $extra)],
        ]));
    }

    public function test_apply_rehydrates_body_and_priority_and_removes_marker(): void
    {
        $stub = $this->seedStub();
        $real = 'Leia o doc canônico antes de confiar em read models. Por quê: read models envelhecem. Evidência: governance doc.';
        $this->writeMap((string) $stub->getKey(), $real, 90);

        $this->artisan('atlas:brain:rehydrate-memory', ['map' => $this->mapPath, '--apply' => true])->assertExitCode(0);

        $fresh = AtlasMemoryEntry::query()->find($stub->getKey());
        $this->assertSame($real, $fresh->body);
        $this->assertSame(90, $fresh->priority);
        $this->assertStringNotContainsString('corpo pode estar truncado', (string) $fresh->body);
        $this->assertStringContainsString('Por quê', (string) $fresh->redacted_body);
        $this->assertStringNotContainsString('corpo pode estar truncado', (string) $fresh->redacted_body);
    }

    public function test_apply_rehydrates_title_and_summary_through_curate(): void
    {
        $stub = $this->seedStub();
        $real = 'Docs canonicos governam implementação porque read models envelhecem. Evidência: docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md.';
        $this->writeMap((string) $stub->getKey(), $real, 90, [
            'title' => 'Docs canônicos governam implementação',
            'summary' => 'Use docs canônicos como fonte autoral antes de confiar em Postgres, Obsidian, projections ou chat.',
            'evidence_refs' => ['docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md'],
        ]);

        $this->artisan('atlas:brain:rehydrate-memory', ['map' => $this->mapPath, '--apply' => true])->assertExitCode(0);

        $fresh = AtlasMemoryEntry::query()->find($stub->getKey());
        $this->assertSame('Docs canônicos governam implementação', $fresh->title);
        $this->assertSame(
            'Use docs canônicos como fonte autoral antes de confiar em Postgres, Obsidian, projections ou chat.',
            $fresh->summary,
        );
        $this->assertNotSame($fresh->title, $fresh->summary);
        $this->assertSame($real, $fresh->body);

        $chain = (new AtlasMemoryJournal($this->journalPath))->verifyChain();
        $this->assertTrue($chain['ok']);
        $this->assertSame(1, $chain['count'], 'title/summary application should still be one registry curate write');
    }

    public function test_dry_run_does_not_write(): void
    {
        $stub = $this->seedStub();
        $original = $stub->body;
        $this->writeMap((string) $stub->getKey(), 'novo corpo', 90);

        $this->artisan('atlas:brain:rehydrate-memory', ['map' => $this->mapPath])->assertExitCode(0);

        $this->assertSame($original, AtlasMemoryEntry::query()->find($stub->getKey())->body, 'dry-run must not write.');
    }

    public function test_rehydration_is_journaled_so_it_is_reversible(): void
    {
        $stub = $this->seedStub();
        $this->writeMap((string) $stub->getKey(), 'corpo re-hidratado', 75);

        $this->artisan('atlas:brain:rehydrate-memory', ['map' => $this->mapPath, '--apply' => true])->assertExitCode(0);

        // The curate write appended a hash-chained journal line → replay can reverse it.
        $chain = (new AtlasMemoryJournal($this->journalPath))->verifyChain();
        $this->assertTrue($chain['ok']);
        $this->assertGreaterThanOrEqual(1, $chain['count']);
    }

    public function test_missing_id_is_skipped_never_minted(): void
    {
        $this->writeMap('019f0000-0000-7000-8000-000000000000', 'corpo', 90);

        $this->artisan('atlas:brain:rehydrate-memory', ['map' => $this->mapPath, '--apply' => true])->assertExitCode(0);

        $this->assertSame(0, AtlasMemoryEntry::query()->count(), 're-hydration never creates memory.');
    }

    public function test_shipped_rehydration_map_entries_have_resolvable_engineering_knowledge_evidence_refs(): void
    {
        $mapPath = base_path('database/atlas/memory-rehydration-wiper-restore.json');
        $map = json_decode((string) file_get_contents($mapPath), true, flags: JSON_THROW_ON_ERROR);

        foreach ($map['entries'] as $index => $entry) {
            $this->assertIsString($entry['title'] ?? null, "entry {$index} missing title");
            $this->assertNotSame('', trim((string) $entry['title']), "entry {$index} empty title");
            $this->assertIsString($entry['summary'] ?? null, "entry {$index} missing summary");
            $this->assertNotSame('', trim((string) $entry['summary']), "entry {$index} empty summary");
            $this->assertNotSame(trim((string) $entry['title']), trim((string) $entry['summary']), "entry {$index} title must not equal summary");
            $this->assertNotEmpty($entry['evidence_refs'] ?? [], "entry {$index} missing evidence refs");

            foreach ($entry['evidence_refs'] as $ref) {
                $this->assertIsString($ref);
                $this->assertStringStartsWith('docs/engineering-knowledge-base/', $ref);
                $this->assertFileExists(base_path($ref), "entry {$index} evidence ref does not resolve: {$ref}");
            }
        }
    }
}
