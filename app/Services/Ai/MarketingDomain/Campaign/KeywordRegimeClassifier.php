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

    // 'discovered_real' (termo COLHIDO do Blackink) FICA coined: a tentativa de tirá-lo (decidir por morfologia)
    // foi REVERTIDA — sem o owned-root no contexto (asset vazio / cross-produto no feed), nomes coined de alto
    // valor (orivelle 28% CVR, bariatric 26%, dr-barbara celebridade) caíam em probe e o held-out de LUCRO
    // despencou 40,5×→2,6×. O agregado parecia limpo mas escondia o mis-bucket per-term. Coinedness-por-
    // morfologia sem dicionário é a armadilha lexical; até ter um detector provado, colhido=coined é o certo.
    private const COINED_FAMILIES = ['mechanism_trick', 'slogan', 'celebrity', 'power_phrase', 'discovered_real', 'objection_verification'];

    /**
     * @param  array<string,mixed>  $row  uma linha de KeywordQualityIndex::score() (usa family/suffix_regime/keyword)
     * @return array{regime:string,isolation:string,why:string}
     */
    public function classify(array $row, array $ownedRoots = []): array
    {
        $kw = mb_strtolower(trim((string) ($row['keyword'] ?? '')));
        $suffix = (string) ($row['suffix_regime'] ?? 'neutral');
        $family = (string) ($row['family'] ?? '');
        $last = $this->lastWord($kw);

        // coined = posse morfológica OU família coined OU casa o owned-root PRÓPRIO do ativo (provenance)
        $coined = $suffix === 'owned' || $suffix === 'possession'
            || in_array($family, self::COINED_FAMILIES, true)
            || $this->matchesOwnedRoot($kw, $ownedRoots);

        // PROBE: sintoma/treatment frio sem raiz coined → sensor de demanda, campanha capada/separada
        if (! $coined && (in_array($last, self::SYMPTOM_SUFFIX, true) || $suffix === 'neutral')) {
            return ['regime' => 'probe', 'isolation' => 'C_symptom_probe', 'why' => 'sintoma frio sem raiz coined — sensor de demanda, NUNCA no tCPA de venda'];
        }

        // SEED: coined mas com sufixo de INFORMAÇÃO (recall/recipe/trick) → prospecting, tCPA frouxo
        if ($suffix === 'information') {
            return ['regime' => 'seed', 'isolation' => 'B_recall_seed', 'why' => 'coined + sufixo de informação (recall/recipe/trick) — semeadura, tCPA frouxo, isolado de A'];
        }

        // HARVEST: o NOME COINED PRÓPRIO do ativo, nu (sem sufixo de info) — recall PURO da marca, o re-finder
        // de máxima intenção (digitou só o nome que lembrou do anúncio). Provenance-based: só promove o root do
        // PRÓPRIO ativo (marca estrangeira no feed fica seed). Corrige o bug "orivelle→seed" (28% CVR sub-bidado).
        if ($coined && $this->matchesOwnedRoot($kw, $ownedRoots)) {
            return ['regime' => 'harvest', 'isolation' => 'A_refinder_harvest', 'why' => 'nome coined PRÓPRIO nu — recall puro da marca, re-finder máximo, COLHEITA (tCPA agressivo)'];
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
    public function partition(array $scored, array $ownedRoots = []): array
    {
        $buckets = ['harvest' => [], 'seed' => [], 'probe' => []];
        foreach ($scored as $row) {
            $c = $this->classify($row, $ownedRoots);
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

    /** o termo É um coined-root próprio nu (root inteiro, ou root como cabeça: "orivelle" / "orivelle pen"). */
    private function matchesOwnedRoot(string $kw, array $ownedRoots): bool
    {
        foreach ($ownedRoots as $r) {
            $r = mb_strtolower(trim((string) $r));
            if ($r === '' || mb_strlen($r) < 4) {
                continue;
            }
            if ($kw === $r || str_starts_with($kw, $r.' ')) {
                return true;
            }
        }

        return false;
    }

    private function lastWord(string $k): string
    {
        $p = preg_split('/\s+/', $k) ?: [];

        return (string) (end($p) ?: '');
    }
}
