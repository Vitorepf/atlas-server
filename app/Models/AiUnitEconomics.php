<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiUnitEconomics extends Model
{
    use HasUuids;

    protected $table = 'ai_unit_economics';

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_blueprint_id',
        'opportunity_id',
        'strategy_run_id',
        'unit_id',
        'cac',
        'ltv',
        'arpu',
        'gross_margin',
        'payback_months',
        'churn_monthly',
        'currency',
        'assumptions',
        'breakdown',
        'status',
        'unit_economics_hash',
    ];

    protected function casts(): array
    {
        return [
            'cac' => 'float',
            'ltv' => 'float',
            'arpu' => 'float',
            'gross_margin' => 'float',
            'payback_months' => 'float',
            'churn_monthly' => 'float',
            'assumptions' => 'array',
            'breakdown' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
