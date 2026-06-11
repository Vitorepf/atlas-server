<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Services\Engineering\CodeGraph\CodeGraphSchemaVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AP-818 F2.0 · Estado de inteligência de um workspace, derivado do read-model.
 *
 * Responde "o que o Atlas já SABE sobre este workspace_id?" lendo apenas:
 *   - o read-model do Code Intelligence (atlas_engineering_code_modules,
 *     keyado por workspace_id — W-1/W-3), e
 *   - o marcador de ciclo de vida do assembly (cache, escrito pelo job F2.1).
 *
 * intelligence_status:
 *   - indexing          · assembly em execução agora (marcador running)
 *   - assembly_pending  · assembly enfileirado, ainda não começou
 *   - failed            · último assembly falhou (marcador com TTL — não é veredito eterno)
 *   - indexed           · read-model tem módulos vivos para o workspace_id
 *   - portrait_only     · só o retrato barato da Fase 1 existe
 *
 * Read-only, fail-safe (DB indisponível → portrait_only honesto, nunca exceção).
 * Também é o consumidor vivo do W-9 ({@see CodeGraphSchemaVersion}): grava em
 * cache a versão de schema sob a qual cada workspace foi indexado, e responde
 * se um re-assembly é necessário mesmo com índice recente.
 */
final class WorkspaceIntelligenceStatusReader
{
    private const MARKER_PREFIX = 'atlas.folder_intel.assembly_state:';

    private const MARKER_TTL_SECONDS = 7200;

    private const SCHEMA_STORE_KEY = 'atlas.code_graph.schema_versions';

    /**
     * @return array{intelligence_status: string, modules: int, symbols: int, last_indexed_at: string|null}
     */
    public function status(string $workspaceId): array
    {
        $base = [
            'intelligence_status' => 'portrait_only',
            'modules' => 0,
            'symbols' => 0,
            'last_indexed_at' => null,
        ];

        if (trim($workspaceId) === '') {
            return $base;
        }

        try {
            $row = DB::table('atlas_engineering_code_modules')
                ->where('workspace_id', $workspaceId)
                ->whereNull('archived_at')
                ->selectRaw('count(*) as modules, coalesce(sum(symbol_count), 0) as symbols, max(indexed_at) as last_indexed_at')
                ->first();

            $base['modules'] = (int) ($row->modules ?? 0);
            $base['symbols'] = (int) ($row->symbols ?? 0);
            $base['last_indexed_at'] = $row->last_indexed_at !== null
                ? (string) $row->last_indexed_at
                : null;
        } catch (Throwable) {
            // read-model indisponível → estado honesto sem índice.
        }

        $marker = $this->marker($workspaceId);
        $base['intelligence_status'] = match (true) {
            $marker === 'running' => 'indexing',
            $marker === 'queued' => 'assembly_pending',
            $marker === 'failed' => 'failed',
            $base['modules'] > 0 => 'indexed',
            default => 'portrait_only',
        };

        return $base;
    }

    /**
     * Status de um MEMBRO de guarda-chuva que não tem grafo próprio: o filho
     * vive DENTRO do grafo do umbrella (canon do CodeGraphWorkspaceIdentity —
     * cobertura por prefixo), com módulos keyados por root_path relativo
     * ('atlas-desktop/apps/...'). Esta leitura fatia o grafo do umbrella pelo
     * diretório do filho, então cada membro mostra os SEUS números — nunca o
     * total do guarda-chuva repetido.
     *
     * @return array{intelligence_status: string, modules: int, symbols: int, last_indexed_at: string|null}
     */
    public function scopedStatus(string $umbrellaWorkspaceId, string $childDirName): array
    {
        $base = [
            'intelligence_status' => 'portrait_only',
            'modules' => 0,
            'symbols' => 0,
            'last_indexed_at' => null,
        ];

        $dir = trim($childDirName, '/');
        if (trim($umbrellaWorkspaceId) === '' || $dir === '') {
            return $base;
        }

        try {
            $row = DB::table('atlas_engineering_code_modules')
                ->where('workspace_id', $umbrellaWorkspaceId)
                ->whereNull('archived_at')
                ->where(function ($query) use ($dir): void {
                    $query->where('root_path', $dir)
                        ->orWhere('root_path', 'like', $dir.'/%');
                })
                ->selectRaw('count(*) as modules, coalesce(sum(symbol_count), 0) as symbols, max(indexed_at) as last_indexed_at')
                ->first();

            $base['modules'] = (int) ($row->modules ?? 0);
            $base['symbols'] = (int) ($row->symbols ?? 0);
            $base['last_indexed_at'] = $row->last_indexed_at !== null ? (string) $row->last_indexed_at : null;
        } catch (Throwable) {
            // read-model indisponível → estado honesto sem índice.
        }

        // O ciclo de vida do assembly do umbrella cobre os membros sem grafo próprio.
        $marker = $this->marker($umbrellaWorkspaceId);
        $base['intelligence_status'] = match (true) {
            $marker === 'running' => 'indexing',
            $marker === 'queued' => 'assembly_pending',
            $base['modules'] > 0 => 'indexed',
            $marker === 'failed' => 'failed',
            default => 'portrait_only',
        };

        return $base;
    }

    /**
     * W-9 na admissão: um workspace precisa de assembly quando NÃO está indexado,
     * quando o índice é mais velho que a janela de frescor, ou quando o schema do
     * grafo bump-ou desde o último index ({@see CodeGraphSchemaVersion}).
     */
    public function needsAssembly(string $workspaceId): bool
    {
        $status = $this->status($workspaceId);

        if ($status['modules'] === 0) {
            return true;
        }

        if ($this->schemaVersions()->needsReindex($workspaceId)) {
            return true;
        }

        $freshMinutes = max(1, (int) config('atlas.code_folder_intelligence.fresh_minutes', 30));
        $lastIndexedAt = $status['last_indexed_at'];
        if ($lastIndexedAt === null) {
            return true;
        }

        try {
            return now()->diffInMinutes(\Illuminate\Support\Carbon::parse($lastIndexedAt), true) > $freshMinutes;
        } catch (Throwable) {
            return true; // timestamp ilegível → re-assembly é a direção segura.
        }
    }

    /**
     * AP-818 F2.3 · Retrato indexado: fatos REAIS do read-model — não
     * contagens por convenção de pasta. Top módulos por densidade de símbolos,
     * distribuição por tipo de símbolo e amostras NOMEADAS por categoria
     * (rotas/migrations/commands), tudo bounded e read-only. null quando o
     * workspace nunca foi indexado (o chamador cai no retrato v1).
     *
     * @return array<string, mixed>|null
     */
    public function indexPortrait(string $workspaceId): ?array
    {
        if (trim($workspaceId) === '') {
            return null;
        }

        try {
            $symbolTypes = DB::table('atlas_engineering_code_symbols')
                ->where('workspace_id', $workspaceId)
                ->whereNull('archived_at')
                ->selectRaw('symbol_type, count(*) as total')
                ->groupBy('symbol_type')
                ->orderByDesc('total')
                ->limit(10)
                ->get()
                ->mapWithKeys(static fn (object $row): array => [(string) $row->symbol_type => (int) $row->total])
                ->all();

            if ($symbolTypes === []) {
                return null;
            }

            $topModules = DB::table('atlas_engineering_code_modules')
                ->where('workspace_id', $workspaceId)
                ->whereNull('archived_at')
                ->orderByDesc('symbol_count')
                ->limit(5)
                ->get(['slug', 'root_path', 'symbol_count'])
                ->map(static fn (object $row): array => [
                    'slug' => (string) $row->slug,
                    'root_path' => (string) $row->root_path,
                    'symbols' => (int) $row->symbol_count,
                ])
                ->all();

            $samples = [];
            foreach (['route' => 'routes', 'migration_table' => 'migrations', 'cli_command' => 'commands'] as $type => $label) {
                $names = DB::table('atlas_engineering_code_symbols')
                    ->where('workspace_id', $workspaceId)
                    ->where('symbol_type', $type)
                    ->whereNull('archived_at')
                    ->orderByDesc('indexed_at')
                    ->limit(3)
                    ->pluck('symbol_name')
                    ->map(static fn ($name): string => (string) $name)
                    ->all();
                if ($names !== []) {
                    $samples[$label] = $names;
                }
            }

            return [
                'symbol_types' => $symbolTypes,
                'top_modules' => $topModules,
                'samples' => $samples,
            ];
        } catch (Throwable) {
            return null; // read-model indisponível → retrato v1 segue valendo.
        }
    }

    public function markQueued(string $workspaceId): void
    {
        $this->putMarker($workspaceId, 'queued');
    }

    public function markRunning(string $workspaceId): void
    {
        $this->putMarker($workspaceId, 'running');
    }

    public function markFailed(string $workspaceId): void
    {
        $this->putMarker($workspaceId, 'failed');
    }

    public function clearMarker(string $workspaceId): void
    {
        try {
            Cache::forget(self::MARKER_PREFIX.$workspaceId);
        } catch (Throwable) {
            // best-effort; TTL limpa sozinho.
        }
    }

    /**
     * Registra (W-9) que o workspace acabou de ser indexado sob o schema atual.
     */
    public function recordIndexedSchemaVersion(string $workspaceId): void
    {
        try {
            $store = Cache::get(self::SCHEMA_STORE_KEY, []);
            $store = is_array($store) ? $store : [];
            $store[$workspaceId] = CodeGraphSchemaVersion::CURRENT;
            Cache::forever(self::SCHEMA_STORE_KEY, $store);
        } catch (Throwable) {
            // sem registro → próximo needsAssembly() re-indexa; direção segura.
        }
    }

    private function marker(string $workspaceId): ?string
    {
        try {
            $value = Cache::get(self::MARKER_PREFIX.$workspaceId);

            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function putMarker(string $workspaceId, string $state): void
    {
        if (trim($workspaceId) === '') {
            return;
        }

        try {
            Cache::put(self::MARKER_PREFIX.$workspaceId, $state, self::MARKER_TTL_SECONDS);
        } catch (Throwable) {
            // cache indisponível → status degrada para o read-model puro.
        }
    }

    private function schemaVersions(): CodeGraphSchemaVersion
    {
        try {
            $store = Cache::get(self::SCHEMA_STORE_KEY, []);

            return new CodeGraphSchemaVersion(is_array($store) ? $store : []);
        } catch (Throwable) {
            return new CodeGraphSchemaVersion;
        }
    }
}
