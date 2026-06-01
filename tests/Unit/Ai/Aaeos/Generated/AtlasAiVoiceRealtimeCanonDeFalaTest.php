<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiVoiceRealtimeCanonDeFalaService as Canon;
use Tests\TestCase;

/**
 * Pins the documented "Canon de Fala" speech rules: tone anti-patterns, turn
 * SLOs, interruption policy, prosody bounds and the one-sentence error close.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-voice-realtime-canon-de-fala.md
 */
class AtlasAiVoiceRealtimeCanonDeFalaTest extends TestCase
{
    private function canon(): Canon
    {
        return new Canon();
    }

    /**
     * Doc "Exemplos" / "Bom": weight-decreasing, no ack, no filler, fast start,
     * fast barge-in stop => pass, emit_turn, speak allowed, transcript not kept.
     */
    public function test_canonical_good_turn_passes(): void
    {
        $r = $this->canon()->judgeTurn([
            'text' => 'Nao concordo. O backup automatico quebra em sessao longa porque o token expira antes do flush.',
            'start_speaking_ms' => 180,
            'interruption_stop_ms' => 210,
            'backchannel_count' => 0,
            'wpm' => 140,
            'sentence_pause_ms' => 400,
        ]);

        $this->assertSame(Canon::STATUS_PASS, $r['status']);
        $this->assertSame(Canon::NEXT_EMIT_TURN, $r['required_next_action']);
        $this->assertTrue($r['speak_allowed']);
        $this->assertSame([], $r['violations']);
        $this->assertFalse($r['transcript_persisted']);
        // SLO targets are surfaced verbatim from the canon.
        $this->assertSame(250, $r['slo_targets']['interruption_stop_audio_p95_ms']);
        $this->assertSame(0, $r['slo_targets']['ack_vocal_before_content_count_per_turn']);
    }

    /**
     * Anti-Padroes table: "Claro! Posso ajudar com isso!" is performed enthusiasm
     * + servile. It must fail and be flagged as forbidden tone (not passed).
     */
    public function test_performed_enthusiasm_and_flattery_fail(): void
    {
        $r = $this->canon()->judgeTurn([
            'text' => 'Claro! Posso ajudar com isso! Que pergunta interessante.',
            'start_speaking_ms' => 150,
        ]);

        $this->assertSame(Canon::STATUS_FAIL, $r['status']);
        $this->assertSame(Canon::NEXT_REWRITE_TURN, $r['required_next_action']);
        $this->assertFalse($r['speak_allowed']);
        $ids = array_column($r['violations'], 'id');
        $this->assertContains('forbidden_tone:performed_enthusiasm', $ids);
        $this->assertContains('forbidden_tone:flattery', $ids);
        // "!" in spoken text is independently flagged.
        $this->assertContains('performed_enthusiasm_exclamation', $ids);
    }

    /**
     * Regra de Turno 2 + SLO ack_vocal_before_content_count_per_turn=0:
     * a turn that OPENS with "Ok." (an ack before content) must fail.
     */
    public function test_vocal_ack_before_content_fails(): void
    {
        $r = $this->canon()->judgeTurn([
            'text' => 'Ok. O deploy terminou.',
            'start_speaking_ms' => 120,
        ]);

        $this->assertSame(Canon::STATUS_FAIL, $r['status']);
        $this->assertContains('ack_vocal_before_content', array_column($r['violations'], 'id'));
    }

    /**
     * Interruption Policy: barge-in must stop audio within 250ms. 480ms is a
     * critical SLO breach (the most sacred rule) => fail + has_critical=true.
     */
    public function test_slow_barge_in_stop_is_critical_failure(): void
    {
        $r = $this->canon()->judgeTurn([
            'text' => 'Resposta densa e correta sem filler.',
            'start_speaking_ms' => 200,
            'interruption_stop_ms' => 480,
        ]);

        $this->assertSame(Canon::STATUS_FAIL, $r['status']);
        $this->assertTrue($r['has_critical']);
        $this->assertContains('interruption_stop_slo_breach', array_column($r['violations'], 'id'));
    }

    /**
     * Interruption Policy: "proactividade proibida em v1". An unprompted turn with
     * NO Phase 4 gate must fail and the next action is hold (do not speak). With
     * the gate open, the same turn passes.
     */
    public function test_proactive_turn_forbidden_without_gate(): void
    {
        $forbidden = $this->canon()->judgeTurn([
            'text' => 'Notei que o build caiu.',
            'start_speaking_ms' => 100,
            'is_proactive' => true,
            'phase4_curator_gate_open' => false,
        ]);
        $this->assertSame(Canon::STATUS_FAIL, $forbidden['status']);
        $this->assertSame(Canon::NEXT_HOLD_NO_RESUME, $forbidden['required_next_action']);
        $this->assertContains('proactive_turn_forbidden_v1', array_column($forbidden['violations'], 'id'));

        $allowed = $this->canon()->judgeTurn([
            'text' => 'Notei que o build caiu.',
            'start_speaking_ms' => 100,
            'is_proactive' => true,
            'phase4_curator_gate_open' => true,
        ]);
        $this->assertSame(Canon::STATUS_PASS, $allowed['status']);
    }

    /**
     * Interruption Policy row 2: user interrupted then went silent => Atlas must
     * NOT resume. Any non-empty turn in that state fails and resolves to hold.
     */
    public function test_no_resume_after_interrupt_then_silence(): void
    {
        $r = $this->canon()->judgeTurn([
            'text' => 'Como eu dizia, o token...',
            'start_speaking_ms' => 90,
            'user_interrupted_then_silent' => true,
        ]);

        $this->assertSame(Canon::STATUS_FAIL, $r['status']);
        $this->assertSame(Canon::NEXT_HOLD_NO_RESUME, $r['required_next_action']);
        $this->assertContains('resume_after_interrupt_forbidden', array_column($r['violations'], 'id'));
    }

    /**
     * Prosodia Alvo: WPM band is 130-150. 175 WPM (tagarela) is out of band and
     * must be flagged.
     */
    public function test_wpm_out_of_canon_band_fails(): void
    {
        $r = $this->canon()->judgeTurn([
            'text' => 'Frase densa e correta.',
            'start_speaking_ms' => 200,
            'wpm' => 175,
        ]);

        $this->assertSame(Canon::STATUS_FAIL, $r['status']);
        $this->assertContains('prosody_out_of_band:wpm', array_column($r['violations'], 'id'));
    }

    /**
     * Interruption Policy rows 4 & 5 via evaluateSilence():
     *  - empty-room (audio played, no barge-in, >=30s) => close clean.
     *  - prolonged silence (>15s) with no open turn => hold, never reopen.
     *  - short silence => hold, no speech.
     * In all cases should_speak is false (Atlas never reopens on its own).
     */
    public function test_silence_policy_never_reopens(): void
    {
        $empty = $this->canon()->evaluateSilence(31_000, audioPlayedNoBargeIn: true);
        $this->assertSame(Canon::NEXT_CLOSE_CLEAN, $empty['action']);
        $this->assertFalse($empty['should_speak']);

        $prolonged = $this->canon()->evaluateSilence(20_000, audioPlayedNoBargeIn: false);
        $this->assertSame(Canon::NEXT_HOLD_NO_RESUME, $prolonged['action']);
        $this->assertFalse($prolonged['should_speak']);

        $short = $this->canon()->evaluateSilence(4_000);
        $this->assertFalse($short['should_speak']);
    }

    /**
     * Abertura E Fechamento: "Encerramento por erro" closes in ONE statement of
     * which gate failed, with NO apology.
     */
    public function test_error_close_is_one_line_no_apology(): void
    {
        $close = $this->canon()->errorClose('Kernel nao emitiu receipt');

        $this->assertSame('Kernel nao emitiu receipt falhou. Sessao encerrada.', $close['line']);
        $this->assertFalse($close['has_apology']);
        $this->assertStringNotContainsStringIgnoringCase('desculpa', $close['line']);
    }
}
