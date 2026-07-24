<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasMemoryFeedbackCommand extends Command
{
    use EmitsCanonicalJson;

    private const EXPLICIT_NEGATIVE_ACTIONS = [
        'not_useful',
        'wrong_context',
        'stale',
    ];

    protected $signature = 'atlas:memory:feedback
        {memory : Memory id, content-hash prefix, metadata slug, or title slug}
        {--action=not_useful : Explicit action: not_useful, wrong_context, or stale}
        {--comment= : Optional provider-safe operator comment}
        {--dry-run : Resolve and report without writing}
        {--json : Machine-readable output}';

    protected $description = 'Record explicit operator feedback for a recalled Atlas memory entry.';

    public function handle(AtlasMemoryUsageService $usageService): int
    {
        if (! DatabaseTableAvailability::all(['atlas_memory_entries', 'atlas_memory_entry_usages'])) {
            return $this->emit(['ok' => false, 'reason' => 'memory_tables_absent'], self::SUCCESS);
        }

        $action = trim((string) $this->option('action'));
        if (! in_array($action, self::EXPLICIT_NEGATIVE_ACTIONS, true)) {
            return $this->emit([
                'ok' => false,
                'reason' => 'unsupported_feedback_action',
                'allowed_actions' => self::EXPLICIT_NEGATIVE_ACTIONS,
            ], self::FAILURE);
        }

        $entry = $this->resolveEntry((string) $this->argument('memory'));
        if ($entry === null) {
            return $this->emit(['ok' => false, 'reason' => 'memory_not_found'], self::FAILURE);
        }

        $usage = $this->latestUnratedUsage($entry) ?? $this->newOperatorUsage($entry);
        $payload = [
            'ok' => true,
            'dry_run' => (bool) $this->option('dry-run'),
            'memory_entry_id' => (string) $entry->id,
            'usage_id' => (string) $usage->id,
            'feedback_action' => $action,
            'usage_source_type' => (string) $usage->source_type,
        ];

        if (! (bool) $this->option('dry-run')) {
            $usageService->recordFeedback($usage, [
                'feedback_action' => $action,
                'feedback_comment' => $this->option('comment'),
                'feedback_source' => 'operator_cli',
            ]);
        }

        return $this->emit($payload, self::SUCCESS);
    }

    private function resolveEntry(string $needle): ?AtlasMemoryEntry
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        if (Str::isUuid($needle)) {
            $entry = AtlasMemoryEntry::query()->find($needle);
            if ($entry instanceof AtlasMemoryEntry) {
                return $entry;
            }
        }

        $slug = Str::slug($needle);

        $query = AtlasMemoryEntry::query();
        $hasHashColumn = false;
        foreach (['content_hash', 'source_hash'] as $column) {
            if (! DatabaseTableAvailability::hasColumn('atlas_memory_entries', $column)) {
                continue;
            }
            $hasHashColumn = true;
            $query->orWhere($column, $needle)->orWhere($column, 'like', $needle.'%');
        }

        $hashMatch = $hasHashColumn ? $query->first() : null;
        if ($hashMatch instanceof AtlasMemoryEntry) {
            return $hashMatch;
        }

        return AtlasMemoryEntry::query()
            ->get()
            ->first(fn (AtlasMemoryEntry $entry): bool => (string) data_get($entry->metadata, 'slug') === $needle
                || Str::slug((string) $entry->title) === $slug);
    }

    private function latestUnratedUsage(AtlasMemoryEntry $entry): ?AtlasMemoryEntryUsage
    {
        return AtlasMemoryEntryUsage::query()
            ->where('memory_entry_id', $entry->id)
            ->whereNull('feedback_action')
            ->latest('created_at')
            ->first();
    }

    private function newOperatorUsage(AtlasMemoryEntry $entry): AtlasMemoryEntryUsage
    {
        return AtlasMemoryEntryUsage::query()->create([
            'memory_entry_id' => (string) $entry->id,
            'memory_type' => (string) $entry->memory_type,
            'scope_type' => (string) $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'source_type' => 'operator_explicit',
            'source_id' => 'operator:'.Str::uuid(),
            'position' => 0,
            'source_ref_json' => [
                'type' => 'atlas_memory_entry',
                'id' => (string) $entry->id,
            ],
            'context_payload_json' => [],
            'metadata' => [
                'created_by' => 'atlas_memory_feedback_cli',
            ],
            'used_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line(($payload['ok'] ?? false)
                ? sprintf('<info>memory feedback</info> %s -> %s%s', $payload['memory_entry_id'] ?? '', $payload['feedback_action'] ?? '', ($payload['dry_run'] ?? false) ? ' (dry-run)' : '')
                : sprintf('<error>memory feedback skipped</error> %s', $payload['reason'] ?? 'unknown'));
        }

        return $code;
    }
}
