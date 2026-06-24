<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * PhoneticMistypeForge — a veia de ouro #1 revelada pela dissecação das vendas reais (Blackink): os MISTYPES
 * do nome coined convertem IGUAL ao spelling certo (orvelle/orville = 32-34%, mesmo que "orivelle fungus
 * pen" 33.58%) e venderam 473× — ~17% da conta — num leilão VIRGEM (concorrência ~0, CPC mínimo, porque
 * parece "lixo" e o anunciante oficial só bida a grafia certa).
 *
 * Por que é o sinal MAIS PURO de intenção: quem erra o nome OUVIU no áudio da VSL (mobile, nunca leu a tela)
 * e o reconstruiu DE OUVIDO — opera 100% de memória de DESEJO. O erro PROVA exposição ao pitch → Most-Aware.
 *
 * Gera o cone de erro determinístico de um nome coined: omissão, transposição, troca-de-vogal, colapso de
 * letra dupla e QWERTY-adjacency (1 e 2 edições). Provider-free, determinístico, dedup, sem o original.
 */
class PhoneticMistypeForge
{
    /** vizinhos de teclado QWERTY (erro de dedo) — só consoantes/letras adjacentes plausíveis. */
    private const QWERTY = [
        'q' => 'wa', 'w' => 'qase', 'e' => 'wsdr', 'r' => 'edft', 't' => 'rfgy', 'y' => 'tghu',
        'u' => 'yhji', 'i' => 'ujko', 'o' => 'iklp', 'p' => 'ol', 'a' => 'qwsz', 's' => 'awedxz',
        'd' => 'serfcx', 'f' => 'drtgvc', 'g' => 'ftyhbv', 'h' => 'gyujnb', 'j' => 'huikmn',
        'k' => 'jiolm', 'l' => 'kop', 'z' => 'asx', 'x' => 'zsdc', 'c' => 'xdfv', 'v' => 'cfgb',
        'b' => 'vghn', 'n' => 'bhjm', 'm' => 'njk',
    ];

    private const VOWELS = ['a', 'e', 'i', 'o', 'u'];

    /**
     * O cone de mistype de UM nome coined (token único). Retorna variantes plausíveis, sem o original.
     *
     * @return array<int,string>
     */
    public function cone(string $word, int $cap = 80): array
    {
        $w = mb_strtolower(trim($word));
        if (mb_strlen($w) < 4 || ! preg_match('/^[a-z]+$/', $w)) {
            return []; // só nomes coined alfabéticos (≥4) têm cone de erro útil
        }

        // ordem por QUALIDADE de mistype (mais plausível primeiro): omissão/colapso > transposição/vogal >
        // depth-2 (vogal-sobre-omissão: "orivelle"→"orvelle"→"orville") > QWERTY (escorregão de dedo).
        // dedup preservando a ordem — NUNCA alfabético (jogaria o lixo 'krivelle' na frente do 'orvelle').
        $ordered = array_merge(
            $this->omissions($w),
            $this->doubleCollapse($w),
            $this->transpositions($w),
            $this->vowelSwaps($w),
            $this->depth2($w),
            $this->qwerty($w),
        );

        $seen = [];
        $out = [];
        foreach ($ordered as $x) {
            if ($x === $w || mb_strlen($x) < 3 || isset($seen[$x])) {
                continue;
            }
            $seen[$x] = true;
            $out[] = $x;
        }

        return array_slice($out, 0, $cap);
    }

    /** depth-2: troca-de-vogal aplicada sobre cada omissão — o combo que reconstrói "orville" de "orivelle". */
    private function depth2(string $w): array
    {
        $out = [];
        foreach ($this->omissions($w) as $o) {
            foreach ($this->vowelSwaps($o) as $v) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * Cone × sufixos (categoria/forma): "orivelle" × ["fungus pen","nail pen"] → "orvelle fungus pen" etc.
     * Muta SÓ o nome coined (head), preserva o sufixo — espelha o dado real (orvelle nail pen / orville fungus pen).
     *
     * @param  array<int,string>  $suffixes
     * @return array<int,string>
     */
    public function forge(string $coinedName, array $suffixes = [], int $cap = 80): array
    {
        $cone = $this->cone($coinedName, $cap);
        if ($suffixes === []) {
            return $cone;
        }
        $out = [];
        foreach ($cone as $mis) {
            foreach ($suffixes as $sfx) {
                $sfx = mb_strtolower(trim((string) $sfx));
                $out[] = $sfx === '' ? $mis : $mis.' '.$sfx;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<int,string> */
    private function edits(string $w): array
    {
        return array_merge(
            $this->omissions($w),
            $this->transpositions($w),
            $this->vowelSwaps($w),
            $this->doubleCollapse($w),
            $this->qwerty($w),
        );
    }

    /** @return array<int,string> */
    private function omissions(string $w): array
    {
        $out = [];
        $n = mb_strlen($w);
        for ($i = 0; $i < $n; $i++) {
            $out[] = mb_substr($w, 0, $i).mb_substr($w, $i + 1);
        }

        return $out;
    }

    /** @return array<int,string> */
    private function transpositions(string $w): array
    {
        $out = [];
        $c = mb_str_split($w);
        for ($i = 0; $i < count($c) - 1; $i++) {
            $a = $c;
            [$a[$i], $a[$i + 1]] = [$a[$i + 1], $a[$i]];
            $out[] = implode('', $a);
        }

        return $out;
    }

    /** @return array<int,string> */
    private function vowelSwaps(string $w): array
    {
        $out = [];
        $c = mb_str_split($w);
        foreach ($c as $i => $ch) {
            if (in_array($ch, self::VOWELS, true)) {
                foreach (self::VOWELS as $v) {
                    if ($v !== $ch) {
                        $a = $c;
                        $a[$i] = $v;
                        $out[] = implode('', $a);
                    }
                }
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    private function doubleCollapse(string $w): array
    {
        $out = [];
        $c = mb_str_split($w);
        for ($i = 0; $i < count($c) - 1; $i++) {
            if ($c[$i] === $c[$i + 1]) {
                $out[] = mb_substr($w, 0, $i).mb_substr($w, $i + 1); // "ll" → "l"
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    private function qwerty(string $w): array
    {
        $out = [];
        $c = mb_str_split($w);
        foreach ($c as $i => $ch) {
            foreach (mb_str_split(self::QWERTY[$ch] ?? '') as $nb) {
                $a = $c;
                $a[$i] = $nb;
                $out[] = implode('', $a);
            }
        }

        return $out;
    }
}
