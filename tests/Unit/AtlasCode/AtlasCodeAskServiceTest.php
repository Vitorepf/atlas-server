<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeAskService;
use App\Services\AtlasCode\AtlasCodeRepoLocator;
use App\Services\AtlasCode\AtlasCodeWorkspaceScanner;
use App\Services\AtlasCode\AtlasCodeViolationService;
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

    public function test_changes_phrase_counts_signatures_only_when_there_is_more_than_one_hand(): void
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

    public function test_a_single_hand_is_not_news_and_does_not_pad_the_answer(): void
    {
        // A frase original era "32 commits hoje — 32 de Vitor Freire.", no
        // repositório do próprio Vitor. Contagem de assinatura com um autor só
        // é ruído com cara de dado: ele já sabe que foi ele.
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 100, 'message' => 'x'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 90, 'message' => 'y'],
        ];

        self::assertSame('2 commits hoje.', $this->ask->phraseChanges($commits, 'today'));
    }

    public function test_the_phrase_carries_the_work_not_only_the_count(): void
    {
        // O que o operador quer saber ao abrir o app: o que mudou — não quantas
        // vezes alguém apertou commit.
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 100, 'message' => 'x'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 90, 'message' => 'y'],
        ];
        $work = [
            'files' => 47,
            'additions' => 2104,
            'deletions' => 890,
            'top' => [['path' => 'App/Atlas/AtlasCodeView.swift', 'touches' => 9]],
        ];

        self::assertSame(
            '2 commits hoje: 47 arquivos, +2104 −890. O mais mexido: AtlasCodeView.swift (9×).',
            $this->ask->phraseChanges($commits, 'today', $work)
        );
    }

    public function test_a_file_touched_once_is_not_a_concentration_of_effort(): void
    {
        // "O mais mexido: X (1×)" seria ruído: com um toque, não há concentração.
        $commits = [['hash' => str_repeat('a', 40), 'author_name' => 'V', 'authored_at' => 100, 'message' => 'x']];
        $work = ['files' => 3, 'additions' => 10, 'deletions' => 2, 'top' => [['path' => 'a.swift', 'touches' => 1]]];

        self::assertSame('1 commit hoje: 3 arquivos, +10 −2.', $this->ask->phraseChanges($commits, 'today', $work));
    }

    public function test_work_parsing_counts_distinct_files_and_never_invents_binary_lines(): void
    {
        $numstat = implode("\n", [
            "7\t6\tApp/Atlas/AtlasCodeView.swift",
            "271\t168\tApp/Atlas/AtlasCodeRadarView.swift",
            "-\t-\tApp/Assets/icon.png",
            "12\t3\tApp/Atlas/AtlasCodeView.swift",
        ]);

        $work = $this->ask->parseWork($numstat);

        // 3 arquivos distintos, e o binário conta como arquivo sem somar linha.
        self::assertSame(3, $work['files']);
        self::assertSame(290, $work['additions']);
        self::assertSame(177, $work['deletions']);
        // Tocado em dois commits = onde o esforço bateu.
        self::assertSame(['path' => 'App/Atlas/AtlasCodeView.swift', 'touches' => 2], $work['top'][0]);
    }

    public function test_changes_phrase_speaks_portuguese_in_the_singular(): void
    {
        $one = [['hash' => str_repeat('a', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 100, 'message' => 'x']];

        self::assertSame('1 commit ontem.', $this->ask->phraseChanges($one, 'yesterday'));
    }

    public function test_no_commits_is_said_not_dressed_up(): void
    {
        self::assertSame('nenhum commit hoje.', $this->ask->phraseChanges([], 'today'));
    }

    public function test_problems_phrase_groups_by_rule_in_human_words(): void
    {
        // Ids reais do motor de regras — os mesmos cinco que ele emite.
        $violations = [
            ['rule_id' => 'main_only', 'target' => 'obra-1'],
            ['rule_id' => 'main_only', 'target' => 'obra-2'],
            ['rule_id' => 'worktree_allowlist', 'target' => '/tmp/wt'],
        ];

        self::assertSame(
            '3 exceções: 2 obras fora da main, 1 worktree fora do lugar.',
            $this->ask->phraseProblems($violations)
        );
    }

    public function test_who_touched_answers_what_the_question_really_wants(): void
    {
        // "Vitor Freire (9 commits)" no repositório do próprio Vitor informa
        // que ele existe. O que ele quer saber: o arquivo é quente? cresceu ou
        // encolheu? mexeram agora ou faz meses?
        $now = 1_784_100_000;
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'Vitor Freire', 'authored_at' => $now - 7200, 'message' => 'x'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'Vitor Freire', 'authored_at' => $now - 90000, 'message' => 'y'],
        ];
        $work = ['files' => 1, 'additions' => 1204, 'deletions' => 380, 'top' => []];

        self::assertSame(
            '2 commits tocaram “atlascodeview.swift”: +1204 −380. O último há 2 horas.',
            $this->ask->phraseWhoTouched('atlascodeview.swift', $commits, $work, $now)
        );
    }

    public function test_who_touched_names_the_hands_only_when_there_is_more_than_one(): void
    {
        $now = 1_784_100_000;
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'Vitor Freire', 'authored_at' => $now - 600, 'message' => 'x'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'forge', 'authored_at' => $now - 3600, 'message' => 'y'],
        ];

        $phrase = $this->ask->phraseWhoTouched('worker', $commits, null, $now);

        self::assertStringContainsString('1 de Vitor Freire, 1 de forge', $phrase);
        self::assertStringContainsString('O último há 10 minutos', $phrase);
    }

    public function test_no_file_by_that_name_is_not_the_end_of_the_answer(): void
    {
        // "nenhum commit tocou em pílula" está tecnicamente CERTO (nenhum
        // arquivo se chama pílula) e é inútil: há sete commits falando dela.
        // O operador perguntou pelo ASSUNTO; o caminho era só o palpite dele de
        // onde procurar, e o Atlas não pode morrer no palpite dele.
        //
        // A frase distingue as duas coisas que ele precisa separar: "não
        // existe" e "existe com outro nome".
        $now = 1_784_100_000;
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'V', 'authored_at' => $now - 3600, 'message' => 'feat: a pílula responde'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'V', 'authored_at' => $now - 7200, 'message' => 'fix: a pílula'],
        ];

        self::assertSame(
            'nenhum arquivo com “pilula” no nome — mas 2 commits falam disso. O último há 1 hora.',
            $this->ask->phraseSubjectOnly('pilula', $commits, $now)
        );
    }

    public function test_the_subject_fallback_speaks_singular_too(): void
    {
        $now = 1_784_100_000;
        $one = [['hash' => str_repeat('a', 40), 'author_name' => 'V', 'authored_at' => $now - 86400, 'message' => 'x']];

        self::assertSame(
            'nenhum arquivo com “metal” no nome — mas 1 commit fala disso. O último há 1 dia.',
            $this->ask->phraseSubjectOnly('metal', $one, $now)
        );
    }

    public function test_standing_on_the_trunk_is_not_the_end_of_the_branch_question(): void
    {
        // "não há branch para explicar aqui" enquanto o Atlas acusa 4 branches
        // órfãs na resposta ao lado. Estar na trunk é a posição NORMAL dele; a
        // pergunta é sobre as OUTRAS.
        //
        // E a frase separa dívida de trabalho: a mais velha não voltou; a mais
        // nova é um agente rodando agora — branch nova não é dívida.
        $now = 1_784_100_000;
        $others = [
            ['name' => 'atlas/loop/run', 'at' => $now - 22 * 86400],
            ['name' => 'claude/dazzling-napier', 'at' => $now - 4 * 3600],
        ];

        self::assertSame(
            'você está na main. Fora dela existem 2 branches — a mais velha é atlas/loop/run, há 22 dias; '
                .'a mais nova, claude/dazzling-napier, nasceu há 4 horas.',
            $this->ask->phraseOtherBranches('main', $others, $now)
        );
    }

    public function test_only_the_trunk_is_the_truth_not_a_dead_end(): void
    {
        self::assertSame(
            'você está na production, e não existe outra branch neste repositório.',
            $this->ask->phraseOtherBranches('production', [])
        );
    }

    public function test_an_old_branch_alone_is_debt_without_the_new_branch_noise(): void
    {
        // Com uma só, "a mais nova" seria a mesma coisa dita duas vezes.
        $now = 1_784_100_000;
        $one = [['name' => 'atlas/loop/run', 'at' => $now - 22 * 86400]];

        self::assertSame(
            'você está na main. Fora dela existem 1 branch — a mais velha é atlas/loop/run, há 22 dias.',
            $this->ask->phraseOtherBranches('main', $one, $now)
        );
    }

    public function test_branch_parsing_survives_a_pipe_in_the_name_and_skips_the_trunk(): void
    {
        // `for-each-ref` NÃO conhece `%x1f` (só o `git log` conhece): o formato
        // saía literal e o parse voltava vazio — o Atlas dizia "não existe
        // branch" num repo com 20. Por isso o separador é `|`, com a DATA na
        // frente: nome de branch pode conter `|`, e assim ele é o resto.
        $output = implode("\n", [
            '1782192952|atlas/loop/run',
            '1784100000|main',
            '1783555542|claude/clever-joliot',
            'lixo sem separador',
            '1783555999|feature|com|pipe',
        ]);

        $branches = $this->ask->parseBranches($output, 'main');

        // A trunk sai; o lixo sai; o nome com pipe sobrevive inteiro.
        self::assertSame(
            ['atlas/loop/run', 'claude/clever-joliot', 'feature|com|pipe'],
            array_column($branches, 'name')
        );
        // Da mais velha para a mais nova: a primeira é a que não voltou.
        self::assertSame(1782192952, $branches[0]['at']);
    }

    public function test_relative_time_speaks_short_portuguese(): void
    {
        $now = 1_784_100_000;

        // PROSA, não vocabulário de gráfico: isto vive dentro de uma frase.
        // "há 22d" é compacto para caber ao lado de um nó; "há 22 dias" é
        // português. E o singular é dito — nunca "1 dias".
        self::assertSame('1 minuto', AtlasCodeAskService::ago($now - 5, $now));
        self::assertSame('30 minutos', AtlasCodeAskService::ago($now - 1800, $now));
        self::assertSame('1 hora', AtlasCodeAskService::ago($now - 3600, $now));
        self::assertSame('5 horas', AtlasCodeAskService::ago($now - 18000, $now));
        self::assertSame('1 dia', AtlasCodeAskService::ago($now - 86400, $now));
        self::assertSame('3 dias', AtlasCodeAskService::ago($now - 3 * 86400, $now));
        self::assertSame('1 mês', AtlasCodeAskService::ago($now - 31 * 86400, $now));
        self::assertSame('2 meses', AtlasCodeAskService::ago($now - 60 * 86400, $now));
    }

    public function test_the_exception_says_how_old_it_is_because_that_is_what_changes_the_decision(): void
    {
        // "23 exceções" é um número. "A mais antiga há 22 dias" é urgência — é
        // o que faz o operador agir ou dormir tranquilo. O `since` sempre
        // esteve na violação e era jogado fora.
        $now = (new \DateTimeImmutable('2026-07-15 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $violations = [
            ['rule_id' => 'main_only', 'target' => 'obra-17', 'since' => '2026-06-23T12:00:00Z'],
            ['rule_id' => 'main_only', 'target' => 'obra-18', 'since' => '2026-07-14T12:00:00Z'],
        ];

        self::assertSame(
            '2 exceções: 2 obras fora da main. A mais antiga há 22 dias.',
            $this->ask->phraseProblems($violations, 'main', $now)
        );
    }

    public function test_an_exception_without_a_date_never_becomes_zero_days(): void
    {
        // O operador já foi enganado por número sem sentido na tela. "Há 0
        // dias" numa violação que ninguém datou seria exatamente isso: um
        // número que parece medida e não é.
        $violations = [['rule_id' => 'orphan_branch', 'target' => 'x']];

        self::assertSame(
            '1 exceção: 1 branch que nunca voltou.',
            $this->ask->phraseProblems($violations, 'main')
        );
        self::assertNull($this->ask->oldestViolation($violations));
    }

    public function test_a_date_in_the_future_is_refused_instead_of_read_backwards(): void
    {
        // Relógio torto não vira "há -3 dias" nem "agora": é descartado.
        $now = (new \DateTimeImmutable('2026-07-15 12:00:00', new \DateTimeZone('UTC')))->getTimestamp();

        self::assertNull($this->ask->oldestViolation(
            [['rule_id' => 'main_only', 'since' => '2027-01-01T00:00:00Z']],
            $now
        ));
    }

    public function test_the_operator_types_without_accent_and_still_finds_the_accented_commit(): void
    {
        // BUG REAL: "procura pilula" → "nenhum commit fala de pilula", com SEIS
        // commits sobre a pílula no repositório. O roteador tira o acento
        // (certo) e o --grep literal nunca casa "pílula".
        //
        // Classe de caractere não resolve: o regex do git é byte a byte e `í`
        // são dois bytes — `p[ií]lula` casa ZERO (medido). Só alternação.
        self::assertSame('p(i|í)l(u|ú|ü)l(a|á|à|ã|â)', $this->ask->accentTolerantPattern('pilula'));
        self::assertSame('r(e|é|ê)v(i|í)s(a|á|à|ã|â)', $this->ask->accentTolerantPattern('revisa'));
        // Consoante com cedilha entra no par; o resto passa igual.
        self::assertSame('(c|ç)(o|ó|õ|ô)d(i|í)g(o|ó|õ|ô)', $this->ask->accentTolerantPattern('codigo'));
    }

    public function test_a_term_the_operator_typed_is_literal_never_a_wildcard(): void
    {
        // Se ele digitou `.` ou `*`, ele quis o caractere — não um curinga que
        // faz a busca voltar o repositório inteiro.
        $pattern = $this->ask->accentTolerantPattern('v1.*');

        self::assertStringContainsString('\.', $pattern);
        self::assertStringContainsString('\*', $pattern);
    }

    public function test_find_says_when_because_that_is_half_the_search(): void
    {
        $now = 1_784_100_000;
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'V', 'authored_at' => $now - 7200, 'message' => 'fix: o guarda'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'V', 'authored_at' => $now - 3 * 86400, 'message' => 'feat: nasce'],
        ];

        self::assertSame(
            '2 commits falam de “sanitizer”, entre 3 dias e 2 horas atrás. O mais recente: “fix: o guarda”',
            $this->ask->phraseFind('sanitizer', $commits, $now)
        );
    }

    public function test_a_single_find_is_the_message_without_ceremony(): void
    {
        // Com um só, "o mais recente" é cerimônia: não há disputa.
        $now = 1_784_100_000;
        $commits = [['hash' => str_repeat('a', 40), 'author_name' => 'V', 'authored_at' => $now - 3600, 'message' => 'feat: pílula']];

        self::assertSame(
            '1 commit fala de “pilula”, há 1 hora: “feat: pílula”',
            $this->ask->phraseFind('pilula', $commits, $now)
        );
    }

    public function test_a_conversation_inside_one_window_does_not_fake_an_interval(): void
    {
        // "entre 3 horas e 3 horas atrás" seria ruído.
        $now = 1_784_100_000;
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'V', 'authored_at' => $now - 10800, 'message' => 'b'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'V', 'authored_at' => $now - 11000, 'message' => 'a'],
        ];

        self::assertStringContainsString('há 3 horas.', $this->ask->phraseFind('x', $commits, $now));
    }

    public function test_healthy_repository_is_quiet_not_celebrated(): void
    {
        self::assertSame('nada fora do lugar neste repositório.', $this->ask->phraseProblems([]));
    }

    public function test_the_phrase_names_the_real_trunk_not_the_word_main(): void
    {
        // A frota do operador não é só Atlas: nivor-back-end nem TEM `main` —
        // a trunk é `production`. Dizer "fora da main" ali é mentira, e o
        // Atlas mentindo sobre a lei é pior que o Atlas calado.
        $violations = [['rule_id' => 'main_only', 'target' => 'develop']];

        self::assertSame(
            '1 exceção: 1 obra fora da production.',
            $this->ask->phraseProblems($violations, 'production')
        );
        self::assertSame(
            '1 exceção: 1 obra fora da main.',
            $this->ask->phraseProblems($violations, 'main')
        );
    }

    public function test_every_rule_the_engine_emits_speaks_portuguese(): void
    {
        // A primeira versão desta tradução foi escrita de imaginação — com ids
        // que não existem — e a tela mostrou "18 × obra_return_deadline" ao
        // operador. Este teste amarra a tradução às regras REAIS: uma sexta
        // regra sem tradução quebra aqui, não na cara dele.
        $engineRules = (new AtlasCodeViolationService())->scan([
            'main_branch' => 'main',
            'current_branch' => 'feature/cobaia',
            'current_since' => '2026-07-01T00:00:00Z',
            'allowed_worktree_roots' => ['/repo'],
            'worktrees' => [['path' => '/tmp/foreign', 'head' => 'a']],
            'obra_return_deadline_days' => 3,
            'now' => '2026-07-15T00:00:00Z',
            'branches' => [
                ['name' => 'feature/cobaia', 'committed_at' => '2026-07-01T00:00:00Z', 'reachable_from_main' => true],
                ['name' => 'orphan', 'committed_at' => '2026-07-14T00:00:00Z', 'reachable_from_main' => false],
            ],
            'main_head' => 'main-hash',
            'mirror_head' => 'old-hash',
        ])['violations'];

        foreach (array_unique(array_column($engineRules, 'rule_id')) as $ruleId) {
            $phrase = $this->ask->phraseRule($ruleId, 2);
            self::assertStringNotContainsString(
                $ruleId,
                $phrase,
                "a regra {$ruleId} vaza vocabulário de máquina para a tela: {$phrase}"
            );
            self::assertStringNotContainsString('_', $phrase, "tradução com underscore não é português: {$phrase}");
        }
    }

    public function test_a_rule_the_atlas_grew_later_shows_its_id_instead_of_an_invented_name(): void
    {
        // Tradução inventada para regra desconhecida seria mentira confiante.
        // Feio de propósito: pede tradução em vez de fingir que tem uma.
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

    public function test_a_git_that_does_not_answer_is_said_never_reported_as_an_empty_day(): void
    {
        // A pior mentira de uma ferramenta de governança é a que parece
        // verdade. Antes disto TODA falha de git virava string vazia, e string
        // vazia virava "não há commit hoje": timeout de 20s num repo grande,
        // permissão negada, `.git` corrompido — tudo dizia ao operador
        // exatamente o mesmo que um dia sem trabalho, numa frase afirmativa e
        // sem ressalva nenhuma. Ele acreditaria, e acreditaria com razão: a
        // ferramenta existe justamente para ele não precisar conferir.
        //
        // Aqui o `.git` é uma pasta vazia: o locator ACHA o repositório (ele só
        // procura a pasta) e o git recusa de verdade. Falha real, não simulada.
        $raiz = sys_get_temp_dir().'/atlas-git-quebrado-'.uniqid();
        mkdir($raiz.'/repo-quebrado/.git', 0o777, true);
        putenv('ATLAS_CODE_WORKSPACE_ROOT='.$raiz);

        try {
            $resposta = (new AtlasCodeAskService(
                locator: new AtlasCodeRepoLocator(scanner: new AtlasCodeWorkspaceScanner())
            ))->answer('repo-quebrado', 'o que mudou hoje?');

            self::assertFalse($resposta['answered'], 'git que não responde NÃO é um dia vazio');
            self::assertStringContainsString('não consegui ler o git', $resposta['answer']);
            self::assertSame([], $resposta['commits']);
        } finally {
            putenv('ATLAS_CODE_WORKSPACE_ROOT');
            rmdir($raiz.'/repo-quebrado/.git');
            rmdir($raiz.'/repo-quebrado');
            rmdir($raiz);
        }
    }

    public function test_a_broken_git_collecting_facts_leaves_the_agent_without_a_crutch(): void
    {
        // Coletando fato, `answered=false` faz o bloco sumir no app
        // (AtlasCodeFacts.block devolve nil) e o agente responde sem muleta.
        // O caminho errado seria mandar "não consegui ler o git" como se fosse
        // FATO do repositório: o agente citaria um git fantasma como se
        // tivesse aberto.
        $raiz = sys_get_temp_dir().'/atlas-git-quebrado-facts-'.uniqid();
        mkdir($raiz.'/repo-quebrado/.git', 0o777, true);
        putenv('ATLAS_CODE_WORKSPACE_ROOT='.$raiz);

        try {
            $resposta = (new AtlasCodeAskService(
                locator: new AtlasCodeRepoLocator(scanner: new AtlasCodeWorkspaceScanner())
            ))->answer('repo-quebrado', 'revise os commits de hoje', null, null, AtlasCodeAskService::MODE_FACTS);

            self::assertFalse($resposta['answered']);
            self::assertArrayNotHasKey('detail', $resposta, 'sem git não há código para o agente ler');
        } finally {
            putenv('ATLAS_CODE_WORKSPACE_ROOT');
            rmdir($raiz.'/repo-quebrado/.git');
            rmdir($raiz.'/repo-quebrado');
            rmdir($raiz);
        }
    }

    public function test_a_cited_commit_hands_the_agent_the_whole_commit(): void
    {
        // A pergunta que a folha do commit semeia. Antes, caía em `unknown` e
        // o agente respondia sem os fatos do commit que o operador olhava.
        $raiz = sys_get_temp_dir().'/atlas-commit-citado-'.uniqid();
        mkdir($raiz.'/repo-x', 0o777, true);
        $run = static function (string $cmd) use ($raiz): string {
            return (string) shell_exec('cd '.escapeshellarg($raiz.'/repo-x').' && '.$cmd.' 2>&1');
        };
        $run('git init -q -b main');
        $run('git -c user.name="Vitor Freire" -c user.email=v@x.test commit -q --allow-empty -m "feat: nasce o motor" -m "O corpo explica o porquê."');
        file_put_contents($raiz.'/repo-x/a.txt', "linha\n");
        $run('git add a.txt && git -c user.name="Vitor Freire" -c user.email=v@x.test commit -q -m "feat(core): grava a linha" -m "Porque linha gravada é fato."');
        $full = trim($run('git rev-parse HEAD'));
        putenv('ATLAS_CODE_WORKSPACE_ROOT='.$raiz);

        try {
            $ask = new AtlasCodeAskService(locator: new AtlasCodeRepoLocator(scanner: new AtlasCodeWorkspaceScanner()));
            $curto = substr($full, 0, 9);
            $resposta = $ask->answer('repo-x', "o que o commit {$curto} fez, e por quê?", null, null, AtlasCodeAskService::MODE_FACTS);

            self::assertSame('commit', $resposta['intent']);
            self::assertTrue($resposta['answered']);
            self::assertStringContainsString('feat(core): grava a linha', $resposta['answer']);
            self::assertStringContainsString('Vitor Freire', $resposta['answer']);
            // A âncora é o hash COMPLETO: o grafo compara hashes cheios, e uma
            // âncora abreviada nunca acenderia nada.
            self::assertSame([$full], $resposta['commits']);
            // O dossiê do agente: a descrição do autor E o código.
            self::assertStringContainsString('Porque linha gravada é fato.', (string) ($resposta['detail'] ?? ''));
            self::assertStringContainsString('diff --git', (string) ($resposta['detail'] ?? ''));
        } finally {
            putenv('ATLAS_CODE_WORKSPACE_ROOT');
            shell_exec('rm -rf '.escapeshellarg($raiz));
        }
    }

    public function test_a_hash_that_does_not_exist_is_a_verified_absence_not_a_guess(): void
    {
        $raiz = sys_get_temp_dir().'/atlas-hash-inexistente-'.uniqid();
        mkdir($raiz.'/repo-x', 0o777, true);
        shell_exec('cd '.escapeshellarg($raiz.'/repo-x').' && git init -q -b main && git -c user.name=V -c user.email=v@x.test commit -q --allow-empty -m ok 2>&1');
        putenv('ATLAS_CODE_WORKSPACE_ROOT='.$raiz);

        try {
            $ask = new AtlasCodeAskService(locator: new AtlasCodeRepoLocator(scanner: new AtlasCodeWorkspaceScanner()));
            $resposta = $ask->answer('repo-x', 'o que o commit deadbee99 fez?', null, null, AtlasCodeAskService::MODE_FACTS);

            // O git está DE PÉ (a sonda rev-parse HEAD passou), então "não
            // achei" é ausência verificada — fato, não falha vestida de fato.
            self::assertTrue($resposta['answered']);
            self::assertStringContainsString('não achei o commit deadbee99', $resposta['answer']);
            self::assertSame([], $resposta['commits']);
        } finally {
            putenv('ATLAS_CODE_WORKSPACE_ROOT');
            shell_exec('rm -rf '.escapeshellarg($raiz));
        }
    }

    public function test_a_dead_provenance_registry_does_not_accuse_the_branch(): void
    {
        // Medido em 16/07: o escritor de proveniência nunca foi chamado — 0
        // eventos em 2.125 — e TODA branch fora da trunk recebia "ela nasceu
        // fora do Atlas". Ausência num registro que nunca gravou nada não
        // discrimina nada: acusar com ela é vestir um vazio de fato. A
        // acusação fica reservada ao caso em que informa: registro VIVO e a
        // branch fora dele.
        $raiz = sys_get_temp_dir().'/atlas-branch-sem-registro-'.uniqid();
        mkdir($raiz.'/repo-x', 0o777, true);
        $run = static fn (string $cmd): string => (string) shell_exec('cd '.escapeshellarg($raiz.'/repo-x').' && '.$cmd.' 2>&1');
        $run('git init -q -b main');
        $run('git -c user.name=V -c user.email=v@x.test commit -q --allow-empty -m base');
        $run('git checkout -q -b obra/experimento');
        putenv('ATLAS_CODE_WORKSPACE_ROOT='.$raiz);

        try {
            $ask = new AtlasCodeAskService(locator: new AtlasCodeRepoLocator(scanner: new AtlasCodeWorkspaceScanner()));
            $resposta = $ask->answer('repo-x', 'por que essa branch existe?');

            self::assertTrue($resposta['answered']);
            self::assertStringContainsString('ainda não grava proveniência', $resposta['answer']);
            self::assertStringNotContainsString('nasceu fora do Atlas', $resposta['answer']);
        } finally {
            putenv('ATLAS_CODE_WORKSPACE_ROOT');
            shell_exec('rm -rf '.escapeshellarg($raiz));
        }
    }
}
