<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Rivals Evidence Pack Verifier v1.
 *
 * Read-only check that an evidence pack is honest, replayable and free of
 * fake-evidence patterns. Never executes providers, never mutates state.
 *
 * Schema: atlas.programming.rivals_evidence_pack_verification.v1
 */
class AtlasRivalsEvidencePackVerifierService
{
    public const SCHEMA_VERSION = 'atlas.programming.rivals_evidence_pack_verification.v1';

    public const MODE_DIAGNOSTIC_LOCAL = 'diagnostic_local';

    public const MODE_REAL_RUN = 'real_run';

    /** @var list<string> Fields a real-run pack MUST carry to be admitted as Rivals evidence. */
    public const REQUIRED_FIELDS_FOR_REAL_RUN = [
        'workspace.before_status_hash',
        'workspace.after_clean_check.ran',
        'workspace.after_clean_check.clean',
        'replay_manifest.state',
        'provider_receipt.exit_code',
        'provider_receipt.stdout_hash',
        'provider_receipt.model',
        'provider_receipt.binary_resolved',
        'timeline_events',
        'human_intervention.count',
        'human_intervention.source',
        'final_gates',
    ];

    /**
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    public function verify(array $pack, string $mode = self::MODE_DIAGNOSTIC_LOCAL): array
    {
        $blockers = [];
        $warnings = [];

        if (($pack['schema_version'] ?? null) !== AtlasRivalsEvidencePackService::SCHEMA_VERSION) {
            $blockers[] = 'invalid_schema_version';
        }

        if (! is_string($pack['evidence_pack_id'] ?? null) || trim((string) $pack['evidence_pack_id']) === '') {
            $blockers[] = 'missing_evidence_pack_id';
        }

        if (! is_string($pack['case_id'] ?? null) || trim((string) $pack['case_id']) === '') {
            $blockers[] = 'missing_case_id';
        }

        if (($pack['external_provider_call'] ?? null) !== false) {
            $blockers[] = 'provider_call_flag_not_false';
        }

        if (($pack['promotes_external_rivals_claim'] ?? null) !== false) {
            $blockers[] = 'promotes_external_rivals_claim_flag_not_false';
        }

        if (($pack['claim_ready'] ?? null) !== false) {
            $blockers[] = 'claim_ready_flag_not_false';
        }

        if (($pack['synthetic_scores_allowed'] ?? null) !== false) {
            $blockers[] = 'synthetic_scores_flag_not_false';
        }

        $blockers = array_merge($blockers, $this->verifyWorkspace($pack));
        $blockers = array_merge($blockers, $this->verifyReplayManifest($pack));
        $blockers = array_merge($blockers, $this->verifyPresentTrueRequiresSourceAndHash($pack));
        $blockers = array_merge($blockers, $this->verifyMissingEvidenceCoherence($pack));
        $blockers = array_merge($blockers, $this->verifyCommandExitCodesCoherence($pack));

        $missingRealRunFields = [];
        if ($mode === self::MODE_REAL_RUN) {
            $missingRealRunFields = $this->collectMissingRealRunFields($pack);
            if ($missingRealRunFields !== []) {
                $blockers[] = 'invalid_missing_evidence_for_real_run';
            }
            if (data_get($pack, 'workspace.after_clean_check.clean') === false) {
                $blockers[] = 'dirty_workspace_after_run';
            }
            if (data_get($pack, 'replay_manifest.state') !== 'executed') {
                $blockers[] = 'replay_manifest_not_promoted_to_executed';
            }
        }

        $blockers = array_values(array_unique($blockers));
        $status = $blockers === [] ? 'passed' : 'blocked';
        if ($mode === self::MODE_REAL_RUN && in_array('invalid_missing_evidence_for_real_run', $blockers, true)) {
            $status = 'invalid_missing_evidence';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verified_at' => now()->toJSON(),
            'status' => $status,
            'mode' => $mode,
            'evidence_pack_schema_version' => $pack['schema_version'] ?? null,
            'evidence_pack_id' => $pack['evidence_pack_id'] ?? null,
            'case_id' => $pack['case_id'] ?? null,
            'blockers' => $blockers,
            'missing_real_run_fields' => $missingRealRunFields,
            'warnings' => $warnings,
            'verifier_blocks_fake_evidence' => true,
            'no_provider_call' => true,
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<string>
     */
    private function collectMissingRealRunFields(array $pack): array
    {
        $missing = [];
        foreach (self::REQUIRED_FIELDS_FOR_REAL_RUN as $path) {
            $value = data_get($pack, $path);
            if ($value === null) {
                $missing[] = $path;
                continue;
            }
            if (is_array($value) && $value === []) {
                $missing[] = $path;
            }
            if (is_string($value) && trim($value) === '') {
                $missing[] = $path;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<string>
     */
    private function verifyWorkspace(array $pack): array
    {
        $workspace = (array) ($pack['workspace'] ?? []);
        $blockers = [];

        if (! isset($workspace['path_hash']) || ! is_string($workspace['path_hash']) || strlen($workspace['path_hash']) !== 64) {
            $blockers[] = 'workspace_path_hash_missing_or_invalid';
        }

        if (($workspace['is_git'] ?? null) === false && ($workspace['clean'] ?? null) === true) {
            $blockers[] = 'workspace_clean_inconsistent_with_non_git_state';
        }

        if (($workspace['clean'] ?? null) === true && ((int) ($workspace['dirty_count'] ?? 0)) > 0) {
            $blockers[] = 'workspace_dirty_count_inconsistent_with_clean_flag';
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<string>
     */
    private function verifyReplayManifest(array $pack): array
    {
        $rm = (array) ($pack['replay_manifest'] ?? []);
        $blockers = [];

        if (($rm['present'] ?? null) === true) {
            $hash = $rm['hash'] ?? null;
            if (! is_string($hash) || strlen($hash) !== 64) {
                $blockers[] = 'replay_manifest_hash_missing_when_present';
            }
            if (! isset($rm['source'])) {
                $blockers[] = 'replay_manifest_source_missing_when_present';
            }
        } elseif (! isset($rm['reason_missing'])) {
            $blockers[] = 'replay_manifest_reason_missing_absent';
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<string>
     */
    private function verifyPresentTrueRequiresSourceAndHash(array $pack): array
    {
        $sections = ['business_rule', 'patch_diff', 'tests', 'quality_scan'];
        $blockers = [];

        foreach ($sections as $section) {
            $payload = (array) ($pack[$section] ?? []);
            if (($payload['present'] ?? false) !== true) {
                continue;
            }

            $source = $payload['source'] ?? null;
            $hash = $payload['hash'] ?? ($payload['log_hash'] ?? null);
            if (! is_string($source) || trim($source) === '' || $source === 'not_run' || $source === null) {
                $blockers[] = $section.'_present_but_source_missing';
            }
            if (! is_string($hash) || strlen($hash) !== 64) {
                $blockers[] = $section.'_present_but_hash_missing';
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<string>
     */
    private function verifyMissingEvidenceCoherence(array $pack): array
    {
        $missing = (array) ($pack['missing_evidence'] ?? []);
        $blockers = [];

        $checks = [
            'business_rule' => 'missing_business_rule',
            'patch_diff' => 'missing_patch_diff',
            'tests' => 'missing_test_run_log',
            'quality_scan' => 'missing_quality_scan_log',
            'replay_manifest' => 'missing_replay_manifest',
        ];

        foreach ($checks as $section => $missingFlag) {
            $present = (bool) data_get($pack, $section.'.present', false);
            if ($present && in_array($missingFlag, $missing, true)) {
                $blockers[] = $section.'_present_but_flagged_missing';
            }
            if (! $present && ! in_array($missingFlag, $missing, true)) {
                $blockers[] = $section.'_absent_but_not_reported_in_missing_evidence';
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<string>
     */
    private function verifyCommandExitCodesCoherence(array $pack): array
    {
        $codes = (array) ($pack['command_exit_codes'] ?? []);
        $tests = (array) ($pack['tests'] ?? []);
        $quality = (array) ($pack['quality_scan'] ?? []);
        $blockers = [];

        if (($tests['present'] ?? false) === true) {
            if (! array_key_exists('test_command', $codes)) {
                $blockers[] = 'tests_present_but_command_exit_code_missing';
            } elseif (($codes['test_command'] ?? null) !== ($tests['exit_code'] ?? null)) {
                $blockers[] = 'test_command_exit_code_mismatch';
            }
        }

        if (($quality['present'] ?? false) === true) {
            if (! array_key_exists('quality_command', $codes)) {
                $blockers[] = 'quality_present_but_command_exit_code_missing';
            } elseif (($codes['quality_command'] ?? null) !== ($quality['exit_code'] ?? null)) {
                $blockers[] = 'quality_command_exit_code_mismatch';
            }
        }

        return $blockers;
    }
}
