<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ACDE O2 — one operator accept/reject decision on an O1 origination proposal, keyed by a deterministic shape
 * token. Pure telemetry; the origination producer reads its Wilson accept-rate to back off rejected shapes.
 */
class AtlasLoopOriginationOutcome extends Model
{
    use HasUuids;

    protected $fillable = [
        'shape_token',
        'accepted',
        'proposal_id',
        'target_path',
    ];

    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
        ];
    }
}
