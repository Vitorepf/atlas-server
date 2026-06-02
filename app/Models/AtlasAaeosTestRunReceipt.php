<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * GREEN-RUN RECEIPT for a capability's named test (B3 / criterion C2).
 *
 * A row proves the capability's test ACTUALLY RAN and whether it passed — the
 * evidence the truth service requires before a capability may reach `verified`.
 * Written only by `atlas:aaeos:verify-tests`; read by AtlasAaeosImplementationTruthService.
 *
 * @see database/migrations/2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php
 */
class AtlasAaeosTestRunReceipt extends Model
{
    use HasUuids;

    protected $table = 'atlas_aaeos_test_run_receipts';

    protected $fillable = [
        'capability_id',
        'test_ref',
        'filter',
        'passed',
        'tests_run',
        'exit_code',
        'commit_stamp',
        // B3 freshness (criterion C2): content hashes binding the receipt to the
        // code+test it proved, so verified decays when either file's content changes.
        'test_file_hash',
        'impl_files_hash',
        'output_tail',
        'runner',
        'metadata',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'tests_run' => 'integer',
            'exit_code' => 'integer',
            'metadata' => 'array',
            'ran_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Only receipts that record a real GREEN run (process exited 0 AND >=1 test ran).
     */
    public function scopeGreen(Builder $query): Builder
    {
        return $query->where('passed', true)->where('tests_run', '>=', 1);
    }
}
