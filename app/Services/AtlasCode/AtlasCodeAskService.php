<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Atlas Código · H6 · a pílula responde.
 *
 * A pílula não é chat. Chat devolve prosa e deixa o operador traduzir sozinho
 * para ação. Aqui cada resposta é um FATO ancorado: uma frase curta + os
 * commits que a sustentam, para o grafo acender atrás do vidro. Todo hash
 * citado foi filtrado da topologia — nenhum foi lembrado por um modelo.
 *
 * A frase é composta aqui, no servidor, e não no app, porque a resposta tem
 * uma voz só: quando a cauda longa for para o cérebro (ACOS), ele devolve a
 * frase pelo mesmo campo. O app renderiza a resposta; ele não aprende a
 * fraseá-la para cada intenção.
 *
 * Somente leitura: nenhum comando aqui muta o worktree.
 */
final class AtlasCodeAskService
{
    public const SCHEMA_VERSION = 'atlas.code.ask.v1';

    public const SOURCE_GRAPH = 'graph';

    public const SOURCE_RULES = 'rules';

    public const SOURCE_LEDGER = 'ledger';

    /** Quantos commits uma resposta ancora antes de virar ruído. */
    private const MAX_ANCHORS = 12;

    public function __construct(
        private readonly ?AtlasCodeQuestionRouter $router = null,
        private readonly ?AtlasCodeRepoLocator $locator = null,
        private readonly ?AtlasCodeViolationService $violations = null,
        private readonly int $timeoutSeconds = 20,
        private readonly ?AtlasCodeBrainService $brain = null,
        private readonly ?AtlasCodeReviewService $review = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    /**
     * @param  string|null  $timezone  O fuso do OPERADOR (IANA). Quem sabe onde
     *                                 ele está é o aparelho na mão dele, então o
     *                                 app manda; o servidor nunca adivinha.
     */
    public function answer(string $repo, string $question, ?int $now = null, ?string $timezone = null): array
    {
        $asked = trim($question);
        if ($asked === '') {
            throw new InvalidArgumentException('empty_question');
        }

        $located = ($this->locator ?? new AtlasCodeRepoLocator())->locate($repo);
        $route = ($this->router ?? new AtlasCodeQuestionRouter())->route($asked);
        $now ??= time();

        $result = match ($route['intent']) {
            AtlasCodeQuestionRouter::INTENT_PROBLEMS => $this->answerProblems($located['slug']),
            AtlasCodeQuestionRouter::INTENT_CHANGES => $this->answerChanges($located['path'], (string) $route['window'], $now, $timezone),
            AtlasCodeQuestionRouter::INTENT_REVIEW_BATCH => $this->startReview($located['slug'], $located['path'], (string) $route['window'], $now, $timezone),
            AtlasCodeQuestionRouter::INTENT_WHY_BRANCH => $this->answerWhyBranch($located['slug'], $located['path']),
            AtlasCodeQuestionRouter::INTENT_WHO_TOUCHED => $this->answerWhoTouched($located['path'], $route['term']),
            AtlasCodeQuestionRouter::INTENT_FIND => $this->answerFind($located['path'], $route['term']),
            // Não é filtro do grafo: é pergunta de julgamento. Vai ao cérebro.
            default => $this->consultBrain($asked, $located['path']),
        };

        $response = [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => $located['slug'],
            'question' => $asked,
            'intent' => $route['intent'],
            'answered' => $result['answered'],
            'answer' => $result['answer'],
            'commits' => array_slice($result['commits'], 0, self::MAX_ANCHORS),
            // Quantas âncoras EXISTEM, não quantas couberam. Sem isto a tela só
            // sabe dizer "há mais", e "12 acesos" ao lado de "22 commits" lê
            // como contradição em vez de recorte.
            'commits_total' => count($result['commits']),
            'truncated' => count($result['commits']) > self::MAX_ANCHORS,
            'evidence' => $result['evidence'],
            'source' => $result['source'],
        ];

        // "Hoje" é uma afirmação sobre um recorte do tempo: o recorte vai junto,
        // para o operador poder conferir contra o próprio git.
        if ($route['intent'] === AtlasCodeQuestionRouter::INTENT_CHANGES) {
            $response['window'] = [
                'kind' => (string) $route['window'],
                'since' => $this->windowStart((string) $route['window'], $now, $timezone),
                'timezone' => $this->zone($timezone)->getName(),
            ];
        }

        return $response;
    }

    // MARK: — Composição das frases (puras, golden-testáveis)

    /**
     * @param  array<int, array{hash:string, author_name:string, authored_at:int, message:string}>  $commits
     */
    /**
     * A frase do dia — e o que ela DEVE responder.
     *
     * A primeira versão dizia "32 commits hoje — 32 de Vitor Freire.": um
     * `git log` com fonte serifada. O operador não abre o Atlas para contar
     * commits nem para descobrir que ele mesmo os fez; ele abre para saber O
     * QUE mudou. Contagem de assinatura, num repositório de um homem só, é
     * ruído com cara de dado.
     *
     * Agora a frase carrega o TRABALHO: quantos arquivos, quanto entrou e
     * saiu, e onde o esforço se concentrou. Tudo fato do git — nada inferido.
     *
     * @param  array<int, array{hash:string, author_name:string, authored_at:int, message:string}>  $commits
     * @param  array{files:int, additions:int, deletions:int, top:array<int,array{path:string,touches:int}>}|null  $work
     */
    public function phraseChanges(array $commits, string $window, ?array $work = null): string
    {
        $when = match ($window) {
            AtlasCodeQuestionRouter::WINDOW_YESTERDAY => 'ontem',
            AtlasCodeQuestionRouter::WINDOW_WEEK => 'nos últimos 7 dias',
            AtlasCodeQuestionRouter::WINDOW_MONTH => 'nos últimos 30 dias',
            default => 'hoje',
        };

        if ($commits === []) {
            return "nenhum commit {$when}.";
        }

        $count = count($commits);
        $noun = $count === 1 ? 'commit' : 'commits';
        $phrase = "{$count} {$noun} {$when}";

        // Quantas mãos: só vale dizer quando há MAIS DE UMA. "32 de Vitor
        // Freire" no repositório do próprio Vitor não informa nada.
        $byAuthor = [];
        foreach ($commits as $commit) {
            $name = trim($commit['author_name']) !== '' ? $commit['author_name'] : 'sem autor';
            $byAuthor[$name] = ($byAuthor[$name] ?? 0) + 1;
        }
        if (count($byAuthor) > 1) {
            arsort($byAuthor);
            $who = [];
            foreach (array_slice($byAuthor, 0, 3, true) as $name => $total) {
                $who[] = "{$total} de {$name}";
            }
            $phrase .= ' — '.implode(', ', $who).(count($byAuthor) > 3 ? ', entre outros' : '');
        }

        if ($work === null || ($work['files'] ?? 0) === 0) {
            return $phrase.'.';
        }

        // O tamanho do trabalho, não o tamanho da lista.
        $fileNoun = $work['files'] === 1 ? 'arquivo' : 'arquivos';
        $phrase .= ": {$work['files']} {$fileNoun}, +{$work['additions']} \u{2212}{$work['deletions']}";

        // Onde o esforço se concentrou — o que um humano perguntaria em seguida.
        $top = $work['top'][0] ?? null;
        if (is_array($top) && ($top['touches'] ?? 0) > 1) {
            $name = basename((string) $top['path']);
            $phrase .= ". O mais mexido: {$name} ({$top['touches']}×)";
        }

        return $phrase.'.';
    }

    /**
     * O trabalho da janela, medido no git: arquivos distintos, linhas, e onde
     * o esforço bateu mais.
     *
     * Puro sobre a saída do `--numstat`, para o teste ler o que o operador lê.
     *
     * @return array{files:int, additions:int, deletions:int, top:array<int,array{path:string,touches:int}>}
     */
    public function parseWork(string $numstat): array
    {
        $touches = [];
        $additions = 0;
        $deletions = 0;

        foreach (preg_split('/\r?\n/', trim($numstat)) ?: [] as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) < 3 || trim($parts[2]) === '') {
                continue;
            }
            // '-' é binário: o git não mediu linha, e nós não inventamos zero.
            if ($parts[0] !== '-') {
                $additions += (int) $parts[0];
            }
            if ($parts[1] !== '-') {
                $deletions += (int) $parts[1];
            }
            $path = trim($parts[2]);
            $touches[$path] = ($touches[$path] ?? 0) + 1;
        }

        arsort($touches);
        $top = [];
        foreach (array_slice($touches, 0, 3, true) as $path => $count) {
            $top[] = ['path' => $path, 'touches' => $count];
        }

        return [
            'files' => count($touches),
            'additions' => $additions,
            'deletions' => $deletions,
            'top' => $top,
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $violations
     * @param  string  $trunk  O nome REAL da trunk deste repositório. Escrever
     *                         "fora da main" onde a trunk é `production` é
     *                         mentira — e o operador tem repositórios assim.
     */
    public function phraseProblems(array $violations, string $trunk = 'main'): string
    {
        if ($violations === []) {
            // Saudável é silêncio: o texto diz o fato, sem festa.
            return 'nada fora do lugar neste repositório.';
        }

        $byRule = [];
        foreach ($violations as $violation) {
            $rule = (string) ($violation['rule_id'] ?? 'desconhecida');
            $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
        }
        arsort($byRule);

        $parts = [];
        foreach ($byRule as $rule => $total) {
            $parts[] = $this->phraseRule($rule, $total, $trunk);
        }

        $count = count($violations);
        $noun = $count === 1 ? 'exceção' : 'exceções';

        return "{$count} {$noun}: ".implode(', ', $parts).'.';
    }

    /**
     * A regra é máquina; a tela é português.
     *
     * Os ids aqui são os cinco que o AtlasCodeViolationService emite de
     * verdade — conferidos no código, não lembrados. A primeira versão desta
     * tradução foi escrita de imaginação (`branch_not_merged`,
     * `worktree_outside_root`, `stale_branch`: nenhum existe) e o resultado
     * apareceu na tela como "18 × obra_return_deadline": exatamente o
     * vazamento de vocabulário de máquina que o operador já tinha cobrado uma
     * vez. AtlasCodeAskServiceTest cobre os cinco para isso não voltar.
     */
    public function phraseRule(string $ruleId, int $total, string $trunk = 'main'): string
    {
        return match ($ruleId) {
            'main_only' => $total === 1 ? "1 obra fora da {$trunk}" : "{$total} obras fora da {$trunk}",
            'obra_return_deadline' => $total === 1 ? '1 obra que não voltou no prazo' : "{$total} obras que não voltaram no prazo",
            'orphan_branch' => $total === 1 ? '1 branch que nunca voltou' : "{$total} branches que nunca voltaram",
            'worktree_allowlist' => $total === 1 ? '1 worktree fora do lugar' : "{$total} worktrees fora do lugar",
            'mirror_drift' => $total === 1 ? '1 espelho atrasado' : "{$total} espelhos atrasados",
            // Regra que o Atlas ganhou depois desta tela: aparece pelo id, sem
            // tradução inventada. Feio de propósito — pede tradução.
            default => "{$total} × {$ruleId}",
        };
    }

    // MARK: — As respostas

    /**
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function answerProblems(string $slug): array
    {
        try {
            $captured = ($this->violations ?? new AtlasCodeViolationService())->capture($slug);
        } catch (Throwable) {
            // O scanner não leu → o Atlas diz isso, e não "está tudo bem".
            return $this->shape(false, 'não consegui varrer as regras deste repositório agora.', source: self::SOURCE_RULES);
        }

        $violations = array_values(array_filter((array) ($captured['violations'] ?? []), 'is_array'));

        // A evidência de uma exceção é a LEI que ela viola, não o id dela. Uma
        // regra sem canon acusaria sem citar — por isso ela vem junto.
        $evidence = [];
        $seen = [];
        foreach ($violations as $violation) {
            $rule = (string) ($violation['rule_id'] ?? 'desconhecida');
            if (isset($seen[$rule])) {
                continue;
            }
            $seen[$rule] = true;
            $evidence[] = array_filter([
                'kind' => 'rule',
                'ref' => $rule,
                'canon' => isset($violation['rule_canon_ref']) ? (string) $violation['rule_canon_ref'] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $this->shape(
            true,
            $this->phraseProblems($violations, (string) ($captured['trunk'] ?? 'main')),
            evidence: $evidence,
            source: self::SOURCE_RULES,
        );
    }

    /**
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function answerChanges(string $path, string $window, int $now, ?string $timezone): array
    {
        $since = $this->windowStart($window, $now, $timezone);
        $lines = $this->git($path, [
            'git', 'log', '--all', '--since=@'.$since, '--format=%H%x1f%an%x1f%at%x1f%s',
        ]);

        $commits = $this->parseCommits($lines);
        // O filtro do git é por data de commit; a janela é por data de autoria.
        $commits = array_values(array_filter($commits, static fn (array $c): bool => $c['authored_at'] >= $since));

        // O trabalho da janela, não só a contagem dela. Uma chamada a mais no
        // git compra a diferença entre "32 commits" e "32 commits: 47
        // arquivos, +2104 −890, o mais mexido foi X".
        $work = $commits === []
            ? null
            : $this->parseWork($this->git($path, [
                'git', 'log', '--all', '--since=@'.$since, '--format=', '--numstat', '-M',
            ]));

        return $this->shape(
            true,
            $this->phraseChanges($commits, $window, $work),
            commits: array_column($commits, 'hash'),
            source: self::SOURCE_GRAPH,
        );
    }

    /**
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function answerWhyBranch(string $slug, string $path): array
    {
        $current = trim($this->git($path, ['git', 'branch', '--show-current']));
        // A trunk deste repositório, não a nossa: em nivor-back-end ela é
        // `production`, e "você está na main" ali seria mentira.
        $trunk = (new AtlasCodeTrunkResolver())->resolve($path);

        if ($current === '' || $current === $trunk) {
            return $this->shape(
                true,
                "você está na {$trunk} — não há branch para explicar aqui.",
                source: self::SOURCE_GRAPH,
            );
        }

        // A pergunta é "por que existe": a resposta honesta cruza a regra que
        // ela viola com o que o ledger sabe sobre o commit que a criou.
        $head = trim($this->git($path, ['git', 'rev-parse', $current]));
        $evidence = [['kind' => 'branch', 'ref' => $current, 'target' => $head]];

        try {
            $provenance = (new AtlasCodeProvenanceService())->capture($head, $slug);
            $quote = $provenance['operator_quote'] ?? null;
            if (is_string($quote) && $quote !== '') {
                return $this->shape(
                    true,
                    "a branch {$current} existe porque você pediu: \u{201C}{$quote}\u{201D}",
                    commits: [$head],
                    evidence: $evidence,
                    source: self::SOURCE_LEDGER,
                );
            }
        } catch (Throwable) {
            // Ledger indisponível não vira explicação inventada.
        }

        return $this->shape(
            true,
            "a branch {$current} não tem proveniência registrada — ela nasceu fora do Atlas.",
            commits: $head !== '' ? [$head] : [],
            evidence: $evidence,
            source: self::SOURCE_LEDGER,
        );
    }

    /**
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function answerWhoTouched(string $path, ?string $term): array
    {
        if ($term === null) {
            return $this->shape(false, 'quem mexeu em quê? diga o arquivo ou o assunto.', source: self::SOURCE_GRAPH);
        }

        $lines = $this->git($path, [
            'git', 'log', '--all', '--format=%H%x1f%an%x1f%at%x1f%s', '--', ':(icase)*'.$term.'*',
        ]);
        $commits = $this->parseCommits($lines);

        if ($commits === []) {
            return $this->shape(true, "nenhum commit tocou em \u{201C}{$term}\u{201D}.", source: self::SOURCE_GRAPH);
        }

        $byAuthor = [];
        foreach ($commits as $commit) {
            $name = trim($commit['author_name']) !== '' ? $commit['author_name'] : 'sem autor';
            $byAuthor[$name] = ($byAuthor[$name] ?? 0) + 1;
        }
        arsort($byAuthor);

        $parts = [];
        foreach ($byAuthor as $name => $total) {
            $parts[] = $total === 1 ? "{$name} (1 commit)" : "{$name} ({$total} commits)";
        }

        return $this->shape(
            true,
            "em \u{201C}{$term}\u{201D}: ".implode(', ', $parts).'.',
            commits: array_column($commits, 'hash'),
            source: self::SOURCE_GRAPH,
        );
    }

    /**
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function answerFind(string $path, ?string $term): array
    {
        if ($term === null) {
            return $this->shape(false, 'procurar o quê? diga o assunto do commit.', source: self::SOURCE_GRAPH);
        }

        $lines = $this->git($path, [
            'git', 'log', '--all', '-i', '--grep='.$term, '--format=%H%x1f%an%x1f%at%x1f%s',
        ]);
        $commits = $this->parseCommits($lines);

        if ($commits === []) {
            return $this->shape(true, "nenhum commit fala de \u{201C}{$term}\u{201D}.", source: self::SOURCE_GRAPH);
        }

        $count = count($commits);
        $noun = $count === 1 ? 'commit fala' : 'commits falam';
        $newest = $commits[0]['message'];

        return $this->shape(
            true,
            "{$count} {$noun} de \u{201C}{$term}\u{201D}. O mais recente: \u{201C}{$newest}\u{201D}",
            commits: array_column($commits, 'hash'),
            source: self::SOURCE_GRAPH,
        );
    }

    /**
     * A 2ª natureza: o operador MANDA revisar, e N agentes vão trabalhar.
     *
     * A resposta não descreve o trabalho — ela ancora os commits, e cada linha
     * do grafo passa a ter estado próprio, mudando ao vivo. A interface É a
     * resposta.
     *
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function startReview(string $slug, string $path, string $window, int $now, ?string $timezone): array
    {
        $since = $this->windowStart($window, $now, $timezone);
        $commits = array_values(array_filter(
            $this->parseCommits($this->git($path, [
                'git', 'log', '--all', '--since=@'.$since, '--format=%H%x1f%an%x1f%at%x1f%s',
            ])),
            static fn (array $commit): bool => $commit['authored_at'] >= $since,
        ));

        $when = match ($window) {
            AtlasCodeQuestionRouter::WINDOW_YESTERDAY => 'ontem',
            AtlasCodeQuestionRouter::WINDOW_WEEK => 'nos últimos 7 dias',
            AtlasCodeQuestionRouter::WINDOW_MONTH => 'nos últimos 30 dias',
            default => 'hoje',
        };

        if ($commits === []) {
            return $this->shape(true, "não há commit {$when} para revisar.", source: self::SOURCE_GRAPH);
        }

        $review = $this->review ?? new AtlasCodeReviewService();
        $hashes = $review->boundedHashes(array_column($commits, 'hash'));

        // O DESPACHO É ASSÍNCRONO, e não é otimização: enfileirar 12 agentes
        // em linha (cada um com leitura de diff + decisão de roteador) estourou
        // os 30s do PHP e devolveu 500 na cara do operador. Ele não espera o
        // despacho — ele vê o grafo mudar. O estado real de cada commit vem do
        // /code/review, que lê o que existe: `idle` enquanto ninguém pegou,
        // depois queued → running → done. Ausência continua dita, e nenhum
        // cartão gira para sempre fingindo trabalho.
        $slugForDispatch = $slug;
        dispatch(function () use ($slugForDispatch, $hashes, $review): void {
            $review->start($slugForDispatch, $hashes);
        })->afterResponse();

        $count = count($hashes);
        $noun = $count === 1 ? 'commit' : 'commits';

        return $this->shape(
            true,
            "{$count} {$noun} em revisão — um agente por commit, ao vivo no grafo.",
            commits: $hashes,
            source: self::SOURCE_GRAPH,
        );
    }

    /**
     * A pergunta que não é filtro sobe para o ACOS: é onde git, ledger, canon
     * e decisão se cruzam — a camada que nenhum cliente de git alcança.
     *
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function consultBrain(string $question, string $path): array
    {
        $consulted = ($this->brain ?? new AtlasCodeBrainService())->consult($question, $path);

        return $this->shape(
            $consulted['answered'],
            $consulted['answer'],
            evidence: $consulted['evidence'],
            source: $consulted['source'],
        );
    }

    // MARK: — Encanamento

    /**
     * @param  array<int,string>  $commits
     * @param  array<int,array<string,string>>  $evidence
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function shape(bool $answered, string $answer, array $commits = [], array $evidence = [], string $source = self::SOURCE_GRAPH): array
    {
        return compact('answered', 'answer', 'commits', 'evidence', 'source');
    }

    /**
     * "Hoje" é o dia do OPERADOR, não o dia do servidor.
     *
     * Este servidor roda em UTC. Em São Paulo, meia-noite UTC é 21h de ontem —
     * então "o que mudou hoje" às 9h da manhã varria três horas da noite
     * anterior e devolvia 24 commits onde o git do Mac via 22. Não era um erro
     * de arredondamento: era o Atlas afirmando um fato falso sobre o dia dele.
     *
     * Semana e mês são janelas móveis (7×24h, 30×24h) e não dependem de fuso.
     */
    public function windowStart(string $window, int $now, ?string $timezone = null): int
    {
        $moment = (new DateTimeImmutable('@'.$now))->setTimezone($this->zone($timezone));

        return match ($window) {
            AtlasCodeQuestionRouter::WINDOW_YESTERDAY => $moment->modify('-1 day')->setTime(0, 0)->getTimestamp(),
            AtlasCodeQuestionRouter::WINDOW_WEEK => $now - 7 * 86_400,
            AtlasCodeQuestionRouter::WINDOW_MONTH => $now - 30 * 86_400,
            default => $moment->setTime(0, 0)->getTimestamp(),
        };
    }

    /** Fuso ilegível não derruba a pergunta — vira UTC, e o recorte é dito. */
    private function zone(?string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone !== null && trim($timezone) !== '' ? trim($timezone) : 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * @return array<int, array{hash:string, author_name:string, authored_at:int, message:string}>
     */
    public function parseCommits(string $output): array
    {
        $commits = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode("\x1f", $line, 4);
            if (count($parts) !== 4 || preg_match('/^[0-9a-f]{40}$/', $parts[0]) !== 1) {
                continue;
            }
            $timestamp = filter_var(trim($parts[2]), FILTER_VALIDATE_INT);
            if ($timestamp === false) {
                continue;
            }
            $commits[] = [
                'hash' => $parts[0],
                'author_name' => trim($parts[1]),
                'authored_at' => (int) $timestamp,
                'message' => trim($parts[3]),
            ];
        }

        return $commits;
    }

    /** @param array<int,string> $command */
    private function git(string $path, array $command): string
    {
        try {
            $process = new Process($command, $path, null, null, $this->timeoutSeconds);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : '';
        } catch (Throwable) {
            return '';
        }
    }
}
