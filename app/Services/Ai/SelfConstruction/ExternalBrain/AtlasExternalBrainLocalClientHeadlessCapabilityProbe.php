<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Classifies whether an installed subscription client can run as a reliable
 * headless muscle for Hermes or Atlas without using paid model APIs. This
 * probe only judges supplied facts — it never launches the client, never
 * calls the network, and never reads credentials.
 *
 * Check order (every failing condition is reported, not just the first):
 *   !binary_exists                     -> binary_missing
 *   !login_state_known                 -> missing_login_state
 *   !print_mode_supported OR
 *   !noninteractive_prompt_supported   -> ui_only_client
 *   !patch_output_supported            -> patch_output_unsupported
 *   !workspace_scoping_supported       -> workspace_scoping_unsupported
 *   paid_api_required=true             -> paid_api_required
 *
 * ready_for_headless_trial=true only when paid_api_required=false and every
 * required headless fact is true (no blockers present).
 *
 * interaction_mode distinguishes an interactive-only client from one that can run a
 * repeatable headless task loop with evidence:
 *   interactive_only                       — ui_only_client blocker present
 *   headless_repeatable_loop_with_evidence  — headless AND loop_execution_supported
 *                                             AND reporting_supported AND proof_capture_supported
 *   headless_single_shot_only               — headless but missing loop/reporting/proof capture
 *
 * safe_use_class (AC4 fail-closed autonomy gate): a subscription client is only ever
 * classified autonomous_muscle when ready_for_headless_trial=true AND
 * loop_execution_supported AND reporting_supported AND proof_capture_supported are ALL
 * true — missing any one of those keeps it at supervised_accelerator at best, no matter
 * how capable the client otherwise looks.
 *   not_usable          — binary_missing or missing_login_state
 *   manual_only          — interaction_mode=interactive_only
 *   supervised_accelerator — headless but loop/reporting/proof capture incomplete, or
 *                            blocked by patch_output/workspace_scoping/paid_api
 *   autonomous_muscle     — ready_for_headless_trial AND full loop+reporting+proof capture
 *
 * INPUT:
 *   binary_exists?:                  bool (default false)
 *   login_state_known?:              bool (default false)
 *   print_mode_supported?:           bool (default false)
 *   noninteractive_prompt_supported?: bool (default false)
 *   patch_output_supported?:         bool (default false)
 *   workspace_scoping_supported?:    bool (default false)
 *   paid_api_required?:              bool (default false)
 *   loop_execution_supported?:       bool (default false)
 *   reporting_supported?:            bool (default false)
 *   proof_capture_supported?:        bool (default false)
 *
 * OUTPUT:
 *   { schema, status, facts, blockers, blocker_count,
 *     ready_for_headless_trial, provider_call_allowed=false,
 *     token_spend_allowed=false, adapter_execution_allowed=false,
 *     interaction_mode, safe_use_class, capability, limitation,
 *     required_manual_surface, expected_failure_mode }
 *
 * Pure: no I/O, no network calls, no credential reads, no side effects.
 */
final class AtlasExternalBrainLocalClientHeadlessCapabilityProbe
{
    public const SCHEMA = 'atlas.external_brain.local_client_headless_capability_probe.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function probe(array $input): array
    {
        $facts = [
            'binary_exists' => (bool) ($input['binary_exists'] ?? false),
            'login_state_known' => (bool) ($input['login_state_known'] ?? false),
            'print_mode_supported' => (bool) ($input['print_mode_supported'] ?? false),
            'noninteractive_prompt_supported' => (bool) ($input['noninteractive_prompt_supported'] ?? false),
            'patch_output_supported' => (bool) ($input['patch_output_supported'] ?? false),
            'workspace_scoping_supported' => (bool) ($input['workspace_scoping_supported'] ?? false),
            'paid_api_required' => (bool) ($input['paid_api_required'] ?? false),
            'loop_execution_supported' => (bool) ($input['loop_execution_supported'] ?? false),
            'reporting_supported' => (bool) ($input['reporting_supported'] ?? false),
            'proof_capture_supported' => (bool) ($input['proof_capture_supported'] ?? false),
        ];

        $blockers = [];
        if (! $facts['binary_exists']) {
            $blockers[] = 'binary_missing';
        }
        if (! $facts['login_state_known']) {
            $blockers[] = 'missing_login_state';
        }
        if (! $facts['print_mode_supported'] || ! $facts['noninteractive_prompt_supported']) {
            $blockers[] = 'ui_only_client';
        }
        if (! $facts['patch_output_supported']) {
            $blockers[] = 'patch_output_unsupported';
        }
        if (! $facts['workspace_scoping_supported']) {
            $blockers[] = 'workspace_scoping_unsupported';
        }
        if ($facts['paid_api_required']) {
            $blockers[] = 'paid_api_required';
        }

        $ready = $blockers === [];

        // UI fragility: even a client that passes every hard blocker can still be a print-mode
        // wrapper over an interactive UI — name the exact facts that make headless output brittle
        // so callers can weigh trial risk before routing real muscle work to it.
        $fragilitySignals = [];
        if (! $facts['print_mode_supported']) {
            $fragilitySignals[] = 'no_print_mode';
        }
        if (! $facts['noninteractive_prompt_supported']) {
            $fragilitySignals[] = 'no_noninteractive_prompt';
        }
        if (! $facts['patch_output_supported']) {
            $fragilitySignals[] = 'no_patch_output';
        }
        if (! $facts['workspace_scoping_supported']) {
            $fragilitySignals[] = 'no_workspace_scoping';
        }
        $uiFragilityRisk = match (true) {
            count($fragilitySignals) >= 3 => 'high',
            count($fragilitySignals) >= 1 => 'medium',
            default => 'low',
        };

        $uiOnly = in_array('ui_only_client', $blockers, true);
        $fullLoopCapability = $facts['loop_execution_supported']
            && $facts['reporting_supported']
            && $facts['proof_capture_supported'];

        $interactionMode = match (true) {
            $uiOnly => 'interactive_only',
            $fullLoopCapability => 'headless_repeatable_loop_with_evidence',
            default => 'headless_single_shot_only',
        };

        $notUsable = in_array('binary_missing', $blockers, true) || in_array('missing_login_state', $blockers, true);

        $safeUseClass = match (true) {
            $notUsable => 'not_usable',
            $uiOnly => 'manual_only',
            $ready && $fullLoopCapability => 'autonomous_muscle',
            default => 'supervised_accelerator',
        };

        [$capability, $limitation, $requiredManualSurface, $expectedFailureMode] = match ($safeUseClass) {
            'not_usable' => [
                'none',
                'binary missing or login state unknown',
                'install and authenticate the client, then re-probe',
                'any invocation fails immediately with no usable output',
            ],
            'manual_only' => [
                'usable only through its interactive UI',
                'no print mode or no noninteractive prompt support',
                'a human must drive every interaction manually',
                'unattended automation attempts hang waiting on interactive input',
            ],
            'autonomous_muscle' => [
                'runs repeatable headless task loops end-to-end with reporting and proof capture',
                'still bounded by whatever workspace scope and patch permissions are granted',
                'none for routine loop execution; human review remains for merge and release decisions',
                'silent drift if loop, reporting or proof capture is later revoked without re-probing',
            ],
            default => [
                'executes individual headless tasks but cannot be trusted to loop, report or prove work unattended',
                'missing loop_execution_supported, reporting_supported or proof_capture_supported (or blocked on patch/workspace/paid-api facts)',
                'a human or governance layer must review each output and re-trigger the next task',
                'unattended runs stall silently or produce unproven output with no evidence trail',
            ],
        };

        return [
            'schema' => self::SCHEMA,
            'status' => $ready ? 'ready_for_headless_trial' : 'blocked',
            'facts' => $facts,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'ready_for_headless_trial' => $ready,
            'ui_fragility_risk' => $uiFragilityRisk,
            'ui_fragility_signals' => $fragilitySignals,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'interaction_mode' => $interactionMode,
            'safe_use_class' => $safeUseClass,
            'capability' => $capability,
            'limitation' => $limitation,
            'required_manual_surface' => $requiredManualSurface,
            'expected_failure_mode' => $expectedFailureMode,
        ];
    }
}
