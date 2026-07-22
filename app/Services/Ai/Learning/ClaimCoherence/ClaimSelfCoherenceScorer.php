<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\ClaimCoherence;

final class ClaimSelfCoherenceScorer
{
    private const SCHEMA_VERSION = 'atlas.cognitive.claim_coherence.self_coherence.v1';

    /**
     * @return array{schema_version: string, coherence: float, penalties: list<string>, status: string}
     */
    public function score(
        string $subject,
        string $predicate,
        string $qualifier,
        float $assertedConfidence,
        int $evidenceRefCount
    ): array {
        $coherence = 1.0;
        $penalties = [];

        if ($assertedConfidence >= 0.8 && $evidenceRefCount === 0) {
            $coherence -= 0.5;
            $penalties[] = 'confidence_without_evidence';
        }

        if ($this->hasHedgeToken($qualifier) && $assertedConfidence >= 0.8) {
            $coherence -= 0.3;
            $penalties[] = 'hedge_with_high_confidence';
        }

        if (trim($subject) === '' || trim($predicate) === '') {
            $coherence -= 0.4;
            $penalties[] = 'empty_term';
        }

        if ($this->subjectAppearsInPredicate($subject, $predicate)) {
            $coherence = min(1.0, $coherence + 0.1);
        }

        $coherence = round(max(0.0, min(1.0, $coherence)), 3);

        $status = match (true) {
            $coherence >= 0.7 => 'coherent',
            $coherence >= 0.4 => 'weak',
            default => 'incoherent',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'coherence' => $coherence,
            'penalties' => $penalties,
            'status' => $status,
        ];
    }

    private function hasHedgeToken(string $qualifier): bool
    {
        $lowered = strtolower($qualifier);

        foreach (['maybe', 'possibly', 'might'] as $token) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $lowered) === 1) {
                return true;
            }
        }

        return false;
    }

    private function subjectAppearsInPredicate(string $subject, string $predicate): bool
    {
        $needle = trim(strtolower($subject));

        if ($needle === '') {
            return false;
        }

        return preg_match('/\b' . preg_quote($needle, '/') . '\b/', strtolower($predicate)) === 1;
    }
}
