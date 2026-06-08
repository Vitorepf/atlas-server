<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorComprehensionExtractor;
use App\Services\Ai\OperatorIntelligence\OperatorLearningGate;
use Tests\TestCase;

/**
 * The anti-hallucination gate stack, proven with a canned model response (no live
 * provider). A signal must survive EVERY gate or it is dropped — a fabricated quote,
 * an invented id, a too-short anchor, or a high-stakes inference all fail closed.
 */
class OperatorComprehensionExtractorTest extends TestCase
{
    private function extractor(): OperatorComprehensionExtractor
    {
        return app(OperatorComprehensionExtractor::class);
    }

    private function extractFrom(string $text, ?string $canned): array
    {
        $x = $this->extractor();
        $x->setCannedResponseForTesting($canned);

        return $x->extract($text);
    }

    public function test_grounds_an_explicit_preference(): void
    {
        $text = 'prefiro sempre respostas curtas e diretas, sem enrolacao';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-073',
            'claim' => 'O operador prefere respostas curtas e diretas.',
            'evidence_quote' => 'prefiro sempre respostas curtas e diretas',
            'inference_type' => 'explicit',
            'confidence_tier' => 'explicit',
            'privacy_class' => 'normal',
        ]]]);

        $signals = $this->extractFrom($text, $canned);

        $this->assertCount(1, $signals);
        $this->assertSame('OP-073', $signals[0]['taxonomy_item_id']);
        $this->assertSame('explicit', $signals[0]['inference_type']);
        $this->assertEqualsWithDelta(0.9, $signals[0]['confidence'], 0.001);
    }

    public function test_drops_a_hallucinated_quote_not_in_the_text(): void
    {
        $text = 'prefiro respostas curtas';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-074',
            'claim' => 'O operador prefere respostas longas e detalhadas.',
            'evidence_quote' => 'eu adoro respostas longas e super detalhadas sempre',
            'inference_type' => 'explicit',
        ]]]);

        $this->assertSame([], $this->extractFrom($text, $canned));
    }

    public function test_rejects_an_invented_taxonomy_id_via_schema(): void
    {
        $text = 'prefiro sempre respostas curtas e diretas sim';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-999',
            'claim' => 'x',
            'evidence_quote' => 'prefiro sempre respostas curtas e diretas',
            'inference_type' => 'explicit',
        ]]]);

        $this->assertSame([], $this->extractFrom($text, $canned));
    }

    public function test_high_stakes_inference_is_forced_to_low_confidence(): void
    {
        $text = 'acho que nunca deveriam mexer no kernel constitucional sem revisao';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-145', // "o que nunca pode mexer" — high-stakes
            'claim' => 'O operador nao quer que mexam no kernel sem revisao.',
            'evidence_quote' => 'nunca deveriam mexer no kernel constitucional sem revisao',
            'inference_type' => 'implicit',
            'confidence_tier' => 'single_inference',
        ]]]);

        $signals = $this->extractFrom($text, $canned);

        $this->assertCount(1, $signals);
        $this->assertLessThanOrEqual(0.35, $signals[0]['confidence']);
        $this->assertTrue($signals[0]['metadata']['high_stakes']);
        $this->assertTrue($signals[0]['metadata']['requires_refutation']);
    }

    public function test_short_quote_is_rejected(): void
    {
        $text = 'gosto de respostas curtas';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-073', 'claim' => 'curtas', 'evidence_quote' => 'curtas', 'inference_type' => 'explicit',
        ]]]);

        $this->assertSame([], $this->extractFrom($text, $canned));
    }

    public function test_empty_or_failed_provider_yields_nothing(): void
    {
        $this->assertSame([], $this->extractFrom('prefiro respostas curtas e diretas sempre', ''));
        $this->assertSame([], $this->extractFrom('prefiro respostas curtas e diretas sempre', '{not json'));
    }

    public function test_high_stakes_labelled_explicit_is_still_forced_to_review(): void
    {
        // The bypass: the LLM labels a high-stakes signal 'explicit' to dodge the clamp.
        $text = 'o atlas nunca deve mexer no kernel constitucional sozinho jamais';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-145',
            'claim' => 'O operador declara que o kernel nunca pode ser tocado sozinho.',
            'evidence_quote' => 'o atlas nunca deve mexer no kernel constitucional sozinho',
            'inference_type' => 'explicit',
            'confidence_tier' => 'explicit',
        ]]]);

        $signals = $this->extractFrom($text, $canned);
        $this->assertCount(1, $signals);
        $this->assertLessThanOrEqual(0.35, $signals[0]['confidence'], 'high-stakes must review regardless of label');
        $this->assertTrue($signals[0]['metadata']['requires_refutation']);
    }

    public function test_sensitive_default_item_is_raised_even_if_model_says_normal(): void
    {
        // OP-131 (projects) is registry-sensitive; the model says 'normal' — raise-only.
        $text = 'o projeto da aquisicao da empresa beta e prioridade maxima agora';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-131',
            'claim' => 'Projeto da aquisicao da empresa beta e prioridade.',
            'evidence_quote' => 'o projeto da aquisicao da empresa beta e prioridade maxima',
            'inference_type' => 'explicit',
            'privacy_class' => 'normal',
        ]]]);

        $signals = $this->extractFrom($text, $canned);
        $this->assertCount(1, $signals);
        $this->assertSame('sensitive', $signals[0]['privacy_class'], 'registry sensitive floor must hold');
        $this->assertSame('[redacted-quote]', $signals[0]['metadata']['evidence_quote']);
    }

    public function test_refute_drops_an_inference_the_second_model_cannot_confirm(): void
    {
        $text = 'me da uma resposta mais curta nesse caso especifico aqui agora';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-073',
            'claim' => 'O operador prefere respostas curtas em geral.',
            'evidence_quote' => 'me da uma resposta mais curta nesse caso especifico',
            'inference_type' => 'implicit',
            'confidence_tier' => 'single_inference',
        ]]]);

        $x = $this->extractor();
        $x->setCannedResponseForTesting($canned);
        $x->setCannedRefuteForTesting(false); // the 2nd model could NOT confirm it

        $this->assertSame([], $x->extract($text), 'a refuted inference must be dropped');
    }

    public function test_refute_keeps_a_confirmed_inference(): void
    {
        $text = 'me da uma resposta mais curta nesse caso especifico aqui agora';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-073',
            'claim' => 'O operador prefere respostas curtas.',
            'evidence_quote' => 'me da uma resposta mais curta nesse caso especifico',
            'inference_type' => 'implicit',
            'confidence_tier' => 'single_inference',
        ]]]);

        $x = $this->extractor();
        $x->setCannedResponseForTesting($canned);
        $x->setCannedRefuteForTesting(true);

        $this->assertCount(1, $x->extract($text));
    }

    public function test_over_general_claim_on_a_short_quote_is_clamped_to_review(): void
    {
        $text = 'me da respostas mais curtas por favor nesse caso aqui';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-073',
            'claim' => 'O operador SEMPRE prefere respostas curtas em TODOS os contextos.',
            'evidence_quote' => 'respostas mais curtas por favor',
            'inference_type' => 'explicit',
            'confidence_tier' => 'explicit',
            'scope_type' => 'global',
        ]]]);

        $signals = $this->extractFrom($text, $canned);
        $this->assertCount(1, $signals);
        $this->assertLessThanOrEqual(0.55, $signals[0]['confidence'], 'over-general claim on a short quote must review');
    }

    // ── Structural re-proof of the residual holes (task: re-prove + fix structurally) ──

    /** Push the extractor's own output through the REAL downstream gate at the most
     *  permissive risk (low) — proves the auto-apply decision end-to-end, not by proxy. */
    private function gate(array $signal): array
    {
        return app(OperatorLearningGate::class)->evaluate([
            'privacy_class' => $signal['privacy_class'],
            'confidence' => $signal['confidence'],
            'scope_type' => $signal['scope_type'],
            'risk_level' => 'low',
        ]);
    }

    public function test_hole1_explicit_only_non_high_stakes_on_hedged_source_is_not_auto_eligible(): void
    {
        // OP-099 is EXPLICIT_ONLY but NOT high-stakes — the high-stakes clamp never touches
        // it. With EXPLICIT_ONLY unwired, the LLM's bare 'explicit' label rode a hedged
        // source ("as vezes acho que… mas nao sei") to 0.9 with NO refute → auto-apply.
        $text = 'as vezes acho que o atlas devesse virar um copiloto de engenharia mas nao sei';
        $quote = 'o atlas devesse virar um copiloto de engenharia'; // hedge-free span; the SOURCE is hedged
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-099',
            'claim' => 'O operador declara que quer que o Atlas vire um copiloto de engenharia.',
            'evidence_quote' => $quote,
            'inference_type' => 'explicit',
            'confidence_tier' => 'explicit',
            'privacy_class' => 'normal',
            'scope_type' => 'project',
        ]]]);

        $x = $this->extractor();
        $x->setCannedResponseForTesting($canned);
        $x->setCannedRefuteForTesting(true); // even a "confirming" 2nd model must not make it explicit/eligible

        $signals = $x->extract($text);
        $this->assertCount(1, $signals);
        $this->assertSame('implicit', $signals[0]['inference_type'], 'a hedged source cannot be recorded as an explicit declaration');
        $this->assertLessThanOrEqual(0.35, $signals[0]['confidence'], 'explicit-only on a hedged source is forced to deep review');
        $this->assertTrue($signals[0]['metadata']['requires_refutation']);
        $this->assertFalse($this->gate($signals[0])['auto_apply_eligible'], 'must NOT be auto-apply-eligible');
    }

    public function test_hole1_high_stakes_explicit_label_on_hedged_source_loses_explicit_provenance(): void
    {
        // OP-145 high-stakes: the clamp already blocks auto-apply, but the FALSE 'explicit'
        // provenance survived. A hedged source must be recorded as inference, never as a
        // settled explicit declaration of "what Atlas can never touch".
        $text = 'as vezes acho que o atlas nao devesse mexer no kernel sozinho mas nao sei';
        $quote = 'o atlas nao devesse mexer no kernel sozinho';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-145',
            'claim' => 'O operador declara que o kernel nunca pode ser tocado sozinho.',
            'evidence_quote' => $quote,
            'inference_type' => 'explicit',
            'confidence_tier' => 'explicit',
        ]]]);

        $x = $this->extractor();
        $x->setCannedResponseForTesting($canned);
        $x->setCannedRefuteForTesting(true);

        $signals = $x->extract($text);
        $this->assertCount(1, $signals);
        $this->assertSame('implicit', $signals[0]['inference_type'], 'hedged high-stakes source must not claim explicit provenance');
        $this->assertLessThanOrEqual(0.35, $signals[0]['confidence']);
        $this->assertFalse($this->gate($signals[0])['auto_apply_eligible']);
    }

    public function test_hole1_explicit_only_with_clean_declaration_stays_honored(): void
    {
        // Precision guard: the EXPLICIT_ONLY wiring must NOT punish a genuine, hedge-free
        // explicit declaration on an explicit-only item (no false positives).
        $text = 'o atlas deve virar um copiloto de engenharia completo e definitivo';
        $quote = 'o atlas deve virar um copiloto de engenharia completo';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-099',
            'claim' => 'O operador quer que o Atlas vire um copiloto de engenharia.',
            'evidence_quote' => $quote,
            'inference_type' => 'explicit',
            'confidence_tier' => 'explicit',
            'privacy_class' => 'normal',
            'scope_type' => 'project',
        ]]]);

        $signals = $this->extractFrom($text, $canned);
        $this->assertCount(1, $signals);
        $this->assertSame('explicit', $signals[0]['inference_type']);
        $this->assertEqualsWithDelta(0.9, $signals[0]['confidence'], 0.001, 'a clean explicit declaration must stay honored');
    }

    public function test_hole2_over_general_claim_on_a_long_but_scoped_quote_is_forced_to_review(): void
    {
        // The < 45-char heuristic is gameable: a 49-char yet plainly momentary quote
        // ("eu queria testar…") carrying a forever-claim must still clamp to review.
        $text = 'eu queria testar uma resposta um pouco mais curta nesse caso aqui';
        $quote = 'eu queria testar uma resposta um pouco mais curta';
        $this->assertGreaterThan(45, mb_strlen($quote), 'fixture must defeat the old length heuristic');
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-073',
            'claim' => 'O operador SEMPRE prefere respostas curtas em TODOS os contextos, para sempre.',
            'evidence_quote' => $quote,
            'inference_type' => 'explicit',
            'confidence_tier' => 'explicit',
            'privacy_class' => 'normal',
            'scope_type' => 'global',
        ]]]);

        $signals = $this->extractFrom($text, $canned);
        $this->assertCount(1, $signals);
        $this->assertLessThanOrEqual(0.55, $signals[0]['confidence'], 'fabricated breadth on a scoped quote must review');
        $this->assertFalse($this->gate($signals[0])['auto_apply_eligible']);
    }

    public function test_hole3_sensitive_default_people_item_is_redacted_and_not_provider_safe(): void
    {
        // OP-132 (people/companies) is registry SENSITIVE_DEFAULT — the floor must hold and
        // the at-rest quote be redacted even when the model labels it 'normal'.
        $text = 'a empresa beta e o investidor relevante sao as entidades mais importantes pra mim';
        $quote = 'a empresa beta e o investidor relevante sao as entidades mais importantes';
        $canned = json_encode(['signals' => [[
            'taxonomy_item_id' => 'OP-132',
            'claim' => 'Empresa beta e investidor relevante sao entidades importantes.',
            'evidence_quote' => $quote,
            'inference_type' => 'explicit',
            'confidence_tier' => 'explicit',
            'privacy_class' => 'normal',
        ]]]);

        $signals = $this->extractFrom($text, $canned);
        $this->assertCount(1, $signals);
        $this->assertSame('sensitive', $signals[0]['privacy_class'], 'registry sensitive floor must hold structurally');
        $this->assertSame('[redacted-quote]', $signals[0]['metadata']['evidence_quote']);
        $providerSafe = (array) config('atlas_operator_intelligence.provider_safe_privacy_classes', ['normal']);
        $this->assertNotContains($signals[0]['privacy_class'], $providerSafe, 'sensitive must never be provider-safe/external');
    }
}
