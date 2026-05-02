<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringRunOperatorAction extends Model
{
    use HasUuids;

    protected $fillable = [
        'engineering_run_id',
        'action',
        'actor',
        'status_before',
        'decision_before',
        'status_after',
        'decision_after',
        'note',
        'payload_json',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'acted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringRun::class, 'engineering_run_id');
    }
}
