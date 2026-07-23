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

    private const DEFAULT_MAX_EVIDENCE_AGE_SECONDS = 86400;

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

        // AC1/AC2: the 4 accepted real-evidence kinds — a claim is only safe to report success
        // when backed by at least one of these, never by the client's own narrative.
        $artifactRefs = array_values(array_map('strval', (array) ($facts['artifact_refs'] ?? [])));
        $taskReport = is_array($facts['task_report'] ?? null) ? $facts['task_report'] : [];
        $structuredReceipt = is_array($facts['structured_receipt'] ?? null) ? $facts['structured_receipt'] : [];

        // AC3: raw narrative / screenshot-only claims — never real evidence on their own.
        $transcriptClaim = trim((string) ($facts['transcript_claim'] ?? ''));
        $uiClaim = (bool) ($facts['ui_claim'] ?? false);

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

        $hasArtifactEvidence = $artifactRefs !== [];
        $hasTaskReportEvidence = trim((string) ($taskReport['task_packet_id'] ?? '')) !== ''
            && trim((string) ($taskReport['outcome'] ?? '')) !== '';
        $hasStructuredReceiptEvidence = trim((string) ($structuredReceipt['receipt_hash'] ?? '')) !== '';

        $hasAcceptedEvidence = $hasArtifactEvidence || $hasRunnableTestProof || $hasTaskReportEvidence || $hasStructuredReceiptEvidence;

        // AC5: stale evidence never counts, even when a kind is otherwise present.
        $evidenceAgeSeconds = isset($facts['evidence_age_seconds']) ? (int) $facts['evidence_age_seconds'] : null;
        $maxEvidenceAgeSeconds = (int) ($facts['max_evidence_age_seconds'] ?? self::DEFAULT_MAX_EVIDENCE_AGE_SECONDS);
        $evidenceStale = $evidenceAgeSeconds !== null && $evidenceAgeSeconds > $maxEvidenceAgeSeconds;
        if ($evidenceStale) {
            $blockers[] = 'stale_evidence_rejected';
            $hasAcceptedEvidence = false;
        }

        if ($claimedGreen && ! $hasAcceptedEvidence) {
            if ($transcriptClaim !== '' && ! $uiClaim) {
                $blockers[] = 'transcript_only_claim_rejected';
            } elseif ($uiClaim) {
                $blockers[] = 'ui_only_claim_rejected';
            } else {
                // Preserves the original blocker string for the plain "no evidence at all" case.
                $blockers[] = 'claimed_success_without_runnable_test_proof';
            }
        }

        $blockers = array_values(array_unique($blockers));
        $verifiedGreen = $claimedGreen && $hasAcceptedEvidence && $blockers === [];
        $verifierStatus = $verifiedGreen ? 'verified_green' : 'blocked';

        return [
            'schema_version' => self::SCHEMA,
            'verifier_status' => $verifierStatus,
            'claimed_green' => $claimedGreen,
            'verified_green' => $verifiedGreen,
            'safe_to_report_success' => $verifiedGreen,
            'blockers' => $blockers,
            'acceptance_criteria_count' => count($acceptanceCriteria),
            'evidence_kinds' => [
                'artifact' => $hasArtifactEvidence,
                'test_output' => $hasRunnableTestProof,
                'task_report' => $hasTaskReportEvidence,
                'structured_receipt' => $hasStructuredReceiptEvidence,
            ],
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
