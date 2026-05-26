<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Atlas Cognition Operating System — Integer ID Mapping persistence.
 *
 * Schema canonico: atlas.context.id_remap.v1.
 * Doc canon: atlas-cognition-operating-system.md (subsistema MC/Open Brain).
 * Doc absorcao: atlas-external-memory-pattern-absorptions-v1.md (Absorcao 1).
 *
 * Esta model nunca expoe payload bruto de memorias ao provider.
 * internal_to_real / real_to_internal sao espelhos diretos do mapping
 * deterministico construido por AtlasContextIdRemapService.
 */
class AtlasContextIdRemap extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'atlas_context_id_remaps';

    protected $fillable = [
        'id',
        'context_pack_id',
        'mapping_hash',
        'schema_version',
        'internal_to_real',
        'real_to_internal',
        'ref_types',
        'scope',
        'provider_safe',
        'lookup_count',
        'reverse_lookup_count',
        'created_at',
        'updated_at',
        'expires_at',
    ];

    protected $casts = [
        'internal_to_real' => 'array',
        'real_to_internal' => 'array',
        'ref_types' => 'array',
        'provider_safe' => 'boolean',
        'lookup_count' => 'integer',
        'reverse_lookup_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expires_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
