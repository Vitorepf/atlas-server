<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Models\AiMemoryDelta;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AiMemoryDeltaProposer;
use App\Services\Ai\Memory\AtlasMemoryDeltaPromotionService;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasCliMemoryCommand extends Command
{
    use EmitsCanonicalJson;

    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:cli:memory
        {action=review : review, accept, reject, promote, list, show or propose}
        {delta? : Delta id}
        {--workspace= : Workspace path. Defaults to current directory}
        {--type=*}
        {--scope=}
        {--all : Apply action to all pending deltas in scope}
        {--force : Promote a non-accepted delta}
        {--memory-type= : Override promoted registry memory type}
        {--scope-type= : Override promoted registry scope type}
        {--scope-id= : Override promoted registry scope id}
        {--limit=50 : Maximum rows for list and bulk actions}
        {--json : Print machine-readable JSON}';

    protected $description = 'Review and apply typed Atlas memory deltas.';

    public function handle(AiMemoryDeltaProposer $proposer, AtlasMemoryDeltaPromotionService $promoter): int
    {
        if (! DatabaseTableAvailability::has('ai_memory_deltas')) {
            $this->error('Tabela ai_memory_deltas ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $action = Str::of((string) $this->argument('action'))->lower()->trim()->value();

        return match ($action) {
            'propose' => $this->propose($proposer),
            'review', 'list' => $this->review(),
            'show' => $this->show(),
            'accept' => $this->setStatus('accepted'),
            'reject' => $this->setStatus('rejected'),
            'promote' => $this->promote($promoter),
            default => $this->invalid($action),
        };
    }

    private function propose(AiMemoryDeltaProposer $proposer): int
    {
        $deltas = $proposer->proposeForWorkspace($this->workspace());
        $payload = array_map(fn (AiMemoryDelta $delta): array => $this->row($delta), $deltas);

        return $this->print(['ok' => true, 'proposed' => $payload]);
    }

    private function review(): int
    {
        $query = $this->query();
        if (! (bool) $this->option('all')) {
            $query->where('status', 'pending');
        }

        $payload = $query->latest('created_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->map(fn (AiMemoryDelta $delta): array => $this->row($delta))
            ->all();

        return $this->print(['memory_deltas' => $payload]);
    }

    private function show(): int
    {
        $delta = $this->delta();
        if (! $delta) {
            $this->error('Memory delta nao encontrado.');

            return self::FAILURE;
        }

        return $this->print(['memory_delta' => $this->row($delta, full: true)]);
    }

    private function setStatus(string $status): int
    {
        $query = $this->query();
        if ((bool) $this->option('all')) {
            $query->where('status', 'pending');
            $count = 0;
            $query->get()->each(function (AiMemoryDelta $delta) use ($status, &$count): void {
                $delta->update(['status' => $status]);
                $count++;
            });

            return $this->print(['ok' => true, 'updated' => $count, 'status' => $status]);
        }

        $delta = $this->delta();
        if (! $delta) {
            $this->error('Memory delta nao encontrado.');

            return self::FAILURE;
        }

        $delta->update(['status' => $status]);

        return $this->print(['ok' => true, 'memory_delta' => $this->row($delta->refresh(), full: true)]);
    }

    private function promote(AtlasMemoryDeltaPromotionService $promoter): int
    {
        try {
            if ((bool) $this->option('all')) {
                $entries = $promoter->promoteAccepted($this->promotionOptions() + [
                    'scope' => $this->stringOption('scope'),
                    'types' => array_values(array_filter((array) $this->option('type'), 'is_string')),
                    'limit' => (int) $this->option('limit'),
                ]);

                return $this->print([
                    'ok' => true,
                    'promoted' => $entries->map(fn (AtlasMemoryEntry $entry): array => $this->memoryRow($entry))->values()->all(),
                ]);
            }

            $delta = $this->delta();
            if (! $delta) {
                $this->error('Memory delta nao encontrado.');

                return self::FAILURE;
            }

            $entry = $promoter->promote($delta, $this->promotionOptions());

            return $this->print([
                'ok' => true,
                'memory_delta' => $this->row($delta->refresh(), full: true),
                'memory' => $this->memoryRow($entry),
            ]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function delta(): ?AiMemoryDelta
    {
        $id = $this->argument('delta');

        return is_string($id) && $id !== '' ? AiMemoryDelta::query()->find($id) : null;
    }

    private function query()
    {
        $query = AiMemoryDelta::query();
        $scope = $this->option('scope');
        if (is_string($scope) && $scope !== '') {
            $query->where('scope', $scope);
        } else {
            $query->whereIn('scope', ['global', 'project:atlas', 'workspace:'.$this->workspace()]);
        }

        $types = array_values(array_filter((array) $this->option('type'), 'is_string'));
        if ($types !== []) {
            $query->whereIn('type', $types);
        }

        return $query;
    }

    /**
     * @return array<string,mixed>
     */
    private function row(AiMemoryDelta $delta, bool $full = false): array
    {
        $row = [
            'id' => $delta->id,
            'status' => $delta->status,
            'type' => $delta->type,
            'claim' => $delta->claim,
            'scope' => $delta->scope,
            'confidence' => $delta->confidence,
            'valid_until' => $delta->valid_until?->toJSON(),
            'promoted_memory_entry_id' => $delta->promoted_memory_entry_id,
            'promoted_at' => $delta->promoted_at?->toJSON(),
        ];

        return $full ? $row + [
            'evidence' => $delta->evidence,
            'use_when' => $delta->use_when,
            'do_not_use_when' => $delta->do_not_use_when,
            'source_trace_id' => $delta->source_trace_id,
            'source_session_id' => $delta->source_session_id,
        ] : $row;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function print(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        if (isset($payload['memory'])) {
            $memory = (array) $payload['memory'];
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Memoria promovida</>', (string) ($memory['id'] ?? '-'));
            $this->components->twoColumnDetail('Type', (string) ($memory['memory_type'] ?? '-'));
            $this->components->twoColumnDetail('Scope', (string) ($memory['scope'] ?? '-'));
            $this->components->twoColumnDetail('Source delta', (string) ($memory['source_id'] ?? '-'));

            return self::SUCCESS;
        }

        if (isset($payload['memory_delta'])) {
            $this->line(json_encode($payload['memory_delta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (isset($payload['promoted'])) {
            $this->table(['id', 'type', 'scope', 'source_delta', 'summary'], collect($payload['promoted'])->map(fn (array $row): array => [
                $row['id'],
                $row['memory_type'],
                $row['scope'],
                $row['source_id'],
                Str::limit((string) ($row['summary'] ?: $row['body']), 100),
            ])->all());

            return self::SUCCESS;
        }

        $rows = $payload['memory_deltas'] ?? $payload['proposed'] ?? [];
        $this->table(['id', 'status', 'type', 'confidence', 'scope', 'claim'], collect($rows)->map(fn (array $row): array => [
            $row['id'],
            $row['status'],
            $row['type'],
            $row['confidence'],
            $row['scope'],
            Str::limit($row['claim'], 100),
        ])->all());

        return self::SUCCESS;
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function invalid(string $action): int
    {
        $this->error("Acao invalida para atlas memory: {$action}");

        return self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function promotionOptions(): array
    {
        return array_filter([
            'force' => (bool) $this->option('force'),
            'memory_type' => $this->stringOption('memory-type'),
            'scope_type' => $this->stringOption('scope-type'),
            'scope_id' => $this->stringOption('scope-id'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string,mixed>
     */
    private function memoryRow(AtlasMemoryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'scope' => $entry->scope_id ? $entry->scope_type.':'.$entry->scope_id : $entry->scope_type,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'body' => $entry->body,
            'priority' => $entry->priority,
            'importance' => $entry->importance,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'status' => $entry->status,
        ];
    }

}
