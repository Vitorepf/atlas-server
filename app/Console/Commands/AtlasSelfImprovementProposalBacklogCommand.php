<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Improvement Proposal Backlog CLI (Level 7).
 *
 *   php artisan atlas:self-improvement:proposal-backlog --json --strict
 *   php artisan atlas:self-improvement:proposal-backlog --create --proposal=@payload.json --json
 *   php artisan atlas:self-improvement:proposal-backlog --proposal=<id> --evaluate --json --strict
 *   php artisan atlas:self-improvement:proposal-backlog --proposal=<id> --prioritize [--bucket=core_runtime] --json --strict
 *
 * NEVER creates an Obra. NEVER calls a provider. NEVER spends tokens.
 */
final class AtlasSelfImprovementProposalBacklogCommand extends Command
{
    protected $signature = 'atlas:self-improvement:proposal-backlog
        {--create : Create a new proposal (requires --proposal payload)}
        {--proposal= : Inline JSON or @path with the proposal payload (for --create) or proposal id (for read/evaluate/prioritize)}
        {--evaluate : Run Power Gate on the proposal (requires --proposal id)}
        {--prioritize : Compute strategy bucket + priority score (requires --proposal id)}
        {--bucket= : Explicit strategy bucket override for --prioritize}
        {--status= : Filter list by status}
        {--source= : Filter list by source}
        {--linked-obra= : Filter list by linked obra presence (true/false)}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when proposal is blocked or has blockers}';

    protected $description = 'Atlas Self-Improvement Proposal Backlog v1 (Level 7). Manage proposals before they become activations.';

    public function handle(AtlasSelfImprovementProposalBacklogService $service): int
    {
        $create = (bool) $this->option('create');
        $evaluate = (bool) $this->option('evaluate');
        $prioritize = (bool) $this->option('prioritize');
        $strict = (bool) $this->option('strict');
        $proposalOption = $this->stringOption('proposal');

        if ($create) {
            $payload = $this->resolveJsonOption('proposal');
            if (! is_array($payload)) {
                $this->emit([
                    'status' => 'blocked',
                    'blockers' => ['create_requires_proposal_payload'],
                ]);

                return $strict ? self::FAILURE : self::SUCCESS;
            }
            $item = $service->createProposal($payload);
            $this->emit($item);

            return $this->strictExit($item, $strict);
        }

        if ($evaluate || $prioritize) {
            if ($proposalOption === null) {
                $this->emit([
                    'status' => 'blocked',
                    'blockers' => ['proposal_id_required'],
                ]);

                return $strict ? self::FAILURE : self::SUCCESS;
            }
            $item = $evaluate
                ? $service->evaluateProposal($proposalOption)
                : $service->prioritize($proposalOption, ['strategy_bucket' => $this->stringOption('bucket')]);
            $this->emit($item);

            return $this->strictExit($item, $strict);
        }

        if ($proposalOption !== null) {
            $item = $service->getProposal($proposalOption);
            if ($item === null) {
                $payload = [
                    'status' => 'blocked',
                    'proposal_id' => $proposalOption,
                    'blockers' => ['proposal_not_found'],
                ];
                $this->emit($payload);

                return $strict ? self::FAILURE : self::SUCCESS;
            }
            $this->emit($item);

            return $this->strictExit($item, $strict);
        }

        $listing = $service->listBacklog([
            'status' => $this->stringOption('status'),
            'source' => $this->stringOption('source'),
            'linked_obra' => $this->boolOption('linked-obra'),
        ]);
        $this->emit($listing);

        return $this->strictExitListing($listing, $strict);
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
        $blockers = $payload['blockers'] ?? [];
        if (is_array($blockers) && $blockers !== []) {
            $this->components->bulletList($blockers);
        }
        $counters = $payload['counters'] ?? null;
        if (is_array($counters)) {
            foreach ($counters as $k => $v) {
                $this->components->twoColumnDetail($k, (string) $v);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function strictExit(array $payload, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }
        $blockers = (array) ($payload['blockers'] ?? []);
        if ($blockers !== []) {
            return self::FAILURE;
        }
        $status = (string) ($payload['status'] ?? '');
        if (in_array($status, ['blocked', 'rejected'], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $listing
     */
    private function strictExitListing(array $listing, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }
        $counters = (array) ($listing['counters'] ?? []);
        if (($counters['total'] ?? 0) === 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
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

    private function boolOption(string $key): ?bool
    {
        $value = $this->stringOption($key);
        if ($value === null) {
            return null;
        }
        $value = strtolower($value);
        if (in_array($value, ['true', '1', 'yes'], true)) {
            return true;
        }
        if (in_array($value, ['false', '0', 'no'], true)) {
            return false;
        }

        return null;
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
                $this->components->error('payload file not found: '.$path);

                return null;
            }
            $raw = (string) file_get_contents($path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->components->error('payload is not valid JSON: '.$e->getMessage());

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
