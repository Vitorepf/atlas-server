<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiToolReceipt extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'tool_invocation_id',
        'receipt_type',
        'status',
        'evidence_refs',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function invocation(): BelongsTo
    {
        return $this->belongsTo(AiToolInvocation::class, 'tool_invocation_id');
    }
}
