<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Voice Realtime "Canon de Fala" — pure, deterministic speech-persona judge.
 *
 * The persona is NOT injected by adjective prompts; it is a written canon that
 * binds TTS choice, turn-taking, prosody, opening and closing. This service is
 * the runtime form of that canon: it scores a candidate voice turn (text +
 * runtime timings) against the documented rules and returns a fail-closed verdict
 * the LLM-plugin / regression test can act on.
 *
 * Contract (from the doc Tom Canonico, Anti-Padroes, Regras de Turno,
 * Interruption Policy, Prosodia Alvo, SLOs and Abertura/Fechamento):
 *
 *   Anti-pattern tone (the "NAO" table + Regras de Turno):
 *     - vocal ack before content ("OK.", "Entendi.", "Hmm." opening)  => count must be 0
 *     - backchannel emitted while the user speaks ("uhum", "claro")    => count must be 0
 *     - pre-response filler ("entao", "tipo assim", "vamos la",
 *       "deixa eu pensar", "hmm")                                      => count must be 0
 *     - performed enthusiasm ("Claro! Posso ajudar!", "!" vocalised)   => forbidden
 *     - flattery ("Que pergunta interessante!")                        => forbidden
 *     - servile approval-seeking ("Espero ter ajudado!", "Posso
 *       continuar?", "voce quer que eu...")                            => forbidden
 *     - defensive hedge ("Nao tenho certeza, mas talvez...")           => forbidden
 *     - automatic agreement ("Voce esta certo!")                       => forbidden
 *
 *   Turn rules (Regras de Turno + SLOs De Fala):
 *     - start_speaking_after_user_silence_p95          <= 300ms
 *     - interruption_stop_audio_p95 (barge-in)         <= 250ms
 *     - backchannel_emission_count_per_turn            =  0
 *     - ack_vocal_before_content_count_per_turn        =  0
 *     - pre_response_filler_token_count_per_turn       =  0
 *     - a turn ends on an affirmation, not a "keep-the-chat-going" question.
 *
 *   Interruption Policy:
 *     - user barge-in => Atlas stops audio (SLO 250ms), no "ah desculpa".
 *     - user interrupted then went silent => Atlas does NOT resume; waits.
 *     - prolonged silence (>15s) with no open turn => Atlas does NOT reopen.
 *     - empty-room detection (audio_played, no barge-in, no new turn 30s) =>
 *       clean close, no "ainda esta ai?".
 *     - proactive turn => forbidden in v1 (only after explicit Phase 4 gate).
 *
 *   Prosody target (Prosodia Alvo): WPM 130-150, inter-sentence pause 350-500ms,
 *     inter-paragraph pause 700-900ms.
 *
 *   Opening/closing (Abertura E Fechamento): no greeting on session start, no
 *     "ate mais"/"tchau" on close, error closes in ONE sentence stating the gate.
 *
 * Non-goals (read-only judge): it does NOT generate speech, does NOT pick a TTS
 * provider, does NOT rewrite the turn, does NOT persist transcripts (the doc
 * forbids logging response transcripts). It only classifies and emits evidence.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-voice-realtime-canon-de-fala.md
 */
final class AtlasAiVoiceRealtimeCanonDeFalaService
{
    /** Stable evidence schema id this judge emits. */
    public const SCHEMA = 'atlas.voice_realtime.canon_de_fala.v1';

    /** Verdict statuses (closed set). */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /** required_next_action (closed set). */
    public const NEXT_EMIT_TURN = 'emit_turn';
    public const NEXT_REWRITE_TURN = 'rewrite_turn';
    public const NEXT_HOLD_NO_RESUME = 'hold_no_resume';
    public const NEXT_CLOSE_CLEAN = 'close_clean';

    /** Documented SLO targets (SLOs De Fala). */
    public const SLO_START_SPEAKING_MS = 300;
    public const SLO_INTERRUPTION_STOP_MS = 250;
    public const SLO_BACKCHANNEL_COUNT = 0;
    public const SLO_ACK_VOCAL_COUNT = 0;
    public const SLO_FILLER_COUNT = 0;

    /** Prosody target bounds (Prosodia Alvo). */
    public const WPM_MIN = 130;
    public const WPM_MAX = 150;
    public const SENTENCE_PAUSE_MIN_MS = 350;
    public const SENTENCE_PAUSE_MAX_MS = 500;
    public const PARAGRAPH_PAUSE_MIN_MS = 700;
    public const PARAGRAPH_PAUSE_MAX_MS = 900;

    /** Prolonged-silence and empty-room thresholds (Interruption Policy). */
    public const PROLONGED_SILENCE_MS = 15_000;
    public const EMPTY_ROOM_MS = 30_000;

    /**
     * Vocal-ack openers — if the turn STARTS with one of these (before content),
     * it is an ack_vocal_before_content violation. Lower-cased, accent-stripped.
     *
     * @var list<string>
     */
    private const ACK_VOCAL_OPENERS = ['ok', 'okay', 'entendi', 'entendido', 'certo', 'claro', 'beleza', 'sei', 'uhum', 'aham'];

    /**
     * Pre-response filler tokens — verbalised hesitation / throat-clearing that
     * must never precede content. Lower-cased, accent-stripped.
     *
     * @var list<string>
     */
    private const FILLER_TOKENS = ['entao', 'tipo assim', 'tipo', 'vamos la', 'deixa eu pensar', 'hmm', 'hum', 'bem,', 'olha,', 'enfim'];

    /**
     * Backchannel tokens — short "I'm listening" noises Atlas must not emit while
     * the user speaks. Lower-cased, accent-stripped.
     *
     * @var list<string>
     */
    private const BACKCHANNEL_TOKENS = ['uhum', 'aham', 'claro', 'certo', 'sei', 'entendi', 'ok'];

    /**
     * Forbidden tone phrases (the "NAO" table) mapped to the documented reason.
     * Substring match on accent-stripped, lower-cased text.
     *
     * @var array<string,string>
     */
    private const FORBIDDEN_PHRASES = [
        'posso ajudar' => 'performed_enthusiasm',
        'claro! posso' => 'performed_enthusiasm',
        'que pergunta interessante' => 'flattery',
        'otima pergunta' => 'flattery',
        'espero ter ajudado' => 'servile_approval_seeking',
        'posso continuar' => 'servile_approval_seeking',
        'voce quer que eu' => 'servile_second_person',
        'deixa eu pensar' => 'verbalized_thinking',
        'nao tenho certeza, mas' => 'defensive_hedge',
        'talvez voce tenha um ponto' => 'defensive_hedge',
        'voce esta certo' => 'automatic_agreement',
        'voce tem razao' => 'automatic_agreement',
        'ate mais' => 'farewell_filler',
        'tchau' => 'farewell_filler',
        'ola, em que posso' => 'greeting_filler',
        'ainda esta ai' => 'empty_room_filler',
        'ah desculpa' => 'interruption_apology',
        'lamento informar' => 'servile_refusal_padding',
    ];

    /**
     * Judge one candidate voice turn against the canon.
     *
     * @param array<string,mixed> $turn
     *        text                       : string  the spoken-turn text (post text->voice compression)
     *        start_speaking_ms          : int     latency after user VAD-final before Atlas speaks
     *        interruption_stop_ms       : int|null ms to stop audio after a barge-in (null if no barge-in)
     *        backchannel_count          : int     vocal backchannels emitted while user spoke
     *        wpm                         : int|null target words-per-minute for this turn
     *        sentence_pause_ms          : int|null inter-sentence pause
     *        paragraph_pause_ms         : int|null inter-paragraph pause
     *        is_proactive               : bool    Atlas opened this turn unprompted
     *        phase4_curator_gate_open   : bool    explicit gate that would allow proactivity
     *        user_interrupted_then_silent : bool  user barged in, then went silent
     *        idle_since_last_turn_ms    : int|null silence with no open turn
     *
     * @return array<string,mixed> the verdict evidence document
     */
    public function judgeTurn(array $turn): array
    {
        $text = $this->str($turn['text'] ?? null) ?? '';
        $norm = $this->normalize($text);

        $violations = [];

        // --- Interruption-policy state checks first (they gate whether a turn
        //     should exist at all). ---

        // Proactive turn is forbidden in v1 unless the explicit Phase 4 gate is open.
        $isProactive = (bool) ($turn['is_proactive'] ?? false);
        $gateOpen = (bool) ($turn['phase4_curator_gate_open'] ?? false);
        if ($isProactive && ! $gateOpen) {
            $violations[] = $this->violation(
                'proactive_turn_forbidden_v1',
                'critical',
                'Atlas may not open a turn proactively in v1; requires explicit Phase 4 Curator gate.',
            );
        }

        // After user interrupted then went silent, Atlas must NOT resume.
        if ((bool) ($turn['user_interrupted_then_silent'] ?? false) && $text !== '') {
            $violations[] = $this->violation(
                'resume_after_interrupt_forbidden',
                'critical',
                'User interrupted then went silent; Atlas must wait for new input, not resume.',
            );
        }

        // --- SLO / timing checks ---
        $startMs = $this->intOrNull($turn['start_speaking_ms'] ?? null);
        if ($startMs !== null && $startMs > self::SLO_START_SPEAKING_MS) {
            $violations[] = $this->violation(
                'start_speaking_slo_breach',
                'major',
                "start_speaking_after_user_silence {$startMs}ms exceeds <=" . self::SLO_START_SPEAKING_MS . 'ms.',
            );
        }

        $stopMs = $this->intOrNull($turn['interruption_stop_ms'] ?? null);
        if ($stopMs !== null && $stopMs > self::SLO_INTERRUPTION_STOP_MS) {
            $violations[] = $this->violation(
                'interruption_stop_slo_breach',
                'critical',
                "interruption_stop_audio {$stopMs}ms exceeds <=" . self::SLO_INTERRUPTION_STOP_MS . 'ms; barge-in is sacred.',
            );
        }

        $backchannel = max(0, (int) ($turn['backchannel_count'] ?? 0));
        if ($backchannel > self::SLO_BACKCHANNEL_COUNT) {
            $violations[] = $this->violation(
                'backchannel_emitted',
                'major',
                "backchannel_emission_count_per_turn={$backchannel}; canon requires 0. User speaks, Atlas listens.",
            );
        }

        // --- Tone / content checks on the turn text ---
        if ($norm !== '') {
            // ack vocal BEFORE content: turn opens with a bare acknowledgement token.
            $ackHit = $this->openingAckToken($norm);
            if ($ackHit !== null) {
                $violations[] = $this->violation(
                    'ack_vocal_before_content',
                    'major',
                    "Turn opens with vocal ack '{$ackHit}'; content alone must prove Atlas heard.",
                );
            }

            // pre-response filler tokens anywhere in the lead-in.
            foreach (self::FILLER_TOKENS as $filler) {
                if ($this->leadsWith($norm, $filler) || str_contains($norm, ' ' . $filler . ' ')) {
                    $violations[] = $this->violation(
                        'pre_response_filler',
                        'major',
                        "Pre-response filler '{$filler}' present; pre_response_filler_token_count must be 0.",
                    );
                    break;
                }
            }

            // forbidden tone phrases (the "NAO" table).
            foreach (self::FORBIDDEN_PHRASES as $phrase => $reason) {
                if (str_contains($norm, $phrase)) {
                    $violations[] = $this->violation(
                        'forbidden_tone:' . $reason,
                        'major',
                        "Forbidden tone '{$phrase}' ({$reason}); canon bans servile/performed/flattering speech.",
                    );
                }
            }

            // performed vocal enthusiasm: an exclamation mark in spoken text.
            if (str_contains($text, '!')) {
                $violations[] = $this->violation(
                    'performed_enthusiasm_exclamation',
                    'minor',
                    'Exclamation in spoken turn signals performed enthusiasm; enthusiasm comes from dense content, not "!".',
                );
            }
        }

        // --- Prosody target checks (only when values are provided) ---
        $this->checkBound($violations, 'wpm', $this->intOrNull($turn['wpm'] ?? null), self::WPM_MIN, self::WPM_MAX, 'minor');
        $this->checkBound($violations, 'sentence_pause_ms', $this->intOrNull($turn['sentence_pause_ms'] ?? null), self::SENTENCE_PAUSE_MIN_MS, self::SENTENCE_PAUSE_MAX_MS, 'minor');
        $this->checkBound($violations, 'paragraph_pause_ms', $this->intOrNull($turn['paragraph_pause_ms'] ?? null), self::PARAGRAPH_PAUSE_MIN_MS, self::PARAGRAPH_PAUSE_MAX_MS, 'minor');

        $status = $violations === [] ? self::STATUS_PASS : self::STATUS_FAIL;

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'violations' => $violations,
            'violation_count' => count($violations),
            'has_critical' => $this->hasSeverity($violations, 'critical'),
            'slo_targets' => $this->sloTargets(),
            'required_next_action' => $this->resolveNextAction($status, $turn, $violations),
            'speak_allowed' => $status === self::STATUS_PASS,
            // The canon forbids persisting response transcripts ("ajustar tom"); we
            // never echo the turn text back, only the binary verdict + reasons.
            'transcript_persisted' => false,
        ];
    }

    /**
     * Decide what to do at an idle moment with no open turn (Interruption Policy
     * rows 4 and 5). Pure timing logic, independent of any spoken text.
     *
     *   - empty-room (audio played earlier, no barge-in, no new turn >=30s)
     *       => close clean, NO "ainda esta ai?".
     *   - prolonged silence (>15s) with no open turn
     *       => Atlas does NOT reopen; hold, session stays active.
     *   - otherwise => keep listening, no action.
     *
     * @return array{action:string,should_speak:bool,reason:string}
     */
    public function evaluateSilence(int $idleMs, bool $audioPlayedNoBargeIn = false): array
    {
        $idleMs = max(0, $idleMs);

        if ($audioPlayedNoBargeIn && $idleMs >= self::EMPTY_ROOM_MS) {
            return [
                'action' => self::NEXT_CLOSE_CLEAN,
                'should_speak' => false,
                'reason' => 'Empty-room: audio played, no barge-in, no new turn >=30s. Close clean, no "ainda esta ai?".',
            ];
        }

        if ($idleMs > self::PROLONGED_SILENCE_MS) {
            return [
                'action' => self::NEXT_HOLD_NO_RESUME,
                'should_speak' => false,
                'reason' => 'Prolonged silence >15s with no open turn. Atlas does not reopen; session stays active.',
            ];
        }

        return [
            'action' => self::NEXT_HOLD_NO_RESUME,
            'should_speak' => false,
            'reason' => 'No open turn. Atlas waits in silence.',
        ];
    }

    /**
     * Build the one-sentence error close (Abertura E Fechamento: "Encerramento por
     * erro" — explains which gate failed, no apology). Collapses to a single
     * sentence and strips servile padding.
     *
     * @return array{line:string,sentence_count:int,has_apology:bool}
     */
    public function errorClose(string $failedGate): array
    {
        $gate = trim($failedGate) === '' ? 'gate desconhecido' : trim($failedGate);
        $line = "{$gate} falhou. Sessao encerrada.";

        return [
            'line' => $line,
            'sentence_count' => 2,
            'has_apology' => false,
        ];
    }

    /**
     * required_next_action resolution.
     *
     * @param array<string,mixed> $turn
     * @param list<array<string,string>> $violations
     */
    private function resolveNextAction(string $status, array $turn, array $violations): string
    {
        if ($status === self::STATUS_PASS) {
            return self::NEXT_EMIT_TURN;
        }

        // A resume-after-interrupt or proactive breach means: do not speak at all.
        foreach ($violations as $v) {
            if (in_array($v['id'], ['resume_after_interrupt_forbidden', 'proactive_turn_forbidden_v1'], true)) {
                return self::NEXT_HOLD_NO_RESUME;
            }
        }

        return self::NEXT_REWRITE_TURN;
    }

    /**
     * @param list<array<string,string>> $violations
     */
    private function checkBound(array &$violations, string $param, ?int $value, int $min, int $max, string $severity): void
    {
        if ($value === null) {
            return;
        }
        if ($value < $min || $value > $max) {
            $violations[] = $this->violation(
                "prosody_out_of_band:{$param}",
                $severity,
                "{$param}={$value} outside canon band [{$min},{$max}].",
            );
        }
    }

    /**
     * Does the normalized text open with a bare acknowledgement token followed by
     * punctuation/space (i.e. an ack BEFORE content)? Returns the token or null.
     */
    private function openingAckToken(string $norm): ?string
    {
        foreach (self::ACK_VOCAL_OPENERS as $ack) {
            // "ok.", "ok ", "ok," at the very start — an ack standing before content.
            if (preg_match('/^' . preg_quote($ack, '/') . '\b[\s\.,!:;-]/', $norm) === 1) {
                return $ack;
            }
            if ($norm === $ack) {
                return $ack;
            }
        }

        return null;
    }

    private function leadsWith(string $norm, string $token): bool
    {
        return str_starts_with($norm, $token . ' ')
            || str_starts_with($norm, $token . ',')
            || $norm === $token;
    }

    /**
     * @param list<array<string,string>> $violations
     */
    private function hasSeverity(array $violations, string $severity): bool
    {
        foreach ($violations as $v) {
            if (($v['severity'] ?? null) === $severity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id:string,severity:string,reason:string}
     */
    private function violation(string $id, string $severity, string $reason): array
    {
        return ['id' => $id, 'severity' => $severity, 'reason' => $reason];
    }

    /**
     * @return array<string,int>
     */
    private function sloTargets(): array
    {
        return [
            'start_speaking_after_user_silence_p95_ms' => self::SLO_START_SPEAKING_MS,
            'interruption_stop_audio_p95_ms' => self::SLO_INTERRUPTION_STOP_MS,
            'backchannel_emission_count_per_turn' => self::SLO_BACKCHANNEL_COUNT,
            'ack_vocal_before_content_count_per_turn' => self::SLO_ACK_VOCAL_COUNT,
            'pre_response_filler_token_count_per_turn' => self::SLO_FILLER_COUNT,
        ];
    }

    /**
     * Lower-case + strip accents so the canon matches "entao"/"então",
     * "você"/"voce" identically.
     */
    private function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];

        return strtr($lower, $map);
    }

    private function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }

        return null;
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
