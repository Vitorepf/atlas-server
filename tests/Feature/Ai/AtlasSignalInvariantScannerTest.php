<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\Signal\AtlasSignalInvariantScanner;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * A ação 1.1 — o produtor que o adaptador de fee982f52 esperava, e que o doc
 * mãe descrevia no presente sem que existisse.
 *
 * Duas das quatro classes saem 0 COM MOTIVO, e essa é a resposta certa: saber
 * quem escreve uma tabela e quem lê um campo é aresta de chamada, e o índice de
 * código tem 844k símbolos e zero arestas. Uma heurística ali viraria backlog
 * fabricado com cara de medição.
 */
final class AtlasSignalInvariantScannerTest extends TestCase
{
    private function scan(): array
    {
        return app(AtlasSignalInvariantScanner::class)->scan();
    }

    public function test_a_class_it_cannot_derive_reports_zero_with_the_reason_never_a_guess(): void
    {
        $report = $this->scan();

        foreach (['gate_field_without_producer', 'producer_without_clock'] as $class) {
            self::assertFalse($report['derivable'][$class], "{$class} não é derivável hoje");
            self::assertSame(0, $report['counts'][$class]);
            self::assertNotEmpty($report['reasons'][$class] ?? '', 'contagem 0 sem motivo escrito é um silêncio, não uma medição');
        }
    }

    public function test_a_cadence_whose_filter_rejects_is_a_finding(): void
    {
        $schedule = app(Schedule::class);
        $schedule->command('inspire')->daily()->when(static fn (): bool => false);

        $subjects = array_column(
            array_filter($this->scan()['findings'], static fn (array $f): bool => $f['class'] === 'orphan_cadence'),
            'command',
        );

        self::assertContains('inspire', $subjects, 'cadência que existe e não pode disparar é achado');
    }

    public function test_a_cadence_that_can_fire_is_not_a_finding(): void
    {
        $schedule = app(Schedule::class);
        $schedule->command('list')->daily()->when(static fn (): bool => true);

        $subjects = array_column(
            array_filter($this->scan()['findings'], static fn (array $f): bool => $f['class'] === 'orphan_cadence'),
            'command',
        );

        self::assertNotContains('list', $subjects, 'cadência viva não pode virar backlog');
    }

    public function test_two_dead_entries_of_the_same_command_are_two_findings(): void
    {
        // Duas linhas de `queue:work` em filas diferentes são duas cadências
        // mortas. Um subject compartilhado faria o adaptador colapsá-las num
        // achado só — perdendo uma sem que ninguém visse.
        $schedule = app(Schedule::class);
        $schedule->command('inspire', ['--a'])->daily()->when(static fn (): bool => false);
        $schedule->command('inspire', ['--b'])->daily()->when(static fn (): bool => false);

        $found = array_filter(
            $this->scan()['findings'],
            static fn (array $f): bool => $f['class'] === 'orphan_cadence' && ($f['command'] ?? '') === 'inspire',
        );

        self::assertCount(2, $found);
        self::assertCount(2, array_unique(array_column($found, 'subject')));
    }

    public function test_the_empty_tables_still_come_through_with_their_targets(): void
    {
        $report = $this->scan();

        self::assertTrue($report['derivable']['table_without_owner']);
        self::assertSame(
            $report['findings_total'],
            array_sum($report['counts']),
            'o total tem de ser a soma das classes — nenhum achado aparece do nada'
        );
    }
}
