<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordPageRouter — a regra #5 da dissecação: o DESTINO de página é decidido pela string, NÃO pelo nicho.
 * A densidade de artefato-VSL na keyword diz quanto da venda já aconteceu, logo qual página converte:
 *
 *  • ORDER_PAGE  — densidade ALTA (coined/posse/owned/celebridade/mistype): o re-finder é Most-Aware, Google
 *    é o checkout. Dar a order page e CALAR A BOCA — qualquer re-venda/storytelling é fricção que MATA o
 *    14-37% CVR. Repetir a palavra-gatilho idêntica; preservar o frame editorial/autoridade.
 *  • BRIDGE      — densidade MÉDIA (coined sem forma, neutro): ponte curta de confirmação "sim, é isto".
 *  • ADVERTORIAL — densidade BAIXA (sintoma/recipe/treatment frio, sem coined): a landing faz 100% do
 *    trabalho de consciência e PLANTA um nome-coined proprietário (semente do re-finder de amanhã).
 *
 * PROIBIÇÕES pétreas: NUNCA mandar coined/celebridade pra bridge (insulta o comprador no checkout); NUNCA
 * mandar sintoma cru pra order page (converte 0%). Provider-free, determinístico.
 */
class KeywordPageRouter
{
    private const SYMPTOM_SUFFIX = ['treatment', 'remedy', 'symptoms', 'relief', 'cure', 'help'];

    private const COINED_FAMILIES = ['mechanism_trick', 'slogan', 'celebrity', 'power_phrase', 'discovered_real', 'objection_verification'];

    /**
     * @param  array<string,mixed>  $row  linha de KeywordQualityIndex::score() (usa suffix_regime/family/keyword)
     * @return array{page:string,why:string,frame:string}
     */
    public function route(array $row): array
    {
        $suffix = (string) ($row['suffix_regime'] ?? 'neutral');
        $family = (string) ($row['family'] ?? '');
        $last = $this->lastWord((string) ($row['keyword'] ?? ''));
        $coined = in_array($family, self::COINED_FAMILIES, true) || in_array($suffix, ['owned', 'possession'], true);

        // ALTA densidade → order page direta (comprador pronto, zero re-venda)
        if ($suffix === 'possession' || $suffix === 'owned' || $family === 'celebrity') {
            return ['page' => 'order_page', 'why' => 'densidade-VSL ALTA (coined+posse/celebridade) — Most-Aware, Google é checkout', 'frame' => 'order page direta, repete a palavra-gatilho idêntica, ZERO re-venda/storytelling'];
        }

        // BAIXA densidade → advertorial (sintoma/recipe frio faz a consciência + planta o token)
        if (! $coined && (in_array($last, self::SYMPTOM_SUFFIX, true) || $suffix === 'information' || $suffix === 'neutral')) {
            return ['page' => 'advertorial', 'why' => 'densidade-VSL BAIXA (sintoma/recipe sem coined) — landing faz 100% da consciência', 'frame' => 'advertorial longo: escada de consciência inteira + PLANTA um nome-coined proprietário'];
        }

        // coined com sufixo de informação (recall) ou coined neutro → ponte curta de confirmação
        return ['page' => 'bridge', 'why' => 'densidade-VSL MÉDIA (coined recall/neutro) — confirmar e empurrar', 'frame' => 'bridge curta "sim, é isto" → order page; nunca educação longa'];
    }

    private function lastWord(string $k): string
    {
        $p = preg_split('/\s+/', mb_strtolower(trim($k))) ?: [];

        return (string) (end($p) ?: '');
    }
}
