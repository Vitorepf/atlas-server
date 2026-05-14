<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasSddDriftReport extends Model
{
    use HasUuids;

    protected $table = 'atlas_sdd_drift_reports';

    protected $fillable = [
        'spec_id', 'operation_id', 'status',
        'drift_findings_json', 'summary_json',
        'source', 'detector_version',
    ];

    protected function casts(): array
    {
        return [
            'drift_findings_json' => 'array',
            'summary_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(AtlasSpec::class, 'spec_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(AtlasOperation::class, 'operation_id');
    }
}
