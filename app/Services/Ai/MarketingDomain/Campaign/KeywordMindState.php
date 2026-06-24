<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordMindState — projects a keyword's INTENT (from IntentLadderClassifier) onto the predicted MENTAL
 * STATE of the human who typed it: Schwartz awareness stage, the dominant emotional driver, and the page
 * angle that "fisga aquela mente". This is the first link of the prompt's central lever —
 * keyword → MENTE → página → venda — kept provider-free and deterministic so the conversion-critical path
 * never depends on the weak hermes; the deep, simulated reaction is the PersonaSimulator's job downstream.
 *
 * Honest scope: this is a deterministic PROJECTION of the intent prior (which itself has a ~74% text
 * ceiling), NOT a proven read of a real mind — it tells the page builder which angle to AIM, the real
 * reaction is validated by the PersonaSimulator / live CVR. The mapping is structural (tier → Schwartz),
 * not a lexicon claiming to measure quality.
 */
class KeywordMindState
{
    /**
     * @param  array<string,mixed>  $intent  an IntentLadderClassifier::classify() result
     * @return array{awareness:string,emotional_driver:string,page_angle:string,heat:string,note:string}
     */
    public function project(array $intent): array
    {
        $tier = (string) ($intent['tier'] ?? 'T1');
        $pain = (float) ($intent['pain'] ?? 0.0);
        $polarity = (string) ($intent['polarity'] ?? 'neutral');

        // Schwartz awareness — the tier IS the journey position.
        $awareness = match ($tier) {
            'T4' => 'most_aware',     // knows the mechanism/brand — came back to act
            'T3' => 'product_aware',  // solution + urgency — knows the path, wants it NOW
            'T2' => 'solution_aware', // accepted the need, hunting a category of solution
            'T1' => 'problem_aware',  // feels the problem, no solution sought yet
            default => 'unaware',     // T0 — wants to KNOW, not resolve
        };

        // Dominant emotional driver — what is actually pulling the click.
        $driver = match (true) {
            $polarity === 'negative' => 'distrust',            // defensive — soothe or exclude
            $polarity === 'positive' => 'desire_confirmation', // buyer trust-check — close the last doubt
            $pain >= 0.45 => 'urgency_relief',                 // desperation — pays to make it stop NOW
            $tier === 'T4' => 'desire_confirmation',
            $tier === 'T0' => 'curiosity',
            default => 'problem_tension',
        };

        // Page angle that fisga this mind — what the bridge/VSL must open with.
        $angle = match ($awareness) {
            'most_aware' => 'direct_offer_reminder',       // minimal friction, CTA forward, reassure it's the real thing
            'product_aware' => 'mechanism_proof_and_close', // prove the mechanism, neutralize the final objection, urgency
            'solution_aware' => 'mechanism_differentiation', // why THIS mechanism beats the generic category
            'problem_aware' => 'agitate_then_reveal',        // name the pain hard, then reveal the solution exists
            default => 'curiosity_hook_educate',             // unaware — hook curiosity, build the problem (usually paid-exclude)
        };

        $heat = match ($tier) {
            'T4', 'T3' => 'hot',
            'T2' => 'warm',
            'T1' => 'cool',
            default => 'cold',
        };
        if ($polarity === 'negative') {
            $heat = 'cold';
        }

        return [
            'awareness' => $awareness,
            'emotional_driver' => $driver,
            'page_angle' => $angle,
            'heat' => $heat,
            'note' => $this->note($awareness, $driver),
        ];
    }

    private function note(string $awareness, string $driver): string
    {
        return match ($awareness) {
            'most_aware' => 'já decidiu — não vender de novo, só remover atrito e confirmar (driver: '.$driver.')',
            'product_aware' => 'quer resolver AGORA — provar mecanismo + fechar objeção final (driver: '.$driver.')',
            'solution_aware' => 'comparando soluções — diferenciar o mecanismo da categoria (driver: '.$driver.')',
            'problem_aware' => 'sente a dor, sem solução — agitar e revelar que existe saída (driver: '.$driver.')',
            default => 'só quer saber — fisgar curiosidade; geralmente excluir em pago (driver: '.$driver.')',
        };
    }
}
