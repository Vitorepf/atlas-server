<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Code Intelligence readiness/audit must NOT throw when schema is missing. They must return a
 * structured blocked payload so autonomy gates hold/block with evidence instead of crashing the
 * construction cycle.
 */
final class CodeIntelligenceReadinessMissingTablesTest extends TestCase
{
    private array $expected = [
        'atlas_engineering_code_modules',
        'atlas_engineering_code_symbols',
        'atlas_engineering_doc_links',
    ];

    private function dropCodeIntelligenceTables(): void
    {
        foreach ($this->expected as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createCodeIntelligenceTables(): void
    {
        Schema::create('atlas_engineering_code_modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('name', 220);
            $table->string('layer', 80)->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('docs_status', 40)->default('undocumented')->index();
            $table->unsignedInteger('symbol_count')->default(0);
            $table->string('source_hash', 64);
            $table->json('metadata')->default('{}');
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('module_id')->nullable();
            $table->string('symbol_type', 60);
            $table->string('symbol_name', 300);
            $table->string('file_path', 500);
            $table->string('status', 32)->default('active');
            $table->string('source_hash', 64);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_doc_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('knowledge_item_id')->nullable();
            $table->uuid('module_id')->nullable();
            $table->uuid('symbol_id')->nullable();
            $table->string('link_type', 60);
            $table->string('status', 40)->default('current');
            $table->string('canonical_path', 500);
            $table->string('link_hash', 64);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_summary_returns_not_migrated_when_tables_missing(): void
    {
        $this->dropCodeIntelligenceTables();

        $svc = app(EngineeringCodeIntelligenceService::class);
        $summary = $svc->summary();

        $this->assertSame('not_migrated', $summary['status']);
        $this->assertFalse($summary['table_exists']);
    }

    public function test_audit_returns_structured_blocked_payload_when_tables_missing(): void
    {
        $this->dropCodeIntelligenceTables();

        $svc = app(EngineeringCodeIntelligenceService::class);
        $payload = $svc->audit(['workspace' => base_path()]);

        $this->assertSame('atlas.code_intelligence.audit.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('code_intelligence_tables_missing', $payload['blocker']);
        $this->assertNotEmpty($payload['missing_tables']);
        $this->assertNotEmpty($payload['repair_hint']);
    }

    public function test_readiness_returns_structured_blocked_payload_when_tables_missing(): void
    {
        $this->dropCodeIntelligenceTables();

        $svc = app(EngineeringCodeIntelligenceService::class);
        $payload = $svc->readiness(['workspace' => base_path()]);

        $this->assertSame('atlas.code_intelligence.readiness.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('code_intelligence_tables_missing', $payload['blocker']);
        $this->assertNotEmpty($payload['missing_tables']);
        $this->assertNotEmpty($payload['repair_hint']);
    }

    public function test_summary_returns_empty_when_tables_exist(): void
    {
        $this->dropCodeIntelligenceTables();
        $this->createCodeIntelligenceTables();

        $svc = app(EngineeringCodeIntelligenceService::class);
        $summary = $svc->summary();

        $this->assertTrue($summary['table_exists']);
        $this->assertContains($summary['status'], ['ready', 'empty']);
    }
}
