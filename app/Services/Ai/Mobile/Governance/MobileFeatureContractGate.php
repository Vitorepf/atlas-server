<?php

declare(strict_types=1);

namespace App\Services\Ai\Mobile\Governance;

/**
 * Mobile gateway — Feature Contract Gate.
 *
 * Closes the matrix gap "Mobile gateway base: UX mobile final e contratos
 * por feature ainda precisam maturidade". The mobile gateway already has
 * routes and APIs; this gate adds the per-feature contract enforcement
 * the matrix asks for.
 *
 * Each mobile feature MUST declare:
 *   - canonical schema_version for its API surface
 *   - privacy class (`personal|operational|aggregate`)
 *   - eclipse/presence behaviour
 *   - offline degradation contract
 *   - push policy (proactive vs notification-only)
 *   - safety boundary (which actions are operator-confirmed)
 *
 * Mobile features that don't declare these can still ship in CLI/desktop
 * but NOT in the mobile surface — the gate rejects deployment.
 */
final class MobileFeatureContractGate
{
    public const SCHEMA_VERSION = 'atlas.mobile.feature_contract_gate.v1';

    public const ALLOWED_PRIVACY_CLASSES = ['personal', 'operational', 'aggregate'];

    public const ALLOWED_PUSH_POLICIES = ['proactive', 'notification_only', 'silent_only', 'no_push'];

    public const REQUIRED_FIELDS = [
        'feature_id',
        'schema_version',
        'privacy_class',
        'eclipse_behaviour',
        'offline_contract',
        'push_policy',
        'safety_boundary',
    ];

    /**
     * @param  array<string,mixed>  $feature
     * @return array{
     *   schema_version: string,
     *   feature_id: ?string,
     *   gate_decision: string,
     *   passed_checks: list<string>,
     *   failed_checks: array<string,string>,
     *   detail: string,
     *   evaluated_at: string
     * }
     */
    public function evaluate(array $feature): array
    {
        $passed = [];
        $failed = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $feature[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $failed[$field] = "required field '{$field}' missing";
            } else {
                $passed[] = $field.'_present';
            }
        }

        $privacy = (string) ($feature['privacy_class'] ?? '');
        if ($privacy !== '' && ! in_array($privacy, self::ALLOWED_PRIVACY_CLASSES, true)) {
            $failed['privacy_class'] = sprintf(
                'invalid "%s"; must be one of [%s]',
                $privacy,
                implode(',', self::ALLOWED_PRIVACY_CLASSES),
            );
        } elseif ($privacy !== '') {
            $passed[] = 'privacy_class_valid';
        }

        $push = (string) ($feature['push_policy'] ?? '');
        if ($push !== '' && ! in_array($push, self::ALLOWED_PUSH_POLICIES, true)) {
            $failed['push_policy'] = sprintf(
                'invalid "%s"; must be one of [%s]',
                $push,
                implode(',', self::ALLOWED_PUSH_POLICIES),
            );
        } elseif ($push !== '') {
            $passed[] = 'push_policy_valid';
        }

        $safety = (array) ($feature['safety_boundary'] ?? []);
        $operatorConfirmed = (array) ($safety['operator_confirmed_actions'] ?? []);
        // For features with privacy_class=personal, MUST have eclipse behaviour declared as eclipsing-aware
        if ($privacy === 'personal') {
            $eclipse = (string) ($feature['eclipse_behaviour'] ?? '');
            if ($eclipse === '' || $eclipse === 'ignore') {
                $failed['eclipse_behaviour'] = 'personal-privacy features must declare eclipse-aware behaviour (not "ignore")';
            } else {
                $passed[] = 'personal_privacy_eclipse_aware';
            }
        }

        // Proactive push always requires explicit user-consent gate
        if ($push === 'proactive' && ($feature['user_consent_required'] ?? null) !== true) {
            $failed['user_consent_required'] = 'proactive push requires user_consent_required=true';
        } elseif ($push === 'proactive') {
            $passed[] = 'proactive_push_user_consent_declared';
        }

        // Offline contract must declare degradation mode
        $offline = (array) ($feature['offline_contract'] ?? []);
        $degradation = $offline['degradation_mode'] ?? null;
        if (is_array($offline) && $offline !== [] && ! is_string($degradation)) {
            $failed['offline_contract.degradation_mode'] = 'must declare degradation_mode (read_only|queue|fail_fast)';
        } elseif (is_string($degradation)) {
            $passed[] = 'offline_degradation_declared';
        }

        $decision = $failed === [] ? 'mobile_deployment_approved' : 'mobile_deployment_blocked';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'feature_id' => isset($feature['feature_id']) && is_string($feature['feature_id']) ? $feature['feature_id'] : null,
            'gate_decision' => $decision,
            'passed_checks' => $passed,
            'failed_checks' => $failed,
            'detail' => $decision === 'mobile_deployment_approved'
                ? sprintf('Feature "%s" approved for mobile surface.', $feature['feature_id'] ?? 'unknown')
                : sprintf('Mobile deployment blocked: %d check(s) failed.', count($failed)),
            'evaluated_at' => now()->toAtomString(),
        ];
    }
}
