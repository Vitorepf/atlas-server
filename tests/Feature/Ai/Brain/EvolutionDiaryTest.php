<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Brain;

use App\Services\Ai\Brain\AtlasEvolutionDiary;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DIARIO-1 (Carta Regra 3) — the labelled evolution ledger the operator
 * navigates instead of approving beforehand.
 */
class EvolutionDiaryTest extends TestCase
{
    private string $diaryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diaryPath = sys_get_temp_dir().'/atlas-diary-'.uniqid('', true).'.jsonl';
        @unlink($this->diaryPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->diaryPath);
        parent::tearDown();
    }

    public function test_records_labelled_entries_and_chains_them(): void
    {
        $diary = new AtlasEvolutionDiary($this->diaryPath);

        $a = $diary->record('promocao-memoria', 'promoveu memória X', 'passou os checks G0', 'commit:abc123', 'memory:7');
        $b = $diary->record('merge', 'auto-merge do slice K1', 'K4 verde', 'commit:def456', 'def456');

        $this->assertSame(1, $a['seq']);
        $this->assertSame(2, $b['seq']);
        $this->assertSame($a['line_hash'], $b['prev_hash'], 'each entry chains to the previous one.');
        $this->assertNotEmpty($a['id']);
        $this->assertTrue($diary->verifyChain()['ok']);
        $this->assertSame(2, $diary->verifyChain()['count']);
    }

    public function test_today_groups_by_tipo_and_filter_narrows(): void
    {
        $diary = new AtlasEvolutionDiary($this->diaryPath);
        $diary->record('promocao-memoria', 'a', 'x');
        $diary->record('promocao-memoria', 'b', 'y');
        $diary->record('merge', 'c', 'z');

        $today = $diary->today();
        $this->assertCount(2, $today['promocao-memoria']);
        $this->assertCount(1, $today['merge']);

        $this->assertCount(2, $diary->filter('promocao-memoria'));
        $this->assertCount(3, $diary->filter(null, '2000-01-01'));
        $this->assertCount(0, $diary->filter(null, '2999-01-01'));
    }

    public function test_find_returns_the_entry_by_id(): void
    {
        $diary = new AtlasEvolutionDiary($this->diaryPath);
        $entry = $diary->record('licao', 'aprendeu Y', 'porque Z');

        $found = $diary->find($entry['id']);
        $this->assertNotNull($found);
        $this->assertSame('aprendeu Y', $found['o_que']);
        $this->assertNull($diary->find('nao-existe'));
    }

    public function test_unknown_tipo_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AtlasEvolutionDiary($this->diaryPath))->record('gate-operador', 'algo', 'porque');
    }

    public function test_tampered_entry_breaks_the_chain(): void
    {
        $diary = new AtlasEvolutionDiary($this->diaryPath);
        $diary->record('refatoracao', 'primeira', 'motivo 1');
        $diary->record('refatoracao', 'segunda', 'motivo 2');

        $lines = file($this->diaryPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines[0] = str_replace('primeira', 'ADULTERADA', $lines[0]);
        file_put_contents($this->diaryPath, implode(PHP_EOL, $lines).PHP_EOL);

        $this->assertFalse((new AtlasEvolutionDiary($this->diaryPath))->verifyChain()['ok']);
    }
}
