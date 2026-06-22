<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMarketingVslAsset extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'content_hash',
        'label',
        'source_type',
        'source_ref',
        'source_filename',
        'campaign_ref',
        'niche',
        'sub_niche',
        'language',
        'duration_seconds',
        'status',
        'reason',
        'transcript',
        'transcript_chars',
        'transcript_segments',
        'essential_summary',
        'big_idea',
        'core_promise',
        'problem_mechanism',
        'solution_mechanism',
        'awareness_level',
        'sophistication_level',
        'pitch_starts_at_seconds',
        'avatar',
        'offer',
        'persuasion',
        'claims',
        'levers',
        'funnel_kit',
        'mechanism_name',
        'target_geo',
        'value_equation',
        'keywords',
        'advertorial_brief',
        'ad_assets',
        'cta',
        'objection_rebuttals',
        'power_phrases',
        'economics',
        'beat_timestamps',
        'top_terms',
        'structure_status',
        'extraction_model',
        'structured_at',
        'transcription_ms',
        'transcription_engine',
        'diagnostics',
        'last_ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'transcript_chars' => 'integer',
            'pitch_starts_at_seconds' => 'integer',
            'transcription_ms' => 'integer',
            'transcript_segments' => 'array',
            'avatar' => 'array',
            'offer' => 'array',
            'persuasion' => 'array',
            'claims' => 'array',
            'levers' => 'array',
            'funnel_kit' => 'array',
            'value_equation' => 'array',
            'keywords' => 'array',
            'advertorial_brief' => 'array',
            'ad_assets' => 'array',
            'cta' => 'array',
            'objection_rebuttals' => 'array',
            'power_phrases' => 'array',
            'economics' => 'array',
            'beat_timestamps' => 'array',
            'top_terms' => 'array',
            'diagnostics' => 'array',
            'structured_at' => 'immutable_datetime',
            'last_ingested_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
