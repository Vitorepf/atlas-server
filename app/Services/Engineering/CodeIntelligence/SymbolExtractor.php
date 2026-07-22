<?php

namespace App\Services\Engineering\CodeIntelligence;

use App\Models\AtlasEngineeringCodeSymbol;
use Illuminate\Support\Carbon;

/**
 * GOD-DEBULK FASE C — pure `symbol*` shaping extracted VERBATIM from
 * EngineeringCodeIntelligenceService: the scanned-symbol builder, the model
 * payload, and the audit/source-key/documentation-row shapes. Stateless: no DB,
 * no workspace state — the DB-coupled orchestrator (symbolDrift()) stays in the
 * façade because it reads persisted rows and the mutable workspaceId.
 *
 * NOTE: symbolDriftTypeCounts (a `symbol*` sibling) was DEAD — zero callers in
 * the whole service — so it was dropped here rather than carried across.
 */
class SymbolExtractor
{
    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function symbol(array $attributes): array
    {
        return array_merge([
            'module_slug' => null,
            'symbol_type' => 'symbol',
            'symbol_name' => null,
            'file_path' => null,
            'line_start' => null,
            'line_end' => null,
            'language' => null,
            'signature' => null,
            'namespace' => null,
            'parent_symbol' => null,
            'visibility' => null,
            'metadata' => [],
        ], $attributes);
    }

    /**
     * @param  array<string,mixed>  $symbol
     */
    public function symbolSourceKey(array $symbol): string
    {
        return (string) ($symbol['symbol_type'] ?? '').'|'.(string) ($symbol['source_hash'] ?? '');
    }

    /**
     * @param  array<string,mixed>|null  $symbol
     * @return array<string,mixed>
     */
    public function symbolAuditPayload(?array $symbol, string $reason): array
    {
        return [
            'reason' => $reason,
            'module_slug' => $symbol['module_slug'] ?? null,
            'symbol_type' => (string) ($symbol['symbol_type'] ?? ''),
            'symbol_name' => (string) ($symbol['symbol_name'] ?? ''),
            'file_path' => (string) ($symbol['file_path'] ?? ''),
            'line_start' => $symbol['line_start'] ?? null,
            'language' => $symbol['language'] ?? null,
            'source_hash' => (string) ($symbol['source_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,bool>  $docIds
     * @return array<string,mixed>
     */
    public function symbolDocumentationUpdateRow(string $symbolId, array $docIds, Carbon $now): array
    {
        return [
            'id' => $symbolId,
            'docs_status' => 'documented',
            'related_doc_ids_json' => $this->json(array_keys($docIds)),
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function symbolPayload(AtlasEngineeringCodeSymbol $symbol): array
    {
        return [
            'id' => $symbol->id,
            'module_id' => $symbol->module_id,
            'module_slug' => $symbol->module?->slug,
            'symbol_type' => $symbol->symbol_type,
            'symbol_name' => $symbol->symbol_name,
            'file_path' => $symbol->file_path,
            'line_start' => $symbol->line_start,
            'line_end' => $symbol->line_end,
            'language' => $symbol->language,
            'signature' => $symbol->signature,
            'namespace' => $symbol->namespace,
            'parent_symbol' => $symbol->parent_symbol,
            'visibility' => $symbol->visibility,
            'status' => $symbol->status,
            'docs_status' => $symbol->docs_status,
            'source_hash' => $symbol->source_hash,
            'related_doc_ids' => $symbol->related_doc_ids_json ?? [],
            'metadata' => $symbol->metadata ?? [],
            'indexed_at' => $symbol->indexed_at?->toJSON(),
        ];
    }

    /**
     * ponytail: 1-line copy of the façade's json() helper so this extractor stays
     * dependency-free; not worth a shared Support class for one json_encode flag set.
     *
     * @param  array<mixed>  $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
