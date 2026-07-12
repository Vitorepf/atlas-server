<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Versioned, conjunctive readiness projection for the Quality Foundry domain
 * waves. It only evaluates supplied evidence: it never creates a claim, changes
 * a route/profile, or promotes a future wave.
 */
final class AtlasExternalBrainDomainWaveReadinessManifest
{
    public const SCHEMA = 'atlas.external_brain.domain_wave_readiness_manifest.v1';

    public const STATUS_PROMOTABLE = 'promotable';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_NOT_PROMOTED = 'not_promoted';

    /** @var array<int,string> */
    private const WAVE_IDS = [
        1 => 'wave_1',
        2 => 'wave_2',
        3 => 'wave_3',
        4 => 'wave_4',
        5 => 'wave_5',
    ];

    /** @return array<string,mixed> */
    public function evaluate(array $input): array
    {
        $requestedWave = max(1, min(5, (int) ($input['requested_wave'] ?? 1)));
        $factsByWave = is_array($input['waves'] ?? null) ? $input['waves'] : [];
        $waves = [];

        foreach (self::WAVE_IDS as $number => $waveId) {
            $facts = is_array($factsByWave[$waveId] ?? null) ? $factsByWave[$waveId] : [];
            $blockers = $this->blockers($facts);
            if ($number > $requestedWave) {
                $blockers[] = 'future_wave_not_authorized';
            }

            $blockers = array_values(array_unique($blockers));
            sort($blockers, SORT_STRING);
            $status = $number > $requestedWave
                ? self::STATUS_NOT_PROMOTED
                : ($blockers === [] ? self::STATUS_PROMOTABLE : self::STATUS_BLOCKED);

            $waves[$waveId] = [
                'wave_number' => $number,
                'version' => trim((string) ($facts['version'] ?? '')),
                'status' => $status,
                'promotion_allowed' => $status === self::STATUS_PROMOTABLE,
                'blockers' => $blockers,
                'dimensions' => $this->dimensions($facts),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'requested_wave' => $requestedWave,
            'waves' => $waves,
            'promotion_allowed' => $waves[self::WAVE_IDS[$requestedWave]]['promotion_allowed'],
            'mutates_claims_or_routes' => false,
        ];
    }

    /** @return list<string> */
    private function blockers(array $facts): array
    {
        $blockers = [];
        if (trim((string) ($facts['version'] ?? '')) === '') {
            $blockers[] = 'manifest_version_required';
        }
        if ((float) ($facts['corpus_coverage'] ?? 0) <= 0) {
            $blockers[] = 'corpus_coverage_required';
        }
        if ((int) ($facts['private_hidden_cases'] ?? 0) <= 0) {
            $blockers[] = 'private_hidden_cases_required';
        }
        if (($facts['public_only'] ?? false) === true) {
            $blockers[] = 'private_benchmark_required';
        }
        if ((int) ($facts['independent_oracles'] ?? 0) <= 0) {
            $blockers[] = 'independent_oracles_required';
        }
        if ((int) ($facts['capability_routes'] ?? 0) <= 0) {
            $blockers[] = 'capability_routes_required';
        }
        if (! is_array($facts['risk_depths'] ?? null) || $facts['risk_depths'] === []) {
            $blockers[] = 'risk_depths_required';
        }
        if (($facts['rollback_dr_proven'] ?? false) !== true) {
            $blockers[] = 'rollback_dr_required';
        }
        if (($facts['causal_multiplier_proven'] ?? false) !== true) {
            $blockers[] = 'causal_multiplier_required';
        }
        if (($facts['real_soak'] ?? false) !== true || (int) ($facts['outcome_window_days'] ?? 0) <= 0) {
            $blockers[] = 'real_soak_required';
        }
        if (($facts['rivals_evidence'] ?? false) !== true) {
            $blockers[] = 'rivals_evidence_required';
        }
        if ((int) ($facts['provider_count'] ?? 0) < 2) {
            $blockers[] = 'provider_diversity_required';
        }
        if ((float) ($facts['current_quality'] ?? 0) < (float) ($facts['prior_wave_quality'] ?? 0)) {
            $blockers[] = 'prior_wave_non_regression_failed';
        }

        return $blockers;
    }

    /** @return array<string,mixed> */
    private function dimensions(array $facts): array
    {
        return [
            'corpus_coverage' => (float) ($facts['corpus_coverage'] ?? 0),
            'private_hidden_cases' => (int) ($facts['private_hidden_cases'] ?? 0),
            'independent_oracles' => (int) ($facts['independent_oracles'] ?? 0),
            'capability_routes' => (int) ($facts['capability_routes'] ?? 0),
            'risk_depths' => array_values(array_map('strval', (array) ($facts['risk_depths'] ?? []))),
            'rollback_dr_proven' => ($facts['rollback_dr_proven'] ?? false) === true,
            'causal_multiplier_proven' => ($facts['causal_multiplier_proven'] ?? false) === true,
            'real_soak' => ($facts['real_soak'] ?? false) === true,
            'outcome_window_days' => (int) ($facts['outcome_window_days'] ?? 0),
            'rivals_evidence' => ($facts['rivals_evidence'] ?? false) === true,
            'provider_count' => (int) ($facts['provider_count'] ?? 0),
            'prior_wave_quality' => (float) ($facts['prior_wave_quality'] ?? 0),
            'current_quality' => (float) ($facts['current_quality'] ?? 0),
        ];
    }
}
