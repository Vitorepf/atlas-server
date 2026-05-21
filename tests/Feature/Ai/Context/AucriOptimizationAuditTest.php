<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasAucriOptimizationAuditService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AucriOptimizationAuditTest extends TestCase
{
    public function test_audit_envelope_is_ready_for_current_docs(): void
    {
        $payload = app(AtlasAucriOptimizationAuditService::class)->audit();

        $this->assertSame(AtlasAucriOptimizationAuditService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(10, $payload['summary']['total']);
        $this->assertSame(10, $payload['summary']['passed']);
        $this->assertSame(18, $payload['summary']['aucri_blocks']);
        $this->assertSame([], $payload['remaining_blockers']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claims']['providers_invoked']);
        $this->assertFalse($payload['claims']['rivals_run']);
        $this->assertFalse($payload['claims']['benchmark_run']);
        $this->assertSame('documentation_and_contract_audit', $payload['claims']['scope']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['audit_hash']);
    }

    public function test_audit_hash_is_deterministic(): void
    {
        $service = app(AtlasAucriOptimizationAuditService::class);

        $first = $service->audit();
        $second = $service->audit();

        $this->assertSame($first['audit_hash'], $second['audit_hash']);
    }

    public function test_command_emits_json_and_strict_passes(): void
    {
        $exitCode = Artisan::call('atlas:aucri:optimize-audit', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasAucriOptimizationAuditService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
    }

    public function test_fake_repo_without_block_docs_is_blocked(): void
    {
        $root = sys_get_temp_dir().'/atlas-aucri-audit-'.bin2hex(random_bytes(4));
        mkdir($root.'/docs/engineering-knowledge-base', 0777, true);
        file_put_contents($root.'/docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md', '| 1 | ASEF |');

        $payload = (new AtlasAucriOptimizationAuditService($root))->audit();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('all_18_block_docs_exist', $payload['remaining_blockers']);
    }
}
