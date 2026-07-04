<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Honestly rejects or downgrades local subscription clients that only work
 * through fragile UI automation instead of reliable headless invocation.
 * Pure classifier over supplied risk facts.
 *
 * RISK FACTS (true means the risk is present):
 *   ui_only, requires_screen_focus, brittle_selector_dependency,
 *   cannot_stream_stdout, cannot_set_workspace, cannot_bound_cost,
 *   cannot_enforce_timeout
 *
 * safe_for_24_7=false whenever ANY risk fact is true, even if the client
 * may still be usable as a manual external muscle.
 *
 * RECOMMENDATION (first matching tier wins, most severe first):
 *   reject_for_autonomous_loop  <- cannot_bound_cost OR cannot_enforce_timeout
 *                                   (no safety boundary, never safe to loop)
 *   downgrade_to_manual_muscle  <- ui_only OR requires_screen_focus OR
 *                                   brittle_selector_dependency
 *                                   (fragile UI automation but boundary known)
 *   require_headless_adapter    <- cannot_stream_stdout OR cannot_set_workspace
 *                                   (headless-capable but needs adapter work)
 *   no_risk_detected             <- no risk fact is true
 *
 * Pure: no I/O, no network calls, no side effects.
 */
final class AtlasExternalBrainLocalClientUiFragilityRiskGate
{
    public const SCHEMA = 'atlas.external_brain.local_client_ui_fragility_risk_gate.v1';

    private const RISK_FACT_KEYS = [
        'ui_only',
        'requires_screen_focus',
        'brittle_selector_dependency',
        'cannot_stream_stdout',
        'cannot_set_workspace',
        'cannot_bound_cost',
        'cannot_enforce_timeout',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $facts = [];
        foreach (self::RISK_FACT_KEYS as $key) {
            $facts[$key] = (bool) ($input[$key] ?? false);
        }

        $riskReasons = array_values(array_filter(self::RISK_FACT_KEYS, static fn (string $key): bool => $facts[$key]));
        $safeFor247 = $riskReasons === [];

        $recommendation = match (true) {
            $facts['cannot_bound_cost'] || $facts['cannot_enforce_timeout'] => 'reject_for_autonomous_loop',
            $facts['ui_only'] || $facts['requires_screen_focus'] || $facts['brittle_selector_dependency'] => 'downgrade_to_manual_muscle',
            $facts['cannot_stream_stdout'] || $facts['cannot_set_workspace'] => 'require_headless_adapter',
            default => 'no_risk_detected',
        };

        // required_adapter_work: specific adapter work needed to make this client headless-safe
        $requiredAdapterWork = $this->determineRequiredAdapterWork($facts);

        return [
            'schema' => self::SCHEMA,
            'facts' => $facts,
            'risk_reasons' => $riskReasons,
            'risk_reason_count' => count($riskReasons),
            'safe_for_24_7' => $safeFor247,
            'recommendation' => $recommendation,
            'required_adapter_work' => $requiredAdapterWork,
        ];
    }

    /**
     * Determine specific adapter work required for the given risk facts.
     *
     * @param  array<string, bool>  $facts
     * @return list<string>
     */
    private function determineRequiredAdapterWork(array $facts): array
    {
        $work = [];

        if ($facts['ui_only']) {
            $work[] = 'replace_ui_automation_with_headless_api_or_cli';
        }

        if ($facts['requires_screen_focus']) {
            $work[] = 'eliminate_screen_focus_dependency_with_headless_display_or_virtual_x11';
        }

        if ($facts['brittle_selector_dependency']) {
            $work[] = 'replace_brittle_selectors_with_stable_api_endpoints_or_robust_selectors';
        }

        if ($facts['cannot_stream_stdout']) {
            $work[] = 'implement_stdout_streaming_or_file_based_output_for_headless_invocation';
        }

        if ($facts['cannot_set_workspace']) {
            $work[] = 'add_workspace_path_configuration_for_headless_invocation';
        }

        if ($facts['cannot_bound_cost']) {
            $work[] = 'implement_cost_bound_guard_or_pre_flight_cost_estimate';
        }

        if ($facts['cannot_enforce_timeout']) {
            $work[] = 'implement_timeout_enforcement_or_external_process_monitor';
        }

        return $work;
    }
}
