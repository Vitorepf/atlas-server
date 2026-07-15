<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeAskService;
use PHPUnit\Framework\TestCase;

/**
 * A pílula responde com fato, não com prosa. Cada frase aqui é conferível
 * contra o git — e quando não há fato, a frase diz isso em vez de encher.
 */
final class AtlasCodeAskServiceTest extends TestCase
{
    private AtlasCodeAskService $ask;

    protected function setUp(): void
    {
        $this->ask = new AtlasCodeAskService();
    }

    public function test_changes_phrase_counts_signatures_it_actually_read(): void
    {
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 100, 'message' => 'x'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 90, 'message' => 'y'],
            ['hash' => str_repeat('c', 40), 'author_name' => 'forge', 'authored_at' => 80, 'message' => 'z'],
        ];

        self::assertSame(
            '3 commits hoje — 2 de Vitor Freire, 1 de forge.',
            $this->ask->phraseChanges($commits, 'today')
        );
        self::assertSame(
            '3 commits nos últimos 7 dias — 2 de Vitor Freire, 1 de forge.',
            $this->ask->phraseChanges($commits, 'week')
        );
    }

    public function test_changes_phrase_speaks_portuguese_in_the_singular(): void
    {
        $one = [['hash' => str_repeat('a', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 100, 'message' => 'x']];

        self::assertSame('1 commit ontem — 1 de Vitor Freire.', $this->ask->phraseChanges($one, 'yesterday'));
    }

    public function test_no_commits_is_said_not_dressed_up(): void
    {
        self::assertSame('nenhum commit hoje.', $this->ask->phraseChanges([], 'today'));
    }

    public function test_problems_phrase_groups_by_rule_in_human_words(): void
    {
        $violations = [
            ['rule_id' => 'main_only', 'target' => 'obra-1'],
            ['rule_id' => 'main_only', 'target' => 'obra-2'],
            ['rule_id' => 'worktree_outside_root', 'target' => '/tmp/wt'],
        ];

        self::assertSame(
            '3 exceções: 2 obras fora da main, 1 worktree fora do lugar.',
            $this->ask->phraseProblems($violations)
        );
    }

    public function test_healthy_repository_is_quiet_not_celebrated(): void
    {
        self::assertSame('nada fora do lugar neste repositório.', $this->ask->phraseProblems([]));
    }

    public function test_a_rule_the_atlas_grew_later_shows_its_id_instead_of_an_invented_name(): void
    {
        // Tradução inventada para regra desconhecida seria mentira confiante.
        self::assertSame('2 × rule_from_the_future', $this->ask->phraseRule('rule_from_the_future', 2));
    }

    public function test_commit_parsing_refuses_lines_that_are_not_commits(): void
    {
        $hash = str_repeat('a', 40);
        $output = implode("\n", [
            $hash."\x1fVitor Freire\x1f1784126708\x1ffeat: algo",
            'lixo sem separador',
            "naoehash\x1fX\x1f123\x1fy",
            $hash."\x1fVitor\x1fnao-e-data\x1fz",
        ]);

        $commits = $this->ask->parseCommits($output);

        self::assertCount(1, $commits);
        self::assertSame('feat: algo', $commits[0]['message']);
        self::assertSame(1784126708, $commits[0]['authored_at']);
    }

    public function test_commit_message_with_the_separator_would_not_truncate_the_subject(): void
    {
        $hash = str_repeat('a', 40);
        $output = $hash."\x1fVitor\x1f100\x1ffeat: a | b | c";

        self::assertSame('feat: a | b | c', $this->ask->parseCommits($output)[0]['message']);
    }

    public function test_window_start_is_midnight_for_today_not_24h_ago(): void
    {
        // "o que mudou hoje" é desde a meia-noite, não nas últimas 24 horas:
        // às 9h da manhã, ontem à noite não é hoje.
        $now = (new \DateTimeImmutable('2026-07-15 09:00:00', new \DateTimeZone('UTC')))->getTimestamp();

        self::assertSame(
            (new \DateTimeImmutable('2026-07-15 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
            $this->ask->windowStart('today', $now, 'UTC')
        );
        self::assertSame(
            (new \DateTimeImmutable('2026-07-14 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
            $this->ask->windowStart('yesterday', $now, 'UTC')
        );
        self::assertSame($now - 7 * 86400, $this->ask->windowStart('week', $now, 'UTC'));
    }

    public function test_today_belongs_to_the_operator_not_to_the_server(): void
    {
        // Bug real: o servidor roda em UTC. Às 9h em São Paulo (12h UTC), a
        // meia-noite UTC é 21h de ONTEM — a pílula dizia 24 commits onde o git
        // do Mac via 22, varrendo três horas da noite anterior.
        $now = (new \DateTimeImmutable('2026-07-15 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();

        $saoPaulo = $this->ask->windowStart('today', $now, 'America/Sao_Paulo');
        $utc = $this->ask->windowStart('today', $now, 'UTC');

        // Meia-noite em São Paulo é 03:00 UTC: três horas DEPOIS da meia-noite UTC.
        self::assertSame(3 * 3600, $saoPaulo - $utc);
        self::assertSame('2026-07-15 00:00:00', (new \DateTimeImmutable('@'.$saoPaulo))
            ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s'));
    }

    public function test_an_unreadable_timezone_falls_back_instead_of_breaking_the_question(): void
    {
        $now = (new \DateTimeImmutable('2026-07-15 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();

        self::assertSame(
            $this->ask->windowStart('today', $now, 'UTC'),
            $this->ask->windowStart('today', $now, 'Marte/Olympus_Mons')
        );
    }

    public function test_rolling_windows_do_not_depend_on_timezone(): void
    {
        // Semana e mês são janelas móveis: 7×24h é 7×24h em qualquer lugar.
        $now = (new \DateTimeImmutable('2026-07-15 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();

        self::assertSame(
            $this->ask->windowStart('week', $now, 'UTC'),
            $this->ask->windowStart('week', $now, 'Asia/Tokyo')
        );
    }
}
