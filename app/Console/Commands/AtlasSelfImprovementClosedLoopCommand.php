<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementClosedLoopService;
use Illuminate\Console\Command;

/**
 * Atlas Self-Improvement Closed Loop CLI (Level 7).
 *
 *   php artisan atlas:self-improvement:closed-loop --proposal=<id> --json --strict
 *
 * Pure read-only projection. Emits the proposal → activation → obra →
 * forge → evidence → review → delta → trust → learning → next-cycle map.
 * NEVER mutates state.
 */
final class AtlasSelfImprovementClosedLoopCommand extends Command
{
    protected $signature = 'atlas:self-improvement:closed-loop
        {--proposal= : Proposal id (prop_<ULID>) — required}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when loop_health is blocked or proposal_not_found}';

    protected $description = 'Atlas Self-Improvement Closed Loop v1 (Level 7) — read-only projection of proposal lifecycle.';

    public function handle(AtlasSelfImprovementClosedLoopService $service): int
    {
        $strict = (bool) $this->option('strict');
        $proposalId = $this->stringOption('proposal');

        if ($proposalId === null) {
            $payload = [
                'status' => 'blocked',
                'blockers' => ['proposal_id_required'],
            ];
            $this->emit($payload);

            return $strict ? self::FAILURE : self::SUCCESS;
        }

        $payload = $service->project($proposalId);
        $this->emit($payload);

        if (! $strict) {
            return self::SUCCESS;
        }
        if (($payload['loop_health'] ?? null) === 'blocked') {
            return self::FAILURE;
        }
        if (in_array('proposal_not_found', (array) ($payload['blockers'] ?? []), true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }
        $this->components->twoColumnDetail('schema_version', (string) ($payload['schema_version'] ?? '—'));
        $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? '—'));
        $this->components->twoColumnDetail('proposal_id', (string) ($payload['proposal_id'] ?? '—'));
        $this->components->twoColumnDetail('current_loop_stage', (string) ($payload['current_loop_stage'] ?? '—'));
        $this->components->twoColumnDetail('loop_health', (string) ($payload['loop_health'] ?? '—'));
        $this->components->twoColumnDetail('next_safe_action', (string) ($payload['next_safe_action'] ?? '—'));
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
