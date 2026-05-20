<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasResourceAllocationPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'resources',
        'allocation',
        'constraints',
        'evidence_refs',
        'allocation_hash',
    ];

    protected function casts(): array
    {
        return [
            'resources' => 'array',
            'allocation' => 'array',
            'constraints' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
