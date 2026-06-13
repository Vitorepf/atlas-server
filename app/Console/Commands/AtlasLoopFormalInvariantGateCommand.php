<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFormalInvariantGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AtlasLoopFormalInvariantGateCommand extends Command
{
    protected $signature = 'atlas:loop:formal-invariant-gate
        {--fixture=safe : Built-in fixture: safe, missing-coverage or tampered-never-merge}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless the formal-light gate certifies}
        {--json : Print canonical JSON}';

    protected $description = 'L6-8 formal-light invariant gate for Constitutional Kernel, governed never-merge door and harness guard.';

    public function handle(AtlasLoopFormalInvariantGateService $gate): int
    {
        $payload = $gate->evaluate([
            'fixture' => trim((string) $this->option('fixture')),
        ]);

        $receiptPath = $this->receiptPath();
        if ($receiptPath !== '') {
            File::ensureDirectoryExists(dirname($receiptPath));
            File::put($receiptPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $payload['receipt_path'] = $receiptPath;
        }

        $exit = (bool) $this->option('strict') && ! (bool) ($payload['certified'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->twoColumnDetail('Formal invariant gate', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Certified', (bool) ($payload['certified'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Checks', (string) data_get($payload, 'counts.checks_passed', 0).'/'.(string) data_get($payload, 'counts.invariants', 0));
        $this->components->twoColumnDetail('Proofs', (string) data_get($payload, 'counts.proofs_verified', 0).'/'.(string) data_get($payload, 'counts.proof_results', 0));

        return $exit;
    }

    private function receiptPath(): string
    {
        $explicit = trim((string) ($this->option('receipt') ?: ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config('atlas.loop.formal_invariant_gate.receipt_path', storage_path('app/atlas/evidence/formal-invariant-gate.json'))
            : '';
    }
}
