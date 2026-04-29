<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyMission extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'mission_date',
        'mission_timezone',
        'title',
        'detail',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'mission_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
