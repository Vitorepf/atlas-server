<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiGrcMapping extends Model
{
    use HasUuids;

    protected $table = 'ai_grc_mappings';

    protected $fillable = [
        'schema_version',
        'uuid',
        'engagement_id',
        'mapping_id',
        'framework',
        'control_id',
        'control_title',
        'evidence_refs',
        'gaps',
        'compliance_status',
        'remediation_refs',
        'mapping_hash',
    ];

    protected function casts(): array
    {
        return [
            'evidence_refs' => 'array',
            'gaps' => 'array',
            'remediation_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
