<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiCodebaseWorldModelNode extends Model
{
    use HasUuids;

    protected $fillable = ['world_model_id', 'node_id', 'node_type', 'path', 'flow_id', 'capabilities', 'risks', 'metadata'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'risks' => 'array',
            'metadata' => 'array',
        ];
    }
}
