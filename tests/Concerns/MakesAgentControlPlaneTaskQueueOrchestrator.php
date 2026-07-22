<?php

namespace Tests\Concerns;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Fábrica compartilhada do AgentControlPlaneTaskQueueOrchestrator
 * (Obra #12 cauda da S-15): helper orchestrator() byte-idêntico consolidado.
 */
trait MakesAgentControlPlaneTaskQueueOrchestrator
{
    private function orchestrator(?string $commitRepositoryRoot = null): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
            $commitRepositoryRoot,
        );
    }

    /** @param list<string> $allowedFiles @return array{commit_sha:string,repository:string} */
    private function committedTaskProof(string $taskPacketId, array $allowedFiles): array
    {
        $repository = storage_path('framework/testing/task-commit-proof-'.Str::ulid());
        mkdir($repository, 0777, true);
        $run = static function (array $command) use ($repository): string {
            $process = new Process($command, $repository);
            $process->mustRun();

            return trim($process->getOutput());
        };
        $run(['git', 'init']);
        $run(['git', 'config', 'user.email', 'atlas-tests@example.test']);
        $run(['git', 'config', 'user.name', 'Atlas Tests']);
        file_put_contents($repository.'/seed.txt', 'seed');
        $run(['git', 'add', '--', 'seed.txt']);
        $run(['git', 'commit', '-m', 'seed']);
        foreach ($allowedFiles as $file) {
            $path = $repository.'/'.$file;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, 'scoped proof');
        }
        $run(array_merge(['git', 'add', '--'], $allowedFiles));
        $run(['git', 'commit', '-m', 'atlas-task '.$taskPacketId, '-m', 'Atlas-Task: '.$taskPacketId]);

        return ['commit_sha' => $run(['git', 'rev-parse', 'HEAD']), 'repository' => $repository];
    }
}
