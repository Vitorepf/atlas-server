<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\OperatorEvidence\OperatorEvidenceCanonicalizer;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService;
use Illuminate\Support\Facades\Storage;

/**
 * READ-ONLY EVIDENCE-INPUT loader cluster, extracted from the god-class
 * {@see AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService}.
 *
 * Owns the two evidence-loading concerns (canonical submission files + draft workspace
 * inspection) plus the path constants and normalization they depend on. The readiness
 * service now keeps `build()` thin and delegates the heavy loading here.
 *
 * Storage disk follows the service-level default ('local') — byte-identical with the
 * previous in-class behavior.
 */
final class OperatorEvidenceSubmissionInputLoader
{
    private const STORAGE_DISK = 'local';

    /** @var array<string, string> */
    private const CANONICAL_SUBMISSION_PATHS = [
        'runtime_promotion_receipt' => 'atlas/self-construction/operator-submissions/runtime-promotion.json',
        'real_provider_smoke' => 'atlas/self-construction/operator-submissions/real-provider-smoke.json',
        'human_completion_receipt' => 'atlas/self-construction/operator-submissions/completion-receipt.json',
    ];

    /** @var array<string, string> */
    private const CANONICAL_SUBMISSION_PRIVATE_STORAGE_PATHS = [
        'runtime_promotion_receipt' => 'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
        'real_provider_smoke' => 'storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json',
        'human_completion_receipt' => 'storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json',
    ];

    /** @return array<string, mixed> */
    public function loadCanonicalSubmissionInput(): array
    {
        $payloads = [];
        $files = [];
        $violations = [];

        foreach (self::CANONICAL_SUBMISSION_PATHS as $artifact => $path) {
            $exists = Storage::disk(self::STORAGE_DISK)->exists($path);
            $file = [
                'artifact' => $artifact,
                'path' => $path,
                'exists' => $exists,
                'json_sha256' => '',
                'loaded' => false,
            ];

            if ($exists) {
                $raw = (string) Storage::disk(self::STORAGE_DISK)->get($path);
                $file['json_sha256'] = hash('sha256', $raw);
                try {
                    $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $payloads[$artifact] = $decoded;
                        $file['loaded'] = true;
                    } else {
                        $violations[] = 'canonical_submission_payload_not_object:'.$artifact;
                    }
                } catch (\Throwable) {
                    $violations[] = 'canonical_submission_payload_invalid_json:'.$artifact;
                }
            }

            $files[] = $file;
        }

        $loadedArtifacts = array_keys($payloads);

        return [
            'schema_version' => 'atlas.self_construction.operator_evidence_canonical_submission_input.v1',
            'status' => $loadedArtifacts === [] ? 'no_canonical_submission_files_loaded' : 'loaded_for_read_only_submission_readiness',
            'directory' => 'storage/app/atlas/self-construction/operator-submissions',
            'artifact_count' => count(self::CANONICAL_SUBMISSION_PATHS),
            'loaded_artifacts' => $loadedArtifacts,
            'loaded_artifact_count' => count($loadedArtifacts),
            'files' => $files,
            'violation_count' => count(array_values(array_unique($violations))),
            'violations' => array_values(array_unique($violations)),
            'payloads' => $payloads,
            'read_only' => true,
            'published_submission_json_is_evidence' => false,
            'can_persist_canonical_submission_files_directly' => false,
            'can_promote_completion_from_canonical_submission_files' => false,
            'recommended_readiness_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
            'recommended_persistence_boundary' => 'use_explicit_canonical_verifier_persist_commands_after_this_readiness_surface_reports_ready',
            'private_storage_directory' => 'storage/app/private/atlas/self-construction/operator-submissions',
        ];
    }

    /** @return array<string, mixed> */
    public function loadDraftWorkspaceInput(string $requestedPath): array
    {
        if (trim($requestedPath) === '') {
            return [
                'status' => 'not_requested',
                'requested_path' => '',
                'manifest_path' => '',
                'artifact_count' => 0,
                'loaded_artifacts' => [],
                'violations' => [],
                'warnings' => [],
                'payloads' => [],
            ];
        }

        $inspection = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect([
            'operator_draft_workspace_path' => $requestedPath,
        ]);
        $payloads = [];
        $violations = (array) data_get($inspection, 'violations', []);
        $warnings = (array) data_get($inspection, 'warnings', []);

        foreach ((array) data_get($inspection, 'files', []) as $file) {
            $artifact = (string) data_get($file, 'artifact', '');
            $draftPath = $this->normalizeStoragePath((string) data_get($file, 'draft_path', ''));
            if ($artifact === '' || $draftPath === '' || ! Storage::disk(self::STORAGE_DISK)->exists($draftPath)) {
                continue;
            }

            try {
                $decoded = json_decode(Storage::disk(self::STORAGE_DISK)->get($draftPath), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $payloads[$artifact] = $decoded;
                }
            } catch (\Throwable) {
                $violations[] = 'draft_workspace_payload_invalid_json:'.$artifact;
            }
        }

        return [
            'status' => data_get($inspection, 'status') === 'no_workspace'
                ? 'workspace_not_found'
                : 'loaded_for_read_only_submission_readiness',
            'requested_path' => $requestedPath,
            'manifest_path' => (string) data_get($inspection, 'manifest_path', ''),
            'workspace_directory' => (string) data_get($inspection, 'workspace_directory', ''),
            'artifact_count' => (int) data_get($inspection, 'artifact_count', 0),
            'loaded_artifacts' => array_keys($payloads),
            'inspector_status' => (string) data_get($inspection, 'status', ''),
            'inspector_hash' => (string) data_get($inspection, 'inspector_hash', ''),
            'workspace_safe_for_operator_editing' => (bool) data_get($inspection, 'workspace_safe_for_operator_editing', false),
            'violation_count' => count(array_values(array_unique($violations))),
            'violations' => array_values(array_unique($violations)),
            'warning_count' => count(array_values(array_unique($warnings))),
            'warnings' => array_values(array_unique($warnings)),
            'payloads' => $payloads,
            'read_only' => true,
            'draft_is_evidence' => false,
            'can_persist_draft_directly' => false,
        ];
    }

    private function normalizeStoragePath(string $path): string
    {
        return OperatorEvidenceCanonicalizer::normalizeStoragePath($path);
    }
}
