<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Pure, deterministic, side-effect-free tone filter for AOBG/context-pack memory snippets. Hostile,
 * insulting, panic, or emotionally charged operator language must never reach an external worker
 * verbatim — it gets rewritten into a neutral, provider-safe summary that preserves the actionable
 * meaning. Benign technical memory passes through unchanged. No network, model call, human approval,
 * or git operation — this is a pure string classifier and rewriter.
 */
final class AtlasOpenBrainProviderSafeMemoryToneFilter
{
    public const SCHEMA = 'atlas.ai.open_brain.provider_safe_memory_tone_filter.v1';

    public const RISK_CLEAN = 'clean';

    public const RISK_HOSTILE = 'hostile';

    private const PROFANITY_MARKERS = [
        'merda', 'porra', 'caralho', 'droga', 'idiota', 'burro', 'estúpido', 'estupido', 'imbecil',
        'inútil', 'inutil', 'lixo', 'damn', 'shit', 'fuck', 'stupid', 'idiot', 'useless', 'garbage', 'dumb',
    ];

    private const INSULT_PATTERNS = [
        'você não', 'voce nao', 'vc não', 'vc nao', "you don't understand", "don't understand anything",
        'não entendeu', 'nao entendeu', 'não está entendendo', 'nao esta entendendo', 'não tá entendendo', 'nao ta entendendo',
    ];

    private const PANIC_MARKERS = [
        'urgente', 'emergência', 'emergencia', 'pelo amor de deus', "for god's sake", 'omg',
    ];

    /**
     * @return array<string, mixed>
     */
    public function filter(string $snippet): array
    {
        $lower = mb_strtolower($snippet);

        $redactionFlags = [];
        if ($this->containsAny($lower, self::PROFANITY_MARKERS)) {
            $redactionFlags[] = 'profanity';
        }
        if ($this->containsAny($lower, self::INSULT_PATTERNS)) {
            $redactionFlags[] = 'insult_pattern';
        }
        if ($this->containsAny($lower, self::PANIC_MARKERS) || $this->hasExcessiveExclamation($snippet)) {
            $redactionFlags[] = 'panic_or_emotional';
        }
        if ($this->hasShouting($snippet)) {
            $redactionFlags[] = 'shouting';
        }

        if ($redactionFlags === []) {
            return [
                'schema' => self::SCHEMA,
                'safe_text' => $snippet,
                'risk_level' => self::RISK_CLEAN,
                'redaction_flags' => [],
                'preserved_intent' => $snippet,
                'dropped_raw_quote' => false,
            ];
        }

        $preservedIntent = $this->neutralRewrite($lower);

        return [
            'schema' => self::SCHEMA,
            'safe_text' => $preservedIntent,
            'risk_level' => self::RISK_HOSTILE,
            'redaction_flags' => $redactionFlags,
            'preserved_intent' => $preservedIntent,
            'dropped_raw_quote' => true,
        ];
    }

    private function neutralRewrite(string $lower): string
    {
        if (str_contains($lower, 'over-engineer') || str_contains($lower, 'overengineer') || str_contains($lower, 'complex') || str_contains($lower, 'desnecess')) {
            return 'operator flagged over-engineering or unnecessary complexity';
        }
        if (str_contains($lower, 'token') && (str_contains($lower, 'wast') || str_contains($lower, 'gast') || str_contains($lower, 'queim'))) {
            return 'operator flagged token-waste risk';
        }
        if (str_contains($lower, 'não tá entendendo') || str_contains($lower, 'nao ta entendendo')
            || str_contains($lower, 'não entendeu') || str_contains($lower, 'nao entendeu')
            || str_contains($lower, "don't understand")) {
            return 'operator flagged the approach as fundamentally misunderstood; rework needed';
        }

        return 'operator expressed strong dissatisfaction with the approach; review needed';
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function hasExcessiveExclamation(string $text): bool
    {
        return (bool) preg_match('/!{2,}/', $text);
    }

    private function hasShouting(string $text): bool
    {
        return (bool) preg_match('/\b[A-Z]{4,}\b/', $text);
    }
}
