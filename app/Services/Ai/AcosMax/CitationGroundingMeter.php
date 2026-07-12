<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

final class CitationGroundingMeter
{
    public const SCHEMA_VERSION = 'atlas.context.citation_grounding.v1';

    /**
     * @param  list<array<string,mixed>>  $responses
     * @return array<string,mixed>
     */
    public static function measure(array $responses): array
    {
        $withCitation = 0;
        $grounded = 0;
        $cited = 0;
        $unsupported = 0;

        foreach ($responses as $response) {
            $delivered = array_fill_keys(array_map('strval', (array) ($response['delivered_refs'] ?? [])), true);
            $refs = self::refs((string) ($response['response'] ?? ''));
            if ($refs !== []) {
                $withCitation++;
            }
            foreach ($refs as $ref) {
                $cited++;
                if (isset($delivered[$ref])) {
                    $grounded++;
                } else {
                    $unsupported++;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $cited === 0 ? 'insufficient_signal' : 'ok',
            'grounding_rate' => $cited === 0 ? null : round($grounded / $cited, 4),
            'citation_coverage' => count($responses) === 0 ? 0.0 : round($withCitation / count($responses), 4),
            'unsupported_citation_count' => $unsupported,
            'measured_count' => $withCitation,
            'total' => count($responses),
            'source' => [
                'fuses_grounding_and_coverage' => false,
                'provider_calls_made' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function refs(string $text): array
    {
        preg_match_all('/(?:ref=)?((?:code|memory|graph|sym):[A-Za-z0-9._:-]+)/', $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
