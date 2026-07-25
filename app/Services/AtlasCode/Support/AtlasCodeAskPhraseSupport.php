<?php

declare(strict_types=1);

namespace App\Services\AtlasCode\Support;

use App\Services\AtlasCode\AtlasCodeQuestionRouter;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Pure phrase / parse / window helpers peeled from
 * {@see \App\Services\AtlasCode\AtlasCodeAskService}.
 *
 * No Process, git, DB, ledger, DI, or provider I/O — only deterministic
 * Portuguese phrasing and git-output parsing given already-loaded strings/arrays.
 * The host keeps answer orchestration, Process git, violations, brain, review.
 */
final class AtlasCodeAskPhraseSupport
{
    private function __construct()
    {
    }

    /**
     * @param  array<int, array{hash:string, author_name:string, authored_at:int, message:string}>  $commits
     * @param  array{files:int, additions:int, deletions:int, top:array<int,array{path:string,touches:int}>}|null  $work
     */
    public static function phraseChanges(array $commits, string $window, ?array $work = null): string
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
    public static function parseWork(string $numstat): array
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
    public static function phraseProblems(array $violations, string $trunk = 'main', ?int $now = null): string
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
            $parts[] = self::phraseRule($rule, $total, $trunk);
        }

        $count = count($violations);
        $noun = $count === 1 ? 'exceção' : 'exceções';
        $phrase = "{$count} {$noun}: ".implode(', ', $parts).'.';

        // A IDADE é o que muda o que ele faz. "23 exceções" é um número; "a
        // mais antiga há 22 dias" é urgência. O Atlas já sabia — o `since` vem
        // em cada violação e era jogado fora.
        $oldest = self::oldestViolation($violations, $now);
        if ($oldest !== null) {
            $phrase .= ' A mais antiga há '.$oldest.'.';
        }

        return $phrase;
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
    public static function oldestViolation(array $violations, ?int $now = null): ?string
    {
        $now ??= time();
        $oldest = null;

        foreach ($violations as $violation) {
            $since = $violation['since'] ?? null;
            if (! is_string($since) || trim($since) === '') {
                continue;
            }
            $epoch = strtotime($since);
            if ($epoch === false || $epoch > $now) {
                continue;
            }
            $oldest = $oldest === null ? $epoch : min($oldest, $epoch);
        }

        return $oldest === null ? null : self::ago($oldest, $now);
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
    public static function phraseRule(string $ruleId, int $total, string $trunk = 'main'): string
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

    /**
     * "quem mexeu em X?" — e o que essa pergunta REALMENTE quer saber.
     *
     * @param  array<int, array{hash:string, author_name:string, authored_at:int, message:string}>  $commits
     * @param  array{files:int, additions:int, deletions:int, top:array<int,array{path:string,touches:int}>}|null  $work
     */
    public static function phraseWhoTouched(string $term, array $commits, ?array $work = null, ?int $now = null): string
    {
        $count = count($commits);
        $noun = $count === 1 ? 'commit tocou' : 'commits tocaram';
        $phrase = "{$count} {$noun} \u{201C}{$term}\u{201D}";

        $hands = [];
        foreach ($commits as $commit) {
            $name = trim($commit['author_name']) !== '' ? $commit['author_name'] : 'sem autor';
            $hands[$name] = ($hands[$name] ?? 0) + 1;
        }
        // Mão única não é notícia: ele já sabe que foi ele.
        if (count($hands) > 1) {
            arsort($hands);
            $parts = [];
            foreach (array_slice($hands, 0, 3, true) as $name => $total) {
                $parts[] = "{$total} de {$name}";
            }
            $phrase .= ' — '.implode(', ', $parts).(count($hands) > 3 ? ', entre outros' : '');
        }

        if ($work !== null && ($work['additions'] + $work['deletions']) > 0) {
            $phrase .= ": +{$work['additions']} \u{2212}{$work['deletions']}";
        }

        // Quente ou frio: o último toque é metade da pergunta.
        $newest = max(array_column($commits, 'authored_at'));
        $phrase .= '. O último há '.self::ago($newest, $now ?? time());

        return $phrase.'.';
    }

    /**
     * Nenhum arquivo com esse nome — mas o assunto existe.
     *
     * @param  array<int, array{hash:string, author_name:string, authored_at:int, message:string}>  $commits
     */
    public static function phraseSubjectOnly(string $term, array $commits, ?int $now = null): string
    {
        $count = count($commits);
        $noun = $count === 1 ? 'commit fala' : 'commits falam';
        $last = self::ago(max(array_column($commits, 'authored_at')), $now ?? time());

        return "nenhum arquivo com \u{201C}{$term}\u{201D} no nome — mas {$count} {$noun} disso. O último há {$last}.";
    }

    /**
     * Onde o esforço se concentrou — em português, com o que sustenta.
     *
     * @param  array{files:int, additions:int, deletions:int, top:array<int,array{path:string,touches:int}>}  $work
     */
    public static function phraseHottest(array $work, string $window): string
    {
        $when = match ($window) {
            AtlasCodeQuestionRouter::WINDOW_TODAY => 'hoje',
            AtlasCodeQuestionRouter::WINDOW_YESTERDAY => 'ontem',
            AtlasCodeQuestionRouter::WINDOW_WEEK => 'nos últimos 7 dias',
            default => 'nos últimos 30 dias',
        };

        $top = $work['top'] ?? [];
        if ($top === []) {
            return "nenhum arquivo foi tocado {$when}.";
        }

        // Um toque não é concentração: é só o único arquivo que existe ali.
        if (($top[0]['touches'] ?? 0) < 2) {
            return "{$when}, nenhum arquivo foi mexido mais de uma vez — o trabalho não se concentrou.";
        }

        $parts = [];
        foreach (array_slice($top, 0, 3) as $item) {
            if (($item['touches'] ?? 0) < 2) {
                continue;
            }
            $parts[] = basename((string) $item['path']).' ('.$item['touches'].'×)';
        }

        return "{$when}, o esforço bateu em: ".implode(', ', $parts)
            .'. De '.$work['files'].' arquivos tocados no total.';
    }

    /**
     * As branches que existem fora da trunk, da mais velha para a mais nova.
     *
     * Puro sobre `for-each-ref`: o teste lê o que o operador lê.
     *
     * @return array<int, array{name:string, at:int}>
     */
    public static function parseBranches(string $output, string $trunk): array
    {
        $branches = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            // Data primeiro, nome depois: o nome é o RESTO, e nome de branch
            // pode conter `|`.
            $parts = explode('|', trim($line), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $at = filter_var(trim($parts[0]), FILTER_VALIDATE_INT);
            $name = trim($parts[1]);
            if ($at === false || $name === '' || $name === $trunk) {
                continue;
            }
            $branches[] = ['name' => $name, 'at' => (int) $at];
        }

        usort($branches, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $branches;
    }

    /**
     * "por que essa branch existe?" quando ele está NA trunk.
     *
     * @param  array<int, array{name:string, at:int}>  $others
     */
    public static function phraseOtherBranches(string $trunk, array $others, ?int $now = null): string
    {
        if ($others === []) {
            // Aí sim: só a trunk existe. O silêncio é a verdade.
            return "você está na {$trunk}, e não existe outra branch neste repositório.";
        }

        $now ??= time();
        $count = count($others);
        $noun = $count === 1 ? 'branch' : 'branches';
        $oldest = $others[0];

        $phrase = "você está na {$trunk}. Fora dela existem {$count} {$noun}";

        // A mais velha é a que importa: ela é a que não voltou.
        $phrase .= ' — a mais velha é '.$oldest['name'].', há '.self::ago($oldest['at'], $now);

        // Quem nasceu agora não é dívida: é trabalho acontecendo.
        $newest = $others[$count - 1];
        if ($count > 1 && ($now - $newest['at']) < 86_400) {
            $phrase .= '; a mais nova, '.$newest['name'].', nasceu há '.self::ago($newest['at'], $now);
        }

        return $phrase.'.';
    }

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
     */
    public static function accentTolerantPattern(string $term): string
    {
        $variants = [
            'a' => 'a|á|à|ã|â',
            'e' => 'e|é|ê',
            'i' => 'i|í',
            'o' => 'o|ó|õ|ô',
            'u' => 'u|ú|ü',
            'c' => 'c|ç',
        ];

        $pattern = '';
        foreach (preg_split('//u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $lower = mb_strtolower($char);
            $pattern .= isset($variants[$lower])
                ? '('.$variants[$lower].')'
                : preg_quote($char, '/');
        }

        return $pattern;
    }

    /**
     * Tempo em PROSA, porque isto vive dentro de uma frase.
     *
     * "há 22d" é vocabulário de gráfico — compacto, mono, feito para caber numa
     * linha apertada ao lado de um nó. Numa frase, "há 22 dias". A régua é a
     * mesma que já vale para a tela: máquina embaixo do vidro, português em
     * cima. E o singular é dito: "1 dia", nunca "1 dias".
     */
    public static function ago(int $epoch, int $now): string
    {
        $seconds = max(0, $now - $epoch);

        [$value, $unit] = match (true) {
            $seconds < 3600 => [max(1, intdiv($seconds, 60)), 'minuto'],
            $seconds < 86_400 => [intdiv($seconds, 3600), 'hora'],
            $seconds < 2_592_000 => [intdiv($seconds, 86_400), 'dia'],
            default => [intdiv($seconds, 2_592_000), 'mês'],
        };

        if ($value === 1) {
            return '1 '.$unit;
        }

        return $value.' '.($unit === 'mês' ? 'meses' : $unit.'s');
    }

    /**
     * "cadê o commit do X?" — e o QUANDO, que é metade da busca.
     *
     * @param  array<int, array{hash:string, author_name:string, authored_at:int, message:string}>  $commits
     */
    public static function phraseFind(string $term, array $commits, ?int $now = null): string
    {
        $now ??= time();
        $count = count($commits);
        $noun = $count === 1 ? 'commit fala' : 'commits falam';
        $newest = $commits[0];

        $phrase = "{$count} {$noun} de \u{201C}{$term}\u{201D}";

        // Um só: a mensagem dele É a resposta, sem cerimônia de "o mais
        // recente" — não há disputa.
        if ($count === 1) {
            return $phrase.', há '.self::ago($newest['authored_at'], $now)
                .": \u{201C}{$newest['message']}\u{201D}";
        }

        $oldest = min(array_column($commits, 'authored_at'));
        $newestAt = self::ago($newest['authored_at'], $now);
        $oldestAt = self::ago($oldest, $now);

        // O intervalo só é notícia quando as pontas diferem: "entre 3h e 3h"
        // seria ruído.
        $phrase .= $newestAt === $oldestAt
            ? ", há {$newestAt}"
            : ", entre {$oldestAt} e {$newestAt} atrás";

        return $phrase.". O mais recente: \u{201C}{$newest['message']}\u{201D}";
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
    public static function windowStart(string $window, int $now, ?string $timezone = null): int
    {
        $moment = (new DateTimeImmutable('@'.$now))->setTimezone(self::zone($timezone));

        return match ($window) {
            AtlasCodeQuestionRouter::WINDOW_YESTERDAY => $moment->modify('-1 day')->setTime(0, 0)->getTimestamp(),
            AtlasCodeQuestionRouter::WINDOW_WEEK => $now - 7 * 86_400,
            AtlasCodeQuestionRouter::WINDOW_MONTH => $now - 30 * 86_400,
            default => $moment->setTime(0, 0)->getTimestamp(),
        };
    }

    /** Fuso ilegível não derruba a pergunta — vira UTC, e o recorte é dito. */
    public static function zone(?string $timezone): DateTimeZone
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
    public static function parseCommits(string $output): array
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

    /**
     * Envelope estável da resposta da pílula (sem I/O).
     *
     * @param  array<int,string>  $commits
     * @param  array<int,array<string,string>>  $evidence
     * @return array{answered:bool, answer:string, commits:array<int,string>, evidence:array<int,array<string,string>>, source:string}
     */
    public static function shape(
        bool $answered,
        string $answer,
        array $commits = [],
        array $evidence = [],
        string $source = 'graph',
    ): array {
        return compact('answered', 'answer', 'commits', 'evidence', 'source');
    }
}
