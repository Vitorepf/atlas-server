<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Improvement Measure Result CLI (Level 7).
 *
 *   php artisan atlas:self-improvement:measure-result \
 *     --proposal=<id> \
 *     --obra=<uuid> \
 *     --before=@before.json \
 *     --after=@after.json \
 *     --reviewer=<who> \
 *     --reason=<why> \
 *     --json --strict
 *
 * Persists a result entry, computes delta scorecard + invariant lock +
 * regression sentinel, records the canonical self_improvement_* outcome on
 * the trust ledger, and links the result back to the proposal backlog.
 *
 * NEVER promotes completion claim. NEVER runs Fast Path. NEVER calls
 * provider.
 */
final class AtlasSelfImprovementMeasureResultCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:self-improvement:measure-result
        {--proposal= : Proposal id (prop_<ULID>) — required}
        {--obra= : Obra (AtlasProject) UUID — required}
        {--before= : Before snapshot — inline JSON or @path}
        {--after= : After snapshot — inline JSON or @path}
        {--reviewer= : Reviewer id — required}
        {--reason= : Reason / measurement summary — required}
        {--context= : Optional additional context — inline JSON or @path}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when blockers exist or grade is regressed/invalid}';

    protected $description = 'Atlas Self-Improvement Measure Result v1 (Level 7) — records before/after delta + learning packet.';

    public function handle(
        AtlasSelfImprovementResultLedgerService $resultLedger,
        AtlasSelfImprovementProposalBacklogService $proposalBacklog,
    ): int {
        $strict = (bool) $this->option('strict');
        $proposalId = $this->stringOption('proposal');
        $obraId = $this->stringOption('obra');
        $reviewer = $this->stringOption('reviewer');
        $reason = $this->stringOption('reason');

        $payload = [
            'proposal_id' => $proposalId,
            'obra_id' => $obraId,
            'before_snapshot' => $this->resolveJsonOption('before'),
            'after_snapshot' => $this->resolveJsonOption('after'),
            'reviewer' => $reviewer,
            'reason' => $reason,
            'context' => $this->resolveJsonOption('context') ?? [],
        ];

        $entry = $resultLedger->record($payload);

        if (($entry['status'] ?? null) !== 'blocked' && isset($entry['result_entry_id'], $entry['delta_grade']) && is_string($proposalId)) {
            $proposalBacklog->markDeltaMeasured(
                $proposalId,
                (string) $entry['result_entry_id'],
                (string) $entry['delta_grade'],
            );
        }

        $this->emit($entry);

        if (! $strict) {
            return self::SUCCESS;
        }
        if (($entry['status'] ?? null) === 'blocked') {
            return self::FAILURE;
        }
        if (in_array((string) ($entry['delta_grade'] ?? ''), ['regressed', 'invalid'], true)) {
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
        $this->components->twoColumnDetail('result_entry_id', (string) ($payload['result_entry_id'] ?? '—'));
        $this->components->twoColumnDetail('delta_grade', (string) ($payload['delta_grade'] ?? '—'));
        $this->components->twoColumnDetail('trust_outcome', (string) ($payload['trust_outcome_recorded'] ?? '—'));
        $blockers = $payload['blockers'] ?? [];
        if (is_array($blockers) && $blockers !== []) {
            $this->components->bulletList($blockers);
        }
    }


    /**
     * @return array<string,mixed>|null
     */
    private function resolveJsonOption(string $key): ?array
    {
        $raw = $this->option($key);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            if (! is_file($path)) {
                $this->components->error('file not found: '.$path);

                return null;
            }
            $raw = (string) file_get_contents($path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->components->error('invalid JSON for --'.$key.': '.$e->getMessage());

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
