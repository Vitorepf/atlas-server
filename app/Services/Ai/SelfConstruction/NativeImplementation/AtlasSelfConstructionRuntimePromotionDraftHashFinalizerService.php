<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Support\IsPlaceholderValueShared;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * computeDraftHash() is a self-contained, file-free hashing contract over an operator-supplied
 * runtime promotion draft structure (evidence_refs, verdict, blockers, rollback_plan,
 * runtime_target, plus arbitrary other fields). It is independent of finalize()'s
 * manifest/draft-file loading pipeline and never touches storage.
 *
 *   - Stability (AC2): the draft is recursively ksorted before hashing, so key order never
 *     affects draft_hash; volatile timestamp-shaped fields are excluded from the hash entirely.
 *   - Sensitivity (AC3): draft_hash changes whenever evidence_refs, verdict, blockers,
 *     rollback_plan or runtime_target changes, since each is hashed verbatim (not excluded).
 *   - Provider-safety (AC4): provider-sensitive fields (raw transcripts/responses, API keys,
 *     session/request ids) are stripped from the hash input; excluded_field_paths reports every
 *     dotted path that was actually present and stripped from THIS draft.
 */
final class AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService
{
    use IsPlaceholderValueShared;

    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_draft_hash_finalizer.v1';

    public const MODE = 'read_only_runtime_promotion_draft_hash_finalizer';

    public const DRAFT_HASH_SCHEMA_VERSION = 'atlas.self_construction.runtime_promotion_draft_hash.v1';

    private const EXCLUDED_DRAFT_FIELDS = [
        'generated_at',
        'computed_at',
        'finalized_at',
        'timestamp',
        'created_at',
        'updated_at',
        'provider_transcript',
        'provider_response',
        'raw_provider_output',
        'api_key',
        'session_id',
        'request_id',
    ];

    private const DISK = 'local';

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function computeDraftHash(array $draft): array
    {
        $excludedFieldPaths = $this->excludedFieldPaths($draft, '');
        $canonical = $this->ksortRecursive($this->stripExcludedFields($draft));
        $draftHash = hash('sha256', (string) json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'schema_version' => self::DRAFT_HASH_SCHEMA_VERSION,
            'draft_hash' => $draftHash,
            'excluded_field_paths' => $excludedFieldPaths,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function excludedFieldPaths(array $node, string $prefix): array
    {
        $paths = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (in_array((string) $key, self::EXCLUDED_DRAFT_FIELDS, true)) {
                $paths[] = $path;

                continue;
            }
            if (is_array($value)) {
                $paths = array_merge($paths, $this->excludedFieldPaths($value, $path));
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function stripExcludedFields(array $node): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if (in_array((string) $key, self::EXCLUDED_DRAFT_FIELDS, true)) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->stripExcludedFields($value) : $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function finalize(array $options = []): array
    {
        $writeRequested = (bool) ($options['write_computed_runtime_promotion_receipt_hash'] ?? false);
        $loaded = $this->loadDraft((string) ($options['operator_draft_workspace_path'] ?? ''));
        $receipt = (array) data_get($loaded, 'receipt_payload', []);
        $draftPath = (string) data_get($loaded, 'draft_path', '');
        $draftLoaded = $receipt !== [] && (string) data_get($loaded, 'status') === 'loaded_for_hash_finalization';
        $computedHash = $draftLoaded
            ? (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt)
            : '';
        $payloadWithComputedHash = $draftLoaded ? array_replace($receipt, ['receipt_hash' => $computedHash]) : [];
        $placeholderFields = $draftLoaded ? $this->placeholderFields($receipt) : [];
        $forbiddenRuntimeFlagsTrue = $draftLoaded ? $this->forbiddenRuntimeFlagsTrue($receipt) : [];
        $operatorReady = $draftLoaded
            && ! in_array('signed_by', $placeholderFields, true)
            && ! in_array('reason', $placeholderFields, true);
        $canWrite = $draftLoaded
            && $computedHash !== ''
            && $operatorReady
            && $forbiddenRuntimeFlagsTrue === [];
        $writeBlocker = $this->writeBlocker(
            draftLoaded: $draftLoaded,
            canWrite: $canWrite,
            placeholderFields: $placeholderFields,
            forbiddenRuntimeFlagsTrue: $forbiddenRuntimeFlagsTrue,
        );

        $beforeHash = $draftLoaded ? $this->storageFileHash($draftPath) : '';
        $written = false;
        $afterHash = '';
        if ($writeRequested && $canWrite) {
            Storage::disk(self::DISK)->put($draftPath, json_encode($payloadWithComputedHash, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $written = true;
            $afterHash = $this->storageFileHash($draftPath);
        }

        $status = match (true) {
            $written => 'draft_hash_written',
            $canWrite && $writeRequested === false => 'ready_to_write_computed_hash',
            $draftLoaded => 'blocked_operator_draft_not_ready_for_hash_write',
            default => 'blocked_operator_draft_workspace_required',
        };

        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'requested_path' => (string) data_get($loaded, 'requested_path', ''),
            'manifest_path' => (string) data_get($loaded, 'manifest_path', ''),
            'draft_path' => $draftPath,
            'draft_loaded' => $draftLoaded,
            'load_status' => (string) data_get($loaded, 'status', 'not_requested'),
            'load_violations' => (array) data_get($loaded, 'violations', []),
            'write_requested' => $writeRequested,
            'can_write_hash_to_draft' => $canWrite,
            'written' => $written,
            'write_blocker' => $writeBlocker,
            'original_receipt_hash' => (string) ($receipt['receipt_hash'] ?? ''),
            'computed_receipt_hash' => $computedHash,
            'input_hash_matches_computed_hash' => $computedHash !== '' && (string) ($receipt['receipt_hash'] ?? '') === $computedHash,
            'payload_with_computed_hash' => $payloadWithComputedHash,
            'placeholder_fields' => $placeholderFields,
            'forbidden_runtime_flags_true' => $forbiddenRuntimeFlagsTrue,
            'draft_file_sha256_before' => $beforeHash,
            'draft_file_sha256_after' => $afterHash,
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
                $written ? 'draft_hash_finalizer_writes_only_receipt_hash_to_operator_draft' : 'draft_hash_finalizer_does_not_write_without_explicit_flag',
                'draft_hash_finalizer_does_not_sign_for_operator',
                'draft_hash_finalizer_does_not_persist_runtime_promotion_receipt',
                'draft_hash_finalizer_does_not_enable_runtime',
                'draft_hash_finalizer_does_not_start_process',
                'draft_hash_finalizer_does_not_call_provider',
                'draft_hash_finalizer_does_not_dispatch',
                'draft_hash_finalizer_does_not_spend_tokens',
            ],
            'next_action' => $written
                ? 'run_runtime_promotion_endgame_against_the_same_operator_draft_workspace_path'
                : ($canWrite
                    ? 'rerun_with_write_computed_runtime_promotion_receipt_hash_when_operator_is_ready'
                    : 'operator_must_supply_real_signed_by_reason_and_keep_runtime_flags_false'),
        ];
        $result['finalizer_hash'] = $this->stableHash($result);

        return $result;
    }

    /** @return array<string, mixed> */
    private function loadDraft(string $requestedPath): array
    {
        $requestedPath = trim($requestedPath);
        if ($requestedPath === '') {
            return $this->blockedLoad('', '', '', ['operator_draft_workspace_path_required']);
        }

        $path = $this->normalizeStoragePath($requestedPath);
        if ($path === '') {
            return $this->blockedLoad($requestedPath, '', '', ['invalid_operator_draft_workspace_path']);
        }

        $manifestPath = '';
        $draftPath = '';
        if (str_ends_with($path, 'runtime-promotion.json')) {
            $draftPath = $path;
        } else {
            $manifestPath = str_ends_with($path, 'manifest.json') ? $path : rtrim($path, '/').'/manifest.json';
            if (! Storage::disk(self::DISK)->exists($manifestPath)) {
                return $this->blockedLoad($requestedPath, $manifestPath, '', ['operator_draft_workspace_manifest_not_found']);
            }
            $manifest = $this->readJson($manifestPath);
            foreach ((array) data_get($manifest, 'files', []) as $file) {
                if ((string) data_get($file, 'artifact') === 'runtime_promotion_receipt') {
                    $draftPath = (string) data_get($file, 'draft_path', '');
                    break;
                }
            }
        }

        if ($draftPath === '' || ! Storage::disk(self::DISK)->exists($draftPath)) {
            return $this->blockedLoad($requestedPath, $manifestPath, $draftPath, ['runtime_promotion_draft_file_not_found']);
        }

        $payload = $this->readJson($draftPath);
        if ($payload === []) {
            return $this->blockedLoad($requestedPath, $manifestPath, $draftPath, ['runtime_promotion_draft_file_invalid_json_or_empty']);
        }

        return [
            'status' => 'loaded_for_hash_finalization',
            'requested_path' => $requestedPath,
            'manifest_path' => $manifestPath,
            'draft_path' => $draftPath,
            'receipt_payload' => $payload,
            'violations' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function blockedLoad(string $requestedPath, string $manifestPath, string $draftPath, array $violations): array
    {
        return [
            'status' => 'blocked',
            'requested_path' => $requestedPath,
            'manifest_path' => $manifestPath,
            'draft_path' => $draftPath,
            'receipt_payload' => [],
            'violations' => $violations,
        ];
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

    /** @param array<string, mixed> $receipt */
    private function placeholderFields(array $receipt): array
    {
        $fields = [];
        if ($this->isPlaceholderSigner((string) ($receipt['signed_by'] ?? ''))) {
            $fields[] = 'signed_by';
        }
        $reason = trim((string) ($receipt['reason'] ?? ''));
        if (mb_strlen($reason) < 32 || $this->isPlaceholderValue($reason)) {
            $fields[] = 'reason';
        }

        return $fields;
    }

    /** @param array<string, mixed> $receipt */
    private function forbiddenRuntimeFlagsTrue(array $receipt): array
    {
        $true = [];
        foreach (['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed'] as $flag) {
            if ((bool) ($receipt[$flag] ?? false) === true) {
                $true[] = $flag;
            }
        }

        return $true;
    }

    private function writeBlocker(bool $draftLoaded, bool $canWrite, array $placeholderFields, array $forbiddenRuntimeFlagsTrue): string
    {
        if ($canWrite) {
            return '';
        }
        if (! $draftLoaded) {
            return 'runtime_promotion_draft_not_loaded';
        }
        if ($placeholderFields !== []) {
            return 'operator_identity_or_reason_not_ready';
        }
        if ($forbiddenRuntimeFlagsTrue !== []) {
            return 'runtime_enabling_flags_forbidden';
        }

        return 'computed_hash_not_available';
    }

    private function isPlaceholderSigner(string $signedBy): bool
    {
        $normalized = strtolower(trim($signedBy));

        return in_array($normalized, [
            '',
            '<operator>',
            'operator',
            'human',
            'codex',
            'assistant',
            'system',
            'claude',
            'codex-autosigned',
            'atlas',
        ], true) || $this->isPlaceholderValue($normalized);
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
}
