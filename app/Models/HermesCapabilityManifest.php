<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HermesCapabilityManifest extends Model
{
    use HasUuids;

    protected $table = 'hermes_capability_manifests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'manifest_hash',
        'hermes_version',
        'manifest_version',
        'probe_status',
        'manifest_json',
        'diff_json',
        'receipt_hash',
        'probed_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest_json' => 'array',
            'diff_json' => 'array',
            'manifest_version' => 'int',
            'probed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
