<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiProviderCostRate extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'provider',
        'model',
        'input_microusd_per_1k',
        'output_microusd_per_1k',
        'currency',
        'effective_from',
        'effective_until',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input_microusd_per_1k' => 'integer',
            'output_microusd_per_1k' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
