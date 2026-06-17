<?php

declare(strict_types=1);

namespace App\Services\Ai\VentureFoundry;

use App\Services\Ai\Strategy\OpportunityRadarService;

/**
 * Pure input-validation seam extracted from {@see VentureIdeationService::register()}.
 *
 * The original method accumulated 10+ decision points (cyclomatic 15) doing
 * shape, value-range and enum checks before the persistence boundary. Moving
 * that cohesive cluster here lets `register` orchestrate: validate → score →
 * persist. The class is final, has no state, and exposes one public entry
 * point that mirrors the original behaviour byte-for-byte.
 */
final class VentureIdeationServiceSupport
{
    /**
     * Required non-empty string fields.
     *
     * @var array<int, string>
     */
    private const REQUIRED_STRING_FIELDS = ['problem', 'icp', 'pain'];

    /**
     * 0-5 integer rating fields, validated only when present.
     *
     * @var array<int, string>
     */
    private const RATING_FIELDS = ['pain_severity', 'founder_fit', 'sovereignty_fit'];

    /**
     * Validate and normalize the raw args accepted by `register`.
     *
     * Returns a record of the cleaned scalars that `register` then feeds into
     * scoring and persistence. Pure function: no DB, no globals, no scoring.
     *
     * @param  array<string,mixed>  $args
     * @return array{
     *     title: string,
     *     problem: string,
     *     icp: string,
     *     pain: string,
     *     urgency: string,
     *     source: string,
     *     pain_severity: int,
     *     founder_fit: int,
     *     sovereignty_fit: int,
     *     market_size_usd: float|null
     * }
     */
    public function validateInputs(array $args): array
    {
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            throw VentureFoundryException::missingField('idea', 'title');
        }

        $problem = $this->requireNonEmptyString($args, 'problem');
        $icp = $this->requireNonEmptyString($args, 'icp');
        $pain = $this->requireNonEmptyString($args, 'pain');

        $urgency = (string) ($args['urgency'] ?? OpportunityRadarService::URGENCY_MEDIUM);
        if (! array_key_exists($urgency, VentureIdeationService::URGENCY_FACTOR)) {
            throw VentureFoundryException::invalidValue('idea', 'urgency', 'must be one of ['.implode(',', array_keys(VentureIdeationService::URGENCY_FACTOR)).']');
        }

        $source = (string) ($args['source'] ?? VentureIdeationService::SOURCE_OPERATOR);
        if (! in_array($source, VentureIdeationService::ALLOWED_SOURCES, true)) {
            throw VentureFoundryException::invalidValue('idea', 'source', 'must be one of ['.implode(',', VentureIdeationService::ALLOWED_SOURCES).']');
        }

        $painSeverity = $this->requireRating($args, 'pain_severity');
        $founderFit = $this->requireRating($args, 'founder_fit');
        $sovereigntyFit = $this->requireRating($args, 'sovereignty_fit');

        $marketSize = isset($args['market_size_usd']) ? (float) $args['market_size_usd'] : null;
        if ($marketSize !== null && $marketSize < 0) {
            throw VentureFoundryException::invalidValue('idea', 'market_size_usd', 'must be >= 0');
        }

        return [
            'title' => $title,
            'problem' => $problem,
            'icp' => $icp,
            'pain' => $pain,
            'urgency' => $urgency,
            'source' => $source,
            'pain_severity' => $painSeverity,
            'founder_fit' => $founderFit,
            'sovereignty_fit' => $sovereigntyFit,
            'market_size_usd' => $marketSize,
        ];
    }

    /**
     * @param  array<string,mixed>  $args
     */
    private function requireNonEmptyString(array $args, string $field): string
    {
        $value = $args[$field] ?? '';
        if (! is_string($value) || trim($value) === '') {
            throw VentureFoundryException::missingField('idea', $field);
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $args
     */
    private function requireRating(array $args, string $field): int
    {
        $value = (int) ($args[$field] ?? 3);
        if ($value < 0 || $value > 5) {
            throw VentureFoundryException::invalidValue('idea', $field, 'must be between 0 and 5');
        }

        return $value;
    }
}
