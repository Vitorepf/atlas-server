<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use Illuminate\Console\Command;

/**
 * Hidden technical smoke for the Atlas Dev fast path.
 *
 * Plan-only mode never calls a provider. Passing --execute
 * intentionally spends a provider call through the production RunExecutor and
 * verifies the same provider/scope/verification/receipt path used by Desktop
 * /run.
 */
final class AtlasDevSmokeCommand extends Command
{
    protected $signature = 'atlas:dev:debug:smoke
                            {--workspace= : absolute workspace path (defaults to CWD)}
                            {--intent= : raw intent string (required)}
                            {--surface=atlas_cli_dev : surface_id}
                            {--constraint=* : user constraints, repeatable}
                            {--execute : execute the provider run after planning (requires --yes)}
                            {--yes : confirm provider execution for --execute}
                            {--json : print canonical JSON}';

    protected $description = 'Smoke for Atlas Dev plan-only, with optional confirmed provider execution.';

    protected $hidden = true;

    public function handle(AtlasDevFastPathOrchestrator $orchestrator, RunExecutor $executor): int
    {
        $intent = (string) $this->option('intent');
        if ($intent === '') {
            $this->error('atlas:dev:debug:smoke requires --intent=...');

            return self::INVALID;
        }

        $workspace = (string) ($this->option('workspace') ?: getcwd());
        $surface = (string) $this->option('surface');
        $constraints = array_values((array) $this->option('constraint'));

        $result = $orchestrator->planOnly(
            surfaceId: $surface,
            workspace: $workspace,
            rawIntent: $intent,
            userConstraints: array_filter($constraints, static fn ($c): bool => is_string($c) && $c !== ''),
        );

        if ((bool) $this->option('execute')) {
            if (! (bool) $this->option('yes')) {
                $this->error('atlas:dev:debug:smoke --execute requires --yes.');

                return self::FAILURE;
            }

            if (! $result->isFastPath()) {
                $this->error('atlas:dev:debug:smoke cannot execute because routing_decision='.$result->routingKind());

                return self::FAILURE;
            }

            $run = $executor->execute(
                envelope: $result->envelope,
                taskContract: $result->taskContract,
                promptProjection: $result->promptProjection,
                runId: $result->envelope->runId,
            );

            if ($this->option('json')) {
                $this->line(json_encode([
                    'plan' => $result->toSummaryArray(),
                    'run' => $this->redactedRunArray($run->toArray(), $result->envelope->runId),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->line(sprintf('run_id=%s', $result->envelope->runId));
            $this->line(sprintf('routing=%s risk=%s task_kind=%s', $result->routingKind(), $result->riskLevel, $result->classification->taskKind));
            $this->line(sprintf('completion_state=%s scope=%s verification=%s', $run->completionState, $run->scopeGuardStatus, $run->verificationStatus));
            $this->line(sprintf(
                'provider=%s model=%s calls=%d exit=%d',
                (string) ($run->providerCallSummary['provider'] ?? ''),
                (string) ($run->providerCallSummary['model_family'] ?? ''),
                (int) ($run->providerCallSummary['provider_calls'] ?? 0),
                (int) ($run->providerCallSummary['exit_code'] ?? 0),
            ));

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result->toSummaryArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->line(sprintf('run_id=%s', $result->envelope->runId));
        $this->line(sprintf('routing=%s risk=%s task_kind=%s', $result->routingKind(), $result->riskLevel, $result->classification->taskKind));
        $this->line(sprintf('expected_files=%d allowed_files=%d', count($result->miniSpec->expectedFiles), count($result->miniSpec->allowedFiles)));
        if ($result->blockers !== []) {
            $this->warn('blockers: '.implode(', ', $result->blockers));
        }
        $this->line('artifacts:');
        foreach ($result->persistedArtifactPaths as $name => $path) {
            $this->line("  - {$name} => {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactedRunArray(array $payload, string $runId): array
    {
        if (isset($payload['persisted_receipt_paths']) && is_array($payload['persisted_receipt_paths'])) {
            $refs = [];
            foreach ($payload['persisted_receipt_paths'] as $name => $path) {
                if (is_string($path) && $path !== '') {
                    $refs[(string) $name] = 'receipts/'.$runId.'/'.basename($path);
                }
            }
            $payload['persisted_receipt_refs'] = $refs;
            unset($payload['persisted_receipt_paths']);
        }

        return $payload;
    }
}
