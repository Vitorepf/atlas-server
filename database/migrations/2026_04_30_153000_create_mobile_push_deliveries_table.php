<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_push_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inbox_item_id')->index();
            $table->uuid('device_id')->nullable()->index();
            $table->string('status', 24);
            $table->string('provider', 24)->default('expo');
            $table->text('provider_ticket_id')->nullable();
            $table->text('provider_receipt_id')->nullable();
            $table->json('request_payload')->default('{}');
            $table->json('response_payload')->default('{}');
            $table->text('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at')->useCurrent();
            $table->timestamps();

            $table->index(['inbox_item_id', 'status']);
            $table->index(['provider', 'status', 'attempted_at']);
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_mobile_push_deliveries_updated_at
            BEFORE UPDATE ON mobile_push_deliveries
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_deliveries');
    }
};
