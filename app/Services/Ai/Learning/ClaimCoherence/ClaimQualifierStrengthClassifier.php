<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\ClaimCoherence;

final class ClaimQualifierStrengthClassifier
{
    private const SCHEMA_VERSION = 'atlas.cognitive.claim_coherence.qualifier_strength.v1';

    private const MODALS = ['must', 'shall', 'will', 'cannot', 'required', 'mandatory', 'strictly'];

    /**
     * @param  list<string>  $qualifierTokens
     * @return array{schema_version: string, modalCount: int, band: string, status: string}
     */
    public function classify(array $qualifierTokens): array
    {
        $modalCount = 0;

        foreach ($qualifierTokens as $token) {
            $normalized = strtolower(trim($token));

            if (in_array($normalized, self::MODALS, true)) {
                $modalCount++;
            }
        }

        $band = match (true) {
            $modalCount === 0 => 'none',
            $modalCount <= 2 => 'soft',
            default => 'hard',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'modalCount' => $modalCount,
            'band' => $band,
            'status' => 'classified',
        ];
    }
}
