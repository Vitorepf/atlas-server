<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Brain;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\Brain\AtlasMemoryJournal;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * SIS8 (Obra #20) — the kill-test: DROP the brain, replay from the disk journal
 * alone, and prove the content digest is byte-identical. This is the physics
 * the Carta de Autonomia stands on — reversibility is the license that replaces
 * human approval.
 */
class MemoryJournalReplayTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private string $journalPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->journalPath = sys_get_temp_dir().'/atlas-brain-journal-'.uniqid('', true).'.jsonl';
        @unlink($this->journalPath);

        config([
            'atlas.brain.journal.path' => $this->journalPath,
            'atlas.brain.journal.enabled' => true,
            // Keep the write path lean & deterministic: brain accrual is a
            // separate, pgsql-leaning organ and is not what this test exercises.
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

    private function seedTwelve(): void
    {
        $registry = app(AtlasMemoryRegistryService::class);
        foreach (range(1, 12) as $i) {
            $registry->record([
                'memory_type' => 'technical_context',
                'scope_type' => 'global',
                'title' => 'Memória '.$i,
                'summary' => 'Resumo estruturado '.$i,
                'body' => 'Corpo real da memória '.$i.' — o quê, o porquê e a evidência.',
                'priority' => 40 + $i,
                'confidence' => 0.5,
                'source_type' => 'test_fixture',
            ]);
        }
    }

    public function test_kill_test_drop_then_replay_reconstructs_identical_brain(): void
    {
        $this->seedTwelve();

        $this->assertSame(12, AtlasMemoryEntry::query()->count());
        $before = AtlasMemoryJournal::rowsDigest(
            AtlasMemoryEntry::withTrashed()->orderBy('id')->get(),
        );

        // KILL — drop the whole table (stands in for DROP DATABASE). The journal
        // lives on disk and survives untouched.
        $this->dropAtlasMemoryEntryTable();
        $this->createAtlasMemoryEntryTable();
        $this->assertSame(0, AtlasMemoryEntry::query()->count(), 'Precondition: the brain is empty after the drop.');

        // REPLAY from the journal alone.
        $this->artisan('atlas:brain:replay', ['--journal' => $this->journalPath])
            ->assertExitCode(0);

        $after = AtlasMemoryJournal::rowsDigest(
            AtlasMemoryEntry::withTrashed()->orderBy('id')->get(),
        );

        $this->assertSame(12, AtlasMemoryEntry::query()->count(), 'Replay must reconstruct every row.');
        $this->assertSame($before, $after, 'Content digest after replay must be byte-identical to before the drop.');
    }

    public function test_chain_verifies_and_a_tampered_line_is_detected(): void
    {
        $this->seedTwelve();

        $journal = new AtlasMemoryJournal($this->journalPath);
        $clean = $journal->verifyChain();
        $this->assertTrue($clean['ok'], 'A freshly written journal must verify.');
        $this->assertSame(12, $clean['count']);

        // Tamper with the first line's payload — the chain must break downstream.
        $lines = file($this->journalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines[0] = str_replace('Corpo real da memória 1', 'CORPO ADULTERADO', $lines[0]);
        file_put_contents($this->journalPath, implode(PHP_EOL, $lines).PHP_EOL);

        $tampered = (new AtlasMemoryJournal($this->journalPath))->verifyChain();
        $this->assertFalse($tampered['ok'], 'A tampered line must break the hash chain.');
    }

    public function test_replay_refuses_a_broken_chain(): void
    {
        $this->seedTwelve();

        // Corrupt the middle of the journal.
        $lines = file($this->journalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines[5] = str_replace('Resumo estruturado 6', 'MUTADO', $lines[5]);
        file_put_contents($this->journalPath, implode(PHP_EOL, $lines).PHP_EOL);

        $this->dropAtlasMemoryEntryTable();
        $this->createAtlasMemoryEntryTable();

        // Replay must FAIL closed on a broken chain rather than write a corrupt brain.
        $this->artisan('atlas:brain:replay', ['--journal' => $this->journalPath])
            ->assertExitCode(1);
        $this->assertSame(0, AtlasMemoryEntry::query()->count(), 'A broken chain must not partially reconstruct.');
    }

    public function test_replay_excluding_a_seq_reverses_that_mutation(): void
    {
        $this->seedTwelve();

        $records = (new AtlasMemoryJournal($this->journalPath))->read();
        $seq3 = collect($records)->firstWhere('seq', 3);
        $removedId = (string) $seq3['id'];

        $this->dropAtlasMemoryEntryTable();
        $this->createAtlasMemoryEntryTable();

        // "replay-sem-a-entrada" — rebuild the brain as if seq 3 never happened.
        $this->artisan('atlas:brain:replay', ['--journal' => $this->journalPath, '--exclude-seq' => [3]])
            ->assertExitCode(0);

        $this->assertSame(11, AtlasMemoryEntry::query()->count(), 'Exactly the excluded mutation is gone.');
        $this->assertNull(AtlasMemoryEntry::query()->find($removedId), 'The excluded row must be absent after the reversal.');
    }
}
