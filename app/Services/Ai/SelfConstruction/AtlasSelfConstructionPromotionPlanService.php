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
 * Atlas Self-Construction Promotion Plan Service.
 *
 * Generates an honest dry-run plan for promoting staged scaffold files
 * (under storage/atlas/self_construction/staged/) into the live source
 * tree. By pétreo invariant, this service NEVER writes to app/, docs/ or
 * tests/ on its own. It produces a plan envelope the operator manually
 * acts on (copy commands), and an audit receipt of the planning event.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-self-construction-scaffold-staging-executor.md
 *   (extends the promotion workflow section)
 *
 * Schemas:
 *   - atlas.self_construction.promotion_plan.v1
 *
 * Invariants:
 *   - PRODUCES PLANS ONLY — no source-tree writes;
 *   - Constitutional Kernel must allow before plan is emitted;
 *   - target paths must NOT already exist (otherwise plan flags `conflict`);
 *   - hashes file contents so operator can verify post-copy.
 */
final class AtlasSelfConstructionPromotionPlanService
{
    public const PLAN_SCHEMA = 'atlas.self_construction.promotion_plan.v1';

    public const STATUS_READY = 'ready_to_promote';

    public const STATUS_BLOCKED = 'blocked_by_kernel';

    public const STATUS_CONFLICT = 'target_exists';

    public const STATUS_MISSING_STAGING = 'staging_files_missing';

    private ?string $logPathOverride = null;

    public function __construct(
        private readonly AtlasSelfConstructionScaffoldStagingExecutorService $staging,
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/self_construction')
            : sys_get_temp_dir().'/atlas/self_construction';

        return $base.DIRECTORY_SEPARATOR.'promotion_plans.jsonl';
    }

    /**
     * Build a promotion plan for one staged proposal. Always dry-run.
     *
     * @return array<string,mixed>
     */
    public function plan(string $proposalId, string $proposalHash, string $actor = 'operator'): array
    {
        if ($proposalId === '' || $proposalHash === '') {
            throw new InvalidArgumentException('proposal_id and proposal_hash are required.');
        }

        // Find staging receipt.
        $stagedReceipt = null;
        foreach ($this->staging->listReceipts() as $r) {
            if (($r['proposal_id'] ?? null) === $proposalId
                && ($r['proposal_hash'] ?? null) === $proposalHash
                && ($r['status'] ?? null) === AtlasSelfConstructionScaffoldStagingExecutorService::STATUS_STAGED) {
                $stagedReceipt = $r;
                break;
            }
        }
        if ($stagedReceipt === null) {
            return $this->persist($this->envelope(
                proposalId: $proposalId,
                proposalHash: $proposalHash,
                actor: $actor,
                status: self::STATUS_MISSING_STAGING,
                files: [],
                kernelDecision: null,
                note: 'no STAGED receipt found for this proposal_id + proposal_hash',
            ));
        }

        // Kernel gate — promotion is a high-impact change.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'subsystem_promotion_plan',
            'proposed_effect' => "plan promotion of staged scaffold for proposal {$proposalId}",
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $actor,
        ]);
        if ($kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            return $this->persist($this->envelope(
                proposalId: $proposalId,
                proposalHash: $proposalHash,
                actor: $actor,
                status: self::STATUS_BLOCKED,
                files: [],
                kernelDecision: $kernelEnv['decision'],
                note: 'kernel blocked: '.json_encode($kernelEnv['violations'] ?? []),
            ));
        }

        // Admission consult (always require operator approval — operator is the caller).
        $this->admission->admit([
            'change_kind' => 'subsystem_promotion_plan',
            'proposed_effect' => 'promote staged scaffold to source tree',
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $actor,
            'requested_autonomy' => 'execute_with_approval',
        ]);

        // Build the file mapping. Source = staged file path; target = canonical source-tree path.
        $stagedFiles = (array) ($stagedReceipt['written_files'] ?? []);
        $files = [];
        $anyConflict = false;
        foreach ($stagedFiles as $stagedPath) {
            if (! is_string($stagedPath) || ! is_file($stagedPath)) {
                $files[] = [
                    'staged_path' => (string) $stagedPath,
                    'target_path' => null,
                    'staged_exists' => false,
                    'target_exists' => null,
                    'staged_hash' => null,
                ];
                continue;
            }
            $target = $this->resolveTargetPath($stagedPath);
            $targetExists = is_file($target);
            if ($targetExists) {
                $anyConflict = true;
            }
            $files[] = [
                'staged_path' => $stagedPath,
                'target_path' => $target,
                'staged_exists' => true,
                'target_exists' => $targetExists,
                'staged_hash' => 'sha256:'.hash_file('sha256', $stagedPath),
            ];
        }

        $status = $anyConflict ? self::STATUS_CONFLICT : self::STATUS_READY;

        return $this->persist($this->envelope(
            proposalId: $proposalId,
            proposalHash: $proposalHash,
            actor: $actor,
            status: $status,
            files: $files,
            kernelDecision: $kernelEnv['decision'],
            note: $status === self::STATUS_READY
                ? 'operator may now manually copy staged_path → target_path; this service WILL NOT do it'
                : 'one or more target paths already exist; operator must resolve before promotion'
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPlans(): array
    {
        return $this->readJsonl($this->logPath());
    }

    // ---------- internals ----------

    /**
     * Heuristic: a staged file under `.../staged/<proposal_dir>/SomeService.php`
     * maps to `<project_root>/app/Services/Ai/SelfConstruction/SomeService.php`.
     * Docs map to `docs/engineering-knowledge-base/`. Tests map to `tests/Unit/Ai/`.
     * The operator MUST verify these before copying.
     */
    private function resolveTargetPath(string $stagedPath): string
    {
        $base = function_exists('base_path') ? base_path() : dirname(__DIR__, 5);
        $filename = basename($stagedPath);
        if (str_ends_with($filename, 'Test.php')) {
            return $base.'/tests/Unit/Ai/SelfConstruction/'.$filename;
        }
        if (str_ends_with($filename, '.md')) {
            return $base.'/docs/engineering-knowledge-base/'.$filename;
        }

        return $base.'/app/Services/Ai/SelfConstruction/'.$filename;
    }

    /**
     * @param  list<array<string,mixed>>  $files
     * @return array<string,mixed>
     */
    private function envelope(
        string $proposalId,
        string $proposalHash,
        string $actor,
        string $status,
        array $files,
        ?string $kernelDecision,
        string $note,
    ): array {
        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $envelope = [
            'schema_version' => self::PLAN_SCHEMA,
            'planned_at' => $at,
            'proposal_id' => $proposalId,
            'proposal_hash' => $proposalHash,
            'actor' => $actor,
            'status' => $status,
            'kernel_decision' => $kernelDecision,
            'note' => $note,
            'files' => $files,
            'dry_run_only' => true,
            'source_tree_writes_authorized' => false,
        ];
        $envelope['plan_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::PLAN_SCHEMA,
            'proposal_id' => $proposalId,
            'proposal_hash' => $proposalHash,
            'status' => $status,
            'planned_at' => $at,
            'files' => array_map(static fn ($f) => [
                'staged' => $f['staged_path'] ?? null,
                'target' => $f['target_path'] ?? null,
            ], $files),
        ], JSON_THROW_ON_ERROR));

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function persist(array $envelope): array
    {
        $this->appendJsonl($this->logPath(), $envelope);

        return $envelope;
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
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
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
