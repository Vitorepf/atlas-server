<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * YouTube canonical capability · adds the three-dimension status model
 * (ingestion / transcript / translation) + source/target language to
 * `ai_youtube_ingestions`. Mirrors `@atlas/rich-input-canon` enums.
 *
 * Legacy `status` column stays as the historical audit; it now MIRRORS
 * `ingestion_status` for new writes. Read paths (PromptBuilder, payload
 * projection) should prefer the canonical columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_youtube_ingestions')) {
            return;
        }

        Schema::table('ai_youtube_ingestions', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_youtube_ingestions', 'ingestion_status')) {
                $table->string('ingestion_status', 32)->nullable()->index();
            }
            if (! Schema::hasColumn('ai_youtube_ingestions', 'transcript_status')) {
                $table->string('transcript_status', 32)->nullable()->index();
            }
            if (! Schema::hasColumn('ai_youtube_ingestions', 'translation_status')) {
                $table->string('translation_status', 32)->nullable()->index();
            }
            if (! Schema::hasColumn('ai_youtube_ingestions', 'source_language')) {
                $table->string('source_language', 24)->nullable();
            }
            if (! Schema::hasColumn('ai_youtube_ingestions', 'target_language')) {
                $table->string('target_language', 24)->nullable()->default('pt-BR');
            }
            if (! Schema::hasColumn('ai_youtube_ingestions', 'translation_required')) {
                $table->boolean('translation_required')->nullable();
            }
        });

        // Backfill canonical columns from existing status + caption_language.
        // We do this in PHP rather than SQL to share the canonical mapping
        // with the YoutubeIngestionStatus/YoutubeTranscriptStatus enums.
        DB::table('ai_youtube_ingestions')
            ->whereNull('ingestion_status')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $storedStatus = strtolower(trim((string) ($row->status ?? '')));
                    $ingestion = match ($storedStatus) {
                        'ready' => 'ready',
                        'queued' => 'queued',
                        'processing' => 'processing',
                        'caption_unavailable', 'transcript_empty', 'skipped_duration', 'disabled' => 'ready',
                        default => 'failed',
                    };
                    $transcript = match ($storedStatus) {
                        'ready' => 'original_ready',
                        'queued', 'processing' => 'pending',
                        'caption_unavailable', 'transcript_empty', 'skipped_duration', 'disabled' => 'unavailable',
                        default => 'failed',
                    };

                    $captionLanguage = is_string($row->caption_language ?? null) ? strtolower(trim((string) $row->caption_language)) : '';
                    $source = self::inferSourceLanguage($captionLanguage);
                    $target = 'pt-BR';
                    $translationRequired = $source !== null
                        && ! str_starts_with(strtolower($source), 'pt');
                    $translationStatus = match (true) {
                        $transcript === 'failed' => 'failed',
                        $transcript === 'unavailable' => 'not_required',
                        $transcript === 'pending' => 'pending',
                        ! $translationRequired => 'not_required',
                        default => 'required',
                    };

                    DB::table('ai_youtube_ingestions')
                        ->where('id', $row->id)
                        ->update([
                            'ingestion_status' => $ingestion,
                            'transcript_status' => $transcript,
                            'translation_status' => $translationStatus,
                            'source_language' => $source,
                            'target_language' => $target,
                            'translation_required' => $translationRequired,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_youtube_ingestions')) {
            return;
        }

        Schema::table('ai_youtube_ingestions', function (Blueprint $table): void {
            foreach ([
                'ingestion_status',
                'transcript_status',
                'translation_status',
                'source_language',
                'target_language',
                'translation_required',
            ] as $column) {
                if (Schema::hasColumn('ai_youtube_ingestions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Local copy of canon `inferYouTubeSourceLanguage` so the migration is
     * self-contained and does not depend on PHP code that may not exist
     * when this migration runs in the future.
     */
    private static function inferSourceLanguage(string $captionLanguage): ?string
    {
        $trimmed = strtolower(trim(str_replace('_', '-', $captionLanguage)));
        if ($trimmed === '' || $trimmed === 'und' || $trimmed === 'unknown') {
            return null;
        }
        if ($trimmed === 'pt-br' || str_starts_with($trimmed, 'pt-br-')) {
            return 'pt-BR';
        }
        if ($trimmed === 'pt-pt' || str_starts_with($trimmed, 'pt-pt-')) {
            return 'pt-PT';
        }
        if ($trimmed === 'pt' || str_starts_with($trimmed, 'pt-')) {
            return 'pt';
        }
        if ($trimmed === 'zh-hans' || str_starts_with($trimmed, 'zh-hans-')) {
            return 'zh-Hans';
        }
        if ($trimmed === 'zh-hant' || str_starts_with($trimmed, 'zh-hant-')) {
            return 'zh-Hant';
        }
        $primary = explode('-', $trimmed)[0] ?? '';

        return $primary !== '' && preg_match('/^[a-z]{2,3}$/', $primary) === 1 ? $primary : null;
    }
};
