<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

/**
 * MAXI-04 default port \u2014 char-bigram Jaccard similarity over normalized text.
 *
 * Not a real embedding: the plan calls this "the honest baseline lexical arm"
 * that carries the mechanism until the MAXA-01 daemon-backed port replaces it.
 * Pure PHP, deterministic, zero I/O, zero provider. Nothing leaves the machine.
 */
final class BigramJaccardImmuneSemanticSimilarityPort implements ImmuneSemanticSimilarityPort
{
    public const INT_2 = 2;
    public function similarity(string $candidate, string $exemplar): ?float
    {
        $a = self::bigrams($candidate);
        $b = self::bigrams($exemplar);

        if ($a === [] || $b === []) {
            return 0.0;
        }

        $setA = array_flip($a);
        $setB = array_flip($b);
        $intersection = array_intersect_key($setA, $setB);
        $union = $setA + $setB;

        return count($union) === 0
            ? 0.0
            : round(count($intersection) / count($union), 6);
    }

    /**
     * @return list<string>
     */
    private static function bigrams(string $text): array
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';
        $length = mb_strlen($normalized);
        if ($length < self::INT_2) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $length - 1; $i++) {
            $out[] = mb_substr($normalized, $i, 2);
        }

        return $out;
    }
}
