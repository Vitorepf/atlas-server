<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasMemorySeedCoreCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:memory:seed-core
        {--json : Print machine-readable JSON}';

    protected $description = 'Seed canonical provider-safe Atlas core memories for provider projections and bootstraps.';

    public function handle(AtlasMemoryRegistryService $memory): int
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
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
            $this->line($this->encode($payload));

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
            [
                'source_id' => 'migrations-never-stamp-manually',
                'canonical_doc' => 'CLAUDE.md',
                'memory_type' => 'decision',
                'title' => 'Migrations: nunca INSERT INTO migrations manualmente',
                'summary' => 'Migrations devem ser idempotentes; nunca carimbar manualmente o registro.',
                'body' => "Nunca INSERT INTO migrations manualmente. Nunca editar a tabela migrations pra pular uma migration que conflita com schema existente.\n\nSe uma migration conflita com schema vivo, a migration vira idempotente:\n- Schema::create → guardar com if (! DatabaseTableAvailability::has(...))\n- Schema::table adicionando coluna → guardar com if (! DatabaseTableAvailability::hasColumn(...))\n- CREATE INDEX → usar IF NOT EXISTS\n- ADD CONSTRAINT → checar pg_constraint antes\n- CREATE TRIGGER → DROP TRIGGER IF EXISTS antes\n\nA tabela migrations é log de execução do Laravel, não ferramenta de configuração. Carimbar manualmente cria drift silencioso: o Laravel acredita que rodou, o DDL não rodou, e a próxima migration que dependa daquele schema quebra em produção sem aviso.\n\nIncidente 2026-05-01: 49 migrations carimbadas Ran, 23 tabelas faltando, 14 tabelas legado órfãs. Resolvido com nuke + migrate from zero.",
                'importance' => 9,
                'priority' => 89,
                'confidence' => 1.0,
            ],
            [
                'source_id' => 'database-dev-fresh-over-repair',
                'canonical_doc' => 'CLAUDE.md',
                'memory_type' => 'decision',
                'title' => 'Database em dev: fresh > reparo',
                'summary' => 'DB local que diverge = nuke + recreate, não migration de reparo.',
                'body' => "Quando o DB local diverge das migrations:\n\n1. Não escrever migration de reparo (gambiarra que vira cicatriz permanente).\n2. docker compose stop backend queue scheduler (senão entram em loop tentando migrar).\n3. docker exec atlas-db psql -U atlas -d postgres -c \"DROP DATABASE atlas WITH (FORCE);\" seguido de CREATE DATABASE atlas OWNER atlas.\n4. docker compose run --rm --no-deps backend php artisan migrate --force (one-shot, output limpo).\n5. Religar backend queue scheduler.\n\nDados de dev são descartáveis por padrão. Se algum momento tiver dado vivo que importa, pg_dump --data-only antes do nuke e restore depois.",
                'importance' => 9,
                'priority' => 88,
                'confidence' => 1.0,
            ],
            [
                'source_id' => 'schema-invariants-live-in-db',
                'canonical_doc' => 'CLAUDE.md',
                'memory_type' => 'technical_context',
                'title' => 'Invariantes de schema vivem no DB, não no ORM',
                'summary' => 'Invariantes mecânicos (updated_at, CHECK, FK, defaults) vivem no DB; lógica de negócio no código.',
                'body' => "Toda tabela com coluna updated_at tem trigger trg_<table>_updated_at chamando set_updated_at(). Sem exceção.\n\nCREATE TRIGGER trg_<table>_updated_at BEFORE UPDATE ON <table> FOR EACH ROW EXECUTE FUNCTION set_updated_at();\n\nAdicionar DB::statement() no up() da migration logo depois do Schema::create.\n\nPor que: Eloquent atualiza updated_at em \$model->save(), mas não atualiza em Model::where(...)->update([...]) (bulk update bypassa events), nem em raw SQL (DB::table, DB::statement), nem em workers de outra linguagem, nem em manutenção via psql. Trigger no DB protege todos esses caminhos.\n\nA linha: se a regra existe pra proteger integridade dos dados, vai pro DB. Se existe pra regular comportamento da aplicação, vai pro código.",
                'importance' => 8,
                'priority' => 87,
                'confidence' => 1.0,
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
            'safety' => $this->safetySummary($entry),
            'recorded_at' => $entry->recorded_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(AtlasMemoryEntry $entry): array
    {
        $privacyClass = (string) data_get($entry->metadata ?? [], 'privacy.class', $entry->privacy_class ?? 'normal');
        $redactionStatus = (string) data_get($entry->metadata ?? [], 'privacy.redaction_status', $entry->redaction_status ?? 'clean');
        $externalAiAllowed = filter_var(
            data_get($entry->metadata ?? [], 'privacy.external_ai_allowed', $entry->external_ai_allowed ?? true),
            FILTER_VALIDATE_BOOL,
        );
        $providerExportAllowed = $externalAiAllowed
            && $privacyClass !== 'secret'
            && $redactionStatus !== 'blocked';

        return [
            'schema_version' => 'atlas.memory_entry.safety.v1',
            'memory_eligible' => $entry->status === 'active',
            'context_eligible' => $entry->status === 'active' && $providerExportAllowed,
            'provider_export_allowed' => $providerExportAllowed,
            'open_brain_context_allowed' => $entry->status === 'active' && $providerExportAllowed,
            'raw_content_exposed' => false,
            'privacy_class' => $privacyClass,
            'redaction_status' => $redactionStatus,
            'content_hash' => $entry->content_hash,
        ];
    }
}
