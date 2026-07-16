<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADN F3 — federação da KB por workspace (doc canônico:
 * docs/engineering-knowledge-base/atlas-documentation-network.md).
 *
 * Aditiva e zero-breaking: todo o corpus existente recebe o default
 * 'atlas-server' (era o único workspace ingerido até aqui). O unique de slug
 * vira composto (workspace_id, slug) para que o mesmo slug exista em N repos
 * sem colisão — a rede é federada, nunca concatenada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('atlas_engineering_knowledge_items', 'workspace_id')) {
            Schema::table('atlas_engineering_knowledge_items', function (Blueprint $table): void {
                $table->string('workspace_id', 120)->default('atlas-server')->index();
            });
        }

        Schema::table('atlas_engineering_knowledge_items', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->unique(['workspace_id', 'slug'], 'uq_atlas_eng_knowledge_ws_slug');
        });
    }

    public function down(): void
    {
        Schema::table('atlas_engineering_knowledge_items', function (Blueprint $table): void {
            $table->dropUnique('uq_atlas_eng_knowledge_ws_slug');
            $table->unique(['slug']);
            $table->dropColumn('workspace_id');
        });
    }
};
