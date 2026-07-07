<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Brain;

use App\Services\Ai\Brain\AtlasEvolutionDiary;
use App\Services\Ai\Brain\AtlasEvolutionDiaryRecorder;
use App\Services\Ai\Brain\AtlasMemoryJournal;
use Tests\TestCase;

/**
 * DIARIO-3 — the typed recorder every autonomous act calls to write its labelled
 * Evolution-Diary entry in the same act, each entry reversible.
 */
class EvolutionDiaryRecorderTest extends TestCase
{
    private string $diaryPath;

    private string $journalPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diaryPath = sys_get_temp_dir().'/atlas-diary-rec-'.uniqid('', true).'.jsonl';
        $this->journalPath = sys_get_temp_dir().'/atlas-journal-rec-'.uniqid('', true).'.jsonl';
        @unlink($this->diaryPath);
        @unlink($this->journalPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->diaryPath);
        @unlink($this->journalPath);
        parent::tearDown();
    }

    private function recorder(): AtlasEvolutionDiaryRecorder
    {
        return new AtlasEvolutionDiaryRecorder(
            new AtlasEvolutionDiary($this->diaryPath),
            new AtlasMemoryJournal($this->journalPath),
        );
    }

    private function diary(): AtlasEvolutionDiary
    {
        return new AtlasEvolutionDiary($this->diaryPath);
    }

    public function test_merged_writes_a_merge_entry_reversible_by_commit(): void
    {
        $this->recorder()->merged('abc123def', 'auto-merge do slice X', 'checks verdes', 'commit abc123def');

        $entry = $this->diary()->filter('merge')[0];
        $this->assertSame('merge', $entry['tipo']);
        $this->assertSame('abc123def', $entry['id_reversao'], 'a merge is reversible by git revert of its commit.');
    }

    public function test_memory_promoted_uses_journal_seq_as_reversal_handle(): void
    {
        $journal = new AtlasMemoryJournal($this->journalPath);
        $journal->append('record', 'atlas_memory_entries', 'mem-x', ['id' => 'mem-x']); // seq 1
        $journal->append('record', 'atlas_memory_entries', 'mem-y', ['id' => 'mem-y']); // seq 2

        $this->recorder()->memoryPromoted('mem-y', 'promoveu memória Y', 'passou G0');

        $entry = $this->diary()->filter('promocao-memoria')[0];
        $this->assertSame('memory:2', $entry['id_reversao'], 'reversible by replay-sem-a-entrada of the journal seq.');
    }

    public function test_new_organ_graduated_and_retired_carry_the_right_tipo(): void
    {
        $rec = $this->recorder();
        $rec->newOrgan('construiu órgão Z', 'requires_human_approval=false', 'commit z', 'z');
        $rec->graduated('automação W virou enforce', 'precision estável', null, 'obs->enforce');
        $rec->retired('aposentou órgão V', 'sem uso 30d', null, 'writeoff-v');

        $this->assertCount(1, $this->diary()->filter('orgao-novo'));
        $this->assertCount(1, $this->diary()->filter('graduacao'));
        $this->assertCount(1, $this->diary()->filter('aposentadoria'));
    }
}
