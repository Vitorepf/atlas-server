<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Frontend\RivalReplay;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Support\Facades\File;

/**
 * Rival-replay manifest validation section — extracted verbatim from
 * AtlasFrontendRivalReplayHarnessService by the GOD-DEBULK split. Owns
 * single-manifest inspection and the five validate* gates. Schema-version
 * constants live on the façade and are referenced by FQCN; shared leaf
 * helpers route through RivalReplaySupport.
 */
class RivalReplayValidationSection
{
    public function __construct(
        private readonly RivalReplaySupport $support,
    ) {}

    /**
     * @param  array<int,string>  $requiredFields
     * @return array<string,mixed>
     */
    public function inspectManifest(string $manifestPath, string $caseId, string $system, array $requiredFields): array
    {
        if (! File::isFile($manifestPath)) {
            return $this->runPayload($caseId, $system, 'missing', ['manifest_missing'], null);
        }

        $raw = File::get($manifestPath);
        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return $this->runPayload($caseId, $system, 'invalid', ['manifest_json_invalid'], hash('sha256', $raw));
        }

        $issues = [];
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $manifest) || $manifest[$field] === null || $manifest[$field] === '') {
                $issues[] = 'missing_'.$field;
            }
        }

        if (($manifest['case_id'] ?? null) !== $caseId) {
            $issues[] = 'case_id_mismatch';
        }
        if (($manifest['system'] ?? null) !== $system) {
            $issues[] = 'system_mismatch';
        }
        if (($manifest['status'] ?? null) !== 'complete') {
            $issues[] = 'status_not_complete';
        }
        $issues = array_merge($issues, $this->validateTaskSpecRef($manifest, $manifestPath, $caseId));
        $issues = array_merge($issues, $this->validateRunPacketHash($manifest, $manifestPath, $caseId, $system));
        $evidencePack = $this->validateEvidencePackRef($manifest, $manifestPath, $caseId, $system);
        $issues = array_merge($issues, $evidencePack['issues']);
        $issues = array_merge($issues, $this->validateExternalExecutionReceipt($manifest, $caseId, $system, $evidencePack['verification_hash'] ?? null));
        if (! is_array($manifest['screenshot_hashes'] ?? null) || $manifest['screenshot_hashes'] === []) {
            $issues[] = 'screenshot_hashes_required';
        }
        if (! is_array($manifest['verification_hashes'] ?? null) || $manifest['verification_hashes'] === []) {
            $issues[] = 'verification_hashes_required';
        }
        if ($this->support->hasForbiddenRawFields($manifest)) {
            $issues[] = 'forbidden_raw_prompt_or_source_field_present';
        }
        if (! is_numeric($manifest['score_total'] ?? null) || ! is_numeric($manifest['score_max'] ?? null) || (int) $manifest['score_max'] <= 0) {
            $issues[] = 'invalid_score';
        }
        if (! is_array($manifest['score_breakdown'] ?? null)) {
            $issues[] = 'score_breakdown_required';
        } elseif (is_numeric($manifest['score_total'] ?? null) && is_numeric($manifest['score_max'] ?? null)) {
            $issues = array_merge($issues, app(AtlasFrontendCompetitiveRubricService::class)->validateBreakdown(
                $manifest['score_breakdown'],
                (int) $manifest['score_total'],
                (int) $manifest['score_max'],
            ));
        }
        $issues = array_merge($issues, $this->validateScoreAttestation($manifest, $caseId, $system, $evidencePack['verification_hash'] ?? null));

        $status = $issues === [] ? 'complete' : (in_array('status_not_complete', $issues, true) ? 'pending' : 'invalid');

        return $this->runPayload($caseId, $system, $status, $issues, hash('sha256', $raw), [
            'run_id_hash' => isset($manifest['run_id']) ? hash('sha256', (string) $manifest['run_id']) : null,
            'task_spec_hash' => is_string($manifest['task_spec_hash'] ?? null) ? (string) $manifest['task_spec_hash'] : null,
            'run_packet_hash' => is_string($manifest['run_packet_hash'] ?? null) ? (string) $manifest['run_packet_hash'] : null,
            'task_spec_ref' => is_string($manifest['task_spec_ref'] ?? null) ? (string) $manifest['task_spec_ref'] : null,
            'evidence_pack_ref' => is_string($manifest['evidence_pack_ref'] ?? null) ? (string) $manifest['evidence_pack_ref'] : null,
            'evidence_pack_verification_hash' => $evidencePack['verification_hash'] ?? null,
            'external_execution_receipt_hash' => is_array($manifest['external_execution_receipt'] ?? null)
                ? MissionCanonicalHash::sha256((array) $manifest['external_execution_receipt'])
                : null,
            'score_attestation_hash' => is_array($manifest['score_attestation'] ?? null)
                ? MissionCanonicalHash::sha256((array) $manifest['score_attestation'])
                : null,
            'score_total' => is_numeric($manifest['score_total'] ?? null) ? (int) $manifest['score_total'] : null,
            'score_max' => is_numeric($manifest['score_max'] ?? null) ? (int) $manifest['score_max'] : null,
            'score_breakdown' => is_array($manifest['score_breakdown'] ?? null) ? $manifest['score_breakdown'] : null,
            'completed_at' => is_string($manifest['completed_at'] ?? null) ? $manifest['completed_at'] : null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{issues: array<int,string>, verification_hash?: string}
     */
    public function validateEvidencePackRef(array $manifest, string $manifestPath, string $caseId, string $system): array
    {
        $ref = is_string($manifest['evidence_pack_ref'] ?? null) ? (string) $manifest['evidence_pack_ref'] : '';
        if ($ref === '' || str_starts_with($ref, '/') || str_contains($ref, '..')) {
            return ['issues' => ['evidence_pack_ref_invalid']];
        }

        $packPath = dirname($manifestPath).DIRECTORY_SEPARATOR.$ref;
        if (! File::isFile($packPath)) {
            return ['issues' => ['evidence_pack_ref_missing']];
        }

        $verification = app(AtlasFrontendEvidencePackVerifierService::class)->verify($packPath);
        $issues = [];
        if (($verification['status'] ?? null) !== 'passed') {
            $issues[] = 'evidence_pack_verification_failed';
        }
        if (($verification['case_id'] ?? null) !== $caseId) {
            $issues[] = 'evidence_pack_case_mismatch';
        }
        if (($verification['system'] ?? null) !== $system) {
            $issues[] = 'evidence_pack_system_mismatch';
        }
        if (($verification['task_spec_hash'] ?? null) !== ($manifest['task_spec_hash'] ?? null)) {
            $issues[] = 'evidence_pack_task_spec_hash_mismatch';
        }

        $artifactHashes = collect((array) ($verification['artifact_results'] ?? []))
            ->filter(fn (array $artifact): bool => ($artifact['status'] ?? null) === 'present')
            ->mapWithKeys(fn (array $artifact): array => [(string) ($artifact['kind'] ?? '') => (string) ($artifact['sha256'] ?? '')])
            ->all();

        if (($manifest['output_artifact_hash'] ?? null) !== ($artifactHashes['output_artifact'] ?? null)) {
            $issues[] = 'evidence_pack_output_artifact_hash_mismatch';
        }
        if (($manifest['anti_slop_report_hash'] ?? null) !== ($artifactHashes['anti_slop_report'] ?? null)) {
            $issues[] = 'evidence_pack_anti_slop_hash_mismatch';
        }
        if (! in_array($artifactHashes['screenshot_set'] ?? null, (array) ($manifest['screenshot_hashes'] ?? []), true)) {
            $issues[] = 'evidence_pack_screenshot_hash_missing_from_manifest';
        }
        if (! in_array($artifactHashes['verification_report'] ?? null, (array) ($manifest['verification_hashes'] ?? []), true)) {
            $issues[] = 'evidence_pack_verification_hash_missing_from_manifest';
        }

        return [
            'issues' => array_values(array_unique(array_merge($issues, array_map(
                fn (string $blocker): string => 'evidence_pack_'.$blocker,
                (array) ($verification['blockers'] ?? []),
            )))),
            'verification_hash' => is_string($verification['verification_hash'] ?? null) ? $verification['verification_hash'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    public function validateExternalExecutionReceipt(array $manifest, string $caseId, string $system, ?string $evidencePackVerificationHash): array
    {
        $isExternalRival = $system !== 'atlas_frontend';
        $receipt = $manifest['external_execution_receipt'] ?? null;

        if (! is_array($receipt)) {
            return $isExternalRival ? ['external_execution_receipt_required'] : [];
        }

        $issues = [];
        if (($receipt['schema_version'] ?? null) !== AtlasFrontendRivalReplayHarnessService::EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION) {
            $issues[] = 'external_execution_receipt_schema_invalid';
        }
        if (($receipt['status'] ?? null) !== 'verified') {
            $issues[] = 'external_execution_receipt_status_not_verified';
        }
        if (($receipt['case_id'] ?? null) !== $caseId) {
            $issues[] = 'external_execution_receipt_case_mismatch';
        }
        if (($receipt['system'] ?? null) !== $system) {
            $issues[] = 'external_execution_receipt_system_mismatch';
        }
        if ((bool) ($receipt['operator_approved'] ?? false) !== true) {
            $issues[] = 'external_execution_receipt_operator_approval_missing';
        }
        if (! is_string($receipt['captured_at'] ?? null) || trim((string) $receipt['captured_at']) === '') {
            $issues[] = 'external_execution_receipt_captured_at_missing';
        }
        if (! in_array((string) ($receipt['execution_surface'] ?? ''), ['external_rival_system', 'manual_external_replay'], true)) {
            $issues[] = 'external_execution_receipt_surface_invalid';
        }
        if ($this->support->hasForbiddenRawFields($receipt)) {
            $issues[] = 'external_execution_receipt_forbidden_raw_prompt_or_source_field_present';
        }

        $receiptHashes = (array) ($receipt['manifest_hashes'] ?? []);
        if (($receiptHashes['output_artifact_hash'] ?? null) !== ($manifest['output_artifact_hash'] ?? null)) {
            $issues[] = 'external_execution_receipt_output_artifact_hash_mismatch';
        }
        if (($receiptHashes['anti_slop_report_hash'] ?? null) !== ($manifest['anti_slop_report_hash'] ?? null)) {
            $issues[] = 'external_execution_receipt_anti_slop_hash_mismatch';
        }
        if ((array) ($receiptHashes['screenshot_hashes'] ?? []) !== (array) ($manifest['screenshot_hashes'] ?? [])) {
            $issues[] = 'external_execution_receipt_screenshot_hashes_mismatch';
        }
        if ((array) ($receiptHashes['verification_hashes'] ?? []) !== (array) ($manifest['verification_hashes'] ?? [])) {
            $issues[] = 'external_execution_receipt_verification_hashes_mismatch';
        }
        if (($receiptHashes['evidence_pack_verification_hash'] ?? null) !== $evidencePackVerificationHash) {
            $issues[] = 'external_execution_receipt_evidence_pack_verification_hash_mismatch';
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    public function validateScoreAttestation(array $manifest, string $caseId, string $system, ?string $evidencePackVerificationHash): array
    {
        $attestation = $manifest['score_attestation'] ?? null;
        if (! is_array($attestation)) {
            return ['score_attestation_required'];
        }

        $issues = [];
        $rubric = app(AtlasFrontendCompetitiveRubricService::class)->rubric();
        $scoreBreakdown = is_array($manifest['score_breakdown'] ?? null) ? $manifest['score_breakdown'] : [];
        $scoreBreakdownHash = MissionCanonicalHash::sha256($scoreBreakdown);

        if (($attestation['schema_version'] ?? null) !== AtlasFrontendRivalReplayHarnessService::SCORE_ATTESTATION_SCHEMA_VERSION) {
            $issues[] = 'score_attestation_schema_invalid';
        }
        if (($attestation['status'] ?? null) !== 'verified') {
            $issues[] = 'score_attestation_status_not_verified';
        }
        if (($attestation['case_id'] ?? null) !== $caseId) {
            $issues[] = 'score_attestation_case_mismatch';
        }
        if (($attestation['system'] ?? null) !== $system) {
            $issues[] = 'score_attestation_system_mismatch';
        }
        if (($attestation['rubric_hash'] ?? null) !== ($rubric['rubric_hash'] ?? null)) {
            $issues[] = 'score_attestation_rubric_hash_mismatch';
        }
        if (($attestation['score_breakdown_hash'] ?? null) !== $scoreBreakdownHash) {
            $issues[] = 'score_attestation_breakdown_hash_mismatch';
        }
        if (($attestation['score_total'] ?? null) !== ($manifest['score_total'] ?? null)) {
            $issues[] = 'score_attestation_total_mismatch';
        }
        if (($attestation['score_max'] ?? null) !== ($manifest['score_max'] ?? null)) {
            $issues[] = 'score_attestation_max_mismatch';
        }
        if (($attestation['evidence_pack_verification_hash'] ?? null) !== $evidencePackVerificationHash) {
            $issues[] = 'score_attestation_evidence_pack_verification_hash_mismatch';
        }
        if (($attestation['reviewed_manifest_hashes'] ?? null) !== $this->support->scoreReviewedManifestHashes($manifest, $evidencePackVerificationHash)) {
            $issues[] = 'score_attestation_reviewed_manifest_hashes_mismatch';
        }
        if ((bool) ($attestation['operator_approved'] ?? false) !== true) {
            $issues[] = 'score_attestation_operator_approval_missing';
        }
        if (! is_string($attestation['reviewer_ref_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $attestation['reviewer_ref_hash'])) {
            $issues[] = 'score_attestation_reviewer_ref_hash_invalid';
        }
        if (! is_string($attestation['reviewed_at'] ?? null) || trim((string) $attestation['reviewed_at']) === '') {
            $issues[] = 'score_attestation_reviewed_at_missing';
        }
        if (! in_array((string) ($attestation['scoring_surface'] ?? ''), ['manual_competitive_review', 'independent_review_panel', 'atlas_review_panel'], true)) {
            $issues[] = 'score_attestation_surface_invalid';
        }
        if ($this->support->hasForbiddenRawFields($attestation)) {
            $issues[] = 'score_attestation_forbidden_raw_prompt_or_source_field_present';
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    private function validateTaskSpecRef(array $manifest, string $manifestPath, string $caseId): array
    {
        $issues = [];
        $ref = is_string($manifest['task_spec_ref'] ?? null) ? (string) $manifest['task_spec_ref'] : '';

        if ($ref !== '../task-spec.json') {
            return ['task_spec_ref_must_be_case_canonical'];
        }

        $taskSpecPath = dirname($manifestPath).DIRECTORY_SEPARATOR.$ref;
        if (! File::isFile($taskSpecPath)) {
            return ['task_spec_ref_missing'];
        }

        $taskSpec = json_decode(File::get($taskSpecPath), true);
        if (! is_array($taskSpec)) {
            return ['task_spec_ref_json_invalid'];
        }

        if (($taskSpec['schema_version'] ?? null) !== AtlasFrontendRivalReplayHarnessService::TASK_SPEC_SCHEMA_VERSION) {
            $issues[] = 'task_spec_ref_schema_invalid';
        }
        if (($taskSpec['case_id'] ?? null) !== $caseId) {
            $issues[] = 'task_spec_ref_case_mismatch';
        }
        if (! is_string($taskSpec['task_spec_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $taskSpec['task_spec_hash'])) {
            $issues[] = 'task_spec_ref_hash_invalid';
        } elseif (($manifest['task_spec_hash'] ?? null) !== $taskSpec['task_spec_hash']) {
            $issues[] = 'task_spec_hash_mismatch_with_task_spec_ref';
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    private function validateRunPacketHash(array $manifest, string $manifestPath, string $caseId, string $system): array
    {
        $runnerKitPath = $this->runnerKitPathForManifest($manifestPath);
        if (! File::isFile($runnerKitPath)) {
            return [];
        }

        $runnerKit = json_decode(File::get($runnerKitPath), true);
        if (! is_array($runnerKit)) {
            return ['runner_kit_json_invalid'];
        }

        $packet = collect((array) ($runnerKit['run_packets'] ?? []))
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['case_id'] ?? null) === $caseId
                && ($candidate['system'] ?? null) === $system);

        if (! is_array($packet)) {
            return ['run_packet_missing_from_runner_kit'];
        }

        $expected = is_string($packet['run_packet_hash'] ?? null) ? (string) $packet['run_packet_hash'] : null;
        if ($expected === null || ! preg_match('/\A[a-f0-9]{64}\z/', $expected)) {
            return ['run_packet_hash_missing_from_runner_kit'];
        }

        if (($manifest['run_packet_hash'] ?? null) !== $expected) {
            return ['run_packet_hash_mismatch'];
        }

        return [];
    }

    /**
     * @param  array<int,string>  $issues
     * @param  array<string,mixed>|null  $extra
     * @return array<string,mixed>
     */
    private function runPayload(string $caseId, string $system, string $status, array $issues, ?string $manifestHash, ?array $extra = null): array
    {
        return array_filter([
            'case_id' => $caseId,
            'system' => $system,
            'status' => $status,
            'manifest_hash' => $manifestHash,
            'issues' => $issues,
            'run_id_hash' => $extra['run_id_hash'] ?? null,
            'task_spec_hash' => $extra['task_spec_hash'] ?? null,
            'run_packet_hash' => $extra['run_packet_hash'] ?? null,
            'task_spec_ref' => $extra['task_spec_ref'] ?? null,
            'evidence_pack_ref' => $extra['evidence_pack_ref'] ?? null,
            'evidence_pack_verification_hash' => $extra['evidence_pack_verification_hash'] ?? null,
            'external_execution_receipt_hash' => $extra['external_execution_receipt_hash'] ?? null,
            'score_attestation_hash' => $extra['score_attestation_hash'] ?? null,
            'score_total' => $extra['score_total'] ?? null,
            'score_max' => $extra['score_max'] ?? null,
            'score_breakdown' => $extra['score_breakdown'] ?? null,
            'completed_at' => $extra['completed_at'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function runnerKitPathForManifest(string $manifestPath): string
    {
        return dirname($manifestPath, 3).'/replay-runner-kit.json';
    }
}
