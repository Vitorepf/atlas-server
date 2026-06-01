<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasStructuralContractGateService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Structural Contract Gate CLI.
 *
 *   php artisan atlas:aaeos:structural-contract-gate
 *     [--subsystem=scope_validator]                 // structural subsystem id
 *     [--step=8]                                     // step 1..9 about to run
 *     [--contract=purpose_and_non_goals,...]         // checklist fields present
 *     [--unlock=examples_present,...]                // unlock signals satisfied
 *     [--read-only-runtime]                          // step-8 runtime exists
 *     [--validation-passed]                          // validation done
 *     [--json]
 *
 * Read-only, deterministic, fail-closed. Decides whether a structural subsystem
 * may receive runtime and at which mandatory step. It NEVER writes code, never
 * approves scoped execution by itself — it only emits a verdict + evidence.
 *
 * @see docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
 */
class AtlasStructuralContractGateCommand extends Command
{
    protected $signature = 'atlas:aaeos:structural-contract-gate
        {--subsystem= : structural subsystem id (e.g. scope_validator); empty = non-structural}
        {--step= : mandatory-order step 1..9 the agent is about to perform}
        {--contract= : comma-separated minimum-contract-checklist fields present}
        {--unlock= : comma-separated runtime-unlock signals satisfied}
        {--read-only-runtime : declare that step-8 read-only runtime already exists}
        {--validation-passed : declare that validation already passed}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · structural contract gate that decides if a subsystem may receive runtime (allow|block).';

    public function handle(AtlasStructuralContractGateService $service): int
    {
        try {
            $stepOpt = $this->option('step');
            $hasStep = is_string($stepOpt) && trim($stepOpt) !== '';

            $operation = [
                // Safe default subject: gating read-only runtime (step 8) for
                // the scope_validator front, the canonical structural example.
                'subsystem' => $this->str('subsystem', 'scope_validator'),
                'requested_step' => $hasStep ? (int) trim($stepOpt) : AtlasStructuralContractGateService::FIRST_CODE_STEP,
                'contract' => $this->list('contract'),
                'unlock' => $this->list('unlock'),
                'read_only_runtime_implemented' => (bool) $this->option('read-only-runtime'),
                'validation_passed' => (bool) $this->option('validation-passed'),
            ];

            $result = $service->evaluate($operation);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['verdict'] === AtlasStructuralContractGateService::VERDICT_ALLOW
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'structural_contract_gate_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function str(string $option, string $default): string
    {
        $raw = $this->option($option);

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : $default;
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
