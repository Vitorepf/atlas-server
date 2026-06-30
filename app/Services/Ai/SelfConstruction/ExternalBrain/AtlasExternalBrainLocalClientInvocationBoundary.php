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
 *
 * invocation_contract_ready=true only when: the local client is replaceable,
 * scoped to allowed_files, non-authoritative over queue/git/report, and no
 * blocker is present.
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
 *
 * Pure: no I/O, no network calls, no side effects.
 */
final class AtlasExternalBrainLocalClientInvocationBoundary
{
    public const SCHEMA = 'atlas.external_brain.local_client_invocation_boundary.v1';

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
        ];
    }
}
