<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Atlas Cognition Operating System — AKIF source packet model.
 *
 * Schema canonico: atlas.knowledge.source_packet.v1.
 * Doc canon: atlas-cognition-operating-system.md (AUCRI bloco #14).
 */
class AtlasKnowledgeSourcePacket extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'atlas_knowledge_source_packets';

    protected $fillable = [
        'id',
        'schema_version',
        'source_type',
        'origin_uri',
        'source_hash',
        'version_hash',
        'normalized_text_ref',
        'media_refs',
        'language',
        'confidence',
        'lineage',
        'privacy_status',
        'provider_safe',
        'ingestion_status',
        'receipt_hash',
        'metadata',
        'blocking_reason',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'media_refs' => 'array',
        'lineage' => 'array',
        'metadata' => 'array',
        'confidence' => 'float',
        'provider_safe' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
