<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AtlasEngineeringBenchmarkSuite extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'status',
        'default_runner_options_json',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'default_runner_options_json' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(AtlasEngineeringBenchmarkCase::class, 'suite_id');
    }

    public function benchmarkRuns(): HasMany
    {
        return $this->hasMany(AtlasEngineeringBenchmarkRun::class, 'suite_id')
            ->latest('created_at');
    }

    public function latestBenchmarkRun(): HasOne
    {
        return $this->hasOne(AtlasEngineeringBenchmarkRun::class, 'suite_id')
            ->latestOfMany('created_at');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AtlasEngineeringBenchmarkResult::class, 'suite_id');
    }
}
