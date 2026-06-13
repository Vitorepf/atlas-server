<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoArchitectureProposalService;
use Illuminate\Console\Command;

final class AtlasLoopAutoArchitectureProposalCommand extends Command
{
    protected $signature = 'atlas:loop:auto-architecture
        {--candidate-limit= : Number of code-graph hotspots to include}
        {--create-proposal : Persist/reuse a self-improvement backlog draft for operator review}
        {--operator-review= : Explicit operator review receipt text/hash for L6-4 completion evidence}
        {--write-receipt : Write the report to the configured receipt path}
        {--receipt-path= : Override receipt path}
        {--strict : Exit non-zero unless L6-4 completion claim is allowed}
        {--json : Machine-readable JSON output}';

    protected $description = 'L6-4: propose code-graph driven structural refactors, parked for operator review only.';

    public function handle(AtlasLoopAutoArchitectureProposalService $service): int
    {
        if (! (bool) config('atlas.loop.auto_architecture_proposals.enabled', true)) {
            return $this->emit([
                'schema_version' => AtlasLoopAutoArchitectureProposalService::SCHEMA_VERSION,
                'status' => 'disabled',
                'blockers' => ['auto_architecture_proposals_disabled'],
            ], self::FAILURE);
        }

        $payload = $service->propose(array_filter([
            'candidate_limit' => $this->intOption('candidate-limit'),
            'create_proposal' => (bool) $this->option('create-proposal'),
            'operator_review' => $this->stringOption('operator-review'),
            'write_receipt' => (bool) $this->option('write-receipt'),
            'receipt_path' => $this->stringOption('receipt-path'),
        ], static fn (mixed $value): bool => $value !== null));

        $strict = (bool) $this->option('strict');
        $completion = (bool) data_get($payload, 'claim_policy.completion_claim_allowed', false);
        $exit = $strict && ! $completion ? self::FAILURE : self::SUCCESS;

        return $this->emit($payload, $exit);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->info('Atlas Loop auto-architecture proposals');
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Top candidate', (string) data_get($payload, 'top_candidate.module_slug', 'none'));
        $this->components->twoColumnDetail('Completion claim', data_get($payload, 'claim_policy.completion_claim_allowed') ? 'allowed' : 'blocked');

        return $exit;
    }

    private function intOption(string $key): ?int
    {
        $value = $this->option($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = trim((string) ($this->option($key) ?? ''));

        return $value === '' ? null : $value;
    }
}
