<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Brain;

use App\Services\Ai\Brain\AtlasEvolutionDiary;
use Tests\TestCase;

/**
 * DIARIO-2 — `atlas:evolucao`, the operator's window into (and undo for) the
 * autonomy.
 */
class AtlasEvolucaoCommandTest extends TestCase
{
    private string $diaryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diaryPath = sys_get_temp_dir().'/atlas-diary-cmd-'.uniqid('', true).'.jsonl';
        @unlink($this->diaryPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->diaryPath);
        parent::tearDown();
    }

    private function seedDiary(): AtlasEvolutionDiary
    {
        $diary = new AtlasEvolutionDiary($this->diaryPath);
        $diary->record('promocao-memoria', 'promoveu memória do scheduler', 'passou G0', 'commit:aaa', 'memory:5');
        $diary->record('merge', 'auto-merge do K1', 'K4 verde', 'commit:bbb', 'bbbbbbb');

        return $diary;
    }

    public function test_hoje_lists_todays_evolutions(): void
    {
        $this->seedDiary();

        $this->artisan('atlas:evolucao', ['acao' => 'hoje', '--diary' => $this->diaryPath])
            ->expectsOutputToContain('promocao-memoria')
            ->expectsOutputToContain('merge')
            ->assertExitCode(0);
    }

    public function test_listar_filters_by_tipo(): void
    {
        $this->seedDiary();

        $this->artisan('atlas:evolucao', ['acao' => 'listar', '--tipo' => 'merge', '--diary' => $this->diaryPath, '--json' => true])
            ->expectsOutputToContain('auto-merge do K1')
            ->doesntExpectOutputToContain('scheduler')
            ->assertExitCode(0);
    }

    public function test_ver_shows_one_entry(): void
    {
        $diary = $this->seedDiary();
        $id = $diary->filter('promocao-memoria')[0]['id'];

        $this->artisan('atlas:evolucao', ['acao' => 'ver', 'id' => $id, '--diary' => $this->diaryPath, '--json' => true])
            ->expectsOutputToContain('scheduler')
            ->assertExitCode(0);
    }

    public function test_reverter_prints_git_plan_without_apply(): void
    {
        $diary = $this->seedDiary();
        $id = $diary->filter('merge')[0]['id'];

        $this->artisan('atlas:evolucao', ['acao' => 'reverter', 'id' => $id, '--diary' => $this->diaryPath])
            ->expectsOutputToContain('git revert --no-edit bbbbbbb')
            ->assertExitCode(0);
    }

    public function test_reverter_prints_memory_replay_plan_without_apply(): void
    {
        $diary = $this->seedDiary();
        $id = $diary->filter('promocao-memoria')[0]['id'];

        $this->artisan('atlas:evolucao', ['acao' => 'reverter', 'id' => $id, '--diary' => $this->diaryPath])
            ->expectsOutputToContain('atlas:brain:replay --exclude-seq=5')
            ->assertExitCode(0);
    }

    public function test_reverter_fails_when_id_unknown(): void
    {
        $this->seedDiary();

        $this->artisan('atlas:evolucao', ['acao' => 'reverter', 'id' => 'nao-existe', '--diary' => $this->diaryPath])
            ->assertExitCode(1);
    }
}
