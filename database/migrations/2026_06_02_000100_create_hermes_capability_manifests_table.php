<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hermes_capability_manifests')) {
            return;
        }

        Schema::create('hermes_capability_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('manifest_hash', 64)->unique();
            $table->string('hermes_version')->nullable();
            $table->integer('manifest_version')->index();
            $table->string('probe_status', 20)->index();
            $table->json('manifest_json')->default('{}');
            $table->json('diff_json')->default('{}');
            $table->string('receipt_hash', 64)->nullable();
            $table->timestamp('probed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['manifest_version'], 'idx_hermes_capability_manifests_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hermes_capability_manifests');
    }
};
