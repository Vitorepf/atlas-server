<?php

namespace Tests\Feature\Ai\Product;

use App\Services\Ai\Product\AtlasAiProductCertificationService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Atlas AI · Product Certify CLI roundtrip.
 *
 * Asserts the artisan command emits the cert envelope as JSON, that
 * --strict exits 0 when status is ready, and that the command surfaces
 * the canonical schema version. No provider, no rivals.
 *
 * Uses Artisan::call() to capture output as a single string (bypassing
 * Laravel's PendingCommand line-by-line mockery, which interacts poorly
 * with pretty-printed multi-line JSON).
 */
class AtlasAiProductCertifyCommandTest extends TestCase
{
    public function test_command_prints_canonical_json_envelope(): void
    {
        $exit = Artisan::call('atlas:ai:product-certify', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString(
            AtlasAiProductCertificationService::SCHEMA_VERSION,
            $output,
        );
        $this->assertStringContainsString('hyperflow_v2_entry', $output);
        $this->assertStringContainsString('certification_hash', $output);
        $this->assertStringContainsString('"declares_benchmark": false', $output);
        $this->assertStringContainsString('"runs_rivals": false', $output);
        $this->assertStringContainsString('"scope": "product_plumbing_only"', $output);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, '--json output must be parseable JSON');
        $this->assertSame(AtlasAiProductCertificationService::SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertSame(64, strlen((string) $decoded['certification_hash']));
        $this->assertContains($decoded['status'], ['ready', 'partial', 'blocked']);
    }

    public function test_command_strict_exits_zero_when_status_is_ready(): void
    {
        $report = app(AtlasAiProductCertificationService::class)->certify();
        if ($report['status'] !== 'ready') {
            $this->markTestSkipped('cert tree is not green; --strict semantics asserted elsewhere');
        }

        $exit = Artisan::call('atlas:ai:product-certify', ['--json' => true, '--strict' => true]);
        $this->assertSame(0, $exit);
    }

    public function test_command_renders_human_view_with_each_check(): void
    {
        $exit = Artisan::call('atlas:ai:product-certify');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas AI · Product Certification', $output);
        $checks = [
            'hyperflow_v2_entry',
            'ai_interactions_preserves_rich_input',
            'universal_composer_canon_present',
            'mobile_uses_canon',
            'desktop_uses_canon',
            'forge_accepts_canon',
            'presentation_contract',
            'context_trace_audit_consumes_technical_data',
            'specialist_flows_registered',
            'routing_anti_regression_tests_present',
            'forge_strips_raw_text_to_hash_and_derives_context_refs',
            'no_attachment_path_still_works',
        ];
        foreach ($checks as $check) {
            $this->assertStringContainsString($check, $output);
        }
    }
}
