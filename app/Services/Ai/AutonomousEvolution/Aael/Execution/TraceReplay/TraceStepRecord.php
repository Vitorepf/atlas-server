<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay;

use JsonSerializable;

final class TraceStepRecord implements JsonSerializable
{
    public function __construct(
        public readonly int $stepIndex,
        public readonly string $monotonicTimestamp,
        public readonly string $actionName,
        public readonly string $inputFingerprint,
        public readonly string $outputFingerprint,
        public readonly string $providerId,
        public readonly int $exitCode,
        public readonly int $stdoutByteLength,
        public readonly int $stderrByteLength,
        public readonly string $workingTreeHash,
        public readonly string $decisionContextId,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'record_type' => 'trace_step',
            'step_index' => $this->stepIndex,
            'monotonic_timestamp' => $this->monotonicTimestamp,
            'action_name' => $this->actionName,
            'input_fingerprint' => $this->inputFingerprint,
            'output_fingerprint' => $this->outputFingerprint,
            'provider_id' => $this->providerId,
            'exit_code' => $this->exitCode,
            'stdout_byte_length' => $this->stdoutByteLength,
            'stderr_byte_length' => $this->stderrByteLength,
            'working_tree_hash' => $this->workingTreeHash,
            'decision_context_id' => $this->decisionContextId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
