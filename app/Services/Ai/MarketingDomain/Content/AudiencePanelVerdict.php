<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * AudiencePanelVerdict — the ConversionLeverageDiagnostic applied to the AUDIENCE dimension.
 *
 * The PersonaSimulator returns five raw reactions; the AbandonPointSimulator says WHERE each leaves. This
 * is the third leg: the actionable VERDICT over the panel — how many personas are lost, the DOMINANT
 * failure mode (the fix the most personas independently ask for), the shared fix, and who would buy. It
 * turns the panel from "five opinions" into one decision ("you lose 3 of 5; the shared reason is no risk-
 * reversal → add the guarantee"), the few-shot signal the LearnedWeightLedger can later calibrate with real
 * sales. Reuses PersonaSimulator (no new psychology). Deterministic, provider-free.
 */
class AudiencePanelVerdict
{
    public function __construct(private readonly PersonaSimulator $personas = new PersonaSimulator) {}

    /**
     * @return array{lost_count:int,total:int,lost:array<int,string>,would_buy:array<int,string>,dominant_failure_mode:?string,shared_fix:?string,summary:string}
     */
    public function assess(string $copy, string $niche = ''): array
    {
        $panel = $this->personas->simulate($copy, '', $niche);

        $lost = [];
        $wouldBuy = [];
        $needCounts = [];     // normalized need => count
        $needLabel = [];      // normalized need => original label
        $strongest = ['key' => null, 'close' => -1.0, 'need' => null];

        foreach ($panel as $key => $p) {
            $watch = (float) ($p['will_watch'] ?? 0);
            $close = (float) ($p['will_close'] ?? 0);
            $need = trim((string) ($p['what_she_needs_next'] ?? ''));

            if ($close > $watch) {
                $lost[] = $key;
                if ($need !== '') {
                    $norm = mb_strtolower($need);
                    $needCounts[$norm] = ($needCounts[$norm] ?? 0) + 1;
                    $needLabel[$norm] = $need;
                }
                if ($close > $strongest['close']) {
                    $strongest = ['key' => $key, 'close' => $close, 'need' => $need];
                }
            } elseif ($watch >= 0.5) {
                $wouldBuy[] = $key;
            }
        }

        // Dominant failure mode = the fix the MOST lost personas independently ask for; tiebreak by the
        // strongest-closing persona's need (the most violent rejection).
        $dominant = null;
        $sharedFix = null;
        if ($needCounts !== []) {
            arsort($needCounts);
            $topNorm = array_key_first($needCounts);
            $topCount = $needCounts[$topNorm];
            if ($topCount >= 2) {
                $dominant = $needLabel[$topNorm];
                $sharedFix = $needLabel[$topNorm];
            } else {
                $dominant = $strongest['need'] ?: $needLabel[$topNorm];
                $sharedFix = $dominant;
            }
        }

        $total = count($panel);
        $lostN = count($lost);
        $summary = $lostN === 0
            ? "Painel atravessa: 0/{$total} personas fecham — a página segura a audiência."
            : "Perde {$lostN}/{$total} personas".($dominant !== null ? "; gargalo de audiência compartilhado = \"{$dominant}\"." : '.');

        return [
            'lost_count' => $lostN,
            'total' => $total,
            'lost' => $lost,
            'would_buy' => $wouldBuy,
            'dominant_failure_mode' => $dominant,
            'shared_fix' => $sharedFix,
            'summary' => $summary,
        ];
    }
}
