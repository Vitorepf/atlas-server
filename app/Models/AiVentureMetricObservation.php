<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiVentureMetricObservation extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_metric_observations';

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_id',
        'metric_key',
        'value',
        'unit',
        'currency',
        'source',
        'note',
        'observed_at',
        'observation_hash',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'observed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
