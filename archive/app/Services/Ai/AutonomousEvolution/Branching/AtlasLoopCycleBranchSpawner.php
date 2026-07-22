<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Branching;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializerSupport2;
use Closure;
use RuntimeException;

/**
 * Spawns isolated exploration branches off a parent loop cycle.
 *
 * Each branch lives under storage/atlas-loop/branches/<branch_id>/ with a manifest.
 * Refuses to write into the live source (pétreo guard) and is fail-closed on master OFF.
 */
final class AtlasLoopCycleBranchSpawner
{
    public const REFUSAL_MASTER_OFF = 'loop_master_off';

    /** @var Closure():string */
    private Closure $now;

    /** @var Closure():string */
    private Closure $branchIdGenerator;

    /** @var Closure():bool */
    private Closure $masterEnabled;

    public function __construct(
        private readonly string $branchesRoot,
        ?callable $nowIso = null,
        ?callable $branchIdGenerator = null,
        ?callable $masterEnabled = null,
    ) {
        $this->now = Closure::fromCallable($nowIso ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'));
        $this->branchIdGenerator = Closure::fromCallable($branchIdGenerator ?? static fn (): string => bin2hex(random_bytes(8)));
        $this->masterEnabled = Closure::fromCallable($masterEnabled ?? static fn (): bool => (bool) env('ATLAS_LOOP_MASTER_ENABLED', false));
    }

    /**
     * @return array{ok:bool, refusal_reason?:string, manifest?:AtlasLoopCycleBranchManifest}
     */
    public function spawn(string $parentCycleId, string $hypothesis, string $baseCommitSha): array
    {
        if (! ($this->masterEnabled)()) {
            return ['ok' => false, 'refusal_reason' => self::REFUSAL_MASTER_OFF];
        }

        $branchId = (string) ($this->branchIdGenerator)();
        $workspacePath = rtrim($this->branchesRoot, '/').'/'.$branchId;

        // Pétreo floor: refuse anywhere inside the live source tree.
        AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource($workspacePath);

        if (! is_dir($workspacePath) && ! @mkdir($workspacePath, 0o755, true) && ! is_dir($workspacePath)) {
            throw new RuntimeException('branch_workspace_mkdir_failed:'.$workspacePath);
        }

        $manifest = new AtlasLoopCycleBranchManifest(
            parentCycleId: $parentCycleId,
            branchId: $branchId,
            hypothesis: $hypothesis,
            baseCommitSha: $baseCommitSha,
            createdAt: (string) ($this->now)(),
            workspacePath: $workspacePath,
        );

        $manifestPath = $workspacePath.'/manifest.json';
        $bytes = (string) json_encode($manifest->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = $manifestPath.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new RuntimeException('branch_manifest_write_failed:'.$tmp);
        }
        if (! @rename($tmp, $manifestPath)) {
            @unlink($tmp);
            throw new RuntimeException('branch_manifest_rename_failed:'.$manifestPath);
        }

        return ['ok' => true, 'manifest' => $manifest];
    }
}
