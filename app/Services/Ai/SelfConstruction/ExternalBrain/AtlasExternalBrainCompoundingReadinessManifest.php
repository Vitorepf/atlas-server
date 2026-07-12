<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Honest projection for the causal-compounding fixture. It records live refs and
 * keeps temporal/comparative gaps explicit; it cannot issue claims or promote a wave.
 */
final class AtlasExternalBrainCompoundingReadinessManifest
{
    public const SCHEMA = 'atlas.external_brain.compounding_readiness_manifest.v1';

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function evaluate(array $input): array
    {
        $fixtures = array_values(array_filter((array) ($input['fixtures'] ?? []), 'is_array'));
        $fixtureFailures = [];
        $fixtureRefs = [];
        foreach ($fixtures as $fixture) {
            $id = trim((string) ($fixture['id'] ?? $fixture['name'] ?? 'fixture'));
            if (($fixture['passed'] ?? false) !== true) $fixtureFailures[] = $id;
            $fixtureRefs = array_merge($fixtureRefs, array_map('strval', (array) ($fixture['evidence_refs'] ?? [])));
        }
        $liveRefs = array_values(array_unique(array_filter(
            array_map('strval', (array) ($input['live_evidence_refs'] ?? $fixtureRefs)),
            static fn (string $ref): bool => trim($ref) !== '',
        )));
        $temporalGaps = array_values(array_filter(array_map('strval', (array) ($input['temporal_gaps'] ?? [])), static fn (string $gap): bool => trim($gap) !== ''));
        $comparativeGaps = array_values(array_filter(array_map('strval', (array) ($input['comparative_gaps'] ?? [])), static fn (string $gap): bool => trim($gap) !== ''));
        if (($input['outcome_windows_elapsed'] ?? false) !== true && $temporalGaps === []) $temporalGaps[] = 'real_0h_150d_outcome_window_not_elapsed';
        if ((int) ($input['real_campaigns'] ?? 0) < 3 && $comparativeGaps === []) $comparativeGaps[] = 'three_real_comparative_campaigns_missing';

        $blockers = [];
        if ($fixtures === []) $blockers[] = 'longitudinal_fixtures_missing';
        if ($fixtureFailures !== []) $blockers[] = 'fixture_failures_present';
        if ($liveRefs === []) $blockers[] = 'live_evidence_refs_missing';
        $implemented = $blockers === [];
        $status = ! $implemented ? 'blocked' : (($temporalGaps !== [] || $comparativeGaps !== []) ? 'implemented_not_proven' : 'campaign_ready');

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'implemented_fixture_proof' => $implemented,
            'fixture_failures' => $fixtureFailures,
            'live_evidence_refs' => $liveRefs,
            'temporal_gaps' => $temporalGaps,
            'comparative_gaps' => $comparativeGaps,
            'claim_allowed' => false,
            'wave_promotion_allowed' => false,
            'mutates_claims_or_routes' => false,
            'blockers' => $blockers,
        ];
    }
}
