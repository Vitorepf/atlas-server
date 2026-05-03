<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasMemoryRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AtlasMemorySeedCoreCommand extends Command
{
    protected $signature = 'atlas:memory:seed-core
        {--json : Print machine-readable JSON}';

    protected $description = 'Seed canonical provider-safe Atlas core memories for provider projections and bootstraps.';

    public function handle(AtlasMemoryRegistryService $memory): int
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            $this->error('Tabela atlas_memory_entries ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $rows = [];
        foreach ($this->entries() as $entry) {
            $identity = [
                'source_type' => 'atlas_memory_core_seed',
                'source_id' => $entry['source_id'],
            ];
            $existing = AtlasMemoryEntry::query()
                ->where('source_type', $identity['source_type'])
                ->where('source_id', $identity['source_id'])
                ->first();
            $model = $memory->upsert([
                ...$identity,
            ], array_merge($entry, [
                'scope_type' => 'global',
                'privacy_class' => 'normal',
                'external_ai_allowed' => true,
                'source_type' => $identity['source_type'],
                'source_label' => 'Atlas Memory Core Seed',
                'tags' => ['atlas-core', 'provider-safe', 'projection'],
                'metadata' => [
                    'seed' => 'atlas_memory_core_provider_projection_v1',
                    'canonical_doc' => $entry['canonical_doc'],
                ],
                'recorded_at' => $existing?->recorded_at ?? now(),
            ]));

            $rows[] = $this->row($model);
        }

        $payload = [
            'seed' => 'atlas_memory_core_provider_projection_v1',
            'seeded' => count($rows),
            'memories' => $rows,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Memorias core provider-safe sincronizadas: '.count($rows));
        $this->table(
            ['id', 'type', 'priority', 'title'],
            array_map(fn (array $row): array => [
                $row['id'],
                $row['memory_type'],
                (string) $row['priority'],
                $row['title'],
            ], $rows),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function entries(): array
    {
        return [
            [
                'source_id' => 'source-of-truth',
                'canonical_doc' => 'docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md',
                'memory_type' => 'decision',
                'title' => 'Atlas owns canonical memory',
                'summary' => 'Atlas memory is canonical; provider files are generated projections, not the source of truth.',
                'body' => 'Atlas memory belongs to Atlas. Canonical docs plus Postgres operational registries are the source of truth; CLAUDE.md, AGENTS.md and external provider prompts are short generated projections.',
                'importance' => 5,
                'priority' => 100,
                'confidence' => 1.0,
            ],
            [
                'source_id' => 'provider-projection-non-empty',
                'canonical_doc' => 'docs/engineering-knowledge-base/memory-core-runbook.md',
                'memory_type' => 'technical_context',
                'title' => 'Provider projections must contain provider-safe memory',
                'summary' => 'A managed provider projection with zero provider-safe memory is not ready for real Claude/Codex work.',
                'body' => 'Before trusting CLAUDE.md or AGENTS.md as a bootstrap, verify provider projection status. Empty provider-safe memory should be treated as needs_review and fixed by seeding or reviewing Atlas memory, then applying the projection.',
                'importance' => 5,
                'priority' => 98,
                'confidence' => 1.0,
            ],
            [
                'source_id' => 'php-runtime-contract',
                'canonical_doc' => 'composer.json',
                'memory_type' => 'technical_context',
                'title' => 'Atlas server requires Homebrew PHP 8.4+',
                'summary' => 'Use /opt/homebrew/bin/php for Artisan and Composer workflows in atlas-server.',
                'body' => 'The atlas-server runtime contract requires PHP >= 8.4. Use /opt/homebrew/bin/php for Artisan commands and keep composer.json/composer.lock platform aligned to ^8.4.',
                'importance' => 5,
                'priority' => 96,
                'confidence' => 1.0,
            ],
            [
                'source_id' => 'deterministic-memory-boundary',
                'canonical_doc' => 'docs/engineering-knowledge-base/memory-core-maturity-dod.md',
                'memory_type' => 'decision',
                'title' => 'Keep external memory infrastructure explicit',
                'summary' => 'Local hybrid recall and audited Open Brain context export are implemented; ChromaDB, MCP remote and multiuser sync still require explicit phase and DoD.',
                'body' => 'The Atlas memory core is local and provider-safe: registry, verbatim store, governance, context pack refs, provider projection, Engineering Knowledge, Code Intelligence, hybrid recall and audited Open Brain context export. External embedding providers are opt-in and privacy-gated. ChromaDB, MCP remote tooling and multiuser Open Brain synchronization remain future phases with separate DoD.',
                'importance' => 5,
                'priority' => 94,
                'confidence' => 1.0,
            ],
            [
                'source_id' => 'knowledge-sync-maintenance',
                'canonical_doc' => 'docs/engineering-knowledge-base/START_HERE.md',
                'memory_type' => 'harness_learning',
                'title' => 'Sync docs and code intelligence after memory changes',
                'summary' => 'After changing canonical docs or code, run engineering knowledge sync and index-code before relying on context packs.',
                'body' => 'Maintenance loop: after canonical docs or implementation changes, run atlas engineering knowledge sync --prune and atlas engineering knowledge index-code --prune, then review provider projection status and apply only after review.',
                'importance' => 4,
                'priority' => 90,
                'confidence' => 0.98,
            ],
        ];
    }

    private function row(AtlasMemoryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'title' => $entry->title,
            'summary' => $entry->summary,
            'priority' => $entry->priority,
            'importance' => $entry->importance,
            'privacy_class' => $entry->privacy_class,
            'external_ai_allowed' => $entry->external_ai_allowed,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'status' => $entry->status,
            'recorded_at' => $entry->recorded_at?->toJSON(),
        ];
    }
}
