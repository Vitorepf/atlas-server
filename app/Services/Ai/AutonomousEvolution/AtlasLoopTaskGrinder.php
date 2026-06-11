<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\Framework\AtlasLoopFrameworkMaterializer;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopRunPersister;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\Support\AiStringListNormalizer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The shared grind core — the single path from a CLAIMED durable task to a persisted,
 * propose-only result. Both the serial supervisor (in-process) and the parallel
 * worker subcommand (one process per task) call this, so there is exactly ONE grind
 * path, never two divergent ones.
 *
 * For one claimed task it: admits against the disk gate (backpressure, never crash),
 * materializes the durable snapshot into a fresh per-worker-namespaced workspace, runs
 * the PROVEN {@see AtlasEvolutionLoopRunner} (deep search + frozen judge + propose-only),
 * and streams the result to the durable ledger via {@see AtlasLoopRunPersister}. The
 * task MUST already be claimed by $workerId (the caller owns the lease).
 */
final class AtlasLoopTaskGrinder
{
    public function __construct(
        private readonly AtlasLoopWorkspaceMaterializer $materializer,
        private readonly AtlasLoopFrameworkMaterializer $frameworkMaterializer,
        private readonly AtlasEvolutionLoopRunner $runner,
        private readonly AtlasLoopRunPersister $persister,
        private readonly AtlasLoopStore $store,
        private readonly AtlasLoopResourceGate $gate,
        private readonly AtlasLoopSemanticImplementationCertifier $semanticCertifier,
        private readonly AtlasLoopIntentVerifierFactory $intentVerifierFactory,
    ) {}

    /**
     * @return array{status:string, has_winner:bool, proposals:int, scenarios_explored:int, elapsed_seconds:int, reason?:string}
     */
    public function grind(AtlasLoopTask $task, string $workerId, ?int $scenarios = null, string $workspaceRoot = '', ?int $timeBudgetSeconds = null): array
    {
        $started = microtime(true);
        $this->store->markRunning($task->id, $workerId);

        $tmpRoot = $workspaceRoot !== '' ? $workspaceRoot : sys_get_temp_dir();
        $admit = $this->gate->admitScenario(
            $tmpRoot,
            (int) config('atlas.loop.campaign.min_free_mb', 512),
            (int) config('atlas.loop.campaign.max_live_workspaces', 0),
        );
        if (! $admit['admit']) {
            // Backpressure: hand the claim back so the supervisor retries once disk frees.
            $this->store->releaseClaim($task->id, $workerId);

            return ['status' => 'backpressure', 'reason' => (string) $admit['reason'], 'has_winner' => false, 'proposals' => 0, 'scenarios_explored' => 0, 'elapsed_seconds' => 0];
        }

        $cleanup = static function (): void {};
        try {
            $payload = $this->taskPayload($task);
            $frameworkTask = $this->usesFrameworkMaterializer($payload);
            $intentVerifierPacket = null;
            if ($frameworkTask && $this->shouldCompileIntentVerifier($payload)) {
                $intentVerifierPacket = $this->intentVerifierFactory->compileFrameworkPacket(base_path(), (string) $task->objective, $payload);
                $payload = $this->intentVerifierFactory->taskPayloadOrFail($intentVerifierPacket);
            }
            [$explorerTask, $cleanup] = $frameworkTask
                ? $this->frameworkMaterializer->materializeBase(base_path(), (string) $task->objective, $payload)
                : $this->materializer->materialize((string) $task->objective, $payload);
            if ($workspaceRoot !== '') {
                $explorerTask['workspace_root'] = $workspaceRoot; // namespace + reapable scenario copies
            }
            if ($timeBudgetSeconds !== null && $timeBudgetSeconds > 0) {
                $explorerTask['search_time_budget_seconds'] = $timeBudgetSeconds; // never overrun the campaign deadline
            }

            $options = [];
            if ($scenarios !== null && $scenarios > 0) {
                $options['scenarios_per_task'] = $scenarios;
            }

            $result = $this->runner->run([$explorerTask], $options);
            if ($intentVerifierPacket !== null) {
                $result['intent_verifier_factory'] = $this->summariseIntentVerifierPacket($intentVerifierPacket);
            }
            if ($frameworkTask) {
                $result = $this->gateFrameworkImplementationProposals($result, $explorerTask, $payload);
            }
            $summary = $this->persister->persist($task, $workerId, $result);
            $cleanup();

            return [
                'status' => $summary['has_winner'] ? 'winner' : 'no_winner',
                'has_winner' => $summary['has_winner'],
                'proposals' => $summary['proposals'],
                'scenarios_explored' => $summary['scenarios_explored'],
                'elapsed_seconds' => (int) ceil(microtime(true) - $started),
            ];
        } catch (Throwable $e) {
            $cleanup();
            // Infra failure (not a no-winner) — mark failed, lease-checked; counters untouched.
            $this->store->completeTask($task->id, $workerId, ['error' => mb_substr($e->getMessage(), 0, 400)], false);

            return ['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 200), 'has_winner' => false, 'proposals' => 0, 'scenarios_explored' => 0, 'elapsed_seconds' => (int) ceil(microtime(true) - $started)];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function usesFrameworkMaterializer(array $payload): bool
    {
        return trim((string) ($payload['materializer'] ?? '')) === 'framework';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function shouldCompileIntentVerifier(array $payload): bool
    {
        if ((bool) ($payload['intent_verifier_factory'] ?? false)) {
            return true;
        }

        return trim((string) ($payload['materializer'] ?? '')) === 'framework'
            && AiStringListNormalizer::trimmedStrings(data_get($payload, 'acceptance.commands', [])) === [];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskPayload(AtlasLoopTask $task): array
    {
        $raw = $task->payload;
        if (! is_array($raw)) {
            return [];
        }

        $payload = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function summariseIntentVerifierPacket(array $packet): array
    {
        return [
            'schema_version' => (string) ($packet['schema_version'] ?? AtlasLoopIntentVerifierFactory::SCHEMA),
            'status' => (string) ($packet['status'] ?? 'unknown'),
            'ready' => (bool) ($packet['ready'] ?? false),
            'verifier_hash' => (string) ($packet['verifier_hash'] ?? ''),
            'target_relative_path' => (string) ($packet['target_relative_path'] ?? ''),
            'target_class' => (string) ($packet['target_class'] ?? ''),
            'verification_atom_count' => count((array) ($packet['verification_atoms'] ?? [])),
            'verification_atom_types' => AiStringListNormalizer::uniqueMappedStrings(
                (array) ($packet['verification_atoms'] ?? []),
                static fn (mixed $atom): string => is_array($atom) ? (string) ($atom['type'] ?? '') : '',
            ),
            'blockers' => array_values((array) ($packet['blockers'] ?? [])),
            'red_preflight' => is_array($packet['red_preflight'] ?? null) ? $packet['red_preflight'] : null,
            'verifier_refuters' => is_array($packet['verifier_refuters'] ?? null) ? $packet['verifier_refuters'] : null,
            'acceptance' => is_array($packet['acceptance'] ?? null)
                ? [
                    'commands' => AiStringListNormalizer::trimmedStrings(data_get($packet, 'acceptance.commands', [])),
                    'allowed_globs' => AiStringListNormalizer::trimmedStrings(data_get($packet, 'acceptance.allowed_globs', [])),
                    'frozen_globs' => AiStringListNormalizer::trimmedStrings(data_get($packet, 'acceptance.frozen_globs', [])),
                    'metric_kind' => (string) data_get($packet, 'acceptance.metric_kind', ''),
                    'revert_recheck' => (bool) data_get($packet, 'acceptance.revert_recheck', false),
                ]
                : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $explorerTask
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function gateFrameworkImplementationProposals(array $result, array $explorerTask, array $payload): array
    {
        $proposals = is_array($result['proposals'] ?? null) ? $result['proposals'] : [];
        $acceptance = is_array($explorerTask['acceptance'] ?? null) ? $explorerTask['acceptance'] : [];
        $baseWorkspace = (string) ($explorerTask['base_workspace'] ?? '');
        $sealedHoldouts = $this->sealedHoldoutCommands($payload);
        $refuterCommands = $this->semanticRefuterCommands($payload);
        $kept = [];
        $gateReports = [];
        $certificationReports = [];

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $diff = (string) ($proposal['diff_text'] ?? '');
            $gateWorkspace = $this->materializeGateWorkspace($baseWorkspace, $diff);
            try {
                $verdict = $this->semanticCertifier->certify($gateWorkspace, $acceptance, [
                    'objective' => (string) ($proposal['objective'] ?? $explorerTask['objective'] ?? ''),
                    'allowed_files' => $this->semanticAllowedFiles($payload, $explorerTask),
                    'sealed_holdout_commands' => $sealedHoldouts,
                    'semantic_refuter_commands' => $refuterCommands,
                    'provider_refuters_required' => $this->semanticRefutersRequired($payload, count($refuterCommands)),
                    'refuter_provider' => $payload['refuter_provider'] ?? $payload['provider'] ?? null,
                    'refuter_timeout_seconds' => $payload['refuter_timeout_seconds'] ?? null,
                ]);
            } finally {
                $this->removeGateWorkspace($baseWorkspace, $gateWorkspace);
            }

            $deterministicGate = is_array($verdict['deterministic_gate'] ?? null) ? $verdict['deterministic_gate'] : [];
            $gateReports[] = [
                'proposal_hash' => (string) ($proposal['proposal_hash'] ?? ''),
                'certified' => (bool) ($deterministicGate['certified'] ?? false),
                'reasons' => $deterministicGate['reasons'] ?? [],
                'report' => $deterministicGate['report'] ?? [],
            ];
            $certificationReports[] = [
                'proposal_hash' => (string) ($proposal['proposal_hash'] ?? ''),
                'certified' => (bool) ($verdict['certified'] ?? false),
                'level' => (string) ($verdict['level'] ?? ''),
                'reasons' => $verdict['reasons'] ?? [],
                'provider_refuters' => $verdict['provider_refuters'] ?? [],
                'adversarial_panel' => $verdict['adversarial_panel'] ?? [],
                'receipt' => $verdict,
            ];
            if ((bool) ($verdict['certified'] ?? false)) {
                $proposal['implementation_gate'] = $deterministicGate['report'] ?? [];
                $proposal['semantic_implementation_certification'] = $verdict;
                $kept[] = $proposal;
            }
        }

        $result['proposals'] = $kept;
        $result['proposals_certified_for_review'] = count($kept);
        $result['implementation_gate'] = [
            'schema_version' => 'atlas.loop.framework_implementation_gate.v1',
            'proposals_in' => count($proposals),
            'proposals_certified' => count($kept),
            'sealed_holdout_count' => count($sealedHoldouts),
            'reports' => $gateReports,
        ];
        $result['semantic_implementation_certification'] = [
            'schema_version' => AtlasLoopSemanticImplementationCertifier::SCHEMA.'.summary',
            'proposals_in' => count($proposals),
            'proposals_certified' => count($kept),
            'provider_refuter_command_count' => count($refuterCommands),
            'provider_refuters_required' => $this->semanticRefutersRequired($payload, count($refuterCommands)),
            'reports' => $certificationReports,
        ];

        return $result;
    }

    private function materializeGateWorkspace(string $baseWorkspace, string $diff): string
    {
        if ($baseWorkspace === '' || ! is_dir($baseWorkspace)) {
            throw new RuntimeException('framework gate: base workspace missing');
        }
        if ($diff === '' || str_ends_with($diff, '…')) {
            throw new RuntimeException('framework gate: proposal diff missing or truncated');
        }

        $workspace = sys_get_temp_dir().'/atlas-loop-fw-gate-'.bin2hex(random_bytes(5));
        $this->mustRun(['git', '-C', $baseWorkspace, 'worktree', 'add', '--detach', $workspace, 'HEAD'], 'framework_gate_worktree_add_failed', 120.0);
        $this->copyLocalSupport($baseWorkspace, $workspace);

        $apply = new Process(['git', 'apply', '--whitespace=nowarn', '-'], $workspace, null, null, 60.0);
        $apply->setInput($diff);
        $apply->run();
        if (! $apply->isSuccessful()) {
            $this->removeGateWorkspace($baseWorkspace, $workspace);
            throw new RuntimeException('framework gate: proposal diff did not apply cleanly: '.mb_substr($apply->getErrorOutput() ?: $apply->getOutput(), -240));
        }

        return $workspace;
    }

    private function removeGateWorkspace(string $baseWorkspace, string $workspace): void
    {
        if ($baseWorkspace !== '' && is_dir($baseWorkspace)) {
            (new Process(['git', '-C', $baseWorkspace, 'worktree', 'remove', '--force', $workspace], null, null, null, 60.0))->run();
            (new Process(['git', '-C', $baseWorkspace, 'worktree', 'prune'], null, null, null, 30.0))->run();
        }
        if (is_dir($workspace)) {
            (new Process(['rm', '-rf', $workspace], null, null, null, 60.0))->run();
        }
    }

    private function copyLocalSupport(string $baseWorkspace, string $workspace): void
    {
        foreach (['vendor'] as $dir) {
            if (is_dir($baseWorkspace.'/'.$dir)) {
                $this->mustRun(['bash', '-lc', 'cp -R '.escapeshellarg($baseWorkspace.'/'.$dir).' '.escapeshellarg($workspace.'/'.$dir)], 'framework_gate_support_copy_failed_'.$dir, 180.0);
            }
        }
        foreach (['.env', '.env.testing'] as $file) {
            if (is_file($baseWorkspace.'/'.$file)) {
                copy($baseWorkspace.'/'.$file, $workspace.'/'.$file);
            }
        }
        foreach ([
            'bootstrap/cache',
            'storage/app',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
        ] as $relative) {
            $dir = $workspace.'/'.$relative;
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function sealedHoldoutCommands(array $payload): array
    {
        $commands = [];
        foreach (['sealed_holdout_commands', 'wide_holdout_commands', 'final_holdout_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function semanticRefuterCommands(array $payload): array
    {
        $commands = [];
        foreach (['semantic_refuter_commands', 'provider_refuter_commands', 'refuter_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function semanticRefutersRequired(array $payload, int $configured): int
    {
        foreach (['provider_refuters_required', 'refuters_required', 'refuters'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        return $configured;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $explorerTask
     * @return list<string>
     */
    private function semanticAllowedFiles(array $payload, array $explorerTask): array
    {
        $files = [];
        foreach ([$payload['allowed_files'] ?? [], $explorerTask['allowed_files'] ?? []] as $source) {
            foreach (AiStringListNormalizer::trimmedStrings($source) as $file) {
                $files[] = $file;
            }
        }

        return AiStringListNormalizer::uniqueStrings($files);
    }

    /**
     * @param  list<string>  $argv
     */
    private function mustRun(array $argv, string $stage, float $timeout): void
    {
        $process = new Process($argv, null, null, null, $timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($stage.': '.mb_substr($process->getErrorOutput() ?: $process->getOutput(), -240));
        }
    }
}
