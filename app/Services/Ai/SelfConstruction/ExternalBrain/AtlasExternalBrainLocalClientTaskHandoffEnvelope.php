<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Builds the bounded handoff envelope Atlas hands to a local subscription
 * client (e.g. Cursor, Hermes) so it can act as a replaceable muscle with NO
 * paid API dependency, no secret access, and no git/report authority.
 *
 * BLOCKERS (any one makes the handoff unsafe):
 *   raw_secret_detected          <- objective or context matches a secret-shaped pattern
 *   paid_api_required_true       <- paid_api_required=true in the input
 *   missing_allowed_files        <- allowed_files is empty
 *   missing_runnable_evidence    <- required_evidence is empty or has no runnable marker
 *   direct_git_or_report_authority_requested
 *                                 <- requires_direct_git_commit_rights or
 *                                    requires_direct_atlas_task_report_rights is true
 *
 * handoff_allowed=true only when blockers is empty.
 *
 * Always returned regardless of blockers: plan_only=true,
 * Atlas_remains_commit_owner=true, provider_call_allowed=false,
 * token_spend_allowed=false, paid_api_allowed=false.
 *
 * INPUT:
 *   task_packet_id?:                            string
 *   objective?:                                 string
 *   allowed_files?:                             list<string>
 *   acceptance_criteria?:                        list<string>
 *   required_evidence?:                          list<string>
 *   workspace_root?:                             string
 *   model_hint?:                                 string
 *   local_client_id?:                            string
 *   context?:                                    string
 *   paid_api_required?:                          bool (default false)
 *   requires_direct_git_commit_rights?:          bool (default false)
 *   requires_direct_atlas_task_report_rights?:   bool (default false)
 *
 * Pure: no I/O, no network calls, no side effects.
 */
final class AtlasExternalBrainLocalClientTaskHandoffEnvelope
{
    public const SCHEMA = 'atlas.external_brain.local_client_task_handoff_envelope.v1';

    private const RUNNABLE_MARKERS = ['phpunit', 'artisan', 'bin/php', 'pytest', 'jest', 'rspec'];

    private const SECRET_PATTERNS = [
        '/sk-[a-zA-Z0-9]{16,}/',
        '/ghp_[a-zA-Z0-9]{20,}/',
        '/AKIA[0-9A-Z]{12,}/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/xox[baprs]-[a-zA-Z0-9-]{10,}/',
    ];

    private const DEFAULT_STOP_CONDITIONS = [
        'stop_if_file_outside_allowed_files_modified',
        'stop_if_git_command_attempted',
        'stop_if_raw_secret_requested_or_emitted',
        'stop_if_paid_api_call_attempted',
        'stop_on_timeout',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $taskPacketId = (string) ($input['task_packet_id'] ?? '');
        $objective = (string) ($input['objective'] ?? '');
        $allowedFiles = $this->toStringList($input['allowed_files'] ?? null);
        $acceptanceCriteria = $this->toStringList($input['acceptance_criteria'] ?? null);
        $requiredEvidence = $this->toStringList($input['required_evidence'] ?? null);
        $workspaceRoot = (string) ($input['workspace_root'] ?? '');
        $modelHint = (string) ($input['model_hint'] ?? '');
        $localClientId = (string) ($input['local_client_id'] ?? '');
        $context = (string) ($input['context'] ?? '');
        $paidApiRequired = (bool) ($input['paid_api_required'] ?? false);
        $requiresGitRights = (bool) ($input['requires_direct_git_commit_rights'] ?? false);
        $requiresReportRights = (bool) ($input['requires_direct_atlas_task_report_rights'] ?? false);

        $blockers = [];

        if ($this->containsRawSecret($objective) || $this->containsRawSecret($context)) {
            $blockers[] = 'raw_secret_detected';
        }
        if ($paidApiRequired) {
            $blockers[] = 'paid_api_required_true';
        }
        if ($allowedFiles === []) {
            $blockers[] = 'missing_allowed_files';
        }
        if ($requiredEvidence === [] || ! $this->hasRunnableMarker($requiredEvidence)) {
            $blockers[] = 'missing_runnable_evidence';
        }
        if ($requiresGitRights || $requiresReportRights) {
            $blockers[] = 'direct_git_or_report_authority_requested';
        }

        $handoffAllowed = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'envelope' => [
                'task_packet_id' => $taskPacketId,
                'objective' => $objective,
                'allowed_files' => $allowedFiles,
                'acceptance_criteria' => $acceptanceCriteria,
                'required_evidence' => $requiredEvidence,
                'workspace_root' => $workspaceRoot,
                'model_hint' => $modelHint,
                'local_client_id' => $localClientId,
                'stop_conditions' => self::DEFAULT_STOP_CONDITIONS,
            ],
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'handoff_allowed' => $handoffAllowed,
            'plan_only' => true,
            'Atlas_remains_commit_owner' => true,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'paid_api_allowed' => false,
        ];
    }

    private function containsRawSecret(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $evidence */
    private function hasRunnableMarker(array $evidence): bool
    {
        foreach ($evidence as $line) {
            $low = strtolower($line);
            foreach (self::RUNNABLE_MARKERS as $marker) {
                if (str_contains($low, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== ''));
    }
}
