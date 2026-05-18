<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEngineeringCompanyQaRun extends Model
{
    use HasUuids;

    protected $fillable = ['engagement_record_id', 'schema_version', 'qa_run_id', 'status', 'gates', 'evidence_refs', 'receipt', 'qa_hash'];

    protected function casts(): array
    {
        return [
            'gates' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
