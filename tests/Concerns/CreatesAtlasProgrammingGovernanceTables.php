<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesAtlasProgrammingGovernanceTables
{
    protected function createAtlasProgrammingGovernanceTables(): void
    {
        $this->dropAtlasProgrammingGovernanceTables();

        Schema::create('atlas_programming_work_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->text('intent_text');
            $table->string('intent_type', 40)->index();
            $table->string('scope_mode', 24)->index();
            $table->string('risk_level', 16)->default('medium')->index();
            $table->string('owner', 80)->nullable()->index();
            $table->string('workspace', 255)->nullable();
            $table->string('status', 32)->index();
            $table->string('current_stage', 32)->index();
            $table->string('spec_hash', 64)->nullable()->index();
            $table->string('plan_hash', 64)->nullable()->index();
            $table->json('placement_json')->default('{}');
            $table->json('code_intelligence_json')->default('{}');
            $table->json('spec_json')->default('{}');
            $table->json('plan_json')->default('{}');
            $table->json('tasks_json')->default('[]');
            $table->json('evidence_refs_json')->default('[]');
            $table->json('gaps_json')->default('[]');
            $table->json('metadata_json')->default('{}');
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('atlas_programming_gate_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('work_item_id')->index();
            $table->string('gate_name', 64)->index();
            $table->string('status', 24)->index();
            $table->boolean('blocking')->default(true)->index();
            $table->string('input_hash', 64)->nullable();
            $table->string('output_hash', 64)->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('waiver_reason', 255)->nullable();
            $table->string('decided_by', 80)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->json('payload_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_programming_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('work_item_id')->index();
            $table->string('result', 32)->index();
            $table->text('summary')->nullable();
            $table->text('risk_notes')->nullable();
            $table->string('decided_by', 80)->nullable();
            $table->json('payload_json')->default('{}');
            $table->timestamps();
        });

        // Mirror the production schema exactly so tests fail when the ledger
        // contract drifts. See database/migrations/2026_05_01_010000_create_atlas_engineering_evidence_table.php
        Schema::create('atlas_engineering_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('project_step_id')->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('evidence_type', 80);
            $table->string('target_id', 120)->nullable();
            $table->string('status', 32);
            $table->decimal('confidence', 5, 3)->nullable();
            $table->text('summary');
            $table->string('command', 500)->nullable();
            $table->text('artifact_url')->nullable();
            $table->text('output_excerpt')->nullable();
            $table->json('files')->default('[]');
            $table->json('metadata')->default('{}');
            $table->string('source', 160)->default('tasks.engineering.evidence');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });
    }

    protected function dropAtlasProgrammingGovernanceTables(): void
    {
        Schema::dropIfExists('atlas_engineering_evidence');
        Schema::dropIfExists('atlas_programming_reviews');
        Schema::dropIfExists('atlas_programming_gate_runs');
        Schema::dropIfExists('atlas_programming_work_items');
    }

    /**
     * Create a real on-disk workspace seeded with the given relative files so
     * the Evidence Ledger can hash them. Returns the absolute workspace path.
     *
     * @param  list<string>  $relativeFiles
     */
    protected function makeProgrammingWorkspace(array $relativeFiles = []): string
    {
        $root = sys_get_temp_dir().'/atlas_pg_ws_'.bin2hex(random_bytes(6));
        mkdir($root, 0755, true);
        foreach ($relativeFiles as $rel) {
            $absolute = $root.'/'.ltrim($rel, '/');
            $dir = dirname($absolute);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($absolute, "<?php // {$rel} placeholder for evidence test\n");
        }
        $this->_programmingWorkspaces[] = $root;

        return $root;
    }

    /** @var list<string> */
    private array $_programmingWorkspaces = [];

    protected function cleanupProgrammingWorkspaces(): void
    {
        foreach ($this->_programmingWorkspaces as $root) {
            $this->rmrf($root);
        }
        $this->_programmingWorkspaces = [];
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$item);
        }
        @rmdir($path);
    }
}
