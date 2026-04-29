<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Checkin extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'state',
        'energy_level',
        'mood_level',
        'note',
        'recorded_at',
        'recorded_timezone',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'energy_level' => 'integer',
            'mood_level' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
