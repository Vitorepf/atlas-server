<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasSddTask extends Model
{
    use HasUuids;

    protected $table = 'atlas_sdd_tasks';

    protected $fillable = [
        'plan_id', 'spec_id', 'code', 'type', 'title', 'description',
        'depends_on_json', 'allowed_files_json', 'forbidden_files_json',
        'acceptance_refs_json', 'status', 'order_index',
    ];

    protected function casts(): array
    {
        return [
            'depends_on_json' => 'array',
            'allowed_files_json' => 'array',
            'forbidden_files_json' => 'array',
            'acceptance_refs_json' => 'array',
            'order_index' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AtlasPlan::class, 'plan_id');
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(AtlasSpec::class, 'spec_id');
    }
}
