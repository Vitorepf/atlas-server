<?php

declare(strict_types=1);

namespace App\Services\Engineering;

/**
 * ACOS · a busca que faltava na Engineering KB.
 *
 * MEDIDO EM 15/07/2026, e este é o gargalo do ecossistema:
 *
 *   1052 docs de engenharia no repo
 *    972 indexados na KB (`atlas_engineering_knowledge_items`)
 *      0 no índice semântico que o Open Brain consulta (`semantic_notes`,
 *        169 notas — TODAS do vault `00-constituicao/`)
 *
 * O Atlas TEM o conhecimento e o cérebro NÃO PROCURA NELE. Pior: o
 * `EngineeringKnowledgeBaseService::contextRefs()` não compara texto nenhum —
 * ordena por categoria, prioridade e data. As palavras da pergunta são
 * IGNORADAS. Foi por isso que "por que existe a regra de trabalhar só na
 * main?" devolveu "Codex Review Chain Contract": era só o item de maior
 * prioridade, não uma resposta.
 *
 * Esta classe é o ranking puro — sem banco, sem rede, golden-testável. Ela
 * pontua um item contra os termos da pergunta:
 *
 *   - o slug/título é o nome da coisa: casar ali vale mais que casar no corpo;
 *   - termo raro vale mais que termo comum (`main` aparece em tudo; `main-only`
 *     não), então o peso cai conforme o termo é frequente na KB;
 *   - casar TODOS os termos vale mais que casar um só, muitas vezes.
 *
 * Sem TF-IDF de biblioteca, sem embedding, sem provider: é léxico, é local, é
 * instantâneo e é auditável. Quando o índice semântico cobrir a KB, isto vira
 * o piso barato e o vetor vira o teto — os dois somam.
 */
final class EngineeringKnowledgeSearch
{
    /**
     * O piso DESTA régua — e ela não é a mesma régua do índice semântico.
     *
     * Score léxico e score de similaridade vetorial não vivem na mesma escala:
     * usar um piso só para os dois é comparar coisas diferentes com a mesma
     * régua. Quem mede é dono do próprio piso; quem consome pergunta.
     *
     * Calibrado contra o caso real: "por que existe a regra de trabalhar só na
     * main?" pontua 0.44 no doc certo (`atlas-local-main-only-rule`) — cobertura
     * parcial, porque a pergunta traz palavras que o doc não usa. O doc É a
     * resposta; então o piso léxico é 0.35, e não o número foi ajustado para
     * caber num piso alheio.
     */
    public const RELEVANCE_FLOOR = 0.35;

    /** Termos que não distinguem nada em português técnico. */
    private const STOPWORDS = [
        'a', 'o', 'as', 'os', 'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas',
        'um', 'uma', 'que', 'por', 'para', 'com', 'sem', 'e', 'ou', 'se', 'ao', 'aos',
        'existe', 'existem', 'ser', 'esta', 'este', 'isso', 'como', 'qual', 'quais',
        'the', 'of', 'to', 'and', 'is', 'why', 'what',
    ];

    /**
     * Termos úteis da pergunta: sem acento, sem caixa, sem palavra vazia.
     *
     * @return array<int,string>
     */
    public function terms(string $question): array
    {
        $text = mb_strtolower(trim($question));
        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c',
        ]);
        $words = preg_split('/[^a-z0-9_-]+/', $text) ?: [];

        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $terms[$word] = true;
        }

        return array_keys($terms);
    }

    /**
     * Pontua um item contra os termos. 0 = não casa nada.
     *
     * @param  array{slug?:string,title?:string,summary?:?string,body_excerpt?:?string,canonical_path?:string,tags?:array<int,string>}  $item
     * @param  array<int,string>  $terms
     * @param  array<string,int>  $documentFrequency  Em quantos docs cada termo aparece.
     */
    public function score(array $item, array $terms, array $documentFrequency = [], int $corpusSize = 1): float
    {
        if ($terms === []) {
            return 0.0;
        }

        $name = mb_strtolower(($item['slug'] ?? '').' '.($item['title'] ?? '').' '.($item['canonical_path'] ?? ''));
        $body = mb_strtolower(($item['summary'] ?? '').' '.($item['body_excerpt'] ?? '').' '.implode(' ', $item['tags'] ?? []));

        $covered = 0.0;
        $available = 0.0;
        foreach ($terms as $term) {
            $frequency = $documentFrequency[$term] ?? 1;

            // Termo que não existe em documento NENHUM é a informação mais
            // valiosa da pergunta — e ninguém a cobre. Ele entra no
            // denominador com peso máximo e sai do numerador: é assim que
            // "receita de brigadeiro de colher" morre. (Ignorá-lo deixava só
            // "receita" decidir — que no Atlas casa com receita FINANCEIRA — e
            // a busca respondia brigadeiro com doc de faturamento.)
            $rarity = $frequency <= 0
                ? 1.0
                : log(max(2, $corpusSize) / $frequency) / log(max(2, $corpusSize));

            // Termo que está em TUDO não distingue nada: vale zero e sai da
            // conta inteira — não ajuda nem atrapalha. Sem isto, "atlas"
            // (presente em todo doc) devolvia o corpus inteiro com nota 1.000.
            if ($rarity <= 0.0) {
                continue;
            }
            $available += $rarity;

            $hit = 0.0;
            if ($this->mentions($name, $term)) {
                // O nome da coisa é a coisa: casar aqui é forte.
                $hit = 1.0;
            } elseif ($this->mentions($body, $term)) {
                // O nome é mais forte, mas o corpo não vale metade: um termo
                // raro no corpo ainda é o doc FALANDO da coisa.
                $hit = 0.6;
            }

            $covered += $hit * $rarity;
        }

        if ($covered <= 0.0 || $available <= 0.0) {
            return 0.0;
        }

        // Quanta INFORMAÇÃO da pergunta este doc cobre — não quantas palavras
        // ele casou.
        //
        // Duas versões erradas antes desta, ambas pegas contra a KB real:
        //
        //  1. Dividir pelo número de termos punia igual cada palavra não
        //     casada, e afundava o doc CERTO por causa de palavra de
        //     enchimento.
        //  2. Dividir por `available` fazia a raridade SE CANCELAR: com um
        //     termo só, qualquer acerto no nome dava 1.000 — e "atlas",
        //     presente em quase todo doc, devolvia o corpus inteiro com nota
        //     máxima.
        //
        // O piso de 1.0 no denominador é o que segura isso: para pontuar alto,
        // é preciso cobrir massa de informação de verdade. Um termo raro
        // sozinho (0.68) passa; um termo que está em tudo (0.03) não passa nem
        // casando no título.
        return round(min(1.0, $covered / max(1.0, $available)), 4);
    }

    /**
     * O termo aparece como PALAVRA, não como pedaço de outra.
     *
     * Sem isto, `main` casa dentro de `do-main` — e foi exatamente o que
     * aconteceu contra a KB real: "por que existe a regra de trabalhar só na
     * main?" devolveu `atlas-ai-core-vs-domain` em primeiro lugar. O teste
     * unitário, com 4 docs, não tinha nenhum "domain" e passou verde: a régua
     * de verdade é o corpus de verdade.
     *
     * Em slug (`atlas-local-main-only-rule`) o hífen é fronteira, então `main`
     * casa ali — que é o que se quer.
     */
    public function mentions(string $haystack, string $term): bool
    {
        return preg_match($this->pattern($term), $haystack) === 1;
    }

    /**
     * O padrão de UM termo — e aqui moram as duas armadilhas do português.
     *
     * Curto (≤6): palavra exata. `main` NÃO pode virar prefixo, senão casa
     * `maintain` — e o problema que estamos consertando é justamente casar
     * dentro de outra palavra.
     *
     * Longo (≥7): prefixo pelo radical. O operador pergunta "trabalhar" e o
     * canon escreve "trabalha"; "permitido" e "permitida"; "worktrees" e
     * "worktree". Sem radical, o doc CERTO perde por conjugação — foi o que
     * aconteceu contra a KB real. Cortar duas letras de palavra longa é
     * grosseiro e suficiente; stemmer de verdade é dependência que este
     * problema não paga.
     */
    private function pattern(string $term): string
    {
        $length = mb_strlen($term);
        if ($length <= 6) {
            return '/\b'.preg_quote($term, '/').'\b/u';
        }

        return '/\b'.preg_quote($this->stem($term), '/').'\w*/u';
    }

    /**
     * O mesmo recorte, na sintaxe do Postgres (`\y` = fronteira de palavra).
     *
     * O pré-filtro do banco e o scorer TÊM de recortar igual: um pré-filtro
     * mais estrito corta o doc certo antes de alguém pontuá-lo, e um mais
     * frouxo só desperdiça linha.
     */
    public function postgresPattern(string $term): string
    {
        $length = mb_strlen($term);

        return $length <= 6
            ? '\y'.preg_quote($term, '/').'\y'
            : '\y'.preg_quote($this->stem($term), '/');
    }

    private function stem(string $term): string
    {
        return mb_substr($term, 0, max(6, mb_strlen($term) - 2));
    }

    /**
     * @param  array<int, array<string,mixed>>  $items
     * @return array<string,int>
     */
    public function documentFrequency(array $items, array $terms): array
    {
        $frequency = [];
        foreach ($terms as $term) {
            $count = 0;
            foreach ($items as $item) {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($item['slug'] ?? ''),
                    (string) ($item['title'] ?? ''),
                    (string) ($item['canonical_path'] ?? ''),
                    (string) ($item['summary'] ?? ''),
                    (string) ($item['body_excerpt'] ?? ''),
                ]));
                if ($this->mentions($haystack, $term)) {
                    $count++;
                }
            }
            $frequency[$term] = $count;
        }

        return $frequency;
    }

    /**
     * Ranking completo, puro: itens + pergunta → os melhores, com score.
     *
     * @param  array<int, array<string,mixed>>  $items
     * @return array<int, array<string,mixed>>
     */
    /**
     * @param  array<int, array<string,mixed>>  $items
     * @param  array<string,int>|null  $documentFrequency  Frequência no CORPUS
     *        INTEIRO. Sem isto, mede-se a raridade dentro dos candidatos — e os
     *        candidatos são, por definição, os docs que contêm os termos. Foi
     *        assim que `main` virou "palavra comum" e foi descartada, e a
     *        pergunta sobre a regra da main devolveu três docs aleatórios
     *        empatados em 0.600.
     * @return array<int, array<string,mixed>>
     */
    public function rank(array $items, string $question, int $limit = 5, ?array $documentFrequency = null, ?int $corpusSize = null): array
    {
        $terms = $this->terms($question);
        if ($terms === []) {
            return [];
        }

        $frequency = $documentFrequency ?? $this->documentFrequency($items, $terms);
        $corpus = max(1, $corpusSize ?? count($items));

        $scored = [];
        foreach ($items as $item) {
            $score = $this->score($item, $terms, $frequency, $corpus);
            // O piso é desta régua: quem mede é dono do próprio piso.
            if ($score < self::RELEVANCE_FLOOR) {
                continue;
            }
            $scored[] = ['score' => $score] + $item;
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, $limit));
    }
}
