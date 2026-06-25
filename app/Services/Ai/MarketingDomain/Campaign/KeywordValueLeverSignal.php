<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordValueLeverSignal — operacionaliza a lei `hormozi-value-equation`: a keyword revela QUAL alavanca da
 * Equação de Valor o buscador mais quer, e o OS diz com qual o anúncio+página deve LIDERAR (message-match no
 * DESEJO, não só na palavra). Valor = (Sonho × Probabilidade) / (Tempo × Esforço).
 *
 *   "fast/overnight/quick/em dias"     → TIME (quer resultado rápido)
 *   "easy/at home/no exercise/sem dieta" → EFFORT (quer sem sacrifício)
 *   "proven/clinical/doctor/works/fda" → LIKELIHOOD (quer certeza)
 *   "lose 30 lbs/cure/reverse/permanent" → DREAM (quer o resultado grande)
 *
 * Provider-free, determinístico. Saída = alavancas detectadas + a dominante + diretriz de copy.
 */
class KeywordValueLeverSignal
{
    private const LEXICON = [
        'time' => ['fast', 'quick', 'quickly', 'overnight', 'instant', 'instantly', 'rapid', 'in days', 'in a week', 'immediate', 'rapido', 'em dias'],
        'effort' => ['easy', 'easiest', 'simple', 'at home', 'no exercise', 'no diet', 'without', 'no gym', 'lazy', 'effortless', 'sem dieta', 'sem academia', 'em casa'],
        'likelihood' => ['proven', 'clinically', 'clinical', 'doctor', 'guaranteed', 'works', 'that works', 'fda', 'science', 'studies', 'real', 'legit'],
        'dream' => ['lose 30', 'lose 20', 'lose 40', 'cure', 'reverse', 'permanent', 'permanently', 'forever', 'completely', 'transform', '10 years younger'],
    ];

    private const DIRECTIVE = [
        'time' => 'LIDERE com VELOCIDADE: prometa o resultado no menor tempo (ex.: "em X dias"); o buscador quer rápido',
        'effort' => 'LIDERE com FACILIDADE: zero esforço/sacrifício ("em casa, sem dieta/academia"); o buscador quer sem dor',
        'likelihood' => 'LIDERE com PROVA: autoridade/clínico/garantia/depoimentos; o buscador quer CERTEZA de que funciona',
        'dream' => 'LIDERE com o RESULTADO GRANDE: pinte o sonho específico e total; o buscador quer a transformação máxima',
        'none' => 'sem alavanca explícita — use o mecanismo coined + a maior alavanca do nicho',
    ];

    /**
     * @return array{levers:array<int,string>,dominant:string,copy_directive:string}
     */
    public function assess(string $keyword): array
    {
        $k = ' '.mb_strtolower(trim(preg_replace('/\s+/', ' ', $keyword))).' ';
        $hits = [];
        foreach (self::LEXICON as $lever => $words) {
            foreach ($words as $w) {
                if (str_contains($k, $w)) {
                    $hits[$lever] = ($hits[$lever] ?? 0) + 1;
                }
            }
        }

        if ($hits === []) {
            return ['levers' => [], 'dominant' => 'none', 'copy_directive' => self::DIRECTIVE['none']];
        }

        arsort($hits);
        $dominant = (string) array_key_first($hits);

        return [
            'levers' => array_keys($hits),
            'dominant' => $dominant,
            'copy_directive' => self::DIRECTIVE[$dominant],
        ];
    }
}
