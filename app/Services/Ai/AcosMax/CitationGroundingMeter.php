<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class CitationGroundingMeter
{
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_RESPONSE = 'response';
    public const SCHEMA_VERSION = 'atlas.context.citation_grounding.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';
    public const FIELD_CITATION_COVERAGE = 'citation_coverage';
    public const FIELD_DELIVERED_REFS = 'delivered_refs';
    public const FIELD_FUSES_GROUNDING_AND_COVERAGE = 'fuses_grounding_and_coverage';
    public const FIELD_GROUNDING_RATE = 'grounding_rate';
    public const FIELD_UNSUPPORTED_CITATION_COUNT = 'unsupported_citation_count';
    public const FIELD_MEASURED_COUNT = 'measured_count';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SOURCE = 'source';
    public const FIELD_STATUS = 'status';
    public const FIELD_TOTAL = 'total';
    public const FIELD_STRVAL = 'strval';

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
            $delivered = array_fill_keys(array_map(self::FIELD_STRVAL, AiValueNormalizer::arrayOrEmpty($response[self::FIELD_DELIVERED_REFS] ?? null)), true);
            $refs = self::refs(AiValueNormalizer::trimmedStringOrNull($response[self::FIELD_RESPONSE] ?? null) ?? '');
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $cited === 0 ? self::STATUS_INSUFFICIENT_SIGNAL : self::STATUS_OK,
            self::FIELD_GROUNDING_RATE => $cited === 0 ? null : round($grounded / $cited, 4),
            self::FIELD_CITATION_COVERAGE => count($responses) === 0 ? 0.0 : round($withCitation / count($responses), 4),
            self::FIELD_UNSUPPORTED_CITATION_COUNT => $unsupported,
            self::FIELD_MEASURED_COUNT => $withCitation,
            self::FIELD_TOTAL => count($responses),
            self::FIELD_SOURCE => [
                self::FIELD_FUSES_GROUNDING_AND_COVERAGE => false,
                self::FIELD_PROVIDER_CALLS_MADE => false,
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
