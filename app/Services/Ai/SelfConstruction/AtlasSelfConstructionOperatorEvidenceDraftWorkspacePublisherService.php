<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService
{
    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function ksortRecursive(array $value): array
    {
        $this->ksortRecursiveByReference($value);

        return $value;
    }
    public const SCHEMA_VERSION = 'atlas.self_construction.operator_evidence_draft_workspace_publisher.v1';

    public const MODE = 'operator_evidence_draft_workspace_publisher';

    private const DISK = 'local';

    private const DESTINATION_PREFIX = 'atlas/self-construction/operator-submissions';

    private const RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH = 'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json';

    private const REAL_PROVIDER_SMOKE_PRIVATE_PATH = 'storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json';

    private const COMPLETION_RECEIPT_PRIVATE_PATH = 'storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json';

    /** @return array<string, mixed> */
    public function publish(array $options = []): array
    {
        $requestedPath = (string) ($options['operator_draft_workspace_path'] ?? '');
        $publishRequested = (bool) ($options['publish_operator_draft_workspace'] ?? false);
        $inspection = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect([
            'operator_draft_workspace_path' => $requestedPath,
        ]);
        $finalization = (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => $requestedPath,
            'write_computed_operator_draft_hashes' => false,
        ]);

        $artifactFinalization = (array) data_get($finalization, 'artifacts', []);
        $published = [];
        $artifacts = [];
        $publishableCopies = [];

        foreach ((array) data_get($inspection, 'manifest.files', []) as $file) {
            $artifact = (string) data_get($file, 'artifact', '');
            if (! in_array($artifact, ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'], true)) {
                continue;
            }

            $draftPath = $this->normalizeStoragePath((string) data_get($file, 'draft_path', ''));
            $destinationPath = $this->normalizeStoragePath((string) data_get($file, 'recommended_file_path', ''));
            $finalizer = (array) ($artifactFinalization[$artifact] ?? []);
            $canPublish = $this->canPublishArtifact($draftPath, $destinationPath, $finalizer);
            $publishedArtifact = false;
            $sourceShaBefore = $this->storageSha($draftPath);
            $destinationShaBefore = $this->storageSha($destinationPath);

            if ($canPublish) {
                $publishableCopies[$artifact] = [
                    'draft_path' => $draftPath,
                    'destination_path' => $destinationPath,
                ];
            }

            $artifacts[$artifact] = [
                'artifact' => $artifact,
                'draft_path' => $draftPath,
                'destination_path' => $destinationPath,
                'draft_exists' => $draftPath !== '' && Storage::disk(self::DISK)->exists($draftPath),
                'destination_allowed' => $this->destinationAllowed($destinationPath),
                'hash_field' => (string) data_get($finalizer, 'hash_field', ''),
                'computed_hash' => (string) data_get($finalizer, 'computed_hash', ''),
                'input_hash_matches_computed_hash' => (bool) data_get($finalizer, 'input_hash_matches_computed_hash', false),
                'can_write_hash_to_draft' => (bool) data_get($finalizer, 'can_write_hash_to_draft', false),
                'placeholder_fields' => (array) data_get($finalizer, 'placeholder_fields', []),
                'invalid_hash_fields' => (array) data_get($finalizer, 'invalid_hash_fields', []),
                'forbidden_flags_true' => (array) data_get($finalizer, 'forbidden_flags_true', []),
                'can_publish_to_submission_path' => $canPublish,
                'publish_requested' => $publishRequested,
                'published' => $publishedArtifact,
                'publish_blocker' => $this->publishBlocker($draftPath, $destinationPath, $finalizer, $canPublish),
                'draft_file_sha256' => $sourceShaBefore,
                'destination_file_sha256_before' => $destinationShaBefore,
                'destination_file_sha256_after' => $this->storageSha($destinationPath),
            ];
        }

        $artifactCount = count($artifacts);
        $publishableCount = count(array_filter($artifacts, static fn (array $artifact): bool => (bool) $artifact['can_publish_to_submission_path']));
        $workspaceLoaded = (string) data_get($inspection, 'status') !== 'no_workspace'
            && (bool) data_get($finalization, 'workspace_loaded', false);
        $atomicBundleReady = $workspaceLoaded && $artifactCount === 3 && $publishableCount === $artifactCount;

        if ($publishRequested && $atomicBundleReady) {
            foreach ($publishableCopies as $artifact => $paths) {
                $draftPath = (string) $paths['draft_path'];
                $destinationPath = (string) $paths['destination_path'];
                Storage::disk(self::DISK)->put($destinationPath, (string) Storage::disk(self::DISK)->get($draftPath));
                $published[] = (string) $artifact;
                $artifacts[$artifact]['published'] = true;
                $artifacts[$artifact]['destination_file_sha256_after'] = $this->storageSha($destinationPath);
            }
        }

        $publishedCount = count($published);

        $status = match (true) {
            ! $workspaceLoaded => 'blocked_operator_draft_workspace_required',
            $publishRequested && $publishedCount === $artifactCount && $artifactCount > 0 => 'operator_draft_workspace_published',
            $publishRequested => 'blocked_operator_draft_workspace_not_publishable',
            $publishableCount === $artifactCount && $artifactCount > 0 => 'ready_to_publish_operator_draft_workspace',
            $publishableCount > 0 => 'partially_ready_to_publish_operator_draft_workspace',
            default => 'blocked_operator_draft_workspace_not_publishable',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'requested_path' => $requestedPath,
            'workspace_loaded' => $workspaceLoaded,
            'workspace_directory' => (string) data_get($inspection, 'workspace_directory', ''),
            'manifest_path' => (string) data_get($inspection, 'manifest_path', ''),
            'inspector_status' => (string) data_get($inspection, 'status', ''),
            'inspector_violation_count' => (int) data_get($inspection, 'violation_count', 0),
            'inspector_warning_count' => (int) data_get($inspection, 'warning_count', 0),
            'finalizer_status' => (string) data_get($finalization, 'status', ''),
            'publish_requested' => $publishRequested,
            'artifact_count' => $artifactCount,
            'publishable_artifact_count' => $publishableCount,
            'blocked_artifact_count' => $artifactCount - $publishableCount,
            'published_artifact_count' => $publishedCount,
            'published_artifacts' => $published,
            'atomic_bundle_publish_required' => true,
            'atomic_bundle_ready' => $atomicBundleReady,
            'artifacts' => $artifacts,
            'publish_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-workspace-publisher-status --operator-draft-workspace-path='.($requestedPath === '' ? '<workspace_path>' : $requestedPath).' --publish-operator-draft-workspace --json',
            'post_publish_readiness_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --runtime-promotion-receipt-json=@'.self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH.' --real-provider-smoke-json=@'.self::REAL_PROVIDER_SMOKE_PRIVATE_PATH.' --completion-receipt-json=@'.self::COMPLETION_RECEIPT_PRIVATE_PATH.' --json',
            'post_publish_persistence_sequence' => $postPublishPersistenceSequence = $this->postPublishPersistenceSequence(),
            'post_publish_persistence_step_count' => count($postPublishPersistenceSequence),
            'post_publish_persistence_sequence_ordered' => true,
            'requires_explicit_operator_persistence_commands' => true,
            'human_receipt_persistence_requires_runtime_and_smoke_green' => true,
            'can_persist_from_publisher' => false,
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
                $publishRequested ? 'operator_draft_workspace_publisher_writes_only_submission_json_copies' : 'operator_draft_workspace_publisher_does_not_write_without_explicit_flag',
                'operator_draft_workspace_publisher_does_not_persist_completion_evidence',
                'operator_draft_workspace_publisher_does_not_sign_for_operator',
                'operator_draft_workspace_publisher_does_not_call_provider',
                'operator_draft_workspace_publisher_does_not_spend_tokens',
                'operator_draft_workspace_publisher_does_not_dispatch',
                'operator_draft_workspace_publisher_does_not_enable_runtime',
                'operator_draft_workspace_publisher_does_not_promote_completion',
            ],
            'next_action' => $publishRequested && $publishedCount > 0
                ? 'run_operator_evidence_submission_readiness_against_published_submission_paths'
                : ($publishableCount > 0
                    ? 'rerun_with_publish_operator_draft_workspace_after_operator_review'
                    : 'operator_must_finalize_hashes_and_remove_blockers_before_publishing'),
        ];
        $payload['publisher_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $finalizer */
    private function canPublishArtifact(string $draftPath, string $destinationPath, array $finalizer): bool
    {
        return $draftPath !== ''
            && Storage::disk(self::DISK)->exists($draftPath)
            && $this->destinationAllowed($destinationPath)
            && (bool) data_get($finalizer, 'can_write_hash_to_draft', false)
            && (bool) data_get($finalizer, 'input_hash_matches_computed_hash', false)
            && (array) data_get($finalizer, 'placeholder_fields', []) === []
            && (array) data_get($finalizer, 'invalid_hash_fields', []) === []
            && (array) data_get($finalizer, 'forbidden_flags_true', []) === [];
    }

    private function destinationAllowed(string $path): bool
    {
        return $path !== ''
            && str_starts_with($path, self::DESTINATION_PREFIX.'/')
            && in_array(basename($path), ['runtime-promotion.json', 'real-provider-smoke.json', 'completion-receipt.json'], true);
    }

    /** @param array<string, mixed> $finalizer */
    private function publishBlocker(string $draftPath, string $destinationPath, array $finalizer, bool $canPublish): string
    {
        if ($canPublish) {
            return '';
        }
        if ($draftPath === '' || ! Storage::disk(self::DISK)->exists($draftPath)) {
            return 'draft_file_not_found';
        }
        if (! $this->destinationAllowed($destinationPath)) {
            return 'recommended_destination_path_not_allowed';
        }
        if ((array) data_get($finalizer, 'placeholder_fields', []) !== []) {
            return 'operator_placeholders_remaining';
        }
        if ((array) data_get($finalizer, 'invalid_hash_fields', []) !== []) {
            return 'required_evidence_hashes_invalid';
        }
        if ((array) data_get($finalizer, 'forbidden_flags_true', []) !== []) {
            return 'runtime_or_completion_flags_forbidden';
        }
        if (! (bool) data_get($finalizer, 'input_hash_matches_computed_hash', false)) {
            return 'draft_hash_not_finalized';
        }

        return 'draft_not_publishable';
    }

    /** @return array<int, array<string, mixed>> */
    private function postPublishPersistenceSequence(): array
    {
        return [
            [
                'order' => 1,
                'id' => 'persist_runtime_promotion_receipt',
                'artifact' => 'runtime_promotion_receipt',
                'canonical_submission_path' => 'storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json',
                'canonical_submission_private_storage_path' => self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH,
                'command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@'.self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH.' --persist-runtime-promotion-receipt --json',
                'requires_explicit_persistence_flag' => true,
                'required_previous_steps' => [],
                'writes_completion_evidence_registry' => true,
                'can_run_from_publisher' => false,
            ],
            [
                'order' => 2,
                'id' => 'persist_real_provider_smoke',
                'artifact' => 'real_provider_smoke',
                'canonical_submission_path' => 'storage/app/atlas/self-construction/operator-submissions/real-provider-smoke.json',
                'canonical_submission_private_storage_path' => self::REAL_PROVIDER_SMOKE_PRIVATE_PATH,
                'command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@'.self::REAL_PROVIDER_SMOKE_PRIVATE_PATH.' --persist-completion-evidence --json',
                'requires_explicit_persistence_flag' => true,
                'required_previous_steps' => ['persist_runtime_promotion_receipt'],
                'writes_completion_evidence_registry' => true,
                'can_run_from_publisher' => false,
            ],
            [
                'order' => 3,
                'id' => 'persist_human_completion_receipt',
                'artifact' => 'human_completion_receipt',
                'canonical_submission_path' => 'storage/app/atlas/self-construction/operator-submissions/completion-receipt.json',
                'canonical_submission_private_storage_path' => self::COMPLETION_RECEIPT_PRIVATE_PATH,
                'command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@'.self::COMPLETION_RECEIPT_PRIVATE_PATH.' --persist-completion-evidence --json',
                'requires_explicit_persistence_flag' => true,
                'required_previous_steps' => ['persist_runtime_promotion_receipt', 'persist_real_provider_smoke'],
                'writes_completion_evidence_registry' => true,
                'can_run_from_publisher' => false,
            ],
            [
                'order' => 4,
                'id' => 'refresh_terminal_loop_operational_proof',
                'artifact' => 'terminal_loop_operational_proof',
                'canonical_submission_path' => '',
                'command' => $this->terminalLoopOperationalProofCommand(),
                'requires_explicit_persistence_flag' => false,
                'required_previous_steps' => ['persist_runtime_promotion_receipt', 'persist_real_provider_smoke', 'persist_human_completion_receipt'],
                'writes_completion_evidence_registry' => false,
                'can_run_from_publisher' => false,
            ],
            [
                'order' => 5,
                'id' => 'rerun_completion_audit_with_terminal_loop_operational_proof',
                'artifact' => 'atlas_self_construction_os_completion_audit',
                'canonical_submission_path' => '',
                'command' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                'requires_explicit_persistence_flag' => false,
                'required_previous_steps' => ['persist_runtime_promotion_receipt', 'persist_real_provider_smoke', 'persist_human_completion_receipt', 'refresh_terminal_loop_operational_proof'],
                'writes_completion_evidence_registry' => false,
                'can_run_from_publisher' => false,
            ],
            [
                'order' => 6,
                'id' => 'rerun_completion_audit',
                'artifact' => 'atlas_self_construction_os_completion_audit',
                'canonical_submission_path' => '',
                'command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'requires_explicit_persistence_flag' => false,
                'required_previous_steps' => ['persist_runtime_promotion_receipt', 'persist_real_provider_smoke', 'persist_human_completion_receipt'],
                'writes_completion_evidence_registry' => false,
                'can_run_from_publisher' => false,
            ],
        ];
    }

    private function terminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
    }

    private function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json';
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

    private function storageSha(string $path): string
    {
        if ($path === '' || ! Storage::disk(self::DISK)->exists($path)) {
            return '';
        }

        return hash('sha256', (string) Storage::disk(self::DISK)->get($path));
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['publisher_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
