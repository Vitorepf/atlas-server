<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization extends Model
{
    use HasUuids;

    /**
     * Explícito porque o derivado do nome da classe tem 70 caracteres, e o
     * Postgres corta identificador em 63 — EM SILÊNCIO, sem aviso nem erro. O
     * resultado era este model consultando uma tabela que não existe: toda
     * query dele batia em `..._release_authorizations`, enquanto o disco tinha
     * `..._release_authori`. Nunca escreveu, nunca leu, nunca reclamou.
     */
    protected $table = 'atlas_self_construction_agent_dispatch_authorizations';

    protected $fillable = [
        'authorization_key',
        'receipt_key',
        'authorization_id',
        'packet_id',
        'provider',
        'provider_role',
        'decision',
        'status',
        'signed_by',
        'signed_at',
        'expires_at',
        'signed_receipt_template_hash',
        'signed_receipt_preflight_hash',
        'persistence_template_hash',
        'persistence_preflight_hash',
        'external_signature_validation_report_hash',
        'signed_receipt_hash',
        'payload',
        'persisted_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'payload' => 'array',
            'persisted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
