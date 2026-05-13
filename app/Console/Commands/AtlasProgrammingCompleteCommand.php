<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Register a closeout review and run the completion gate.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md (Definition Of Done Canonico)
 */
class AtlasProgrammingCompleteCommand extends Command
{
    protected $signature = 'atlas:programming:complete
        {work_item : Work item code or UUID}
        {--review=approved : approved|changes_requested|blocked|deferred}
        {--summary= : Reviewer summary}
        {--risk-notes= : Residual risk notes}
        {--decided-by= : Reviewer identifier}
        {--strict : Exit non-zero when completion gate is not green}
        {--json : Print machine-readable JSON}';

    protected $description = 'Register a review decision and run the completion gate.';

    public function handle(ProgrammingGovernanceService $governance): int
    {
        try {
            $workItem = $governance->find((string) $this->argument('work_item'));
            $payload = $governance->closeout(
                $workItem,
                (string) ($this->option('review') ?: 'approved'),
                array_filter([
                    'summary' => $this->stringOption('summary'),
                    'risk_notes' => $this->stringOption('risk-notes'),
                    'decided_by' => $this->stringOption('decided-by'),
                ], static fn (mixed $v): bool => $v !== null),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $this->resolveExit($payload, (bool) $this->option('strict'));
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Programming Complete</>', $payload['code']);
        $this->components->twoColumnDetail('Status', $payload['status']);
        $this->components->twoColumnDetail('Closed at', (string) ($payload['closed_at'] ?? '-'));
        $this->components->twoColumnDetail('Review', (string) ($this->option('review') ?: 'approved'));
        $this->components->twoColumnDetail('All green', data_get($payload, 'gate_summary.all_green') ? 'yes' : 'no');

        return $this->resolveExit($payload, (bool) $this->option('strict'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveExit(array $payload, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        $allGreen = (bool) data_get($payload, 'gate_summary.all_green', false);

        return $allGreen ? self::SUCCESS : self::FAILURE;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
