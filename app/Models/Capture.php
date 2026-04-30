<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Capture extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'kind',
        'domain',
        'content_text',
        'content_file_path',
        'content_duration_ms',
        'content_size_bytes',
        'content_sha256',
        'content_mime_type',
        'transcription_status',
        'transcription_engine',
        'transcription_error',
        'captured_at',
        'captured_timezone',
        'captured_lat',
        'captured_lng',
        'pre_capture_digital_context',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'metadata' => 'array',
            'pre_capture_digital_context' => 'array',
            'captured_lat' => 'float',
            'captured_lng' => 'float',
            'content_duration_ms' => 'integer',
            'content_size_bytes' => 'integer',
        ];
    }

    public function transcriptionJobs(): HasMany
    {
        return $this->hasMany(TranscriptionJob::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(CaptureLink::class);
    }

    public function projectPlanProposals(): HasMany
    {
        return $this->hasMany(AtlasProjectPlanProposal::class, 'source_capture_id');
    }
}
