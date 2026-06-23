<?php

namespace App\Services\Ai\Transcription;

/**
 * TranscriptQualityGate — fail-closed quality assurance for a transcript. A transcription that the
 * Whisper decoder degenerated into loops ("mix mix mix…") or that silently dropped large stretches of
 * audio MUST NOT pass downstream — it poisons every metric/extraction built on it. This gate scores a
 * transcript deterministically and returns a verdict (pass | retry | fail) so the pipeline can
 * re-transcribe with harder settings instead of accepting garbage.
 *
 * Pure, no LLM, no I/O. The thresholds are conservative and tunable.
 */
class TranscriptQualityGate
{
    /** A single token repeated this many times in a row = decoder loop (hallucination). */
    public const MAX_CONSECUTIVE_REPEAT = 4;

    /** Any single content word exceeding this share of all words = degenerate output. */
    public const MAX_TOKEN_SHARE = 0.05;

    /** Unique/total words below this = collapsed vocabulary (looping). */
    public const MIN_UNIQUE_RATIO = 0.18;

    /** Healthy speech band (words per minute of audio). */
    public const MIN_WPM = 70;

    public const MAX_WPM = 240;

    /** Coverage = wpm / expected; below this the transcript dropped audio. */
    public const MIN_COVERAGE = 0.55;

    public const EXPECTED_WPM = 140;

    /** Stopwords dominate any short text naturally — they are not a hallucination signal. */
    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with', 'is', 'are', 'was', 'were',
        'you', 'your', 'i', 'it', 'that', 'this', 'we', 'they', 'he', 'she', 'as', 'at', 'be', 'by', 'but',
        'not', 'no', 'so', 'do', 'does', 'did', 'have', 'has', 'had', 'will', 'would', 'can', 'could', 'my',
        'me', 'what', 'if', 'all', 'e', 'o', 'os', 'um', 'uma', 'de', 'da', 'do', 'que', 'para', 'com', 'não',
        'eu', 'você', 'se', 'na', 'no', 'os', 'as',
    ];

    /**
     * @return array<string,mixed>
     */
    public function assess(string $transcript, ?int $durationSeconds = null): array
    {
        $text = trim($transcript);
        $tokens = $this->tokens($text);
        $total = count($tokens);
        $issues = [];

        if ($total < 50) {
            return $this->result('fail', 0, ['transcript vazio ou curto demais ('.$total.' palavras)'], compact('total'));
        }

        // 1. Consecutive-repeat loop (the "mix mix mix" hallucination).
        $maxRun = $this->maxConsecutiveRun($tokens);
        if ($maxRun['len'] >= self::MAX_CONSECUTIVE_REPEAT) {
            $issues[] = "loop de decodificação: token '{$maxRun['token']}' repetido {$maxRun['len']}× seguidas (alucinação do Whisper)";
        }

        // 2. Degenerate CONTENT-token share (stopwords dominate any short text — ignore them).
        $freq = array_count_values($tokens);
        arsort($freq);
        $contentFreq = array_diff_key($freq, array_flip(self::STOPWORDS));
        $topToken = $contentFreq !== [] ? (string) array_key_first($contentFreq) : (string) array_key_first($freq);
        $topShare = ($contentFreq[$topToken] ?? $freq[$topToken] ?? 0) / $total;
        if ($topShare > self::MAX_TOKEN_SHARE) {
            $issues[] = "token de conteúdo dominante: '{$topToken}' = ".round($topShare * 100, 1).'% de todas as palavras (esperado <'.(self::MAX_TOKEN_SHARE * 100).'%)';
        }

        // 3. Vocabulary ratio is kept as an INFORMATIVE metric only — the global unique/total ratio
        //    drops naturally on long sales videos (Zipf) and does NOT distinguish a loop from healthy
        //    repetition (the poisoned OT169 scored 0.178, the clean one 0.171). Loops are caught by
        //    the consecutive-run + repeated-bigram signals below, which is where the real signal is.
        $uniqueRatio = count($freq) / $total;

        // 4. Phrase-loop (repeated bigram run, e.g. "taking care of myself again ... again").
        $phraseLoop = $this->maxRepeatedBigram($tokens);
        if ($phraseLoop['count'] >= self::MAX_CONSECUTIVE_REPEAT) {
            $issues[] = "frase em loop: \"{$phraseLoop['bigram']}\" repetida {$phraseLoop['count']}×";
        }

        // 5. Words-per-minute + duration coverage (only when we know the audio length).
        $wpm = null;
        $coverage = null;
        if ($durationSeconds !== null && $durationSeconds > 0) {
            $wpm = $total / ($durationSeconds / 60);
            $coverage = $wpm / self::EXPECTED_WPM;
            if ($wpm < self::MIN_WPM) {
                $issues[] = 'words/min baixo ('.round($wpm).') — provável áudio perdido na transcrição';
            } elseif ($wpm > self::MAX_WPM) {
                $issues[] = 'words/min alto demais ('.round($wpm).') — improvável, revisar';
            }
            if ($coverage < self::MIN_COVERAGE) {
                $issues[] = 'cobertura de duração '.round($coverage * 100).'% — transcrição incompleta vs '.round($durationSeconds / 60).'min de áudio';
            }
        }

        // Verdict: any loop / degenerate / collapsed signal → retry (re-transcribe harder).
        $hardFail = $maxRun['len'] >= self::MAX_CONSECUTIVE_REPEAT
            || $topShare > self::MAX_TOKEN_SHARE
            || $phraseLoop['count'] >= self::MAX_CONSECUTIVE_REPEAT
            || ($coverage !== null && $coverage < self::MIN_COVERAGE);

        $verdict = $issues === [] ? 'pass' : ($hardFail ? 'retry' : 'pass');
        $score = (int) round(100 * max(0, 1 - count($issues) * 0.25));

        return $this->result($verdict, $score, $issues, [
            'total_words' => $total,
            'unique_ratio' => round($uniqueRatio, 3),
            'top_token' => $topToken,
            'top_token_share' => round($topShare, 4),
            'max_consecutive_repeat' => $maxRun['len'],
            'words_per_min' => $wpm !== null ? round($wpm, 1) : null,
            'duration_coverage' => $coverage !== null ? round($coverage, 3) : null,
        ]);
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array{token:string,len:int}
     */
    private function maxConsecutiveRun(array $tokens): array
    {
        $best = ['token' => '', 'len' => 0];
        $curToken = null;
        $curLen = 0;
        foreach ($tokens as $t) {
            if ($t === $curToken) {
                $curLen++;
            } else {
                $curToken = $t;
                $curLen = 1;
            }
            if ($curLen > $best['len']) {
                $best = ['token' => $t, 'len' => $curLen];
            }
        }

        return $best;
    }

    /**
     * Longest run of an immediately-repeating bigram (catches looping short phrases).
     *
     * @param  array<int,string>  $tokens
     * @return array{bigram:string,count:int}
     */
    private function maxRepeatedBigram(array $tokens): array
    {
        $best = ['bigram' => '', 'count' => 0];
        $n = count($tokens);
        for ($i = 0; $i + 1 < $n;) {
            $a = $tokens[$i];
            $b = $tokens[$i + 1];
            $reps = 1;
            $j = $i + 2;
            while ($j + 1 < $n && $tokens[$j] === $a && $tokens[$j + 1] === $b) {
                $reps++;
                $j += 2;
            }
            if ($reps > $best['count']) {
                $best = ['bigram' => "{$a} {$b}", 'count' => $reps];
            }
            $i = $reps > 1 ? $j : $i + 1;
        }

        return $best;
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/', trim($text)) ?: [];

        return array_values(array_filter($parts, static fn (string $w): bool => $w !== ''));
    }

    /**
     * @param  array<int,string>  $issues
     * @param  array<string,mixed>  $metrics
     * @return array<string,mixed>
     */
    private function result(string $verdict, int $score, array $issues, array $metrics): array
    {
        return [
            'verdict' => $verdict,       // pass | retry | fail
            'passed' => $verdict === 'pass',
            'score' => $score,
            'issues' => $issues,
            'metrics' => $metrics,
        ];
    }
}
