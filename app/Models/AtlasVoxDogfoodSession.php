<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the `atlas_vox_dogfood_sessions` table.
 *
 * Each row is Vitor's diary entry for one REAL Atlas Vox session — not a
 * head-to-head comparison (that's `AtlasVoxRivalsCase`). The dogfood
 * surface answers: "did I actually use Vox today, and how did it go?"
 *
 * No raw audio, no transcript text, no prompt body is persisted.
 * `metadata` is a free-form short JSON envelope for audit context only.
 */
final class AtlasVoxDogfoodSession extends Model
{
    protected $table = 'atlas_vox_dogfood_sessions';

    protected $fillable = [
        'dogfood_session_id',
        'vox_session_id',
        'mode',
        'outcome',
        'used_hotkey',
        'used_real_stt',
        'used_governed_execute',
        'regret_flag',
        'eclipse_used',
        'started_at',
        'duration_ms',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'used_hotkey' => 'boolean',
        'used_real_stt' => 'boolean',
        'used_governed_execute' => 'boolean',
        'regret_flag' => 'boolean',
        'eclipse_used' => 'boolean',
        'started_at' => 'immutable_datetime',
        'duration_ms' => 'integer',
        'metadata' => 'array',
    ];
}
