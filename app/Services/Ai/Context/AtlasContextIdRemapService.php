<?php

namespace App\Services\Ai\Context;

use App\Models\AtlasContextIdRemap;
use App\Services\Ai\ValueObjects\ContextIdRemap;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Atlas Cognition Operating System — Integer ID Remap Service.
 *
 * Schema canonico: atlas.context.id_remap.v1.
 * Doc canon: atlas-cognition-operating-system.md (Absorcao 1).
 * Doc absorcao: atlas-external-memory-pattern-absorptions-v1.md.
 *
 * RESPONSABILIDADES:
 *  1. Construir mapping deterministico internal_id ([0], [1], ...) <-> real_uuid
 *     a partir de coleta de context refs (memory recall, registry, verbatim,
 *     semantic, evidence refs, etc).
 *  2. Persistir mapping em atlas_context_id_remaps quando feature flag ativa,
 *     por context_pack_id (chave operacional).
 *  3. Reverse lookup: dado label "[N]" do response do provider, retornar UUID real.
 *  4. Parse de response: extrair todos "[N]" do texto e remapear em lote.
 *
 * INVARIANTES (cognitive immune compliance):
 *  - Provider NUNCA recebe UUID real direto em prompt quando feature flag ativa.
 *  - Reverse lookup SEMPRE roda antes de persistir Decision Receipt, Evidence
 *    Ledger ou AiMemoryDelta.
 *  - mapping_hash e deterministico (sha256 sobre payload canonical ordenado).
 *  - mapping_hash garante round-trip: encode -> decode -> assert equal.
 *
 * FEATURE FLAG:
 *  - config('atlas.cognition.id_remap.enabled') default false.
 *  - Quando false, service opera em pass-through (retorna ContextIdRemap::empty).
 *  - Habilitar somente apos round-trip tests verdes + integracao verificada.
 *
 * PRIVACY (ARPTL):
 *  - Mapping em si nao contem payload bruto.
 *  - real_to_internal e internal_to_real sao apenas UUIDs <-> inteiros.
 *  - ref_types carrega type/source_type/privacy_class para diagnostico.
 *  - Mapping respeita TTL canonico (config: atlas.cognition.id_remap.ttl_seconds).
 */
class AtlasContextIdRemapService
{
    public function __construct(
        private readonly ?int $defaultTtlSeconds = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('atlas.cognition.id_remap.enabled', false);
    }

    /**
     * Constroi remap a partir de lista de refs.
     *
     * Cada ref pode ter campos:
     *   - id (string)            REQUIRED — UUID real do recurso.
     *   - type (string)          OPTIONAL — memory_entry, verbatim, semantic_note, etc.
     *   - source_type (string)   OPTIONAL — pass-through para ref_types metadata.
     *   - privacy_class (string) OPTIONAL — pass-through para ref_types metadata.
     *
     * @param  array<int,array<string,mixed>>  $refs
     */
    public function remap(array $refs, string $contextPackId): ContextIdRemap
    {
        if (! $this->isEnabled() || $refs === []) {
            return ContextIdRemap::empty($contextPackId);
        }

        $internalToReal = [];
        $realToInternal = [];
        $refTypes = [];

        $internalCounter = 0;
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $realId = $ref['id'] ?? null;
            if (! is_string($realId) || trim($realId) === '') {
                continue;
            }
            $realId = trim($realId);

            // Dedup: se o mesmo UUID aparecer mais de uma vez, reusa o internal_id ja atribuido.
            if (isset($realToInternal[$realId])) {
                continue;
            }

            $internalKey = (string) $internalCounter++;
            $internalToReal[$internalKey] = $realId;
            $realToInternal[$realId] = $internalKey;

            $refTypes[$internalKey] = array_filter([
                'type' => $this->stringOrNull($ref['type'] ?? null),
                'source_type' => $this->stringOrNull($ref['source_type'] ?? null),
                'privacy_class' => $this->stringOrNull($ref['privacy_class'] ?? null),
                'scope' => $this->stringOrNull($ref['scope'] ?? null),
            ], fn ($v) => $v !== null);
        }

        $remap = new ContextIdRemap(
            contextPackId: $contextPackId,
            mappingHash: ContextIdRemap::hashOf($internalToReal, $refTypes),
            internalToReal: $internalToReal,
            realToInternal: $realToInternal,
            refTypes: $refTypes,
        );

        $this->persist($remap);

        return $remap;
    }

    /**
     * Reverse lookup por context_pack_id + internal_label.
     * Retorna UUID real ou null se nao encontrado.
     */
    public function reverseLookup(string $internalLabel, string $contextPackId): ?string
    {
        $remap = $this->get($contextPackId);
        if ($remap === null) {
            return null;
        }

        $real = $remap->realId($internalLabel);

        if ($real !== null) {
            $this->incrementReverseCounter($contextPackId);
        }

        return $real;
    }

    /**
     * Carrega remap persistido por context_pack_id, retornando ContextIdRemap.
     * Retorna null se nao encontrado, expirado ou tabela ausente.
     */
    public function get(string $contextPackId): ?ContextIdRemap
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (! $this->tableExists()) {
            return null;
        }

        $row = AtlasContextIdRemap::query()
            ->where('context_pack_id', $contextPackId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();

        if ($row === null) {
            return null;
        }

        if ($row->isExpired()) {
            return null;
        }

        return new ContextIdRemap(
            contextPackId: $row->context_pack_id,
            mappingHash: $row->mapping_hash,
            internalToReal: is_array($row->internal_to_real) ? $row->internal_to_real : [],
            realToInternal: is_array($row->real_to_internal) ? $row->real_to_internal : [],
            refTypes: is_array($row->ref_types) ? $row->ref_types : [],
            schemaVersion: $row->schema_version ?: 'atlas.context.id_remap.v1',
            scope: $row->scope ?: 'context_pack',
            providerSafe: (bool) $row->provider_safe,
        );
    }

    /**
     * Parse provider response: extrai todos labels "[N]" e remapeia para UUIDs reais.
     *
     * Aceita formatos:
     *  - [0], [1], [42]  (square bracket simples)
     *  - "supersede [1] with [0]"
     *  - "[memory_entry:3]" (com tipo prefix) — extrai apenas o numero
     *
     * Retorna array deduplicado de UUIDs reais. Labels nao mapeados sao silently dropped
     * (caller pode comparar tamanho do extract vs return para detectar mismatches).
     *
     * @return array<int,string>
     */
    public function parseResponse(string $providerResponse, string $contextPackId): array
    {
        if (! $this->isEnabled() || $providerResponse === '') {
            return [];
        }

        $remap = $this->get($contextPackId);
        if ($remap === null) {
            return [];
        }

        // Pattern 1: [N] simples ou [type:N] (capturamos o N).
        preg_match_all('/\[(?:[a-z_]+:)?(\d+)\]/i', $providerResponse, $matches);
        $internalLabels = $matches[1] ?? [];

        $realIds = [];
        $seen = [];
        foreach ($internalLabels as $label) {
            $real = $remap->realId($label);
            if ($real === null) {
                continue;
            }
            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $realIds[] = $real;
        }

        return $realIds;
    }

    /**
     * Detalhado: retorna lista de {internal_label, real_id, ref_type}
     * mantendo ordem de aparicao no response e marcando misses.
     *
     * @return array<int,array{internal:string,real:?string,ref_type:array<string,mixed>}>
     */
    public function parseResponseDetailed(string $providerResponse, string $contextPackId): array
    {
        if (! $this->isEnabled() || $providerResponse === '') {
            return [];
        }

        $remap = $this->get($contextPackId);
        if ($remap === null) {
            return [];
        }

        preg_match_all('/\[(?:[a-z_]+:)?(\d+)\]/i', $providerResponse, $matches);
        $internalLabels = $matches[1] ?? [];

        $results = [];
        foreach ($internalLabels as $label) {
            $results[] = [
                'internal' => $label,
                'real' => $remap->realId($label),
                'ref_type' => $remap->refType($label) ?? [],
            ];
        }

        return $results;
    }

    private function persist(ContextIdRemap $remap): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $now = now();
        $ttl = $this->defaultTtlSeconds ?? (int) config('atlas.cognition.id_remap.ttl_seconds', 3600);
        $expiresAt = $ttl > 0 ? $now->copy()->addSeconds($ttl) : null;

        // Idempotente: se ja existir mapping com mesmo hash, nao reescreve.
        $existing = AtlasContextIdRemap::query()
            ->where('context_pack_id', $remap->contextPackId)
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null && $existing->mapping_hash === $remap->mappingHash) {
            return;
        }

        // Se mapping mudou (drift), soft-delete o antigo antes de inserir o novo.
        if ($existing !== null) {
            $existing->deleted_at = $now;
            $existing->save();
        }

        AtlasContextIdRemap::create([
            'id' => (string) Str::uuid(),
            'context_pack_id' => $remap->contextPackId,
            'mapping_hash' => $remap->mappingHash,
            'schema_version' => $remap->schemaVersion,
            'internal_to_real' => $remap->internalToReal,
            'real_to_internal' => $remap->realToInternal,
            'ref_types' => $remap->refTypes,
            'scope' => $remap->scope,
            'provider_safe' => $remap->providerSafe,
            'lookup_count' => 0,
            'reverse_lookup_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    private function incrementReverseCounter(string $contextPackId): void
    {
        if (! $this->tableExists()) {
            return;
        }

        AtlasContextIdRemap::query()
            ->where('context_pack_id', $contextPackId)
            ->whereNull('deleted_at')
            ->increment('reverse_lookup_count');
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('atlas_context_id_remaps');
        } catch (\Throwable) {
            return false;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
