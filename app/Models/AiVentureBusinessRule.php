<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiVentureBusinessRule extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_business_rules';

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_id',
        'rule_id',
        'category',
        'statement',
        'rationale',
        'version',
        'status',
        'superseded_by',
        'rule_hash',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
