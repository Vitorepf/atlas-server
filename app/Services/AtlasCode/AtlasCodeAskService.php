<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Services\AtlasCode\Support\AtlasCodeAskPhraseSupport;
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

    /**
     * Quanto código cabe num turno de revisão. Teto de FIO: o `input_text` do
     * servidor não é lugar de carregar um dia inteiro de trabalho, e um agente
     * afogado em 15 mil linhas não revisa melhor — revisa pior. O que não cabe
     * é dito com número, nunca cortado em silêncio.
     */
    private const REVIEW_DIFF_BUDGET = 12_000;

    /** Um commit gigante não pode comer o turno inteiro sozinho. */
    private const REVIEW_DIFF_PER_COMMIT = 6_000;

    /**
     * Perguntar: a resposta É o produto. Pode mandar revisar (verbo do
     * operador) e pode consultar o cérebro quando não é filtro de git.
     */
    public const MODE_ANSWER = 'answer';

    /**
     * Coletar fato para o agente do card ler antes de responder. LEITURA PURA
     * do git: nunca despacha frota, nunca chama outro agente.
     *
     * A distinção não é preciosismo. No card, o coletor roda a CADA turno — se
     * ele mantivesse os verbos do modo `answer`, escrever "revise os commits de
     * hoje" dispararia a frota de revisão E mandaria a pergunta ao agente: o
     * mesmo trabalho duas vezes, um deles sem ninguém ter pedido. Coletar fato
     * é ler; quem age é o operador.
     */
    public const MODE_FACTS = 'facts';

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
    public function answer(
        string $repo,
        string $question,
        ?int $now = null,
        ?string $timezone = null,
        string $mode = self::MODE_ANSWER,
    ): array {
        $asked = trim($question);
        if ($asked === '') {
            throw new InvalidArgumentException('empty_question');
        }

        $located = ($this->locator ?? new AtlasCodeRepoLocator())->locate($repo);
        $route = ($this->router ?? new AtlasCodeQuestionRouter())->route($asked);
        $now ??= time();
        $collecting = $mode === self::MODE_FACTS;

        // Um ponto de captura para as 11 chamadas de git: quando o repositório
        // não responde, a resposta é "não consegui ler" — nunca um fato sobre
        // um git que ninguém leu. Coletando, `answered=false` faz o bloco de
        // fatos sumir, e o agente responde sem muleta em vez de citar um
        // repositório fantasma como se tivesse aberto.
        try {
            $result = $this->route($route, $located, $now, $timezone, $collecting, $asked);
        } catch (AtlasCodeGitUnavailable) {
            $result = $this->shape(
                false,
                'não consegui ler o git de '.$located['slug'].' agora.',
                source: self::SOURCE_GRAPH,
            );
        }

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

        // O que o AGENTE lê e o operador não precisa ver.
        //
        // Duas coisas, nesta ordem: quem são os commits, e o código deles.
        //
        // O hash pelado é inútil para quem responde: `7f3069943f0d5343…` não
        // diz o que o commit fez, e um agente que recebe doze deles ou cala ou
        // inventa. O grafo precisa do hash para acender a linha; o agente
        // precisa da MENSAGEM. Os dois são o mesmo commit visto por quem tem
        // olho diferente — e o servidor já lê as duas coisas do mesmo git.
        $detail = [];
        if ($collecting && $response['commits'] !== []) {
            $roll = $this->commitRoll($located['path'], $response['commits']);
            if ($roll !== '') {
                $detail[] = $roll;
            }
        }
        if (isset($result['detail']) && is_string($result['detail']) && $result['detail'] !== '') {
            $detail[] = $result['detail'];
        }
        if ($detail !== []) {
            $response['detail'] = implode("\n\n", $detail);
        }

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

    /**
     * @param  array<string,mixed>  $route
     * @param  array{slug:string, path:string}  $located
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     *
     * @throws AtlasCodeGitUnavailable
     */
    private function route(array $route, array $located, int $now, ?string $timezone, bool $collecting, string $asked): array
    {
        return match ($route['intent']) {
            AtlasCodeQuestionRouter::INTENT_PROBLEMS => $this->answerProblems($located['slug'], $now),
            AtlasCodeQuestionRouter::INTENT_CHANGES => $this->answerChanges($located['path'], (string) $route['window'], $now, $timezone),
            // Mandar revisar é verbo, e verbo não roda dentro de um coletor de
            // fato. Coletando, "revise os commits de hoje" vira o QUE mudou
            // hoje — e quem revisa é o agente do card, lendo esses commits.
            // Ele responde no mesmo turno; a frota respondia em lugar nenhum.
            AtlasCodeQuestionRouter::INTENT_REVIEW_BATCH => $collecting
                ? $this->collectReview($located['path'], (string) $route['window'], $now, $timezone)
                : $this->startReview($located['slug'], $located['path'], (string) $route['window'], $now, $timezone),
            // Pergunta que CITA um commit: o operador (ou a folha do commit,
            // que semeia esta pergunta) quer ESTE commit — identidade, corpo e,
            // coletando, o código. Antes disto ela caía em `unknown` e o agente
            // respondia sem os fatos do próprio commit que o operador olhava.
            AtlasCodeQuestionRouter::INTENT_COMMIT => $this->answerCommit(
                $located['path'],
                (array) ($route['hashes'] ?? []),
                $collecting,
                $now,
            ),
            // A voz do veto: o ciclo curou/desfez, lido do ledger.
            AtlasCodeQuestionRouter::INTENT_HEALS => $this->answerHeals(
                $located['slug'],
                (string) $route['window'],
                $now,
                $timezone,
            ),
            AtlasCodeQuestionRouter::INTENT_WHY_BRANCH => $this->answerWhyBranch($located['slug'], $located['path']),
            AtlasCodeQuestionRouter::INTENT_WHO_TOUCHED => $this->answerWhoTouched($located['path'], $route['term']),
            AtlasCodeQuestionRouter::INTENT_FIND => $this->answerFind($located['path'], $route['term']),
            AtlasCodeQuestionRouter::INTENT_HOTTEST => $this->answerHottest($located['path'], (string) $route['window'], $now, $timezone),
            // Não é filtro do grafo: é pergunta de julgamento. Perguntando, vai
            // ao cérebro. Coletando, o silêncio é a resposta certa: quem vai
            // julgar é o agente que já está lendo isto, e chamar um segundo
            // cérebro para ditar a resposta do primeiro é ruído, não fato.
            default => $collecting
                ? $this->shape(false, '', source: self::SOURCE_GRAPH)
                : $this->consultBrain($asked, $located['path']),
        };
    }

    /**
     * A pergunta que cita um commit: identidade, esforço e — coletando — o
     * corpo e o código, para o agente responder sobre O commit, não sobre a
     * ideia de um commit.
     *
     * A primeira leitura é `rev-parse HEAD` de propósito: se o git estiver
     * fora, isto LEVANTA e a resposta vira "não consegui ler o git". Sem essa
     * sonda, todo hash pareceria inexistente e a falha viraria fato — "não
     * achei o commit X" sobre um git que ninguém leu.
     *
     * @param  array<int,string>  $hashes
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string, detail?:string}
     *
     * @throws AtlasCodeGitUnavailable
     */
    private function answerCommit(string $path, array $hashes, bool $collecting, int $now): array
    {
        $this->git($path, ['git', 'rev-parse', 'HEAD']);

        $hashes = array_slice(array_values(array_unique($hashes)), 0, 3);
        $review = $this->review ?? new AtlasCodeReviewService();

        $lines = [];
        $anchors = [];
        $missing = [];
        $detail = [];

        foreach ($hashes as $cited) {
            try {
                $full = trim($this->git($path, ['git', 'rev-parse', '--verify', $cited.'^{commit}']));
            } catch (AtlasCodeGitUnavailable) {
                // O git está de pé (a sonda acima passou): ESTE hash não
                // existe aqui, e ausência verificada é fato.
                $missing[] = $cited;

                continue;
            }

            $identity = explode("\x1f", trim($this->git($path, [
                'git', 'show', '--no-patch', '--format=%H%x1f%an%x1f%at%x1f%s', $full,
            ])), 4);
            if (count($identity) !== 4) {
                $missing[] = $cited;

                continue;
            }
            [$fullHash, $author, $epoch, $subject] = $identity;

            $work = $this->parseWork($this->git($path, ['git', 'show', '--format=', '--numstat', '-M', $full]));
            $anchors[] = $fullHash;

            $files = $work['files'] === 1 ? '1 arquivo' : $work['files'].' arquivos';
            $lines[] = mb_substr($fullHash, 0, 10)
                ." · \u{201C}{$subject}\u{201D} — {$author}, há ".self::ago((int) $epoch, $now)
                .": {$files}, +{$work['additions']} \u{2212}{$work['deletions']}.";

            if ($collecting) {
                $body = trim($this->git($path, ['git', 'show', '--no-patch', '--format=%b', $full]));
                $diff = $review->diff($path, $full, self::REVIEW_DIFF_PER_COMMIT);
                $bloco = "--- commit {$fullHash} ---";
                if ($body !== '') {
                    $bloco .= "\nDescrição do autor:\n".$body;
                }
                if (trim($diff) !== '') {
                    $bloco .= "\n".$diff;
                }
                $detail[] = $bloco;
            }
        }

        foreach ($missing as $cited) {
            $lines[] = "não achei o commit {$cited} neste repositório.";
        }

        $result = $this->shape(
            $lines !== [],
            implode("\n", $lines),
            commits: $anchors,
            source: self::SOURCE_GRAPH,
        );
        if ($detail !== []) {
            $result['detail'] = "Código e descrição dos commits citados, lidos do git:\n\n".implode("\n\n", $detail);
        }

        return $result;
    }

    /**
     * A voz do veto — canon nº 1 do operador: o Atlas age sozinho e o humano
     * desfaz com recibo. "O que você curou?" e "o que eu vetei?" fecham esse
     * ciclo, e não tinham intent: o único jeito de saber era abrir a folha.
     *
     * Ledger vazio devolve zero curas, e zero LIDO é fato ("nenhuma cura"),
     * não falha. Ledger fora do ar é a outra coisa, e é dito.
     */
    private function answerHeals(string $slug, string $window, int $now, ?string $timezone): array
    {
        $since = $this->windowStart($window, $now, $timezone);

        try {
            $cycle = app(AtlasCodeHealService::class)->vetoCycle($slug, $since);
        } catch (Throwable) {
            return $this->shape(false, 'não consegui ler o ledger de curas agora.', source: self::SOURCE_LEDGER);
        }

        $label = match ($window) {
            AtlasCodeQuestionRouter::WINDOW_TODAY => 'hoje',
            AtlasCodeQuestionRouter::WINDOW_YESTERDAY => 'ontem',
            // "nos últimos 30 dias", não "no último mês" — a mesma janela é
            // dita do mesmo jeito em todas as respostas irmãs (mudanças,
            // revisão, arquivo mais mexido). Duas frases para o mesmo recorte
            // fazem o operador achar que são janelas diferentes.
            AtlasCodeQuestionRouter::WINDOW_MONTH => 'nos últimos 30 dias',
            default => 'nos últimos 7 dias',
        };

        $healed = $cycle['healed'];
        $undone = $cycle['undone'];

        if ($healed === [] && $undone === []) {
            return $this->shape(
                true,
                "nenhuma cura {$label} — o Atlas não precisou intervir neste repositório, e nada esperou seu veto.",
                source: self::SOURCE_LEDGER,
            );
        }

        $frases = [];
        if ($healed !== []) {
            $frases[] = (count($healed) === 1 ? '1 cura' : count($healed).' curas')." {$label}";
        }
        if ($undone !== []) {
            $frases[] = count($undone) === 1
                ? '1 desfeita por você — o veto funcionou'
                : count($undone).' desfeitas por você — o veto funcionou';
        }

        $evidence = [];
        foreach (array_slice([...$healed, ...$undone], 0, 6) as $receipt) {
            $evidence[] = array_filter([
                'kind' => 'heal',
                'ref' => (string) ($receipt['heal_id'] ?? $receipt['action'] ?? 'cura'),
                'target' => isset($receipt['target']) ? (string) $receipt['target'] : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $this->shape(true, implode('; ', $frases).'.', evidence: $evidence, source: self::SOURCE_LEDGER);
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
        return AtlasCodeAskPhraseSupport::phraseChanges($commits, $window, $work);
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
        return AtlasCodeAskPhraseSupport::parseWork($numstat);
    }

    /**
     * @param  array<int, array<string,mixed>>  $violations
     * @param  string  $trunk  O nome REAL da trunk deste repositório. Escrever
     *                         "fora da main" onde a trunk é `production` é
     *                         mentira — e o operador tem repositórios assim.
     */
    public function phraseProblems(array $violations, string $trunk = 'main', ?int $now = null): string
    {
        return AtlasCodeAskPhraseSupport::phraseProblems($violations, $trunk, $now);
    }

    /**
     * Há quanto tempo a exceção mais velha está aberta — em português curto.
     *
     * `null` quando NENHUMA violação traz data. Ausência de medida não vira
     * "há 0 dias": o operador já foi enganado uma vez por número sem sentido
     * na tela, e um "22" que ninguém sabe de onde veio é pior que silêncio.
     *
     * @param  array<int, array<string,mixed>>  $violations
     */
    public function oldestViolation(array $violations, ?int $now = null): ?string
    {
        return AtlasCodeAskPhraseSupport::oldestViolation($violations, $now);
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
        return AtlasCodeAskPhraseSupport::phraseRule($ruleId, $total, $trunk);
    }

    // MARK: — As respostas

    /**
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function answerProblems(string $slug, ?int $now = null): array
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
                // UM exemplo concreto por regra: "18 obras" é estatística;
                // "obra-17, entre elas" é uma coisa que ele pode ir olhar.
                'target' => isset($violation['target']) && trim((string) $violation['target']) !== ''
                    ? (string) $violation['target']
                    : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $this->shape(
            true,
            $this->phraseProblems($violations, (string) ($captured['trunk'] ?? 'main'), $now),
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
            // Beco sem saída de novo: "não há branch para explicar" enquanto o
            // próprio Atlas acusa 4 branches fora da main na resposta de
            // problemas. Ele não perguntou "explique a branch em que estou" —
            // perguntou POR QUE existem branches. Estar na trunk é a posição
            // normal dele; a pergunta é sobre as outras.
            // A DATA vem primeiro de propósito: `for-each-ref` não conhece
            // `%x1f` (só o `git log` conhece — medido: o formato sai literal e o
            // parse volta vazio), então o separador é `|`, como no resto da
            // casa. E nome de branch PODE conter `|`: com a data na frente, o
            // nome é o resto da linha e sobrevive inteiro.
            $others = $this->parseBranches($this->git($path, [
                'git', 'for-each-ref', '--format=%(committerdate:unix)|%(refname:short)', 'refs/heads',
            ]), $trunk);

            return $this->shape(
                true,
                $this->phraseOtherBranches($trunk, $others),
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
            // O catch estava VAZIO e o comentário dizia que ledger indisponível
            // não vira explicação inventada. Dizia. A execução caía no return
            // seguinte e o Atlas ACUSAVA a branch de ter nascido fora dele —
            // porque um banco estava fora do ar. Acusação falsa é pior que
            // silêncio: ela faz o operador agir, apagar trabalho legítimo, e
            // desconfiar da única ferramenta que deveria provar estado.
            //
            // "Não sei" é resposta. "Nasceu fora do Atlas" sem ter perguntado
            // ao ledger é calúnia com voz de autoridade.
            return $this->shape(
                false,
                "o ledger não respondeu agora — não sei dizer de onde a branch {$current} veio.",
                commits: $head !== '' ? [$head] : [],
                evidence: $evidence,
                source: self::SOURCE_LEDGER,
            );
        }

        // O ledger RESPONDEU e não tinha registro. Mas a frase depende de o
        // registro EXISTIR como sistema: medido em 16/07, o escritor de
        // proveniência nunca foi chamado — 0 eventos em 2.125 —, então TODA
        // branch recebia "nasceu fora do Atlas". Ausência num sistema que
        // nunca gravou nada não discrimina nada: acusar a branch com ela é
        // vestir um vazio de fato. A acusação fica reservada ao único caso em
        // que ela informa: o registro está VIVO (gravou outros commits) e esta
        // branch não está nele.
        if (! $this->provenanceRecordingIsLive()) {
            return $this->shape(
                true,
                "a origem da branch {$current} não está registrada — o Atlas ainda não grava proveniência de commit nenhum, então não sei dizer de onde ela veio.",
                commits: $head !== '' ? [$head] : [],
                evidence: $evidence,
                source: self::SOURCE_LEDGER,
            );
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
     * O registro de proveniência já gravou ALGUM commit? Se nunca gravou
     * nenhum, ausência de registro não diz nada sobre branch nenhuma.
     * Ledger fora do ar conta como "não vivo": errar para o lado de não acusar.
     */
    private function provenanceRecordingIsLive(): bool
    {
        try {
            return \App\Models\AtlasLedgerEvent::query()
                ->where('event_type', \App\Services\Ai\Kernel\Evidence\LedgerEventType::CodeProvenanceRecorded->value)
                ->exists();
        } catch (Throwable) {
            return false;
        }
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
            // Beco sem saída é o pior serviço: "nenhum commit tocou em pílula"
            // está tecnicamente certo (nenhum ARQUIVO se chama pílula) e é
            // inútil — há seis commits falando dela. O operador perguntou pelo
            // ASSUNTO; o caminho era só o palpite dele de onde procurar.
            //
            // Também cobre o abismo de idioma: ele pergunta "conversação" e o
            // arquivo se chama `Conversation`.
            $byMessage = $this->parseCommits($this->git($path, [
                'git', 'log', '--all', '-i', '-E',
                '--grep='.$this->accentTolerantPattern($term),
                '--format=%H%x1f%an%x1f%at%x1f%s',
            ]));

            if ($byMessage === []) {
                return $this->shape(true, "nenhum commit tocou em \u{201C}{$term}\u{201D}.", source: self::SOURCE_GRAPH);
            }

            return $this->shape(
                true,
                $this->phraseSubjectOnly($term, $byMessage),
                commits: array_column($byMessage, 'hash'),
                source: self::SOURCE_GRAPH,
            );
        }

        $work = $this->parseWork($this->git($path, [
            'git', 'log', '--all', '--format=', '--numstat', '-M', '--', ':(icase)*'.$term.'*',
        ]));

        return $this->shape(
            true,
            $this->phraseWhoTouched($term, $commits, $work),
            commits: array_column($commits, 'hash'),
            source: self::SOURCE_GRAPH,
        );
    }

    /**
     * "quem mexeu em X?" — e o que essa pergunta REALMENTE quer saber.
     *
     * A primeira versão respondia "Vitor Freire (9 commits)". No repositório
     * do próprio Vitor, isso informa que ele existe. É o mesmo enchimento que
     * a frase do dia tinha, com outra roupa.
     *
     * O que ele quer quando pergunta isso: esse arquivo é quente? mexeram
     * agora ou faz meses? cresceu ou encolheu? O nome só importa quando há
     * mais de uma mão — aí sim vira notícia.
     *
     * @param  array<int, array{hash:string, author_name:string, authored_at:int, message:string}>  $commits
     * @param  array{files:int, additions:int, deletions:int, top:array<int,array{path:string,touches:int}>}|null  $work
     */
    public function phraseWhoTouched(string $term, array $commits, ?array $work = null, ?int $now = null): string
    {
        return AtlasCodeAskPhraseSupport::phraseWhoTouched($term, $commits, $work, $now);
    }

    /**
     * Tempo em PROSA, porque isto vive dentro de uma frase.
     *
     * "há 22d" é vocabulário de gráfico — compacto, mono, feito para caber numa
     * linha apertada ao lado de um nó. Numa frase, "há 22 dias". A régua é a
     * mesma que já vale para a tela: máquina embaixo do vidro, português em
     * cima. E o singular é dito: "1 dia", nunca "1 dias".
     */
    /**
     * O termo do operador, tolerante a acento, na sintaxe do git.
     *
     * BUG REAL (15/07): "procura pilula" devolvia "nenhum commit fala de
     * pilula" — com SEIS commits sobre a pílula no repositório. O roteador
     * normaliza a pergunta sem acento (certo: o operador escreve dos dois
     * jeitos), e o `--grep` literal então nunca casa "pílula".
     *
     * Classe de caractere NÃO resolve: o regex do git é byte a byte e `í` são
     * dois bytes em UTF-8 — `p[ií]lula` casa ZERO, medido. Só alternação
     * funciona: `p(i|í)lula` casa os seis.
     *
     * Cada vogal vira o par sem-acento/com-acento. O resto é escapado: o termo
     * é do operador, e um `.` ou `*` que ele digitou é literal, não curinga.
     */
    /**
     * Nenhum arquivo com esse nome — mas o assunto existe.
     *
     * A frase separa as duas coisas que o operador precisa distinguir: "não
     * existe" e "existe com outro nome". Ele perguntou pelo ASSUNTO; o caminho
     * era só o palpite dele de onde procurar, e o Atlas não morre no palpite.
     *
     * @param  array<int, array{hash:string, author_name:string, authored_at:int, message:string}>  $commits
     */
    public function phraseSubjectOnly(string $term, array $commits, ?int $now = null): string
    {
        return AtlasCodeAskPhraseSupport::phraseSubjectOnly($term, $commits, $now);
    }

    /**
     * Onde o esforço se concentrou — em português, com o que sustenta.
     *
     * Não devolve só um nome: um arquivo campeão sem contexto é trivia. O que
     * responde é o pódio + quanto ele pesa no todo, porque isso diz se há um
     * gargalo ou se o trabalho está espalhado.
     *
     * @param  array{files:int, additions:int, deletions:int, top:array<int,array{path:string,touches:int}>}  $work
     */
    public function phraseHottest(array $work, string $window): string
    {
        return AtlasCodeAskPhraseSupport::phraseHottest($work, $window);
    }

    /**
     * As branches que existem fora da trunk, da mais velha para a mais nova.
     *
     * Puro sobre `for-each-ref`: o teste lê o que o operador lê.
     *
     * @return array<int, array{name:string, at:int}>
     */
    public function parseBranches(string $output, string $trunk): array
    {
        return AtlasCodeAskPhraseSupport::parseBranches($output, $trunk);
    }

    /**
     * "por que essa branch existe?" quando ele está NA trunk.
     *
     * A pergunta é sobre as OUTRAS: estar na trunk é a posição normal dele.
     * Responder "não há branch para explicar" enquanto o Atlas acusa 4
     * branches fora da main é o mesmo beco sem saída de antes, com outra roupa.
     *
     * @param  array<int, array{name:string, at:int}>  $others
     */
    public function phraseOtherBranches(string $trunk, array $others, ?int $now = null): string
    {
        return AtlasCodeAskPhraseSupport::phraseOtherBranches($trunk, $others, $now);
    }

    public function accentTolerantPattern(string $term): string
    {
        return AtlasCodeAskPhraseSupport::accentTolerantPattern($term);
    }

    public static function ago(int $epoch, int $now): string
    {
        return AtlasCodeAskPhraseSupport::ago($epoch, $now);
    }

    /**
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function answerFind(string $path, ?string $term): array
    {
        if ($term === null) {
            return $this->shape(false, 'procurar o quê? diga o assunto do commit.', source: self::SOURCE_GRAPH);
        }

        // `-E` + alternação: o operador digita "pilula" e o commit diz "pílula".
        $lines = $this->git($path, [
            'git', 'log', '--all', '-i', '-E',
            '--grep='.$this->accentTolerantPattern($term),
            '--format=%H%x1f%an%x1f%at%x1f%s',
        ]);
        $commits = $this->parseCommits($lines);

        if ($commits === []) {
            return $this->shape(true, "nenhum commit fala de \u{201C}{$term}\u{201D}.", source: self::SOURCE_GRAPH);
        }

        return $this->shape(
            true,
            $this->phraseFind($term, $commits),
            commits: array_column($commits, 'hash'),
            source: self::SOURCE_GRAPH,
        );
    }

    /**
     * "cadê o commit do X?" — e o QUANDO, que é metade da busca.
     *
     * Achar 7 commits sobre "sanitizer" sem dizer se o último é de hoje ou de
     * seis meses atrás deixa o operador com a metade da resposta. E se a
     * conversa toda aconteceu num intervalo, o intervalo é a história.
     *
     * @param  array<int, array{hash:string, author_name:string, authored_at:int, message:string}>  $commits
     */
    public function phraseFind(string $term, array $commits, ?int $now = null): string
    {
        return AtlasCodeAskPhraseSupport::phraseFind($term, $commits, $now);
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
        $total = count($commits);
        $noun = $count === 1 ? 'commit' : 'commits';

        // O teto da frota é dito com número: "12 em revisão" quando a janela
        // tem 889 lê como cobertura total, e cobertura fingida é pior que
        // cobertura pequena — o operador confia numa revisão que não houve.
        $frase = $total > $count
            ? "{$count} de {$total} {$noun} em revisão — os mais recentes primeiro; os outros ficaram de fora."
            : "{$count} {$noun} em revisão — um agente por commit, ao vivo no grafo.";

        return $this->shape(
            true,
            $frase,
            commits: $hashes,
            source: self::SOURCE_GRAPH,
        );
    }

    /**
     * "qual arquivo mais mexe?" — onde o esforço se concentra.
     *
     * O dado já existia (o `top` do trabalho da janela) e não tinha porta: a
     * pergunta caía no cérebro e voltava "não tenho fonte boa". Dado pronto sem
     * porta é pior que dado ausente — o Atlas sabia e não sabia que sabia.
     *
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    private function answerHottest(string $path, string $window, int $now, ?string $timezone): array
    {
        $since = $this->windowStart($window, $now, $timezone);
        $work = $this->parseWork($this->git($path, [
            'git', 'log', '--all', '--since=@'.$since, '--format=', '--numstat', '-M',
        ]));

        return $this->shape(
            true,
            $this->phraseHottest($work, $window),
            source: self::SOURCE_GRAPH,
        );
    }

    /**
     * Quem são os commits que a resposta cita — para o AGENTE, não para a tela.
     *
     * `7f3069943f0d5343…` não diz nada a ninguém. O grafo precisa do hash para
     * acender a linha certa; quem vai RESPONDER precisa da mensagem, do autor e
     * de quando. Sem isto o agente recebe doze hashes pelados e só tem dois
     * caminhos, os dois ruins: calar ou inventar o que eles fizeram.
     *
     * Uma chamada de git para os doze, não doze chamadas.
     *
     * @param  array<int,string>  $hashes
     *
     * @throws AtlasCodeGitUnavailable
     */
    private function commitRoll(string $path, array $hashes): string
    {
        if ($hashes === []) {
            return '';
        }

        $saida = $this->git($path, [
            'git', 'show', '--no-patch', '--format=%H%x1f%an%x1f%at%x1f%s', ...$hashes,
        ]);

        $linhas = [];
        foreach ($this->parseCommits($saida) as $commit) {
            $linhas[] = '- '.mb_substr($commit['hash'], 0, 10)
                .' · '.$commit['message']
                .' — '.$commit['author_name'];
        }

        if ($linhas === []) {
            return '';
        }

        return "Os commits que a resposta cita:\n".implode("\n", $linhas);
    }

    /**
     * Revisar em lote, coletando: os commits da janela MAIS o código deles.
     *
     * Sem o diff, "revise os commits de hoje" é promessa quebrada — o agente
     * recebia a contagem e os hashes, e revisar sem ver o código é opinar. Foi
     * literalmente o que aconteceu com a frota antiga: 12 agentes procuraram o
     * repositório, não acharam, e devolveram raciocínio no lugar do veredito.
     *
     * O teto é de FIO, não de verdade: cabe o que cabe, do mais recente para
     * trás, e o que não coube é DITO com número. Um agente que revisa 4 de 45
     * e diz isso é útil; um que revisa 4 de 45 calado está mentindo sobre a
     * cobertura, e o operador confia numa revisão que não houve.
     *
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string, detail:string}
     */
    private function collectReview(string $path, string $window, int $now, ?string $timezone): array
    {
        $base = $this->answerChanges($path, $window, $now, $timezone);
        if ($base['commits'] === []) {
            return $base + ['detail' => ''];
        }

        $review = $this->review ?? new AtlasCodeReviewService();
        $lidos = [];
        $orcamento = self::REVIEW_DIFF_BUDGET;

        foreach ($base['commits'] as $hash) {
            if ($orcamento <= 0) {
                break;
            }

            $diff = $review->diff($path, $hash, min($orcamento, self::REVIEW_DIFF_PER_COMMIT));
            if (trim($diff) === '') {
                continue;
            }

            $lidos[] = "--- commit {$hash} ---\n".$diff;
            $orcamento -= mb_strlen($diff);
        }

        if ($lidos === []) {
            return $base + ['detail' => ''];
        }

        $fora = count($base['commits']) - count($lidos);
        $detail = "Código dos commits, lido do git (do mais recente para trás):\n\n".implode("\n\n", $lidos);
        if ($fora > 0) {
            $detail .= "\n\n[".$fora.' commit(s) desta janela NÃO couberam neste turno e você não os viu. '
                .'Revise o que leu e diga que os outros ficaram de fora — nunca fale deles como se tivesse lido.]';
        }

        return $base + ['detail' => $detail];
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
        return AtlasCodeAskPhraseSupport::shape($answered, $answer, $commits, $evidence, $source);
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
        return AtlasCodeAskPhraseSupport::windowStart($window, $now, $timezone);
    }

    /** Fuso ilegível não derruba a pergunta — vira UTC, e o recorte é dito. */
    private function zone(?string $timezone): DateTimeZone
    {
        return AtlasCodeAskPhraseSupport::zone($timezone);
    }

    /**
     * @return array<int, array{hash:string, author_name:string, authored_at:int, message:string}>
     */
    public function parseCommits(string $output): array
    {
        return AtlasCodeAskPhraseSupport::parseCommits($output);
    }

    /** @param array<int,string> $command */
    /**
     * Roda git e devolve a saída — ou levanta, se o git não respondeu.
     *
     * Levantar não é rigor: é a única forma de "não consegui ler" chegar à
     * superfície. Devolvendo '' na falha (como era), timeout, permissão negada
     * e `.git` corrompido produziam a frase "não há commit hoje" — afirmativa,
     * sem ressalva, indistinguível de um dia sem trabalho. O operador
     * acreditaria, e a ferramenta que existe para provar estado teria mentido
     * calada.
     *
     * Saída VAZIA com sucesso continua sendo vazia de verdade: `git log` num
     * dia sem commit sai zero com nada, e isso é um fato, não uma falha.
     *
     * @throws AtlasCodeGitUnavailable
     */
    private function git(string $path, array $command): string
    {
        try {
            $process = new Process($command, $path, null, null, $this->timeoutSeconds);
            $process->run();
        } catch (Throwable $exception) {
            throw new AtlasCodeGitUnavailable($command[1] ?? 'git', 0, $exception);
        }

        if (! $process->isSuccessful()) {
            throw new AtlasCodeGitUnavailable($command[1] ?? 'git');
        }

        return $process->getOutput();
    }
}
