<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair\Contracts;

use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;

/**
 * Bridge between the repair loop (this module) and the Provider/Gate layer.
 *
 * The repair loop never talks to Claude CLI or to the verification command
 * runner directly. It hands the upstream evaluator a repair prompt, the
 * locked task contract and the previous failure capsule, and gets back an
 * honest report of what happened in that attempt.
 *
 * Implementations must enforce {@see LightTaskContract::$providerLock}
 * (same provider/model as the original run, no fallback) and must NOT
 * mutate {@see LightTaskContract::$allowedFiles}. Any apparent scope growth
 * is surfaced through {@see RepairAttemptOutcome::STATUS_SCOPE_VIOLATION}
 * so the orchestrator can escalate honestly instead of silently expanding
 * the contract.
 *
 * Tests inject a fake implementation; the real provider/gate-backed
 * implementation lives in Claude 12's module and is wired in production.
 */
interface RepairAttemptEvaluator
{
    public function evaluate(
        ProviderPromptProjection $repairPrompt,
        LightTaskContract $contract,
        FailureCapsule $previousCapsule,
        int $attemptIndex,
    ): RepairAttemptOutcome;
}
