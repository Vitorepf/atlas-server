<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_host_status', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host_key', 120)->unique();
            $table->string('hostname', 180)->nullable();
            $table->string('status', 40)->default('unknown');
            $table->boolean('agent_available')->default(false);
            $table->boolean('caffeinate_available')->default(false);
            $table->boolean('pmset_available')->default(false);
            $table->boolean('docker_available')->default(false);
            $table->boolean('on_ac_power')->nullable();
            $table->unsignedSmallInteger('battery_percent')->nullable();
            $table->unsignedInteger('active_power_sessions')->default(0);
            $table->unsignedInteger('active_ai_jobs')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_power_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host_key', 120)->index();
            $table->string('kind', 60)->index();
            $table->string('status', 40)->default('active')->index();
            $table->string('reason', 240)->nullable();
            $table->string('source', 120)->nullable();
            $table->uuid('created_by_device_id')->nullable()->index();
            $table->uuid('ai_job_id')->nullable()->index();
            $table->unsignedInteger('caffeinate_pid')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason', 120)->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_maintenance_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host_key', 120)->index();
            $table->string('name', 160);
            $table->boolean('enabled')->default(true)->index();
            $table->string('timezone', 80)->default('America/Sao_Paulo');
            $table->string('wake_time', 5);
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->json('days_of_week')->default('[]');
            $table->timestamp('last_scheduled_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_power_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host_key', 120)->index();
            $table->uuid('power_session_id')->nullable()->index();
            $table->uuid('ai_job_id')->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('severity', 20)->default('info')->index();
            $table->text('message')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['atlas_host_status', 'atlas_power_sessions', 'atlas_maintenance_windows', 'atlas_power_events'] as $table) {
                DB::statement("CREATE TRIGGER trg_{$table}_updated_at BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION set_updated_at();");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_power_events');
        Schema::dropIfExists('atlas_maintenance_windows');
        Schema::dropIfExists('atlas_power_sessions');
        Schema::dropIfExists('atlas_host_status');
    }
};
