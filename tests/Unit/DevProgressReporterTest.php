<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\DevProgressReporter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

class DevProgressReporterTest extends TestCase
{
    public function test_phase_labels_match_canonical_portuguese_states(): void
    {
        $this->assertSame('analisando', DevProgressReporter::labelFor('inspect'));
        $this->assertSame('planejando', DevProgressReporter::labelFor('plan'));
        $this->assertSame('editando', DevProgressReporter::labelFor('edit'));
        $this->assertSame('testando', DevProgressReporter::labelFor('test'));
        $this->assertSame('corrigindo', DevProgressReporter::labelFor('repair'));
        $this->assertSame('revisando', DevProgressReporter::labelFor('review'));
        $this->assertSame('finalizado', DevProgressReporter::labelFor('finish'));
    }

    public function test_unknown_phase_returns_input_label(): void
    {
        $this->assertSame('mistery', DevProgressReporter::labelFor('mistery'));
    }

    public function test_start_then_done_produces_label_and_duration(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        $reporter = new DevProgressReporter($output);

        $reporter->start('edit');
        $reporter->done('edit', '4 arquivos');

        $rendered = $output->fetch();
        $this->assertStringContainsString('· editando', $rendered);
        $this->assertStringContainsString('4 arquivos', $rendered);
    }

    public function test_fail_marks_phase_as_failed(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        $reporter = new DevProgressReporter($output);

        $reporter->start('test');
        $reporter->fail('test', 'phpunit retornou 3');

        $rendered = $output->fetch();
        $this->assertStringContainsString('· testando', $rendered);
        $this->assertStringContainsString('falhou', $rendered);
        $this->assertStringContainsString('phpunit retornou 3', $rendered);
        $entries = $reporter->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('failed', $entries[0]['status']);
    }

    public function test_note_writes_metadata_line(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        $reporter = new DevProgressReporter($output);

        $reporter->note('workspace', '/repo/atlas');
        $reporter->note('provider', 'claude_cli');

        $rendered = $output->fetch();
        $this->assertStringContainsString('· workspace /repo/atlas', $rendered);
        $this->assertStringContainsString('· provider claude_cli', $rendered);
    }

    public function test_summarize_supports_recorded_runs_without_live_timing(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        $reporter = new DevProgressReporter($output);

        $reporter->summarize('edit', 'done', 65000, '4 arquivos');

        $rendered = $output->fetch();
        $this->assertStringContainsString('· editando', $rendered);
        $this->assertStringContainsString('1m5s', $rendered);
        $this->assertStringContainsString('4 arquivos', $rendered);
    }

    public function test_format_duration_handles_seconds_and_minutes(): void
    {
        $reporter = new DevProgressReporter(new BufferedOutput);
        $this->assertSame('<1s', $reporter->formatDuration(0));
        $this->assertSame('5s', $reporter->formatDuration(5_000));
        $this->assertSame('1m', $reporter->formatDuration(60_000));
        $this->assertSame('2m11s', $reporter->formatDuration(131_000));
    }

    public function test_starting_a_new_phase_finalizes_pending_one(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        $reporter = new DevProgressReporter($output);

        $reporter->start('edit');
        $reporter->start('test');

        $rendered = $output->fetch();
        $this->assertStringContainsString('pendente', $rendered);
        $this->assertStringContainsString('· editando', $rendered);
        $this->assertStringContainsString('· testando', $rendered);
    }
}
