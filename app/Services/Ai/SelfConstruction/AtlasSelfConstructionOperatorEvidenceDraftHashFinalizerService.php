<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.operator_evidence_draft_hash_finalizer.v1';

    public const MODE = 'operator_evidence_draft_hash_finalizer';

    private const DISK = 'local';

    /** @return array<string, mixed> */
    public function finalize(array $options = []): array
    {
        $writeRequested = (bool) ($options['write_computed_operator_draft_hashes'] ?? false);
        $loaded = $this->loadWorkspace((string) ($options['operator_draft_workspace_path'] ?? ''));
        $artifacts = [];
        $writtenArtifacts = [];

        foreach ((array) data_get($loaded, 'drafts', []) as $artifact => $draft) {
            $result = $this->finalizeArtifact((string) $artifact, (array) $draft, $writeRequested);
            $artifacts[$artifact] = $result;
            if ((bool) data_get($result, 'written', false)) {
                $writtenArtifacts[] = (string) $artifact;
            }
        }

        $artifactCount = count($artifacts);
        $readyCount = count(array_filter($artifacts, static fn (array $artifact): bool => (bool) ($artifact['can_write_hash_to_draft'] ?? false)));
        $blockedCount = count(array_filter($artifacts, static fn (array $artifact): bool => (bool) ($artifact['can_write_hash_to_draft'] ?? false) !== true));
        $writtenCount = count($writtenArtifacts);
        $workspaceLoaded = (string) data_get($loaded, 'status') === 'loaded_for_hash_finalization';

        $status = match (true) {
            $workspaceLoaded && $writeRequested && $writtenCount === $artifactCount && $artifactCount > 0 => 'operator_draft_hashes_written',
            $workspaceLoaded && $writeRequested && $writtenCount > 0 => 'operator_draft_hashes_partially_written',
            $workspaceLoaded && $writeRequested && $writtenCount === 0 => 'blocked_operator_drafts_not_ready_for_hash_write',
            $workspaceLoaded && $readyCount === $artifactCount && $artifactCount > 0 => 'ready_to_write_computed_hashes',
            $workspaceLoaded && $readyCount > 0 => 'partially_ready_to_write_computed_hashes',
            $workspaceLoaded => 'blocked_operator_drafts_not_ready_for_hash_write',
            default => 'blocked_operator_draft_workspace_required',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'requested_path' => (string) data_get($loaded, 'requested_path', ''),
            'manifest_path' => (string) data_get($loaded, 'manifest_path', ''),
            'workspace_loaded' => $workspaceLoaded,
            'load_status' => (string) data_get($loaded, 'status', 'not_requested'),
            'load_violations' => (array) data_get($loaded, 'violations', []),
            'write_requested' => $writeRequested,
            'artifact_count' => $artifactCount,
            'ready_artifact_count' => $readyCount,
            'blocked_artifact_count' => $blockedCount,
            'written_artifact_count' => $writtenCount,
            'written_artifacts' => $writtenArtifacts,
            'artifacts' => $artifacts,
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                $writeRequested ? 'operator_draft_hash_finalizer_writes_only_hash_fields_to_ready_drafts' : 'operator_draft_hash_finalizer_does_not_write_without_explicit_flag',
                'operator_draft_hash_finalizer_does_not_sign_for_operator',
                'operator_draft_hash_finalizer_does_not_persist_completion_evidence',
                'operator_draft_hash_finalizer_does_not_enable_runtime',
                'operator_draft_hash_finalizer_does_not_call_provider',
                'operator_draft_hash_finalizer_does_not_dispatch',
                'operator_draft_hash_finalizer_does_not_spend_tokens',
            ],
            'next_action' => $writeRequested && $writtenCount > 0
                ? 'run_operator_evidence_submission_readiness_against_the_same_workspace'
                : ($readyCount > 0
                    ? 'rerun_with_write_computed_operator_draft_hashes_after_operator_review'
                    : 'operator_must_replace_placeholders_and_keep_forbidden_runtime_flags_false'),
        ];
        $payload['finalizer_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function loadWorkspace(string $requestedPath): array
    {
        $requestedPath = trim($requestedPath);
        if ($requestedPath === '') {
            return $this->blockedLoad('', '', ['operator_draft_workspace_path_required']);
        }

        $path = $this->normalizeStoragePath($requestedPath);
        if ($path === '') {
            return $this->blockedLoad($requestedPath, '', ['invalid_operator_draft_workspace_path']);
        }

        $manifestPath = str_ends_with($path, 'manifest.json') ? $path : rtrim($path, '/').'/manifest.json';
        if (! Storage::disk(self::DISK)->exists($manifestPath)) {
            return $this->blockedLoad($requestedPath, $manifestPath, ['operator_draft_workspace_manifest_not_found']);
        }

        $manifest = $this->readJson($manifestPath);
        $drafts = [];
        foreach ((array) data_get($manifest, 'files', []) as $file) {
            $artifact = (string) data_get($file, 'artifact', '');
            $draftPath = (string) data_get($file, 'draft_path', '');
            if (! in_array($artifact, ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'], true)) {
                continue;
            }
            if ($draftPath === '' || ! Storage::disk(self::DISK)->exists($draftPath)) {
                $drafts[$artifact] = [
                    'status' => 'blocked',
                    'draft_path' => $draftPath,
                    'payload' => [],
                    'violations' => ['draft_file_not_found'],
                ];

                continue;
            }
            $payload = $this->readJson($draftPath);
            $drafts[$artifact] = [
                'status' => $payload === [] ? 'blocked' : 'loaded',
                'draft_path' => $draftPath,
                'payload' => $payload,
                'violations' => $payload === [] ? ['draft_file_invalid_json_or_empty'] : [],
            ];
        }

        if ($drafts === []) {
            return $this->blockedLoad($requestedPath, $manifestPath, ['operator_draft_workspace_has_no_supported_artifacts']);
        }

        return [
            'status' => 'loaded_for_hash_finalization',
            'requested_path' => $requestedPath,
            'manifest_path' => $manifestPath,
            'drafts' => $drafts,
            'violations' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function blockedLoad(string $requestedPath, string $manifestPath, array $violations): array
    {
        return [
            'status' => 'blocked',
            'requested_path' => $requestedPath,
            'manifest_path' => $manifestPath,
            'drafts' => [],
            'violations' => $violations,
        ];
    }

    /** @param array<string, mixed> $draft */
    private function finalizeArtifact(string $artifact, array $draft, bool $writeRequested): array
    {
        $payload = (array) data_get($draft, 'payload', []);
        $draftPath = (string) data_get($draft, 'draft_path', '');
        $loaded = (string) data_get($draft, 'status') === 'loaded' && $payload !== [];
        $hashField = $this->hashField($artifact);
        $computedHash = $loaded ? $this->computedHash($artifact, $payload) : '';
        $payloadWithComputedHash = $loaded ? array_replace($payload, [$hashField => $computedHash]) : [];
        $placeholderFields = $loaded ? $this->placeholderFields($artifact, $payload) : [];
        $invalidHashFields = $loaded ? $this->invalidHashFields($artifact, $payload) : [];
        $forbiddenFlagsTrue = $loaded ? $this->forbiddenFlagsTrue($artifact, $payload) : [];
        $canWrite = $loaded
            && $computedHash !== ''
            && $placeholderFields === []
            && $invalidHashFields === []
            && $forbiddenFlagsTrue === [];
        $beforeHash = $loaded ? $this->storageFileHash($draftPath) : '';
        $written = false;
        $afterHash = '';
        if ($writeRequested && $canWrite) {
            Storage::disk(self::DISK)->put($draftPath, $this->prettyJson($payloadWithComputedHash));
            $written = true;
            $afterHash = $this->storageFileHash($draftPath);
        }

        return [
            'artifact' => $artifact,
            'status' => $written ? 'draft_hash_written' : ($canWrite ? 'ready_to_write_computed_hash' : 'blocked_operator_draft_not_ready_for_hash_write'),
            'draft_path' => $draftPath,
            'draft_loaded' => $loaded,
            'load_violations' => (array) data_get($draft, 'violations', []),
            'hash_field' => $hashField,
            'original_hash' => (string) ($payload[$hashField] ?? ''),
            'computed_hash' => $computedHash,
            'input_hash_matches_computed_hash' => $computedHash !== '' && (string) ($payload[$hashField] ?? '') === $computedHash,
            'payload_with_computed_hash' => $payloadWithComputedHash,
            'placeholder_fields' => $placeholderFields,
            'invalid_hash_fields' => $invalidHashFields,
            'forbidden_flags_true' => $forbiddenFlagsTrue,
            'can_write_hash_to_draft' => $canWrite,
            'write_requested' => $writeRequested,
            'written' => $written,
            'write_blocker' => $this->writeBlocker($loaded, $canWrite, $placeholderFields, $invalidHashFields, $forbiddenFlagsTrue),
            'draft_file_sha256_before' => $beforeHash,
            'draft_file_sha256_after' => $afterHash,
        ];
    }

    private function hashField(string $artifact): string
    {
        return $artifact === 'real_provider_smoke' ? 'smoke_hash' : 'receipt_hash';
    }

    /** @param array<string, mixed> $payload */
    private function computedHash(string $artifact, array $payload): string
    {
        $hashes = new AtlasSelfConstructionCompletionEvidenceHashService;

        return match ($artifact) {
            'runtime_promotion_receipt' => $hashes->runtimePromotionReceiptHash($payload),
            'real_provider_smoke' => $hashes->realProviderSmokeHash($payload),
            'human_completion_receipt' => $hashes->humanCompletionReceiptHash($payload),
            default => '',
        };
    }

    /** @param array<string, mixed> $payload */
    private function placeholderFields(string $artifact, array $payload): array
    {
        $fields = [];
        $hashField = $this->hashField($artifact);
        foreach ($payload as $field => $value) {
            if ((string) $field === $hashField) {
                continue;
            }
            if (is_string($value) && str_starts_with(trim($value), '<')) {
                $fields[] = (string) $field;
            }
        }
        if (in_array($artifact, ['runtime_promotion_receipt', 'human_completion_receipt'], true)) {
            $reason = trim((string) ($payload['reason'] ?? ''));
            if (mb_strlen($reason) < 32) {
                $fields[] = 'reason';
            }
            if ($this->isPlaceholderSigner((string) ($payload['signed_by'] ?? ''))) {
                $fields[] = 'signed_by';
            }
        }

        return array_values(array_unique($fields));
    }

    /** @param array<string, mixed> $payload */
    private function invalidHashFields(string $artifact, array $payload): array
    {
        $fields = match ($artifact) {
            'runtime_promotion_receipt' => [
                'runtime_gap_matrix_hash',
                'runtime_promotion_basis_hash',
                'runtime_promotion_closure_basis_hash',
            ],
            'real_provider_smoke' => [
                'operator_approval_receipt_hash',
                'evidence_ledger_hash',
                'work_product_manifest_hash',
                'cost_event_hash',
                'continuation_summary_hash',
                'provider_response_hash',
            ],
            'human_completion_receipt' => [
                'completion_audit_hash',
                'release_dossier_hash',
                'replay_diff_hash',
                'runtime_gap_matrix_hash',
                'runtime_promotion_receipt_hash',
                'real_provider_smoke_hash',
                'certification_status_batch_hash',
            ],
            default => [],
        };

        return array_values(array_filter($fields, static fn (string $field): bool => preg_match('/^[a-f0-9]{64}$/', (string) ($payload[$field] ?? '')) !== 1));
    }

    /** @param array<string, mixed> $payload */
    private function forbiddenFlagsTrue(string $artifact, array $payload): array
    {
        $flags = match ($artifact) {
            'real_provider_smoke' => [
                'provider_called_by_atlas',
                'token_spent_by_atlas',
                'dispatch_allowed',
                'adapter_execution_allowed',
                'self_programming_allowed',
                'completion_claim_promoted_without_receipt',
            ],
            default => [
                'execution_allowed',
                'dispatch_allowed',
                'provider_call_allowed',
                'token_spend_allowed',
                'adapter_execution_allowed',
                'self_programming_allowed',
                'completion_autopromoted',
            ],
        };

        return array_values(array_filter($flags, static fn (string $flag): bool => (bool) ($payload[$flag] ?? false) === true));
    }

    private function writeBlocker(bool $loaded, bool $canWrite, array $placeholderFields, array $invalidHashFields, array $forbiddenFlagsTrue): string
    {
        if ($canWrite) {
            return '';
        }
        if (! $loaded) {
            return 'operator_draft_not_loaded';
        }
        if ($placeholderFields !== []) {
            return 'operator_placeholders_or_reason_not_ready';
        }
        if ($invalidHashFields !== []) {
            return 'required_evidence_hashes_not_ready';
        }
        if ($forbiddenFlagsTrue !== []) {
            return 'runtime_or_completion_flags_forbidden';
        }

        return 'computed_hash_not_available';
    }

    private function isPlaceholderSigner(string $signedBy): bool
    {
        return in_array(strtolower(trim($signedBy)), ['', '<operator>', 'operator', 'human', 'codex', 'assistant', 'system', 'claude', 'codex-autosigned', 'atlas'], true);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        try {
            $decoded = json_decode(Storage::disk(self::DISK)->get($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizeStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        $path = preg_replace('#^storage/app/private/#', '', $path) ?? $path;
        $path = preg_replace('#^storage/app/#', '', $path) ?? $path;

        return trim($path, '/');
    }

    private function storageFileHash(string $path): string
    {
        if ($path === '' || ! Storage::disk(self::DISK)->exists($path)) {
            return '';
        }

        return hash('sha256', (string) Storage::disk(self::DISK)->get($path));
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['finalizer_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function prettyJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
