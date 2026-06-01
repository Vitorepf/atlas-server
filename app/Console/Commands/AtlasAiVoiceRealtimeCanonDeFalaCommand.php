<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiVoiceRealtimeCanonDeFalaService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Voice Realtime "Canon de Fala" CLI.
 *
 *   php artisan atlas:aaeos:atlas-ai-voice-realtime-canon-de-fala
 *     [--text="..."]                   // candidate spoken-turn text to judge
 *     [--start-speaking-ms=180]
 *     [--interruption-stop-ms=210]
 *     [--backchannel-count=0]
 *     [--wpm=140]
 *     [--proactive]                    // Atlas opened the turn unprompted
 *     [--gate-open]                    // Phase 4 Curator gate explicitly open
 *     [--json]
 *
 * Read-only, deterministic. Scores the turn against the written speech canon and
 * emits a pass|fail verdict. It never generates speech, picks a TTS provider, or
 * persists the transcript.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-voice-realtime-canon-de-fala.md
 */
class AtlasAiVoiceRealtimeCanonDeFalaCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-ai-voice-realtime-canon-de-fala
        {--text= : candidate spoken-turn text to judge against the canon}
        {--start-speaking-ms= : ms after user VAD-final before Atlas speaks}
        {--interruption-stop-ms= : ms to stop audio after a barge-in}
        {--backchannel-count=0 : vocal backchannels emitted while user spoke}
        {--wpm= : target words-per-minute for this turn}
        {--proactive : Atlas opened this turn unprompted}
        {--gate-open : Phase 4 Curator gate explicitly open (allows proactivity)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas voice persona · judges a candidate voice turn against the written speech canon (pass|fail).';

    public function handle(AtlasAiVoiceRealtimeCanonDeFalaService $service): int
    {
        try {
            $turn = [
                'text' => $this->strOpt('text') ?? 'Nao concordo. O backup automatico quebra em sessao longa porque o token expira antes do flush.',
                'start_speaking_ms' => $this->intOpt('start-speaking-ms'),
                'interruption_stop_ms' => $this->intOpt('interruption-stop-ms'),
                'backchannel_count' => (int) ($this->intOpt('backchannel-count') ?? 0),
                'wpm' => $this->intOpt('wpm'),
                'is_proactive' => (bool) $this->option('proactive'),
                'phase4_curator_gate_open' => (bool) $this->option('gate-open'),
            ];

            $result = $service->judgeTurn($turn);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $result['status'] === AtlasAiVoiceRealtimeCanonDeFalaService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'voice_canon_judge_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function strOpt(string $option): ?string
    {
        $raw = $this->option($option);

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    private function intOpt(string $option): ?int
    {
        $raw = $this->option($option);

        return is_numeric($raw) ? (int) $raw : null;
    }
}
