<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiCodebaseWorldModelEdge extends Model
{
    use HasUuids;

    protected $fillable = ['world_model_id', 'from_node_id', 'to_node_id', 'edge_type', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
