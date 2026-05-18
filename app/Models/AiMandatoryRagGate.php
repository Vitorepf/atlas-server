<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMandatoryRagGate extends Model
{
    use HasUuids;

    protected $fillable = ['goal_record_id', 'cycle_record_id', 'schema_version', 'gate_id', 'status', 'retrieval_plan', 'included_sources', 'used_sources', 'noise_sources', 'missed_required_sources', 'context_sufficiency', 'evidence_refs', 'context_pack_hash', 'receipt', 'receipt_hash'];

    protected function casts(): array
    {
        return [
            'retrieval_plan' => 'array',
            'included_sources' => 'integer',
            'used_sources' => 'integer',
            'noise_sources' => 'integer',
            'missed_required_sources' => 'array',
            'context_sufficiency' => 'integer',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
