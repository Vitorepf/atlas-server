<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_engineering_benchmark_cases', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_engineering_benchmark_cases', 'corpus_tier')) {
                $table->string('corpus_tier', 40)->nullable()->index()->after('min_score');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_cases', 'domain_slug')) {
                $table->string('domain_slug', 80)->nullable()->index()->after('corpus_tier');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_cases', 'risk_profile')) {
                $table->string('risk_profile', 40)->nullable()->index()->after('domain_slug');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_cases', 'curation_status')) {
                $table->string('curation_status', 40)->default('candidate')->index()->after('risk_profile');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_cases', 'curation_score')) {
                $table->unsignedSmallInteger('curation_score')->nullable()->after('curation_status');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_cases', 'corpus_fingerprint')) {
                $table->string('corpus_fingerprint', 64)->nullable()->index()->after('curation_score');
            }

            if (! Schema::hasColumn('atlas_engineering_benchmark_cases', 'curated_at')) {
                $table->timestamp('curated_at')->nullable()->after('corpus_fingerprint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('atlas_engineering_benchmark_cases', function (Blueprint $table): void {
            foreach ([
                'curated_at',
                'corpus_fingerprint',
                'curation_score',
                'curation_status',
                'risk_profile',
                'domain_slug',
                'corpus_tier',
            ] as $column) {
                if (Schema::hasColumn('atlas_engineering_benchmark_cases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
