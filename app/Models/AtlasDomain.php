<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtlasDomain extends Model
{
    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'slug',
        'label',
        'description',
        'color_light',
        'color_dark',
        'default_sensitivity',
        'external_ai_policy',
        'active',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
