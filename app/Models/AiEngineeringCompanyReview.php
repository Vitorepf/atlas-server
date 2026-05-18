<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEngineeringCompanyReview extends Model
{
    use HasUuids;

    protected $fillable = ['engagement_record_id', 'schema_version', 'review_id', 'status', 'findings', 'evidence_refs', 'receipt', 'review_hash'];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'evidence_refs' => 'array',
            'receipt' => 'array',
        ];
    }
}
