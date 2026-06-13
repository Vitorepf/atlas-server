<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWeeklyAgendaProposalService;
use Illuminate\Console\Command;

/**
 * L5-1 · Propose the Loop's weekly engineering agenda at SUGGEST level.
 */
final class AtlasLoopWeeklyAgendaCommand extends Command
{
    protected $signature = 'atlas:loop:weekly-agenda
        {--hours= : Lookback window in hours (default: config atlas.loop.weekly_agenda.window_hours)}
        {--max-items= : Maximum agenda priorities (default: config)}
        {--create-proposal : Persist the agenda as a self-improvement backlog draft for operator review}
        {--operator-approval= : Explicit operator approval receipt text/hash for executing the agenda}
        {--execute-approved : With explicit operator approval, evaluate/prioritize the agenda proposal and write an execution receipt}
        {--no-prioritize : Do not compute backlog priority after creating the draft}
        {--strict : Return failure unless the agenda is ready for operator review}
        {--json : Machine-readable JSON output}';

    protected $description = 'Compose a governed weekly Loop agenda from resolved evidence; never auto-executes.';

    public function handle(AtlasLoopWeeklyAgendaProposalService $weeklyAgenda): int
    {
        if (! (bool) config('atlas.loop.weekly_agenda.enabled', true)) {
            return $this->emit([
                'schema_version' => AtlasLoopWeeklyAgendaProposalService::SCHEMA_VERSION,
                'status' => 'disabled',
                'reason' => 'weekly_agenda_disabled',
            ], self::FAILURE);
        }

        $payload = $weeklyAgenda->propose(array_filter([
            'hours' => $this->intOption('hours'),
            'max_items' => $this->intOption('max-items'),
            'create_proposal' => (bool) $this->option('create-proposal') || (bool) $this->option('execute-approved'),
            'prioritize_proposal' => ! (bool) $this->option('no-prioritize'),
            'operator_approval' => $this->stringOption('operator-approval'),
            'execute_approved' => (bool) $this->option('execute-approved'),
        ], static fn (mixed $value): bool => $value !== null));

        $strict = (bool) $this->option('strict');
        $ready = ($payload['status'] ?? null) === 'ready_for_operator_review';
        $createdRequired = (bool) $this->option('create-proposal');
        $created = data_get($payload, 'operator_approval.created_proposal_id') !== null;
        $executionRequired = (bool) $this->option('execute-approved');
        $executed = data_get($payload, 'approved_execution.status') === 'executed';
        $exit = $strict && (! $ready || ($createdRequired && ! $created) || ($executionRequired && ! $executed))
            ? self::FAILURE
            : self::SUCCESS;

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

        $this->components->info((string) ($payload['title'] ?? 'Atlas Loop weekly agenda'));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Operator approval', data_get($payload, 'operator_approval.required') ? 'required' : 'not required');
        $this->components->twoColumnDetail('Auto-execute', data_get($payload, 'operator_approval.auto_execute_allowed') ? 'allowed' : 'blocked');
        $created = data_get($payload, 'operator_approval.created_proposal_id');
        if ($created !== null) {
            $this->components->twoColumnDetail('Backlog proposal', (string) $created);
        }
        foreach ((array) ($payload['agenda'] ?? []) as $item) {
            $this->line(sprintf(
                '- [%s] %s',
                (string) ($item['score'] ?? '-'),
                (string) ($item['title'] ?? 'untitled'),
            ));
        }

        return $exit;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }

    private function stringOption(string $key): ?string
    {
        $raw = $this->option($key);
        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        return $raw === '' ? null : $raw;
    }
}
