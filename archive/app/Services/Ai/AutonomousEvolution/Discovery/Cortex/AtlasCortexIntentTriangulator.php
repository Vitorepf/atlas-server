<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex;

/**
 * CORTEX INTENT TRIANGULATOR — fuses three independent legs of evidence about a class's purpose into a single
 * {@see TriangulatedIntentFact}: the docblock IntentExtractionFact, the DecisionHistoryFact (recent commit
 * subjects), and sibling consensus (neighbours whose docblocks reference the same concept).
 *
 * ANTI-GOODHART: it NEVER invents a purpose. If no leg carries real tokens, purpose_statement is null and
 * confidence is 0. Confidence reaches the agreement floor (>=70) ONLY when at least TWO legs genuinely agree
 * (token overlap >= the configurable threshold). A conflict is recorded when the extractor's purpose tokens
 * are disjoint from the most-recent decision keyword set — a basic token-overlap check, no LLM.
 */
final class AtlasCortexIntentTriangulator
{
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'its', 'into', 'from', 'when', 'what', 'which', 'are',
        'was', 'has', 'but', 'not', 'all', 'any', 'can', 'may', 'per', 'via', 'use', 'add', 'fix', 'now',
    ];

    public function __construct(
        private readonly int $overlapThreshold = 1,
        private readonly int $agreementFloor = 70,
    ) {
    }

    /**
     * @param  list<array{fqcn?:string, purpose?:string, docblock?:string}|string>  $siblings
     */
    public function triangulate(string $fqcn, ?IntentExtractionFact $extractor, ?DecisionHistoryFact $history, array $siblings = []): TriangulatedIntentFact
    {
        $extractorTokens = $extractor !== null ? $this->tokens((string) ($extractor->docblockPurpose ?? '')) : [];
        $historyTokens = $history !== null ? $this->tokens($this->mostRecentSubject($history)) : [];
        $siblingTokens = $this->siblingConsensusTokens($siblings);

        $evidence = ['extractor' => $extractorTokens, 'history' => $historyTokens, 'siblings' => $siblingTokens];
        $present = array_filter($evidence, static fn (array $tokens): bool => $tokens !== []);

        if ($present === []) {
            return new TriangulatedIntentFact($fqcn, null, $evidence, 0, []); // no fabrication
        }

        $confidence = $this->confidence($present);

        $conflicts = [];
        if ($extractorTokens !== [] && $historyTokens !== [] && count(array_intersect($extractorTokens, $historyTokens)) < $this->overlapThreshold) {
            $conflicts[] = 'extractor_purpose_disjoint_from_recent_decision';
        }

        return new TriangulatedIntentFact($fqcn, $this->purposeStatement($extractor, $history, $siblings), $evidence, $confidence, $conflicts);
    }

    /**
     * @param  array<string,list<string>>  $present
     */
    private function confidence(array $present): int
    {
        $keys = array_keys($present);
        $agreeing = [];
        foreach ($keys as $a) {
            foreach ($keys as $b) {
                if ($a !== $b && count(array_intersect($present[$a], $present[$b])) >= $this->overlapThreshold) {
                    $agreeing[$a] = true;
                    $agreeing[$b] = true;
                }
            }
        }

        $count = count($agreeing);
        if ($count >= 2) {
            return min(100, max($this->agreementFloor, $this->agreementFloor + 10 + ($count - 2) * 20)); // 2 legs ⇒ floor+10, 3 ⇒ +30
        }

        return count($present) >= 1 ? 40 : 0; // some presence but unconfirmed ⇒ below the agreement floor
    }

    private function mostRecentSubject(DecisionHistoryFact $history): string
    {
        $subject = '';
        $latest = PHP_INT_MIN;
        foreach ($history->decisions as $decision) {
            if (! is_array($decision)) {
                continue;
            }
            $at = (int) ($decision['decided_on'] ?? 0);
            if ($at >= $latest) {
                $latest = $at;
                $subject = (string) ($decision['subject'] ?? '');
            }
        }

        return $subject;
    }

    /**
     * @param  list<array{fqcn?:string, purpose?:string, docblock?:string}|string>  $siblings
     * @return list<string>
     */
    private function siblingConsensusTokens(array $siblings): array
    {
        $tokens = [];
        foreach ($siblings as $sibling) {
            $text = is_array($sibling) ? (string) ($sibling['purpose'] ?? ($sibling['docblock'] ?? '')) : (string) $sibling;
            foreach ($this->tokens($text) as $token) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @param  list<array{fqcn?:string, purpose?:string, docblock?:string}|string>  $siblings
     */
    private function purposeStatement(?IntentExtractionFact $extractor, ?DecisionHistoryFact $history, array $siblings): ?string
    {
        if ($extractor !== null && trim((string) ($extractor->docblockPurpose ?? '')) !== '') {
            return $extractor->docblockPurpose;
        }
        if ($history !== null && trim($this->mostRecentSubject($history)) !== '') {
            return $this->mostRecentSubject($history);
        }
        foreach ($siblings as $sibling) {
            $text = is_array($sibling) ? (string) ($sibling['purpose'] ?? '') : (string) $sibling;
            if (trim($text) !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        preg_match_all('/[a-z]+/', strtolower($text), $matches);

        $set = [];
        foreach ($matches[0] as $token) {
            if (strlen($token) >= 3 && ! in_array($token, self::STOPWORDS, true)) {
                $set[$token] = true;
            }
        }

        return array_keys($set);
    }
}
