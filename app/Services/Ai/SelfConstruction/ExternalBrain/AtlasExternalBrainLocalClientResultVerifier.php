<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure verifier for normalized output reported by a local subscription
 * client (Cursor, Codex, Claude, Hermes). Never trusts a client's own
 * "done"/"green" claim — claimed_green and verified_green are kept
 * strictly separate, and success is only safe to report when there is
 * runnable proof, the changed files stay within allowed_files, and every
 * required_evidence item is present.
 *
 * Pure: no I/O, no provider calls, no side effects.
 */
final class AtlasExternalBrainLocalClientResultVerifier
{
    public const SCHEMA = 'atlas.external_brain.local_client_result_verifier.v1';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function verify(array $facts): array
    {
        $allowedFiles = array_values(array_map('strval', (array) ($facts['allowed_files'] ?? [])));
        $acceptanceCriteria = array_values((array) ($facts['acceptance_criteria'] ?? []));
        $requiredEvidence = array_values(array_map('strval', (array) ($facts['required_evidence'] ?? [])));
        $providedEvidence = (array) ($facts['provided_evidence'] ?? []);
        $claimedChangedFiles = array_values(array_map('strval', (array) ($facts['claimed_changed_files'] ?? [])));
        $reportedTestCommands = array_values((array) ($facts['reported_test_commands'] ?? []));
        $claimedGreen = (bool) ($facts['claimed_green'] ?? false);

        $blockers = [];

        foreach ($requiredEvidence as $evidenceKey) {
            $value = trim((string) ($providedEvidence[$evidenceKey] ?? ''));
            if ($value === '') {
                $blockers[] = "missing_required_evidence:{$evidenceKey}";
            }
        }

        foreach ($claimedChangedFiles as $file) {
            if (! in_array($file, $allowedFiles, true)) {
                $blockers[] = "changed_file_outside_allowed_scope:{$file}";
            }
        }

        $hasRunnableTestProof = false;
        $hasFailedReportedTest = false;
        foreach ($reportedTestCommands as $entry) {
            $entry = (array) $entry;
            $command = trim((string) ($entry['command'] ?? ''));
            $executed = (bool) ($entry['executed'] ?? false);
            $passed = (bool) ($entry['passed'] ?? false);

            if ($command === '' || ! $executed) {
                continue;
            }
            if ($passed) {
                $hasRunnableTestProof = true;
            } else {
                $hasFailedReportedTest = true;
            }
        }

        if ($hasFailedReportedTest) {
            $blockers[] = 'reported_test_command_failed';
        }
        if ($claimedGreen && ! $hasRunnableTestProof) {
            $blockers[] = 'claimed_success_without_runnable_test_proof';
        }

        $blockers = array_values(array_unique($blockers));
        $verifiedGreen = $claimedGreen && $hasRunnableTestProof && $blockers === [];
        $verifierStatus = $verifiedGreen ? 'verified_green' : 'blocked';

        return [
            'schema_version' => self::SCHEMA,
            'verifier_status' => $verifierStatus,
            'claimed_green' => $claimedGreen,
            'verified_green' => $verifiedGreen,
            'safe_to_report_success' => $verifiedGreen,
            'blockers' => $blockers,
            'acceptance_criteria_count' => count($acceptanceCriteria),
            'learning_signal' => [
                'claimed_green' => $claimedGreen,
                'verified_green' => $verifiedGreen,
                'claim_matched_verification' => $claimedGreen === $verifiedGreen,
                'blocker_count' => count($blockers),
                'blockers' => $blockers,
            ],
        ];
    }
}
