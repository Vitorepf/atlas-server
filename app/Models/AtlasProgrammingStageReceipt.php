<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasProgrammingStageReceipt extends Model
{
    use HasUuids;

    protected $fillable = [
        'receipt_id',
        'plan_id',
        'parent_plan_id',
        'stage',
        'attempt',
        'status',
        'input_hash',
        'output_hash',
        'evidence_refs_json',
        'payload_json',
        'validation_json',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'evidence_refs_json' => 'array',
            'payload_json' => 'array',
            'validation_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
