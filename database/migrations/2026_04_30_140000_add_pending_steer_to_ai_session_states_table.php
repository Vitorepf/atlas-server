<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_session_states ADD COLUMN IF NOT EXISTS pending_steer TEXT;');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ai_session_states DROP COLUMN IF EXISTS pending_steer;');
    }
};
