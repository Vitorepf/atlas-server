<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\ClaimCoherence;

final class HedgeCertaintyConflictDetector
{
    private const SCHEMA_VERSION = 'atlas.cognitive.claim_coherence.hedge_certainty_conflict.v1';

    private const HEDGE = ['maybe', 'possibly', 'might', 'perhaps', 'probably'];

    private const ABSOLUTE = ['always', 'never', 'definitely', 'certainly', 'guaranteed'];

    private const CONFLICT_RATIO_THRESHOLD = 0.25;

    /**
     * @param  array<int, mixed>  $tokens
     * @return array<string, mixed>
     */
    public function detect(array $tokens): array
    {
        $normalized = $this->normalize($tokens);

        $hedgeCount = $this->countMembers($normalized, self::HEDGE);
        $absoluteCount = $this->countMembers($normalized, self::ABSOLUTE);

        $ratio = round(
            min($hedgeCount, $absoluteCount) / max(1, $hedgeCount + $absoluteCount),
            3,
        );

        $conflict = $hedgeCount >= 1
            && $absoluteCount >= 1
            && $ratio >= self::CONFLICT_RATIO_THRESHOLD;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'hedgeCount' => $hedgeCount,
            'absoluteCount' => $absoluteCount,
            'ratio' => $ratio,
            'conflict' => $conflict,
            'status' => $conflict ? 'conflict' : 'clean',
        ];
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @return array<int, string>
     */
    private function normalize(array $tokens): array
    {
        return array_map(
            static fn (mixed $token): string => strtolower(trim((string) $token)),
            array_values($tokens),
        );
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<int, string>  $lexicon
     */
    private function countMembers(array $tokens, array $lexicon): int
    {
        $count = 0;

        foreach ($tokens as $token) {
            $count += in_array($token, $lexicon, true) ? 1 : 0;
        }

        return $count;
    }
}
