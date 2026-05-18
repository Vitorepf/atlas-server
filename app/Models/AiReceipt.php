<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiReceipt extends Model
{
    use HasUuids;

    protected $table = 'ai_receipts';

    protected $fillable = [
        'schema_version',
        'uuid',
        'receipt_type',
        'target_type',
        'target_id',
        'mission_id',
        'work_order_id',
        'actor_type',
        'action',
        'input_hash',
        'output_hash',
        'evidence_refs',
        'status',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
