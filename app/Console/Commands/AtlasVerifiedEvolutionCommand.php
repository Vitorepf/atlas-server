<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasVerifiedEvolutionRuntimeService;
use Illuminate\Console\Command;
use App\Support\YesNo;

final class AtlasVerifiedEvolutionCommand extends Command
{
    protected $signature = 'atlas:verified-evolution
        {action=evolution-envelope : intent-lock|boundary-contract|proof-plan|execution-contract|drift-watch|patch-simulation|outcome-bridge|quality-score|evolution-envelope}
        {--objective= : Evolution objective}
        {--target= : Path, symbol or runtime target}
        {--changed-file=* : Changed file path for drift-watch}
        {--outcome-status=succeeded : Outcome status for outcome-bridge}
        {--evidence=* : Evidence refs for outcome-bridge}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless ready}';

    protected $description = 'Operate AVEOR, the Atlas Verified Evolution Runtime, as the change safety kernel above ASTR.';

    public function handle(AtlasVerifiedEvolutionRuntimeService $service): int
    {
        $action = (string) $this->argument('action');
        $objective = (string) ($this->option('objective') ?: 'verified evolution objective');
        $target = (string) ($this->option('target') ?: '');
        $payload = match ($action) {
            'intent-lock' => $service->intentLock($objective, $target),
            'boundary-contract' => $service->boundaryContract($objective, $target),
            'proof-plan' => $service->proofPlan($objective, $target),
            'execution-contract' => $service->executionContract($objective, $target),
            'drift-watch' => $service->driftWatch($objective, $target, (array) $this->option('changed-file')),
            'patch-simulation' => $service->patchSimulation($objective, $target, (array) $this->option('changed-file')),
            'outcome-bridge' => $service->outcomeBridge($objective, $target, (string) $this->option('outcome-status'), (array) $this->option('evidence')),
            'quality-score' => $service->qualityScore($objective, $target),
            'evolution-envelope' => $service->evolutionEnvelope($objective, $target),
            default => null,
        };

        if ($payload === null) {
            $this->error('Unknown action. Expected intent-lock, boundary-contract, proof-plan, execution-contract, drift-watch, patch-simulation, outcome-bridge, quality-score or evolution-envelope.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('Atlas Verified Evolution', (string) $payload['status']);
        $this->components->twoColumnDetail('Action', (string) ($payload['action'] ?? $action));
        $this->components->twoColumnDetail('Writes', YesNo::format($payload['writes']));
        $this->components->twoColumnDetail('Hash', (string) $payload['certification_hash']);

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return (bool) $this->option('strict') && $payload['status'] !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
