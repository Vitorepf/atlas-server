<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\Governance\ProgrammingEvidenceLedger;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Applies an approved governed Forge patch to the live Obra workspace.
 *
 * The live execution service only mutates a shadow sandbox. This service is
 * the explicit human-review promotion step: it verifies the structured patch
 * artifact, checks workspace drift, writes a rollback backup, applies the
 * patch to the real workspace, then records hardened Programming Governance
 * evidence.
 */
class AtlasForgeGovernedPromotionService
{
    public const SCHEMA_VERSION = 'atlas.forge_governed_promotion.v1';

    public function __construct(
        private readonly ProgrammingEvidenceLedger $evidenceLedger,
        private readonly ProgrammingGovernanceService $governance,
    ) {}

    /**
     * @param  array<string,mixed>  $historyEntry
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $review
     * @return array<string,mixed>
     */
    public function promote(AtlasProject $project, array $historyEntry, array $snapshot, array $review): array
    {
        $promotionId = (string) Str::ulid();
        $governed = data_get($snapshot, 'governed_execution');
        if (! is_array($governed)) {
            return $this->notApplicable($promotionId, $project, 'no_governed_execution_for_run');
        }

        $artifact = data_get($governed, 'promotion_artifact');
        if (! is_array($artifact)) {
            return $this->blocked($promotionId, $project, ['promotion_artifact_missing']);
        }

        $workItem = $this->workItem($governed);
        if (! $workItem) {
            return $this->blocked($promotionId, $project, ['work_item_missing_for_promotion']);
        }

        $workspace = $this->workspace($project, $workItem);
        if ($workspace === null) {
            return $this->blocked($promotionId, $project, ['workspace_required_for_promotion']);
        }

        try {
            $payload = $this->readArtifact($artifact);
            $targetFile = $this->targetFile($payload);
            $this->assertInScope($targetFile, $snapshot);

            $targetPath = $workspace.'/'.$targetFile;
            if (! is_file($targetPath)) {
                return $this->blocked($promotionId, $project, ['target_file_missing_for_promotion']);
            }

            $before = File::get($targetPath);
            $currentHash = hash('sha256', $before);
            $expectedBefore = (string) ($payload['expected_before_hash'] ?? '');
            $expectedAfter = (string) ($payload['expected_after_hash'] ?? '');
            $line = (string) ($payload['line'] ?? '');
            if ($line === '') {
                return $this->blocked($promotionId, $project, ['promotion_patch_line_missing']);
            }

            if ($expectedAfter !== '' && $currentHash === $expectedAfter) {
                return $this->promoted(
                    $promotionId,
                    $project,
                    $workItem,
                    $historyEntry,
                    $review,
                    $targetFile,
                    false,
                    null,
                    'already_promoted',
                    null,
                );
            }

            if ($expectedBefore === '' || $currentHash !== $expectedBefore) {
                return $this->blocked($promotionId, $project, ['workspace_drift_since_governed_execution'], [
                    'current_hash' => $currentHash,
                    'expected_before_hash' => $expectedBefore,
                ]);
            }

            $after = rtrim($before, "\n")."\n".$line."\n";
            $backupPath = $this->backupPath($promotionId, $targetFile);
            File::ensureDirectoryExists(dirname($backupPath));
            File::put($backupPath, $before);
            File::put($targetPath, $after);

            $actualAfter = hash_file('sha256', $targetPath);
            if ($expectedAfter !== '' && $actualAfter !== $expectedAfter) {
                File::put($targetPath, $before);

                return $this->blocked($promotionId, $project, ['promotion_after_hash_mismatch_rolled_back'], [
                    'actual_after_hash' => $actualAfter,
                    'expected_after_hash' => $expectedAfter,
                ]);
            }

            return $this->promoted(
                $promotionId,
                $project,
                $workItem,
                $historyEntry,
                $review,
                $targetFile,
                true,
                $backupPath,
                'promoted',
                is_string($payload['diff_path'] ?? null) ? (string) $payload['diff_path'] : null,
            );
        } catch (Throwable $e) {
            return $this->blocked($promotionId, $project, ['promotion_exception'], [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $promotion
     * @param  array<string,mixed>  $review
     * @return array<string,mixed>
     */
    public function rollback(AtlasProject $project, array $promotion, array $review): array
    {
        $rollbackId = (string) Str::ulid();
        if ((string) ($promotion['promotion_status'] ?? '') !== 'promoted_to_workspace') {
            return $this->rollbackBlocked($rollbackId, $project, ['promotion_not_promoted_to_workspace']);
        }

        $workItem = $this->workItem($promotion);
        if (! $workItem) {
            return $this->rollbackBlocked($rollbackId, $project, ['work_item_missing_for_rollback']);
        }

        $workspace = $this->workspace($project, $workItem);
        if ($workspace === null) {
            return $this->rollbackBlocked($rollbackId, $project, ['workspace_required_for_rollback']);
        }

        $targetFile = (string) data_get($promotion, 'changed_files.0', '');
        if (! $this->relativePathIsSafe($targetFile)) {
            return $this->rollbackBlocked($rollbackId, $project, ['rollback_target_file_invalid']);
        }

        $backupPath = (string) data_get($promotion, 'rollback.backup_path', '');
        if ($backupPath === '' || ! is_file($backupPath) || ! $this->insideRollbackRoot($backupPath)) {
            return $this->rollbackBlocked($rollbackId, $project, ['rollback_backup_missing_or_outside_root']);
        }

        $expectedBackupHash = (string) data_get($promotion, 'rollback.backup_hash', '');
        $actualBackupHash = hash_file('sha256', $backupPath);
        if ($expectedBackupHash !== '' && $actualBackupHash !== $expectedBackupHash) {
            return $this->rollbackBlocked($rollbackId, $project, ['rollback_backup_hash_mismatch'], [
                'expected_backup_hash' => $expectedBackupHash,
                'actual_backup_hash' => $actualBackupHash,
            ]);
        }

        $targetPath = $workspace.'/'.$targetFile;
        if (! is_file($targetPath)) {
            return $this->rollbackBlocked($rollbackId, $project, ['rollback_target_file_missing']);
        }

        $currentHash = hash_file('sha256', $targetPath);
        if ($currentHash === $actualBackupHash) {
            return $this->rolledBack($rollbackId, $project, $workItem, $promotion, $review, $targetFile, false, 'already_rolled_back');
        }

        $expectedCurrentHash = (string) ($promotion['target_after_hash'] ?? '');
        if ($expectedCurrentHash !== '' && $currentHash !== $expectedCurrentHash) {
            return $this->rollbackBlocked($rollbackId, $project, ['workspace_drift_since_promotion'], [
                'current_hash' => $currentHash,
                'expected_current_hash' => $expectedCurrentHash,
            ]);
        }

        try {
            File::put($targetPath, File::get($backupPath));
            $afterHash = hash_file('sha256', $targetPath);
            if ($afterHash !== $actualBackupHash) {
                return $this->rollbackBlocked($rollbackId, $project, ['rollback_restore_hash_mismatch'], [
                    'after_hash' => $afterHash,
                    'backup_hash' => $actualBackupHash,
                ]);
            }

            return $this->rolledBack($rollbackId, $project, $workItem, $promotion, $review, $targetFile, true, 'rolled_back');
        } catch (Throwable $e) {
            return $this->rollbackBlocked($rollbackId, $project, ['rollback_exception'], [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $governed
     */
    private function workItem(array $governed): ?AtlasProgrammingWorkItem
    {
        $id = (string) ($governed['work_item_id'] ?? '');
        if ($id === '') {
            return null;
        }

        return AtlasProgrammingWorkItem::query()->where('id', $id)->first();
    }

    private function workspace(AtlasProject $project, AtlasProgrammingWorkItem $workItem): ?string
    {
        foreach ([$workItem->workspace, data_get($project->metadata, 'workspace_path')] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $real = realpath($candidate);
            if (is_string($real) && is_dir($real)) {
                return rtrim($real, '/');
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $artifact
     * @return array<string,mixed>
     */
    private function readArtifact(array $artifact): array
    {
        $path = (string) ($artifact['path'] ?? '');
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('promotion_artifact_file_missing');
        }
        if (! $this->insidePromotionArtifactRoot($path)) {
            throw new RuntimeException('promotion_artifact_outside_allowed_root');
        }

        $json = File::get($path);
        $expected = (string) ($artifact['sha256'] ?? '');
        if ($expected !== '' && hash('sha256', $json) !== $expected) {
            throw new RuntimeException('promotion_artifact_hash_mismatch');
        }

        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('promotion_artifact_invalid_json');
        }
        if (($payload['schema_version'] ?? null) !== 'atlas.forge_governed_execution.patch_artifact.v1') {
            throw new RuntimeException('promotion_artifact_schema_mismatch');
        }
        if (($payload['operation'] ?? null) !== 'append_line') {
            throw new RuntimeException('promotion_artifact_operation_unsupported');
        }

        return $payload;
    }

    private function insidePromotionArtifactRoot(string $path): bool
    {
        $root = realpath(storage_path('app/forge-governed-exec-artifacts'));
        $real = realpath($path);

        return is_string($root)
            && is_string($real)
            && ($real === $root || str_starts_with($real, $root.'/'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function targetFile(array $payload): string
    {
        $target = (string) ($payload['target_file'] ?? '');
        if (! $this->relativePathIsSafe($target)) {
            throw new RuntimeException('promotion_target_file_unsafe');
        }

        return $target;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function assertInScope(string $targetFile, array $snapshot): void
    {
        $scope = collect((array) data_get($snapshot, 'diff_scope.files', []))
            ->first(fn (mixed $file): bool => is_array($file)
                && (string) ($file['path'] ?? '') === $targetFile
                && (string) ($file['status'] ?? '') === 'in_scope');

        if (! is_array($scope)) {
            throw new RuntimeException('promotion_target_not_in_diff_scope');
        }
        if ((bool) data_get($snapshot, 'diff_scope.completion_gate.completion_claim_allowed', false) !== true) {
            throw new RuntimeException('promotion_completion_gate_not_allowed');
        }
    }

    private function backupPath(string $promotionId, string $targetFile): string
    {
        return storage_path('app/forge-governed-promotions/'.$promotionId.'/before/'.$targetFile);
    }

    /**
     * @param  array<string,mixed>  $historyEntry
     * @param  array<string,mixed>  $review
     */
    private function promoted(
        string $promotionId,
        AtlasProject $project,
        AtlasProgrammingWorkItem $workItem,
        array $historyEntry,
        array $review,
        string $targetFile,
        bool $mutated,
        ?string $backupPath,
        string $status,
        ?string $diffPath,
    ): array {
        $receipt = null;
        $governanceFeedback = null;
        try {
            $receipt = $this->evidenceLedger->record($workItem, [
                'schema_version' => 'atlas.code.forge_workspace_promotion_evidence.v1',
                'evidence_type' => 'forge_workspace_promotion',
                'status' => 'passed',
                'command' => 'POST /atlas-code/works/{obra}/forge/reviews decision=approved',
                'output' => "forge_workspace_promotion={$status}; file={$targetFile}",
                'files' => [$targetFile],
                'tests' => array_values(array_filter([
                    (string) data_get($review, 'validation_result.command', ''),
                ])),
                'diff_path' => $diffPath,
                'summary' => 'Atlas Code Forge governed patch promoted to live Obra workspace after human approval.',
                'execution_mode' => 'governed_workspace_promotion',
                'parent_receipt_id' => data_get($review, 'receipt_id'),
            ]);
            $this->governance->appendEvidence($workItem, $receipt);
            $verification = $this->governance->verify($workItem->refresh(), ['evidence-required', 'scope-guard']);
            $governanceFeedback = [
                'schema_version' => 'atlas.code.forge_workspace_promotion_governance_feedback.v1',
                'status' => 'synced',
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'receipt_id' => $receipt['receipt_id'] ?? null,
                'gate_summary' => $verification['gate_summary'] ?? [],
            ];
        } catch (Throwable $e) {
            if ($mutated && $backupPath !== null && is_file($backupPath)) {
                File::put($this->workspacePath($workItem).'/'.$targetFile, File::get($backupPath));
            }

            return $this->blocked($promotionId, $project, ['promotion_evidence_failed_and_rolled_back'], [
                'reason' => $e->getMessage(),
                'target_file' => $targetFile,
            ]);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'promotion_id' => $promotionId,
            'status' => $status,
            'promotion_status' => 'promoted_to_workspace',
            'obra_id' => (string) $project->getKey(),
            'history_id' => $historyEntry['history_id'] ?? null,
            'review_id' => $review['review_id'] ?? null,
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'changed_files' => [$targetFile],
            'live_workspace_mutated' => $mutated,
            'idempotent' => ! $mutated,
            'rollback' => [
                'available' => $backupPath !== null,
                'backup_path' => $backupPath,
                'backup_hash' => $backupPath !== null && is_file($backupPath) ? hash_file('sha256', $backupPath) : null,
                'command' => $backupPath !== null ? "restore '{$backupPath}' to '{$targetFile}'" : null,
            ],
            'target_before_hash' => $backupPath !== null && is_file($backupPath) ? hash_file('sha256', $backupPath) : null,
            'target_after_hash' => is_file($this->workspacePath($workItem).'/'.$targetFile)
                ? hash_file('sha256', $this->workspacePath($workItem).'/'.$targetFile)
                : null,
            'evidence' => [
                'receipt_id' => $receipt['receipt_id'] ?? null,
                'engineering_evidence_id' => data_get($receipt, 'storage.id'),
                'persisted' => (bool) data_get($receipt, 'storage.persisted', false),
            ],
            'governance_feedback' => $governanceFeedback,
            'remaining_blockers' => [],
            'external_provider_call' => false,
            'promoted_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $promotion
     * @param  array<string,mixed>  $review
     * @return array<string,mixed>
     */
    private function rolledBack(
        string $rollbackId,
        AtlasProject $project,
        AtlasProgrammingWorkItem $workItem,
        array $promotion,
        array $review,
        string $targetFile,
        bool $mutated,
        string $status,
    ): array {
        $receipt = null;
        $governanceFeedback = null;
        try {
            $receipt = $this->evidenceLedger->record($workItem, [
                'schema_version' => 'atlas.code.forge_workspace_rollback_evidence.v1',
                'evidence_type' => 'forge_workspace_rollback',
                'status' => 'passed',
                'command' => 'POST /atlas-code/works/{obra}/forge/promotions/{promotion}/rollback',
                'output' => "forge_workspace_rollback={$status}; file={$targetFile}",
                'files' => [$targetFile],
                'tests' => [],
                'summary' => 'Atlas Code Forge governed patch rolled back from live Obra workspace.',
                'execution_mode' => 'governed_workspace_rollback',
                'parent_receipt_id' => data_get($promotion, 'evidence.receipt_id'),
            ]);
            $this->governance->appendEvidence($workItem, $receipt);
            $verification = $this->governance->verify($workItem->refresh(), ['evidence-required', 'scope-guard']);
            $governanceFeedback = [
                'schema_version' => 'atlas.code.forge_workspace_rollback_governance_feedback.v1',
                'status' => 'synced',
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'receipt_id' => $receipt['receipt_id'] ?? null,
                'gate_summary' => $verification['gate_summary'] ?? [],
            ];
        } catch (Throwable $e) {
            return $this->rollbackBlocked($rollbackId, $project, ['rollback_evidence_failed'], [
                'reason' => $e->getMessage(),
                'target_file' => $targetFile,
            ]);
        }

        return [
            'schema_version' => 'atlas.forge_governed_rollback.v1',
            'rollback_id' => $rollbackId,
            'promotion_id' => $promotion['promotion_id'] ?? null,
            'status' => $status,
            'promotion_status' => 'rolled_back',
            'obra_id' => (string) $project->getKey(),
            'review_id' => $review['review_id'] ?? null,
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'changed_files' => [$targetFile],
            'live_workspace_mutated' => $mutated,
            'idempotent' => ! $mutated,
            'target_hash_after_rollback' => hash_file('sha256', $this->workspacePath($workItem).'/'.$targetFile),
            'evidence' => [
                'receipt_id' => $receipt['receipt_id'] ?? null,
                'engineering_evidence_id' => data_get($receipt, 'storage.id'),
                'persisted' => (bool) data_get($receipt, 'storage.persisted', false),
            ],
            'governance_feedback' => $governanceFeedback,
            'remaining_blockers' => [],
            'external_provider_call' => false,
            'rolled_back_at' => now()->toJSON(),
        ];
    }

    private function workspacePath(AtlasProgrammingWorkItem $workItem): string
    {
        $workspace = realpath((string) $workItem->workspace);

        return is_string($workspace) ? rtrim($workspace, '/') : rtrim(base_path(), '/');
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $promotionId, AtlasProject $project, array $blockers, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'promotion_id' => $promotionId,
            'status' => 'blocked',
            'promotion_status' => 'blocked',
            'obra_id' => (string) $project->getKey(),
            'changed_files' => [],
            'live_workspace_mutated' => false,
            'rollback' => ['available' => false],
            'remaining_blockers' => array_values(array_unique($blockers)),
            'external_provider_call' => false,
            'promoted_at' => null,
        ], $extra);
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function rollbackBlocked(string $rollbackId, AtlasProject $project, array $blockers, array $extra = []): array
    {
        return array_merge([
            'schema_version' => 'atlas.forge_governed_rollback.v1',
            'rollback_id' => $rollbackId,
            'status' => 'blocked',
            'promotion_status' => 'rollback_blocked',
            'obra_id' => (string) $project->getKey(),
            'changed_files' => [],
            'live_workspace_mutated' => false,
            'remaining_blockers' => array_values(array_unique($blockers)),
            'external_provider_call' => false,
            'rolled_back_at' => null,
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    private function notApplicable(string $promotionId, AtlasProject $project, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'promotion_id' => $promotionId,
            'status' => 'not_applicable',
            'promotion_status' => 'not_applicable',
            'obra_id' => (string) $project->getKey(),
            'reason' => $reason,
            'changed_files' => [],
            'live_workspace_mutated' => false,
            'rollback' => ['available' => false],
            'remaining_blockers' => [],
            'external_provider_call' => false,
        ];
    }

    private function relativePathIsSafe(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }

    private function insideRollbackRoot(string $path): bool
    {
        $root = realpath(storage_path('app/forge-governed-promotions'));
        $real = realpath($path);

        return is_string($root)
            && is_string($real)
            && ($real === $root || str_starts_with($real, $root.'/'));
    }
}
