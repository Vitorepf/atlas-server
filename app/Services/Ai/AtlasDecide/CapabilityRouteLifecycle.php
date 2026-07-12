<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use InvalidArgumentException;

/**
 * Causal lifecycle around a cleared capability route.
 * Selection belongs to CapabilityMarketClearingService; this class owns only
 * preregistration, evidence-bound transitions and intent-to-treat accounting.
 */
final class CapabilityRouteLifecycle
{
    public const SCHEMA = 'atlas.capability_route.lifecycle.v1';

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'shadow' => ['limited_traffic'],
        'limited_traffic' => ['causal_evaluation'],
        'causal_evaluation' => ['promoted'],
        'promoted' => ['revoked'],
        'revoked' => [],
    ];

    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    public function preregister(array $assignment): array
    {
        foreach (['route_id', 'workspace_id', 'metric', 'provider_version'] as $field) {
            if (trim((string) ($assignment[$field] ?? '')) === '') {
                throw new InvalidArgumentException('capability_assignment_'.$field.'_required');
            }
        }
        foreach (['snapshot_hash', 'order_hash'] as $field) {
            $this->requireHash($assignment[$field] ?? null, 'capability_assignment_'.$field.'_invalid');
        }
        if (! is_array($assignment['arms'] ?? null) || count($assignment['arms']) < 2) {
            throw new InvalidArgumentException('capability_assignment_arms_required');
        }
        $this->validateComparableArms($assignment);
        if (! is_array($assignment['observation_window'] ?? null)
            || trim((string) ($assignment['observation_window']['from'] ?? '')) === ''
            || trim((string) ($assignment['observation_window']['until'] ?? '')) === '') {
            throw new InvalidArgumentException('capability_assignment_observation_window_required');
        }

        $core = $this->core($assignment);
        return array_merge($core, [
            'schema' => self::SCHEMA,
            'state' => 'shadow',
            'assignment_hash' => CanonicalKernelPayload::hash($core),
            'history' => [['state' => 'shadow', 'reason' => 'preregistered']],
            'claim_eligible' => false,
        ]);
    }

    /** @param array<string,mixed> $assignment @param array<string,mixed> $evidence @return array<string,mixed> */
    public function advance(array $assignment, string $target, array $evidence): array
    {
        $this->assertAssignmentIntegrity($assignment);
        $current = (string) ($assignment['state'] ?? '');
        if (! in_array($target, self::TRANSITIONS[$current] ?? [], true)) {
            if ($target === 'promoted') {
                return $this->held($assignment, array_values(array_unique(array_merge(
                    ['causal_evaluation_required'],
                    $this->promotionBlockers($evidence),
                ))));
            }

            return $this->held($assignment, ['illegal_transition']);
        }

        $blockers = match ($target) {
            'limited_traffic' => ($evidence['real_execution'] ?? false) === true ? [] : ['real_execution_required'],
            'causal_evaluation' => ($evidence['outcome_observed'] ?? false) === true ? [] : ['outcome_observed_required'],
            'promoted' => $this->promotionBlockers($evidence),
            'revoked' => ($evidence['late_regression'] ?? false) === true
                ? ($this->validHashOrBlocker($evidence['regression_outcome_hash'] ?? null, 'regression_outcome_hash_invalid'))
                : ['late_regression_required'],
            default => ['unknown_transition'],
        };
        if ($blockers !== []) {
            return $this->held($assignment, $blockers);
        }

        $next = $assignment;
        $next['state'] = $target;
        $next['history'] = array_values(array_merge((array) ($assignment['history'] ?? []), [[
            'state' => $target,
            'evidence' => $evidence,
        ]]));
        $next['claim_eligible'] = false;
        $next['transition_hash'] = CanonicalKernelPayload::hash([
            'assignment_hash' => $assignment['assignment_hash'], 'from' => $current,
            'to' => $target, 'evidence' => $evidence,
        ]);
        $next['status'] = 'advanced';
        if ($target === 'revoked') {
            $next['reasons'] = ['late_regression'];
        }

        return $next;
    }

    /** @param list<array<string,mixed>> $outcomes @return array<string,mixed> */
    public function summarizeOutcomes(array $outcomes): array
    {
        $denominator = count($outcomes);
        $failureCount = count(array_filter($outcomes, static fn (array $outcome): bool => ($outcome['status'] ?? null) !== 'success'));

        return [
            'schema' => self::SCHEMA,
            'denominator' => $denominator,
            'success_count' => $denominator - $failureCount,
            'failure_count' => $failureCount,
            'intent_to_treat' => true,
            'claim_eligible' => false,
        ];
    }

    /** @param array<string,mixed> $assignment */
    private function assertAssignmentIntegrity(array $assignment): void
    {
        $hash = (string) ($assignment['assignment_hash'] ?? '');
        if ($hash === '' || ! hash_equals($hash, CanonicalKernelPayload::hash($this->core($assignment)))) {
            throw new InvalidArgumentException('capability_assignment_mutated_after_preregistration');
        }
    }

    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    private function core(array $assignment): array
    {
        $core = $assignment;
        unset($core['schema'], $core['state'], $core['assignment_hash'], $core['history'], $core['claim_eligible'], $core['status'], $core['transition_hash'], $core['reasons']);

        return CanonicalKernelPayload::normalize($core);
    }

    /** @param array<string,mixed> $assignment @param list<string> $blockers @return array<string,mixed> */
    private function held(array $assignment, array $blockers): array
    {
        return array_merge($assignment, [
            'status' => 'held', 'blockers' => array_values(array_unique($blockers)), 'claim_eligible' => false,
        ]);
    }

    /** @param array<string,mixed> $evidence @return list<string> */
    private function promotionBlockers(array $evidence): array
    {
        $blockers = [];
        if (($evidence['causal_evaluation'] ?? false) !== true) $blockers[] = 'causal_evaluation_required';
        if (($evidence['real_outcome'] ?? false) !== true) $blockers[] = 'real_outcome_required';
        if (($evidence['rollback'] ?? '') === '') $blockers[] = 'rollback_required';
        if (($evidence['best_run_only'] ?? false) === true) $blockers[] = 'intent_to_treat_required';
        if (($evidence['provider_specific_fork'] ?? false) === true) $blockers[] = 'shared_provider_port_required';
        $blockers = array_merge($blockers, $this->validHashOrBlocker($evidence['outcome_hash'] ?? null, 'outcome_hash_invalid'));

        return $blockers;
    }

    /** @return list<string> */
    private function validHashOrBlocker(mixed $value, string $blocker): array
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? [] : [$blocker];
    }

    private function requireHash(mixed $value, string $error): string
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    /** @param array<string,mixed> $assignment */
    private function validateComparableArms(array $assignment): void
    {
        $arms = array_values(array_unique(array_map('strval', $assignment['arms'])));
        if (count($arms) !== count($assignment['arms'])) {
            throw new InvalidArgumentException('capability_assignment_arms_must_be_distinct');
        }

        $specs = $assignment['arm_specs'] ?? null;
        if (! is_array($specs) || count($specs) !== count($arms)) {
            throw new InvalidArgumentException('capability_assignment_arm_specs_required');
        }

        $byArm = [];
        foreach ($specs as $spec) {
            if (! is_array($spec) || trim((string) ($spec['arm_id'] ?? '')) === '') {
                throw new InvalidArgumentException('capability_assignment_arm_spec_invalid');
            }
            $armId = (string) $spec['arm_id'];
            if (isset($byArm[$armId]) || ! in_array($armId, $arms, true)) {
                throw new InvalidArgumentException('capability_assignment_arm_spec_mismatch');
            }
            $this->requireHash($spec['snapshot_hash'] ?? null, 'capability_assignment_arm_snapshot_invalid');
            if (! is_array($spec['resources'] ?? null)) {
                throw new InvalidArgumentException('capability_assignment_arm_resources_required');
            }
            foreach (['model_version', 'provider_version', 'harness', 'adapter_port'] as $field) {
                if (trim((string) ($spec[$field] ?? '')) === '') {
                    throw new InvalidArgumentException('capability_assignment_arm_'.$field.'_required');
                }
            }
            $byArm[$armId] = $spec;
        }

        if (count($byArm) !== count($arms)) {
            throw new InvalidArgumentException('capability_assignment_arm_spec_mismatch');
        }
        $first = reset($byArm);
        foreach ($byArm as $spec) {
            if ($spec['snapshot_hash'] !== $first['snapshot_hash']) {
                throw new InvalidArgumentException('capability_assignment_unequal_snapshots');
            }
            if (CanonicalKernelPayload::normalize($spec['resources']) !== CanonicalKernelPayload::normalize($first['resources'])) {
                throw new InvalidArgumentException('capability_assignment_unequal_resources');
            }
            if ($spec['adapter_port'] !== $first['adapter_port']) {
                throw new InvalidArgumentException('capability_assignment_provider_specific_fork');
            }
        }

        if (! in_array('bare', array_map(static fn (array $spec): string => (string) $spec['harness'], $byArm), true)) {
            throw new InvalidArgumentException('capability_assignment_bare_control_required');
        }
    }
}
