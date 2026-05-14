<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'spec_id', 'content_hash', 'content_json',
        'target_files_json', 'forbidden_files_json',
        'hot_file_ownership_json', 'test_plan_json', 'rollback_plan_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'target_files_json' => 'array',
            'forbidden_files_json' => 'array',
            'hot_file_ownership_json' => 'array',
            'test_plan_json' => 'array',
            'rollback_plan_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(AtlasSpec::class, 'spec_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AtlasSddTask::class, 'plan_id')->orderBy('order_index');
    }
}
