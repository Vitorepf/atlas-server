<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\AtlasDev\Support\CompactSddUnavailableException;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Throwable;

final class AtlasDevRunWorkerCommand extends Command
{
    protected $signature = 'atlas:dev:run-worker
        {run_id : Atlas Dev run id}
        {--task-contract-hash= : Persisted task contract hash accepted by /run}
        {--expected-compact-sdd-hash= : CompactSDD hash pinned in the confirmation token row}';

    protected $description = 'Execute an accepted Atlas Dev run from persisted plan artifacts.';

    public function __construct(
        private readonly RunExecutor $executor,
        private readonly ReceiptStorage $storage,
        private readonly AtlasDevRunIndexRepository $runIndex,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $runId = (string) $this->argument('run_id');
        $providedHash = (string) $this->option('task-contract-hash');
        $expectedCompactSddHash = (string) $this->option('expected-compact-sdd-hash');
        $expectedCompactSddHash = $expectedCompactSddHash !== '' ? $expectedCompactSddHash : null;
        $processGroupId = $this->isolateProcessGroup();

        $this->installTerminationHandler($runId, $providedHash, $processGroupId);

        if ($this->isCancelled($runId)) {
            $this->recordRunState($runId, 'cancelled', [
                'task_contract_hash' => $providedHash,
                'worker' => 'artisan',
                'reason' => 'cancelled_before_worker_start',
            ]);
            $this->runIndex->updateCompletion($runId, 'cancelled');

            return self::SUCCESS;
        }

        $this->recordRunState($runId, 'running', [
            'task_contract_hash' => $providedHash,
            'worker' => 'artisan',
            'worker_pid' => getmypid() ?: null,
            'process_group_id' => $processGroupId,
        ]);
        $this->runIndex->updateCompletion($runId, 'running');

        try {
            if ($this->isCancelled($runId)) {
                $this->recordRunState($runId, 'cancelled', [
                    'task_contract_hash' => $providedHash,
                    'worker' => 'artisan',
                    'reason' => 'cancelled_before_provider_call',
                ]);
                $this->runIndex->updateCompletion($runId, 'cancelled');

                return self::SUCCESS;
            }

            $envelopePayload = $this->storage->read($runId, ArtifactNames::OPERATION_ENVELOPE);
            $taskContractPayload = $this->storage->read($runId, ArtifactNames::TASK_CONTRACT);
            $promptPayload = $this->storage->read($runId, ArtifactNames::PROMPT_PROJECTION);

            if (! is_array($envelopePayload) || ! is_array($taskContractPayload) || ! is_array($promptPayload)) {
                throw new \RuntimeException('Persisted plan artifacts are missing.');
            }

            $envelope = OperationEnvelope::fromArray($envelopePayload);
            $taskContract = LightTaskContract::fromArray($taskContractPayload);
            $promptProjection = ProviderPromptProjection::fromArray($promptPayload);

            $result = $this->executor->execute(
                envelope: $envelope,
                taskContract: $taskContract,
                promptProjection: $promptProjection,
                runId: $runId,
                expectedCompactSddHash: $expectedCompactSddHash,
            );

            $this->runIndex->updateCompletion($runId, $result->completionState, $result->verificationReceiptHash);
            $this->recordRunState($runId, 'complete', [
                'completion_state' => $result->completionState,
                'task_contract_hash' => $providedHash,
            ]);

            return self::SUCCESS;
        } catch (CompactSddUnavailableException $e) {
            $this->recordRunState($runId, 'failed', [
                'error_code' => $e->errorCode(),
                'reason' => $e->reasonCode,
                'detail' => $e->detail,
                'task_contract_hash' => $providedHash,
            ]);
            $this->runIndex->updateCompletion($runId, 'failed');
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->recordRunState($runId, 'failed', [
                'error_code' => 'ATLAS_DEV_RUN_WORKER_FAILED',
                'message' => $this->redactThrowableMessage($e->getMessage()),
                'task_contract_hash' => $providedHash,
            ]);
            $this->runIndex->updateCompletion($runId, 'failed');
            $this->error($this->redactThrowableMessage($e->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordRunState(string $runId, string $status, array $extra = []): void
    {
        $this->storage->writeMonotonic($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE, array_filter([
            'schema_version' => 'atlas.dev.run_execution_state.v1',
            'run_id' => $runId,
            'status' => $status,
            'recorded_at' => now()->toISOString(),
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function redactThrowableMessage(string $message): string
    {
        $message = AtlasSecurity::redactString($message);
        $redacted = preg_replace('#/(?:Users|private/var|var/folders|tmp)/[^\s"\']+#', '[path-redacted]', $message);

        return is_string($redacted) ? $redacted : $message;
    }

    private function isCancelled(string $runId): bool
    {
        return $this->storage->exists($runId, ArtifactNames::RUN_CANCELLATION);
    }

    private function isolateProcessGroup(): ?int
    {
        if (! app()->runningUnitTests() && function_exists('posix_setsid')) {
            $sessionId = @posix_setsid();
            if (is_int($sessionId) && $sessionId > 0) {
                return $sessionId;
            }
        }

        if (function_exists('posix_getpgrp')) {
            $groupId = @posix_getpgrp();
            if (is_int($groupId) && $groupId > 0) {
                return $groupId;
            }
        }

        return getmypid() ?: null;
    }

    private function installTerminationHandler(string $runId, string $providedHash, ?int $processGroupId): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        $signal = defined('SIGTERM') ? (int) constant('SIGTERM') : 15;

        pcntl_signal($signal, function () use ($runId, $providedHash): void {
            $cancellation = $this->storage->read($runId, ArtifactNames::RUN_CANCELLATION);
            $reason = is_array($cancellation) && is_string($cancellation['reason'] ?? null)
                ? $cancellation['reason']
                : 'worker_received_sigterm';

            $this->recordRunState($runId, 'cancelled', [
                'task_contract_hash' => $providedHash,
                'worker' => 'artisan',
                'worker_pid' => getmypid() ?: null,
                'process_group_id' => $processGroupId,
                'reason' => $reason,
                'signal' => 'SIGTERM',
            ]);
            $this->runIndex->updateCompletion($runId, 'cancelled');

            exit(self::SUCCESS);
        });
    }
}
