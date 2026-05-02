<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtlasAiRuntimeSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value_json',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
