<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Defines the safe contract for invoking a local subscription client (e.g.
 * Cursor) as a replaceable muscle without granting it direct queue, git, or
 * secret authority. Atlas always remains the owner of queue state, tests,
 * report, and commits.
 *
 * Static contract (always returned):
 *   allowed_inputs, forbidden_inputs, expected_output_shape, timeout_policy,
 *   allowed_files_scope_rule, no_git_rule, no_secret_rule,
 *   atlas_report_commit_owner=true
 *
 * BLOCKERS (derived from supplied facts; any one means the contract is
 * unsafe for this invocation):
 *   raw_secret_passthrough_required        <- requires_raw_secret_passthrough
 *   broad_filesystem_authority_required    <- requires_broad_filesystem_authority
 *   direct_git_commit_rights_required      <- requires_direct_git_commit_rights
 *   direct_atlas_task_report_rights_required <- requires_direct_atlas_task_report_rights
 *   paid_api_credentials_required          <- requires_paid_api_credentials
 *   raw_credential_or_billing_field_captured:<field> <- caller passed a raw
 *     credential/token/billing-shaped field directly (e.g. api_key, token,
 *     secret, password, billing_amount) instead of routing it through the
 *     boolean requires_* facts above.
 *
 * invocation_contract_ready=true only when: the local client is replaceable,
 * scoped to allowed_files, non-authoritative over queue/git/report, and no
 * blocker is present.
 *
 * A local/subscription client is ALWAYS an optional_capability accelerator,
 * never a required steady-state dependency — Atlas must keep working with it
 * absent. Client work is only trusted once its output matches the normalized
 * expected_output_shape AND a runnable task proof command passes; a diff or
 * claim alone is never trusted.
 *
 * INPUT:
 *   replaceable?:                              bool (default false)
 *   scoped?:                                   bool (default false)
 *   non_authoritative?:                        bool (default false)
 *   requires_raw_secret_passthrough?:          bool (default false)
 *   requires_broad_filesystem_authority?:      bool (default false)
 *   requires_direct_git_commit_rights?:        bool (default false)
 *   requires_direct_atlas_task_report_rights?: bool (default false)
 *   requires_paid_api_credentials?:            bool (default false)
 *   any other top-level key resembling a raw credential/token/billing field
 *     (e.g. api_key, token, secret, password, billing_amount) is rejected.
 *
 * Pure: no I/O, no network calls, no side effects.
 */
final class AtlasExternalBrainLocalClientInvocationBoundary
{
    public const SCHEMA = 'atlas.external_brain.local_client_invocation_boundary.v1';

    /** Substrings in a top-level input key that mark it as a raw credential/billing field. */
    private const CREDENTIAL_OR_BILLING_KEY_MARKERS = [
        'token', 'api_key', 'apikey', 'secret', 'password', 'credential', 'billing', 'credit_card', 'account_number',
    ];

    /** Non-boolean input keys the caller may legitimately supply without triggering the scan. */
    private const KNOWN_SAFE_KEYS = [
        'replaceable', 'scoped', 'non_authoritative',
        'requires_raw_secret_passthrough', 'requires_broad_filesystem_authority',
        'requires_direct_git_commit_rights', 'requires_direct_atlas_task_report_rights',
        'requires_paid_api_credentials',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function describe(array $input): array
    {
        $facts = [
            'replaceable' => (bool) ($input['replaceable'] ?? false),
            'scoped' => (bool) ($input['scoped'] ?? false),
            'non_authoritative' => (bool) ($input['non_authoritative'] ?? false),
            'requires_raw_secret_passthrough' => (bool) ($input['requires_raw_secret_passthrough'] ?? false),
            'requires_broad_filesystem_authority' => (bool) ($input['requires_broad_filesystem_authority'] ?? false),
            'requires_direct_git_commit_rights' => (bool) ($input['requires_direct_git_commit_rights'] ?? false),
            'requires_direct_atlas_task_report_rights' => (bool) ($input['requires_direct_atlas_task_report_rights'] ?? false),
            'requires_paid_api_credentials' => (bool) ($input['requires_paid_api_credentials'] ?? false),
        ];

        $blockers = [];
        if ($facts['requires_raw_secret_passthrough']) {
            $blockers[] = 'raw_secret_passthrough_required';
        }
        if ($facts['requires_broad_filesystem_authority']) {
            $blockers[] = 'broad_filesystem_authority_required';
        }
        if ($facts['requires_direct_git_commit_rights']) {
            $blockers[] = 'direct_git_commit_rights_required';
        }
        if ($facts['requires_direct_atlas_task_report_rights']) {
            $blockers[] = 'direct_atlas_task_report_rights_required';
        }
        if ($facts['requires_paid_api_credentials']) {
            $blockers[] = 'paid_api_credentials_required';
        }

        // AC: reject raw credential/token/billing-field capture outright, even if the
        // caller never set the corresponding requires_* boolean.
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::KNOWN_SAFE_KEYS, true) || $value === null || $value === false || $value === '') {
                continue;
            }
            $lowerKey = strtolower($key);
            foreach (self::CREDENTIAL_OR_BILLING_KEY_MARKERS as $marker) {
                if (str_contains($lowerKey, $marker)) {
                    $blockers[] = "raw_credential_or_billing_field_captured:{$key}";
                    break;
                }
            }
        }
        $blockers = array_values(array_unique($blockers));

        $invocationContractReady = $facts['replaceable']
            && $facts['scoped']
            && $facts['non_authoritative']
            && $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'allowed_inputs' => ['fixture_task_objective', 'allowed_files', 'scoped_prompt', 'expected_test_command'],
            'forbidden_inputs' => ['raw_secret_value', 'broad_filesystem_glob', 'git_commit_authority', 'atlas_task_report_authority', 'paid_api_credential'],
            'expected_output_shape' => 'unified_diff_scoped_to_allowed_files',
            'timeout_policy' => 'bounded_timeout_enforced_before_any_invocation',
            'allowed_files_scope_rule' => 'local client may only touch paths explicitly listed in allowed_files for the current task',
            'no_git_rule' => 'local client never commits, pushes, or runs git; Atlas applies and commits the returned diff',
            'no_secret_rule' => 'local client never receives a raw secret value; Atlas passes scoped, non-secret context only',
            'atlas_report_commit_owner' => true,
            'facts' => $facts,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'invocation_contract_ready' => $invocationContractReady,
            // AC: subscription/local clients are optional accelerators, never a required
            // steady-state dependency — Atlas must keep working with this client absent.
            'capability_classification' => 'optional_capability',
            'required_for_steady_state' => false,
            // AC: client work is trusted only once BOTH the normalized output shape is
            // produced AND a runnable task proof command passes — a claim alone is never enough.
            'trust_requirements' => [
                'normalized_output_matches_expected_output_shape',
                'runnable_task_proof_command_passes',
            ],
        ];
    }
}
