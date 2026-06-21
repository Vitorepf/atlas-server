<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Symfony\Component\Process\Process;

/**
 * §5.6 · ORPHAN-WIRING execution — the testable CORE of the grinder route (kept out of the final grinder so it
 * is unit-provable without the full grind() path). Given an orphan + the base workspace + the engine's two
 * authoring callables it: materializes a CONCURRENCY-SAFE standalone workspace, runs the executor (engine
 * authors → earned-RED → Guard 4e cert), captures the net diff (authored test + wiring) for operator review on
 * success, and always discards the workspace. PROPOSE-ONLY: it returns the diff; it NEVER merges.
 */
final class AtlasLoopOrphanWiringRouteHandler
{
    public function __construct(
        private readonly ?AtlasLoopStandaloneWorkspaceMaterializer $materializer = null,
        private readonly ?AtlasLoopOrphanWiringExecutionAdapter $executor = null,
    ) {}

    /**
     * @return array{certified:bool, reason:?string, proposal_diff:string}
     */
    public function handle(string $orphanRel, string $baseWorkspace, callable $authorTest, callable $authorWiring): array
    {
        $materializer = $this->materializer ?? new AtlasLoopStandaloneWorkspaceMaterializer;
        $executor = $this->executor ?? new AtlasLoopOrphanWiringExecutionAdapter;

        $ws = $materializer->materialize($baseWorkspace);
        if ($ws === null) {
            return ['certified' => false, 'reason' => 'workspace_unavailable', 'proposal_diff' => ''];
        }

        try {
            $result = $executor->execute($orphanRel, $ws, $authorTest, $authorWiring);
            $certified = ($result['certified'] ?? false) === true;
            $diff = $certified ? $this->netDiff($ws) : '';
        } finally {
            $materializer->discard($ws);
        }

        return [
            'certified' => $certified,
            'reason' => is_array($result) ? ($result['reason'] ?? null) : null,
            'proposal_diff' => $diff,
        ];
    }

    /** The net change (authored test committed onto HEAD + the uncommitted wiring) vs the baseline root commit. */
    private function netDiff(string $ws): string
    {
        (new Process(['git', 'add', '-A'], $ws, null, null, 60.0))->run();
        $root = new Process(['git', 'rev-list', '--max-parents=0', 'HEAD'], $ws, null, null, 60.0);
        $root->run();
        $rootSha = trim($root->getOutput());
        if ($rootSha === '') {
            return '';
        }
        $diff = new Process(['git', 'diff', $rootSha], $ws, null, null, 60.0);
        $diff->run();

        return $diff->getOutput();
    }
}
