<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordRegimeClassifier — a regra #6 e a ALAVANCA #1 DE ESCALA da dissecação: a conta tem DOIS negócios
 * economicamente opostos que NÃO podem dividir budget/tCPA.
 *
 *  • HARVEST (Regime A) — coined/mistype/celebridade+posse: ~3 cliques/venda, 30%+ CVR. COLHEITA pura.
 *  • SEED (Regime B)    — coined + sufixo de informação (recipe/trick): recall/prospecting, ~2% CVR.
 *  • PROBE (Regime C)   — sintoma/treatment frio, sem raiz coined: <1% CVR, sensor de demanda, não venda.
 *
 * O abismo de 50× em cliques-por-venda faz o Smart Bidding concluir que o tCPA é inatingível e ESTRANGULAR
 * os winners se A e B compartilharem campanha. Logo: 3 campanhas com budget/tCPA ISOLADOS + cross-negativas.
 * Este classificador decide DETERMINISTICAMENTE em qual urna isolada cada keyword entra. Provider-free.
 */
class KeywordRegimeClassifier
{
    /** sufixos de sintoma frio (sem coined → sensor, não venda). */
    private const SYMPTOM_SUFFIX = ['treatment', 'remedy', 'symptoms', 'relief', 'cure', 'help'];

    private const COINED_FAMILIES = ['mechanism_trick', 'slogan', 'celebrity', 'power_phrase', 'discovered_real', 'objection_verification'];

    /**
     * @param  array<string,mixed>  $row  uma linha de KeywordQualityIndex::score() (usa family/suffix_regime/keyword)
     * @return array{regime:string,isolation:string,why:string}
     */
    public function classify(array $row): array
    {
        $kw = mb_strtolower(trim((string) ($row['keyword'] ?? '')));
        $suffix = (string) ($row['suffix_regime'] ?? 'neutral');
        $family = (string) ($row['family'] ?? '');
        $last = $this->lastWord($kw);

        $coined = $suffix === 'owned' || $suffix === 'possession' || in_array($family, self::COINED_FAMILIES, true);

        // PROBE: sintoma/treatment frio sem raiz coined → sensor de demanda, campanha capada/separada
        if (! $coined && (in_array($last, self::SYMPTOM_SUFFIX, true) || $suffix === 'neutral')) {
            return ['regime' => 'probe', 'isolation' => 'C_symptom_probe', 'why' => 'sintoma frio sem raiz coined — sensor de demanda, NUNCA no tCPA de venda'];
        }

        // SEED: coined mas com sufixo de INFORMAÇÃO (recall/recipe/trick) → prospecting, tCPA frouxo
        if ($suffix === 'information') {
            return ['regime' => 'seed', 'isolation' => 'B_recall_seed', 'why' => 'coined + sufixo de informação (recall/recipe/trick) — semeadura, tCPA frouxo, isolado de A'];
        }

        // HARVEST: coined possession/owned/celebridade → colheita, tCPA agressivo
        if ($suffix === 'owned' || $suffix === 'possession' || $family === 'celebrity') {
            return ['regime' => 'harvest', 'isolation' => 'A_refinder_harvest', 'why' => 'coined/celebridade + posse — re-finder de alta intenção, COLHEITA, tCPA agressivo'];
        }

        // coined sem sinal forte → semeadura conservadora; não-coined restante → probe
        return $coined
            ? ['regime' => 'seed', 'isolation' => 'B_recall_seed', 'why' => 'coined neutro — semeadura conservadora']
            : ['regime' => 'probe', 'isolation' => 'C_symptom_probe', 'why' => 'sem raiz coined — sonda'];
    }

    /**
     * Particiona um conjunto pontuado nos 3 buckets ISOLADOS (budget/tCPA separados — a alavanca de escala).
     *
     * @param  array<int,array<string,mixed>>  $scored
     * @return array{harvest:array<int,array<string,mixed>>,seed:array<int,array<string,mixed>>,probe:array<int,array<string,mixed>>,summary:string}
     */
    public function partition(array $scored): array
    {
        $buckets = ['harvest' => [], 'seed' => [], 'probe' => []];
        foreach ($scored as $row) {
            $c = $this->classify($row);
            $buckets[$c['regime']][] = ['keyword' => $row['keyword'] ?? '', 'score' => $row['score'] ?? null, 'isolation' => $c['isolation'], 'why' => $c['why']];
        }

        return [
            'harvest' => $buckets['harvest'],
            'seed' => $buckets['seed'],
            'probe' => $buckets['probe'],
            'summary' => sprintf(
                'A_colheita=%d (tCPA agressivo) · B_semeadura=%d (tCPA frouxo) · C_sonda=%d (budget capado) — NUNCA misturar budget/tCPA',
                count($buckets['harvest']), count($buckets['seed']), count($buckets['probe']),
            ),
        ];
    }

    private function lastWord(string $k): string
    {
        $p = preg_split('/\s+/', $k) ?: [];

        return (string) (end($p) ?: '');
    }
}
