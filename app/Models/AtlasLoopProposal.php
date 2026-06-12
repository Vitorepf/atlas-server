<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A certified-for-review PROPOSAL — the durable artifact the operator wakes up to.
 *
 * HARD INVARIANT: ordinary loop persistence NEVER marks a proposal merged. Merge-livre
 * v2 has one governed exception: AtlasLoopAutoMergeService may set `merged_to_main=true`
 * only inside its re-proofed guarded scope. Every other save forces `merged_to_main=false`
 * and status `certified_for_review`, so stray writers cannot fake a merge.
 */
class AtlasLoopProposal extends Model
{
    use HasUuids;

    public const STATUS_CERTIFIED = 'certified_for_review';

    protected $table = 'atlas_loop_proposals';

    protected $fillable = [
        'campaign_id', 'task_id', 'schema_version', 'status', 'objective', 'provider',
        'target_path', 'diff_text', 'proposal_hash', 'metric', 'quality', 'acceptance_hash',
        'scenarios_explored', 'scenarios_accepted', 'winning_scenario', 'merged_to_main', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'metric' => 'array',
            'quality' => 'array',
            'scenarios_explored' => 'integer',
            'scenarios_accepted' => 'integer',
            'merged_to_main' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Escopo de merge governado (decisão merge-livre v2 do operador, 12/06): SOMENTE o
     * AtlasLoopAutoMergeService — após re-prova real do contrato congelado — pode marcar
     * merged_to_main=true, dentro de um escopo try/finally. Qualquer outro save continua
     * estruturalmente incapaz de marcar merged (o guard abaixo força false).
     */
    public static bool $governedMergeInProgress = false;

    protected static function booted(): void
    {
        // Structural merge guard: outside the governed auto-merge scope, every save
        // forces merged_to_main=false — no stray writer can fake a merge. The ledger
        // only ever holds certified proposals (status forced on every save).
        static::saving(function (AtlasLoopProposal $proposal): void {
            if (! self::$governedMergeInProgress) {
                $proposal->merged_to_main = false;
            }
            $proposal->status = self::STATUS_CERTIFIED;
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AtlasLoopCampaign::class, 'campaign_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasLoopTask::class, 'task_id');
    }
}
