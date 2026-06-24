<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordVerdictGate (L4 — AND de 3 portas) — o veredito investimento-vs-gasto só é "PROVEN" quando passa
 * nas TRÊS portas do relatório §5 ("nunca chute"): SIGNIFICÂNCIA (KeywordInvestmentGate: rule-of-three /
 * breakeven / EPC), ATRIBUIÇÃO (DDA vs last-click — não cortar um assistente subvalorizado) e LAG (a janela
 * de conversão da VSL fechou — não julgar antes do bake). Sem dado de atribuição/lag, essas portas ficam
 * 'unknown' → o veredito NÃO vira proven, fica prior (honesto). Provider-free e determinístico.
 */
class KeywordVerdictGate
{
    /**
     * @param  array<string,mixed>  $significance  KeywordInvestmentGate::decide() output (verdict + basis)
     * @param  array<string,mixed>  $attribution   optional: last_click_conv, dda_conv
     * @param  array<string,mixed>  $lag           optional: days_since_launch, bake_days
     * @return array{verdict:string,basis:string,proven:bool,gates:array<string,mixed>,reason:string}
     */
    public function verdict(array $significance, array $attribution = [], array $lag = []): array
    {
        $sigBasis = (string) ($significance['basis'] ?? 'none');
        $sigVerdict = (string) ($significance['verdict'] ?? 'teste');

        $attr = $this->attributionGate($attribution);
        $lg = $this->lagGate($lag);

        $proven = $sigBasis === 'proven' && $attr['pass'] && $lg['pass'];

        // Ajustes honestos: atribuição protege o assistente; lag imaturo segura o veredito.
        $verdict = $sigVerdict;
        if ($verdict === 'gasto' && $attr['protect']) {
            $verdict = 'teste'; // last-click cortaria, mas o DDA mostra assistência → não cortar ainda
        }
        if (! $lg['pass'] && $lg['status'] === 'immature') {
            $verdict = 'teste'; // janela não fechou → veredito imaturo
        }

        return [
            'verdict' => $verdict,
            'basis' => $proven ? 'proven' : 'prior',
            'proven' => $proven,
            'gates' => [
                'significance' => ['basis' => $sigBasis, 'verdict' => $sigVerdict],
                'attribution' => $attr,
                'lag' => $lg,
            ],
            'reason' => $proven
                ? 'proven: passou nas 3 portas (significância × atribuição × lag)'
                : 'prior: falta '.implode('+', array_filter([
                    $sigBasis === 'proven' ? null : 'significância',
                    $attr['pass'] ? null : 'atribuição',
                    $lg['pass'] ? null : 'lag',
                ])),
        ];
    }

    /** @param array<string,mixed> $a @return array{pass:bool,status:string,protect:bool} */
    private function attributionGate(array $a): array
    {
        if (! isset($a['last_click_conv']) || ! isset($a['dda_conv'])) {
            return ['pass' => false, 'status' => 'unknown', 'protect' => false];
        }
        $lastClick = (float) $a['last_click_conv'];
        $dda = (float) $a['dda_conv'];
        // DDA credita mais que last-click ⇒ assistente subvalorizado ⇒ proteger de corte.
        $protect = $dda > $lastClick * 1.2;

        return ['pass' => true, 'status' => $protect ? 'assist_undervalued' : 'consistent', 'protect' => $protect];
    }

    /** @param array<string,mixed> $l @return array{pass:bool,status:string} */
    private function lagGate(array $l): array
    {
        if (! isset($l['days_since_launch']) || ! isset($l['bake_days'])) {
            return ['pass' => false, 'status' => 'unknown'];
        }
        $matured = (int) $l['days_since_launch'] >= (int) $l['bake_days'];

        return ['pass' => $matured, 'status' => $matured ? 'matured' : 'immature'];
    }
}
