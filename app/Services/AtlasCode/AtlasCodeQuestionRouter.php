<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

/**
 * Atlas Código · H6 · a pergunta em linguagem humana vira intenção.
 *
 * Por que um roteador determinístico ANTES do cérebro, e não um prompt:
 *
 *   - As perguntas que o operador realmente faz sobre o próprio grafo são
 *     poucas e repetidas ("o que mudou hoje?", "tem algum problema?", "quem
 *     mexeu nisso?"). Todas têm resposta EXATA na topologia — filtro, não
 *     opinião. Passar isso por um modelo troca um fato por uma paráfrase.
 *   - É instantâneo. A lei do zero-espera não sobrevive a 3 segundos de
 *     provider para responder "o que mudou hoje".
 *   - É soberano: funciona sem rede, sem provider, sem custo. Provider é
 *     motor; o Atlas não pode precisar dele para ler o próprio git.
 *   - Não alucina. Um hash citado aqui foi filtrado, não lembrado.
 *
 * O cérebro (ACOS) não é o plano B disso: é a cauda longa — a pergunta aberta
 * que exige cruzar git + ledger + canon + decisão. Aqui só mora o roteamento.
 *
 * Função pura: sem git, sem rede, sem banco. Testável por golden.
 */
final class AtlasCodeQuestionRouter
{
    public const INTENT_PROBLEMS = 'problems';

    public const INTENT_CHANGES = 'changes';

    public const INTENT_WHY_BRANCH = 'why_branch';

    public const INTENT_WHO_TOUCHED = 'who_touched';

    public const INTENT_FIND = 'find';

    /** "revise os commits de hoje" — trabalho agêntico, não pergunta. */
    public const INTENT_REVIEW_BATCH = 'review_batch';

    /** Nem toda pergunta é filtro. Esta vai para o cérebro. */
    public const INTENT_UNKNOWN = 'unknown';

    public const WINDOW_TODAY = 'today';

    public const WINDOW_YESTERDAY = 'yesterday';

    public const WINDOW_WEEK = 'week';

    public const WINDOW_MONTH = 'month';

    /**
     * @return array{intent:string, window:?string, term:?string}
     */
    public function route(string $question): array
    {
        $text = $this->normalize($question);

        if ($text === '') {
            return $this->shape(self::INTENT_UNKNOWN);
        }

        // A ordem é do mais específico ao mais genérico: "por que essa branch
        // existe" contém "essa branch", mas não é uma busca por branch.
        if ($this->asksWhy($text) && $this->mentionsBranch($text)) {
            return $this->shape(self::INTENT_WHY_BRANCH);
        }

        // Antes de `changes`: "revise o que mudou hoje" é ORDEM, não pergunta.
        if ($this->ordersReview($text)) {
            $hashes = $this->extractHashes($text);

            // Alvo explícito vence janela: quem cita hash não quer "hoje".
            return $hashes !== []
                ? $this->shape(self::INTENT_REVIEW_BATCH, hashes: $hashes)
                : $this->shape(self::INTENT_REVIEW_BATCH, window: $this->extractWindow($text) ?? self::WINDOW_TODAY, hashes: []);
        }

        if ($this->mentionsProblem($text)) {
            return $this->shape(self::INTENT_PROBLEMS);
        }

        if (($term = $this->extractWhoTouched($text)) !== false) {
            return $this->shape(self::INTENT_WHO_TOUCHED, term: $term);
        }

        // As intenções de JANELA (`changes`, `review_batch`) compartilham o
        // formato do alvo: de quando, e quais. As de TERMO (`who_touched`,
        // `find`) não têm hash e por isso não carregam a chave.
        if ($this->mentionsChange($text)) {
            return $this->shape(self::INTENT_CHANGES, window: $this->extractWindow($text) ?? self::WINDOW_TODAY, hashes: []);
        }

        if (($term = $this->extractFind($text)) !== false) {
            return $this->shape(self::INTENT_FIND, term: $term);
        }

        return $this->shape(self::INTENT_UNKNOWN);
    }

    /**
     * O alvo de uma intenção é `term` (quem/o quê) OU `window`+`hashes` (de
     * quando / quais). `hashes` só aparece onde faz sentido: "quem mexeu no
     * worker?" não tem hash, e uma chave vazia ali seria ruído no contrato.
     *
     * @param  array<int,string>|null  $hashes
     * @return array{intent:string, window:?string, term:?string, hashes?:array<int,string>}
     */
    private function shape(string $intent, ?string $window = null, ?string $term = null, ?array $hashes = null): array
    {
        $shape = ['intent' => $intent, 'window' => $window, 'term' => $term];
        if ($hashes !== null) {
            $shape['hashes'] = $hashes;
        }

        return $shape;
    }

    /**
     * Hashes citados na frase: "revise f76c8be e 9bb7645".
     *
     * A armadilha: português e inglês têm palavras inteiras feitas de letras
     * hex — `decade`, `deadbeef`, `facade`, `beadface`. Um `[0-9a-f]{6,}`
     * ingênuo transformaria prosa em alvo e mandaria agentes revisarem o nada,
     * queimando motor. Duas exigências matam isso: 7+ caracteres (o abbrev
     * padrão do git) E pelo menos um DÍGITO — palavra de gente quase nunca tem.
     *
     * @return array<int,string>
     */
    private function extractHashes(string $text): array
    {
        preg_match_all('/\b(?=[0-9a-f]*\d)[0-9a-f]{7,40}\b/u', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * Caixa baixa, sem acento, sem pontuação de borda. O operador escreve
     * "Por quê?" e "por que" — é a mesma pergunta.
     */
    public function normalize(string $question): string
    {
        $text = mb_strtolower(trim($question));
        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e',
            'í' => 'i', 'î' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        $text = (string) preg_replace('/[?!,;]+/', ' ', $text);
        // O ponto só é pontuação no fim de uma palavra. Dentro dela é o nome:
        // 'AtlasCodeView.swift' não pode virar 'atlascodeview swift'.
        $text = (string) preg_replace('/\.(?=\s|$)/', ' ', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private function asksWhy(string $text): bool
    {
        foreach (['por que', 'porque', 'porque ', 'pq ', 'por quê'] as $needle) {
            if (str_contains($text, trim($needle))) {
                return true;
            }
        }

        return false;
    }

    private function mentionsBranch(string $text): bool
    {
        foreach (['branch', 'ramo', 'obra', 'worktree'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Manda revisar — imperativo do operador, não curiosidade.
     *
     * "revisa" e "revise" cobrem como ele fala de verdade; "revisão" cobre o
     * substantivo ("faz uma revisão dos commits"). Fica fora de propósito quem
     * só MENCIONA revisão ("o que a revisão achou?"), que é pergunta sobre
     * resultado — e essa é a natureza de perguntar, não a de mandar fazer.
     */
    private function ordersReview(string $text): bool
    {
        // Fronteira de palavra é obrigatória: `revisao` CONTÉM `revisa`, e sem
        // \b a pergunta "o que a revisão achou?" disparava seis agentes — o
        // Atlas obedecendo uma ordem que ninguém deu, queimando motor.
        if (preg_match('/\brevis(e|a|ar|em)\b/u', $text) === 1) {
            return true;
        }
        if (preg_match('/\banalis(a|e)\b\s+(os\s+)?commits?\b/u', $text) === 1) {
            return true;
        }

        // O substantivo só vale como ordem quando alguém MANDA fazer uma.
        return preg_match('/\b(faz|faca|fazer|manda|quero)\b.{0,12}\brevisao\b/u', $text) === 1;
    }

    private function mentionsProblem(string $text): bool
    {
        foreach ([
            'problema', 'errado', 'excecao', 'violacao', 'violando', 'fora da main',
            'tudo bem', 'tudo certo', 'quebrad', 'sujo', 'pendencia', 'esta ok', 'ta ok',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function mentionsChange(string $text): bool
    {
        foreach ([
            'que mudou', 'que aconteceu', 'que rolou', 'que foi feito', 'novidade',
            'que teve', 'mudancas', 'mudanca', 'o que houve', 'andamento',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function extractWindow(string $text): ?string
    {
        if (str_contains($text, 'ontem')) {
            return self::WINDOW_YESTERDAY;
        }
        if (str_contains($text, 'hoje')) {
            return self::WINDOW_TODAY;
        }
        if (str_contains($text, 'semana') || str_contains($text, '7 dias')) {
            return self::WINDOW_WEEK;
        }
        if (str_contains($text, 'mes') || str_contains($text, '30 dias')) {
            return self::WINDOW_MONTH;
        }

        return null;
    }

    /**
     * @return string|false|null  false = não é esta intenção; null = é, mas sem alvo.
     */
    private function extractWhoTouched(string $text): string|false|null
    {
        if (! str_starts_with($text, 'quem ')) {
            return false;
        }

        foreach (['mexeu', 'tocou', 'alterou', 'mudou', 'escreveu', 'fez', 'commitou', 'criou'] as $verb) {
            $position = strpos($text, $verb.' ');
            if ($position === false) {
                continue;
            }

            return $this->cleanTerm(substr($text, $position + strlen($verb)));
        }

        return false;
    }

    /**
     * @return string|false|null  false = não é esta intenção; null = é, mas sem alvo.
     */
    private function extractFind(string $text): string|false|null
    {
        foreach (['cade', 'onde esta', 'onde ta', 'procura', 'procure', 'busca', 'busque', 'acha', 'ache', 'encontra', 'encontre', 'me mostra', 'mostra'] as $trigger) {
            $position = strpos($text, $trigger);
            if ($position !== 0 && ! ($position !== false && $position > 0 && $text[$position - 1] === ' ')) {
                continue;
            }

            return $this->cleanTerm(substr($text, $position + strlen($trigger)));
        }

        return false;
    }

    /**
     * O alvo é o que sobra depois das palavras de ligação da FRENTE. Só da
     * frente: em "a folha do commit", 'do commit' é o alvo, não lixo — cortar
     * pelo fim devolveria 'folha' e procuraria a coisa errada.
     *
     * Se sobra só pronome ("quem mexeu nisso?"), o alvo é null — e quem
     * responde pede o alvo em vez de sair procurando pela palavra "isso".
     */
    private function cleanTerm(string $tail): ?string
    {
        $words = array_values(array_filter(explode(' ', trim($tail)), static fn (string $w): bool => $w !== ''));

        $leading = [
            'no', 'na', 'nos', 'nas', 'em', 'o', 'a', 'os', 'as', 'do', 'da', 'dos', 'das',
            'de', 'esse', 'essa', 'este', 'esta', 'commit', 'commits', 'arquivo', 'um', 'uma',
            'pra', 'para', 'com', 'que', 'ultimo', 'ultima',
        ];
        while ($words !== [] && in_array($words[0], $leading, true)) {
            array_shift($words);
        }

        // Dêixis não é alvo: aponta para a tela, não para o repositório.
        $deixis = ['isso', 'nisso', 'nesse', 'nessa', 'neste', 'nesta', 'nele', 'nela', 'aqui', 'ai', 'la', 'ali'];
        $words = array_values(array_filter($words, static fn (string $w): bool => ! in_array($w, $deixis, true)));

        $term = trim(implode(' ', $words));

        return $term === '' ? null : $term;
    }
}
