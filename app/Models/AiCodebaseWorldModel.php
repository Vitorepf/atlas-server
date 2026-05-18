<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiCodebaseWorldModel extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'schema_version', 'model_id', 'scope', 'status', 'capabilities', 'risks', 'receipt', 'model_hash'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'risks' => 'array',
            'receipt' => 'array',
        ];
    }
}
