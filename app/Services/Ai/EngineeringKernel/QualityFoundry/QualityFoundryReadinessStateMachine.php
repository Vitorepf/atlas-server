<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;

/**
 * Separates implementation, cutover, temporal and comparative truth.
 *
 * A green implementation manifest is not allowed to manufacture a cutover,
 * soak or world claim. All inputs are evidence facts supplied by existing
 * owners; this class only adjudicates the projection and never writes claims.
 */
final class QualityFoundryReadinessStateMachine
{
    public const SCHEMA = 'atlas.quality_foundry.readiness_state_machine.v1';

    /** @var list<string> */
    private const WINDOWS = ['0h', '24h', '7d', '30d', '90d', '150d'];

    /** @var list<string> */
    private const CUTOVER_GATES = [
        'p0_13_closed',
        'p0_19_closed',
        'vertical_e2e',
        'mutative_coverage_100',
        'four_manifests',
        'n_minus_1_compatible',
        'rollback_exercised',
        'outcome_writers_active',
        'zero_known_bypass',
    ];

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(array $evidence): array
    {
        $implementation = $this->implementationState($evidence);
        $cutover = $this->cutoverState($evidence, $implementation);
        $temporal = $this->temporalState($evidence);
        $comparative = $this->comparativeState($evidence);

        $payload = [
            'schema' => self::SCHEMA,
            'implementation_state' => $implementation['state'],
            'cutover_state' => $cutover['state'],
            'temporal_state' => $temporal['state'],
            'comparative_state' => $comparative['state'],
            'highest_honest_state' => $cutover['state'] === 'cutover_ready'
                ? ($temporal['state'] === 'pending' ? 'cutover_ready' : $temporal['state'])
                : $implementation['state'],
            'quality_foundry_ready' => false,
            'multiplier_proven' => false,
            'world_leading' => false,
            'world_10x_quality_proven' => false,
            'claim_eligible' => false,
            'cutover_performed' => ($evidence['cutover_performed'] ?? false) === true,
            'implementation' => $implementation,
            'cutover' => $cutover,
            'temporal' => $temporal,
            'comparative' => $comparative,
            'verification_refs' => array_values((array) ($evidence['verification_refs'] ?? [])),
        ];

        if ($payload['cutover_performed']) {
            $payload['blockers'][] = 'cutover_performed_without_authorized_state_transition';
        }
        $payload['blockers'] = array_values(array_unique(array_merge(
            (array) ($implementation['blockers'] ?? []),
            (array) ($cutover['blockers'] ?? []),
            (array) ($temporal['blockers'] ?? []),
            (array) ($comparative['blockers'] ?? []),
            (array) ($payload['blockers'] ?? []),
        )));
        $payload['status'] = $payload['blockers'] === [] ? 'ready' : 'blocked';
        $payload['readiness_hash'] = CanonicalKernelPayload::hash($payload);

        return $payload;
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function implementationState(array $evidence): array
    {
        $implemented = ($evidence['implementation_complete'] ?? false) === true;

        return [
            'state' => $implemented ? 'implemented_not_cutover_ready' : 'implementation_incomplete',
            'blockers' => $implemented ? [] : ['implementation_incomplete'],
        ];
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function cutoverState(array $evidence, array $implementation): array
    {
        $checks = [];
        foreach (self::CUTOVER_GATES as $gate) {
            $checks[$gate] = ($evidence[$gate] ?? false) === true;
        }
        $missing = array_keys(array_filter($checks, static fn (bool $value): bool => ! $value));
        $ready = $implementation['state'] !== 'implementation_incomplete' && $missing === [];

        return [
            'state' => $ready ? 'cutover_ready' : 'implemented_not_cutover_ready',
            'checks' => $checks,
            'blockers' => array_map(static fn (string $gate): string => 'cutover_gate_missing:'.$gate, $missing),
        ];
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function temporalState(array $evidence): array
    {
        $observations = is_array($evidence['observations'] ?? null) ? $evidence['observations'] : [];
        $windows = [];
        $schedule = [];
        $last = 'pending';
        $blockers = [];
        foreach (self::WINDOWS as $window) {
            $row = is_array($observations[$window] ?? null) ? $observations[$window] : [];
            $valid = ($row['observed'] ?? false) === true
                && trim((string) ($row['receipt_hash'] ?? '')) !== ''
                && trim((string) ($row['observed_at'] ?? '')) !== '';
            $windows[$window] = $valid ? 'observed' : 'pending';
            $schedule[$window] = [
                'window' => $window,
                'status' => $valid ? 'observed' : 'pending',
                'observed_at' => $valid ? (string) $row['observed_at'] : null,
                'receipt_hash' => $valid ? (string) $row['receipt_hash'] : null,
            ];
            if (! $valid) {
                $blockers[] = 'temporal_window_pending:'.$window;
                break;
            }
            $last = $window === '0h' ? 'observed_0h' : 'observed_'.$window;
        }

        foreach (array_slice(self::WINDOWS, count($windows)) as $window) {
            $windows[$window] = 'pending';
            $schedule[$window] = [
                'window' => $window,
                'status' => 'pending',
                'observed_at' => null,
                'receipt_hash' => null,
            ];
        }

        return [
            'state' => $last,
            'windows' => $windows,
            'observation_schedule' => $schedule,
            'blockers' => $blockers,
        ];
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function comparativeState(array $evidence): array
    {
        $claim = is_array($evidence['rivals_claim'] ?? null) ? $evidence['rivals_claim'] : [];
        $valid = ($claim['issuer'] ?? null) === 'rivals'
            && ($claim['level'] ?? null) === 'world_10x_quality_proven'
            && ($claim['eligible'] ?? false) === true;

        return [
            'state' => $valid ? 'world_10x_quality_proven' : 'world_10x_quality_proof_pending',
            'blockers' => $valid ? [] : ['rivals_comparative_evidence_pending'],
            'issuer' => $valid ? 'rivals' : null,
        ];
    }
}
