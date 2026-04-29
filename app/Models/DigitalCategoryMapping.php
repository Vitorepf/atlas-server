<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DigitalCategoryMapping extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_identifier',
        'source_name',
        'source_kind',
        'category_class',
        'category_label',
        'intentionality',
        'classified_by',
        'confidence',
        'valid_from',
        'valid_until',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'category_class' => 'integer',
            'confidence' => 'float',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
