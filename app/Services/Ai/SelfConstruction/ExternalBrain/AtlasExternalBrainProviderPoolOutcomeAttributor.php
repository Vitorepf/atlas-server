<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Attributes pool outcomes to individual providers so the independence proof
 * can identify which provider contributed to each outcome.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainProviderPoolOutcomeAttributor
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_outcome_attributor.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function attribute(array $input): array
    {
        $outcomes = (array) ($input['outcomes'] ?? []);
        $attributed = [];

        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }
            $provider = (string) ($outcome['provider'] ?? '');
            $result = (string) ($outcome['result'] ?? 'unknown');
            $attributed[] = [
                'provider' => $provider,
                'result' => $result,
                'attributed' => $provider !== '',
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'attributed_outcomes' => $attributed,
            'total_outcomes' => count($attributed),
        ];
    }
}
