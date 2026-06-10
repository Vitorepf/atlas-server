<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * S3.F3 — one durable row per RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP cycle.
 *
 * The persisted history the HONEST meta-metric reads. Every column is a MEASURED
 * count (the relevance gate's verdict, the brain's node delta) — never a self-declared
 * success flag. See the migration docblock for the anti-Goodhart contract.
 *
 * @property int $id
 * @property string $cycle_hash
 * @property string $receipt_hash
 * @property bool $brain_anchored
 * @property int $signals_detected
 * @property int $generated
 * @property int $relevance_passed
 * @property int $relevance_rejected
 * @property int $branches_delivered
 * @property int $brain_nodes_added
 * @property int $brain_nodes_total
 */
class AtlasSelfConstructCycle extends Model
{
    protected $table = 'atlas_self_construct_cycles';

    protected $fillable = [
        'cycle_hash',
        'receipt_hash',
        'brain_anchored',
        'signals_detected',
        'generated',
        'relevance_passed',
        'relevance_rejected',
        'branches_delivered',
        'brain_nodes_added',
        'brain_nodes_total',
    ];

    protected function casts(): array
    {
        return [
            'brain_anchored' => 'boolean',
            'signals_detected' => 'integer',
            'generated' => 'integer',
            'relevance_passed' => 'integer',
            'relevance_rejected' => 'integer',
            'branches_delivered' => 'integer',
            'brain_nodes_added' => 'integer',
            'brain_nodes_total' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
