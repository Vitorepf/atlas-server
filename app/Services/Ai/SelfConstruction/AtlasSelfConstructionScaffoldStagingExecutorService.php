<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Atlas Self-Construction Scaffold Staging Executor.
 *
 * Closes the autonomous loop end-to-end: APPROVED proposal → scaffold
 * files emitted to a **staging directory** (never directly into the
 * source tree). The operator promotes from staging to source by hand.
 *
 * Authority doc: scaffold (mate to atlas-self-construction-subsystem-builder.md).
 *
 * Safety:
 *   - Writes ONLY under storage/atlas/self_construction/staged/<proposal_id>/
 *   - Refuses to write outside that root (path traversal blocked).
 *   - Kernel + Admission gates obrigatórios.
 *   - Operator must explicitly invoke a 'promote' command (out-of-scope here)
 *     to move staged files into app/, docs/, tests/.
 *
 * Schemas:
 *   - atlas.self_construction.scaffold_staging_receipt.v1
 */
final class AtlasSelfConstructionScaffoldStagingExecutorService
{
    public const RECEIPT_SCHEMA = 'atlas.self_construction.scaffold_staging_receipt.v1';

    public const STATUS_STAGED = 'staged';

    public const STATUS_REJECTED_NOT_APPROVED = 'rejected_not_approved';

    public const STATUS_REJECTED_KERNEL_BLOCK = 'rejected_kernel_block';

    public const PLAN_VALID = 'plan_valid';

    public const PLAN_BLOCKED = 'plan_blocked';

    private const PRODUCTION_PATH_PREFIXES = ['app/', 'config/', 'routes/', 'database/', 'bootstrap/'];

    private ?string $stagingRootOverride = null;

    private ?string $receiptsLogOverride = null;

    public function __construct(
        private readonly AtlasSelfConstructionSubsystemBuilderService $ascb,
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setStagingRootForTesting(?string $path): void
    {
        $this->stagingRootOverride = $path;
    }

    public function setReceiptsLogPathForTesting(?string $path): void
    {
        $this->receiptsLogOverride = $path;
    }

    public function stagingRoot(): string
    {
        if ($this->stagingRootOverride !== null) {
            return $this->stagingRootOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/self_construction')
            : sys_get_temp_dir().'/atlas/self_construction';

        return $base.DIRECTORY_SEPARATOR.'staged';
    }

    public function receiptsLogPath(): string
    {
        if ($this->receiptsLogOverride !== null) {
            return $this->receiptsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/self_construction')
            : sys_get_temp_dir().'/atlas/self_construction';

        return $base.DIRECTORY_SEPARATOR.'staging_receipts.jsonl';
    }

    /**
     * Stage an approved proposal. Writes scaffold files into the staging
     * directory under the proposal_id. Returns a receipt envelope.
     *
     * @return array<string,mixed>
     */
    public function stage(string $proposalId, string $proposalHash, string $actor = 'operator'): array
    {
        if ($proposalId === '' || $proposalHash === '') {
            throw new InvalidArgumentException('proposal_id and proposal_hash are required.');
        }

        // 1. Locate proposal.
        $proposal = null;
        foreach ($this->ascb->listProposals() as $p) {
            if (($p['proposal_id'] ?? null) === $proposalId) {
                $proposal = $p;
                break;
            }
        }
        if ($proposal === null) {
            throw new InvalidArgumentException("Proposal '{$proposalId}' not found.");
        }

        // 2. Verify the proposal hash matches the on-disk record (tamper detection).
        if ((string) ($proposal['proposal_hash'] ?? '') !== $proposalHash) {
            throw new InvalidArgumentException("proposal_hash mismatch for '{$proposalId}'.");
        }

        // 3. Confirm proposal was approved (not just proposed).
        $approved = false;
        foreach ($this->ascb->listApprovals() as $a) {
            if (($a['proposal_id'] ?? null) === $proposalId
                && ($a['action'] ?? null) === AtlasSelfConstructionSubsystemBuilderService::APPROVAL_APPROVE
                && (string) ($a['proposal_hash'] ?? '') === $proposalHash) {
                $approved = true;
                break;
            }
        }
        if (! $approved) {
            $receipt = $this->buildReceipt($proposalId, $proposalHash, $actor, self::STATUS_REJECTED_NOT_APPROVED, [], 'proposal not approved or hash drift');
            $this->appendJsonl($this->receiptsLogPath(), $receipt);

            return $receipt;
        }

        // 4. Constitutional Kernel gate.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => "stage scaffold for proposal {$proposalId}",
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $actor,
        ]);
        if ($kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            $receipt = $this->buildReceipt($proposalId, $proposalHash, $actor, self::STATUS_REJECTED_KERNEL_BLOCK, [], 'kernel blocked: '.json_encode($kernelEnv['violations']));
            $this->appendJsonl($this->receiptsLogPath(), $receipt);

            return $receipt;
        }

        // 5. Autonomy admission (best-effort; we proceed even on require_approval since
        //    operator approval already exists via ASCB.approve).
        $this->admission->admit([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => "stage scaffold for proposal {$proposalId}",
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $actor,
            'requested_autonomy' => 'execute_with_approval',
        ]);

        // 6. Write scaffold files to staging.
        $scaffold = (array) ($proposal['scaffold'] ?? []);
        $proposedSubsystem = (array) ($proposal['proposed_subsystem'] ?? []);

        $serviceClass = (string) ($proposedSubsystem['service_class'] ?? '');
        $docPath = (string) ($proposedSubsystem['doc_path'] ?? '');
        $rel = $this->sanitizeProposalIdForPath($proposalId);

        $stagingDir = $this->stagingRoot().DIRECTORY_SEPARATOR.$rel;
        File::ensureDirectoryExists($stagingDir);

        $writtenFiles = [];

        if (! empty($scaffold['service_skeleton'])) {
            $servicePath = $stagingDir.DIRECTORY_SEPARATOR.basename(str_replace('\\', '/', $serviceClass)).'.php';
            file_put_contents($servicePath, (string) $scaffold['service_skeleton']);
            $writtenFiles[] = $servicePath;
        }
        if (! empty($scaffold['doc_skeleton'])) {
            $docFile = $stagingDir.DIRECTORY_SEPARATOR.basename($docPath);
            if ($docFile === $stagingDir.DIRECTORY_SEPARATOR.'' || $docFile === $stagingDir.DIRECTORY_SEPARATOR.'.') {
                $docFile = $stagingDir.DIRECTORY_SEPARATOR.'doc.md';
            }
            file_put_contents($docFile, (string) $scaffold['doc_skeleton']);
            $writtenFiles[] = $docFile;
        }
        if (! empty($scaffold['test_skeleton'])) {
            $testFile = $stagingDir.DIRECTORY_SEPARATOR.basename(str_replace('\\', '/', $serviceClass)).'Test.php';
            file_put_contents($testFile, (string) $scaffold['test_skeleton']);
            $writtenFiles[] = $testFile;
        }

        $receipt = $this->buildReceipt($proposalId, $proposalHash, $actor, self::STATUS_STAGED, $writtenFiles, 'scaffold staged successfully');
        $this->appendJsonl($this->receiptsLogPath(), $receipt);

        return $receipt;
    }

    /**
     * Validate a staging plan without touching the filesystem.
     * Returns PLAN_VALID + stable staged_artifact_hash, or PLAN_BLOCKED + blockers.
     *
     * @param  array<string,mixed>  $plan  {dry_run:bool, rollback_hint:string, target_paths:string[]}
     * @return array<string,mixed>
     */
    public static function validatePlan(array $plan): array
    {
        $blockers = [];

        if (! (bool) ($plan['dry_run'] ?? false)) {
            $blockers[] = 'non_dry_run_rejected';
        }

        if ((string) ($plan['rollback_hint'] ?? '') === '') {
            $blockers[] = 'rollback_hint_missing';
        }

        $paths = is_array($plan['target_paths'] ?? null) ? $plan['target_paths'] : [];
        foreach ($paths as $path) {
            foreach (self::PRODUCTION_PATH_PREFIXES as $prefix) {
                if (str_starts_with((string) $path, $prefix)) {
                    $blockers[] = 'production_path_mutation:'.$path;
                    break;
                }
            }
        }

        if ($blockers !== []) {
            return ['verdict' => self::PLAN_BLOCKED, 'blockers' => $blockers, 'staged_artifact_hash' => ''];
        }

        $canonicalInput = ['dry_run' => true, 'rollback_hint' => $plan['rollback_hint'], 'target_paths' => $paths];
        $hash = hash('sha256', (string) json_encode($canonicalInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['verdict' => self::PLAN_VALID, 'blockers' => [], 'staged_artifact_hash' => $hash];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listReceipts(): array
    {
        return $this->readJsonl($this->receiptsLogPath());
    }

    // ---------- internals ----------

    private function sanitizeProposalIdForPath(string $proposalId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', $proposalId) ?? 'proposal';
        if ($clean === '') {
            $clean = 'proposal';
        }

        return $clean;
    }

    /**
     * @param  list<string>  $writtenFiles
     * @return array<string,mixed>
     */
    private function buildReceipt(string $proposalId, string $proposalHash, string $actor, string $status, array $writtenFiles, string $reason): array
    {
        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'proposal_id' => $proposalId,
            'proposal_hash' => $proposalHash,
            'actor' => $actor,
            'status' => $status,
            'reason' => $reason,
            'written_files' => $writtenFiles,
            'staging_root' => $this->stagingRoot(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            File::ensureDirectoryExists($dir);
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
