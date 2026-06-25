<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\ConversionCriticGate;
use PHPUnit\Framework\TestCase;

/**
 * Locks the generator+verifier contract: the gate HARD-BLOCKS only on structural truths (reveal/CTA
 * leak in the opening, choice-overload/no-CTA, zero concrete proof) and NEVER blocks on marker-density
 * priors (value-equation/awareness/scores only WARN). Deterministic, provider-free. A strong bridge is
 * never blocked; token-stuffing a prior cannot force a re-roll; threshold='off' still blocks structural.
 */
class ConversionCriticGateTest extends TestCase
{
    /** A strong, clean bridge copy: no opening reveal/hard-CTA, ONE watch CTA, concrete proof, open loops. */
    private function strongCopy(): string
    {
        return implode(' ', [
            'Se você é uma mulher acima dos quarenta e a balança não se move por mais que tente, isto pode explicar o porquê.',
            'Por anos a culpa caiu na sua força de vontade, mas pesquisas recentes apontam para um gatilho metabólico que poucas conhecem.',
            'A médica Dr. Anya Sharma acompanhou 4.812 mulheres que relataram mudanças quando esse gatilho voltou a funcionar.',
            'Uma delas, Karen, registrou 27 lbs a menos em 9 semanas, sem dieta da moda e sem academia.',
            'Os resultados variam de pessoa para pessoa, e o protocolo leva poucos minutos por dia.',
            'O que ela percebeu contraria o que a indústria repete há décadas.',
            'Na apresentação gratuita abaixo ela mostra, passo a passo, o que realmente mudou.',
            'Assista à apresentação gratuita agora para ver se faz sentido para você.',
        ]);
    }

    public function test_strong_bridge_is_not_blocked(): void
    {
        $v = (new ConversionCriticGate)->evaluate($this->strongCopy(), '', 'decent');

        $this->assertTrue($v['structural_pass'], 'forte não deveria ter flaw estrutural: '.json_encode($v['reasons'], JSON_UNESCAPED_UNICODE));
        $this->assertNotSame('block', $v['verdict']);
    }

    public function test_premature_reveal_blocks(): void
    {
        $copy = implode(' ', [
            'O segredo é um gatilho hormonal triplo que muda tudo.',
            'A Dr. Anya Sharma documentou 4.812 casos reais.',
            'Karen registrou 27 lbs a menos em 9 semanas.',
            'Os resultados variam de pessoa para pessoa.',
            'Assista à apresentação gratuita abaixo para entender.',
        ]);

        $v = (new ConversionCriticGate)->evaluate($copy, '', 'decent');

        $this->assertSame('block', $v['verdict']);
        $this->assertContains('watch_through_leak', array_column($v['reasons'], 'floor'));
        $this->assertContains('structural', array_column($v['reasons'], 'kind'));
    }

    public function test_competing_ctas_block(): void
    {
        $copy = implode(' ', [
            'Descubra o método caseiro que está ajudando mulheres acima de quarenta.',
            'A Dr. Anya Sharma documentou 4.812 casos com 27 lbs a menos.',
            'Assista ao vídeo para entender como funciona.',
            'Inscreva-se para receber o guia grátis por email.',
            'Ligue agora para falar com uma especialista.',
            'Os resultados variam de pessoa para pessoa.',
        ]);

        $v = (new ConversionCriticGate)->evaluate($copy, '', 'decent');

        $this->assertSame('block', $v['verdict']);
        $this->assertContains('decision_clarity', array_column($v['reasons'], 'floor'));
    }

    public function test_zero_concrete_proof_blocks(): void
    {
        $copy = implode(' ', [
            'Se você tenta emagrecer há muitos anos, talvez o problema nunca tenha sido você.',
            'Especialistas concordam que algo no metabolismo muda com a idade.',
            'Muitas mulheres relataram uma diferença enorme em suas vidas.',
            'Estudos sugerem que é possível virar esse jogo de vez.',
            'Sem promessas mágicas, mas vale a pena entender o que mudou.',
            'Assista à apresentação gratuita abaixo para descobrir.',
        ]);

        $v = (new ConversionCriticGate)->evaluate($copy, '', 'decent');

        $this->assertSame('block', $v['verdict']);
        $this->assertContains('proof_substance', array_column($v['reasons'], 'floor'));
    }

    public function test_threshold_off_disables_priors_but_structural_still_blocks(): void
    {
        $copy = implode(' ', [
            'Se você tenta emagrecer há muitos anos, talvez o problema nunca tenha sido você.',
            'Especialistas concordam que algo no metabolismo muda com a idade.',
            'Muitas mulheres relataram uma diferença enorme em suas vidas.',
            'Estudos sugerem que é possível virar esse jogo de vez.',
            'Sem promessas mágicas, mas vale a pena entender o que mudou.',
            'Assista à apresentação gratuita abaixo para descobrir.',
        ]);

        $v = (new ConversionCriticGate)->evaluate($copy, 'most_aware', 'off');

        // structural floor (zero concrete proof) STILL blocks with threshold off
        $this->assertSame('block', $v['verdict']);
        $this->assertContains('proof_substance', array_column($v['reasons'], 'floor'));
        // and NO prior-tier reason leaks through when off
        $this->assertNotContains('prior', array_column($v['reasons'], 'kind'));
    }

    public function test_priors_only_warn_never_block(): void
    {
        // strong copy is structurally clean; forcing an awareness target it cannot match yields a PRIOR
        // warn only — proving a marker-density signal can never force a re-roll.
        $v = (new ConversionCriticGate)->evaluate($this->strongCopy(), 'most_aware', 'decent');

        $this->assertTrue($v['structural_pass']);
        $this->assertSame('warn', $v['verdict']);
        $this->assertNotEmpty($v['reasons']);
        foreach ($v['reasons'] as $r) {
            $this->assertSame('prior', $r['kind'], 'um floor estrutural vazou no warn-only: '.json_encode($r, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_deterministic_same_input_same_verdict(): void
    {
        $gate = new ConversionCriticGate;
        $a = $gate->evaluate($this->strongCopy(), 'solution_aware', 'decent');
        $b = $gate->evaluate($this->strongCopy(), 'solution_aware', 'decent');

        $this->assertSame(json_encode($a, JSON_UNESCAPED_UNICODE), json_encode($b, JSON_UNESCAPED_UNICODE));
    }

    /**
     * proof_adjacency is a HARD floor DISTINCT from proof_substance: a page can carry concrete proof
     * SOMEWHERE (proof_substance passes) yet leave a bold claim standing alone (no proof in its window) —
     * that orphan claim blocks. English copy (the real campaign language; isClaim is English-keyed).
     */
    public function test_orphan_claim_blocks_and_is_distinct_from_proof_substance(): void
    {
        $copy = implode(' ', [
            'If you are a woman over forty and the scale will not move, this may be why.',
            'You will lose 34 pounds in 21 days, the easiest path you have tried.',   // bold claim
            'It is the shift so many have quietly been waiting for.',                 // next: no proof → orphan
            'Most mornings still feel ordinary, and that is exactly the point.',
            'Separately, Dr. Anya Sharma tracked 412 women in a clinical review.',    // concrete proof, NOT adjacent
            'Watch the free presentation to see how it works.',                       // one watch CTA
        ]);

        $v = (new ConversionCriticGate)->evaluate($copy, '', 'decent');

        $this->assertSame('block', $v['verdict']);
        $this->assertFalse($v['structural_pass']);
        $this->assertContains('proof_adjacency', array_column($v['reasons'], 'floor'));
        // the page HAS concrete proof somewhere, so proof_substance does NOT fire — proves the distinction
        $this->assertNotContains('proof_substance', array_column($v['reasons'], 'floor'));
    }

    public function test_claim_with_adjacent_proof_does_not_trigger_adjacency(): void
    {
        $copy = implode(' ', [
            'If you are a woman over forty and the scale will not move, this may be why.',
            'You will lose 34 pounds in 21 days, the easiest path you have tried.',           // claim
            'In a clinical review, Dr. Anya Sharma tracked 412 women who did exactly that.',  // adjacent external proof → backed
            'Most mornings still feel ordinary, and that is exactly the point.',
            'Thousands have quietly followed the same steps since.',
            'Watch the free presentation to see how it works.',
        ]);

        $v = (new ConversionCriticGate)->evaluate($copy, '', 'decent');

        $this->assertTrue($v['structural_pass'], 'prova adjacente não deveria deixar flaw estrutural: '.json_encode($v['reasons'], JSON_UNESCAPED_UNICODE));
        $this->assertNotContains('proof_adjacency', array_column($v['reasons'], 'floor'));
    }
}
