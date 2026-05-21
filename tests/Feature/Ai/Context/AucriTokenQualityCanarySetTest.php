<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasAucriTokenQualityCanarySetService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AucriTokenQualityCanarySetTest extends TestCase
{
    public function test_canary_set_has_core_domains_and_quality_floor(): void
    {
        $payload = app(AtlasAucriTokenQualityCanarySetService::class)->report();

        $this->assertSame(AtlasAucriTokenQualityCanarySetService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(5, $payload['summary']['total_cases']);
        $this->assertSame(1.0, $payload['summary']['quality_floor']['must_keep_coverage']);
        $this->assertFalse($payload['summary']['quality_floor']['evidence_coverage_regression_allowed']);
        $this->assertFalse($payload['claims']['providers_invoked']);
        $this->assertFalse($payload['claims']['benchmark_run']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['canary_set_hash']);

        $domains = $payload['summary']['domains'];
        foreach (['programming', 'forge', 'research', 'finance', 'strategy'] as $domain) {
            $this->assertContains($domain, $domains);
        }
    }

    public function test_each_canary_case_blocks_quality_regression(): void
    {
        $payload = app(AtlasAucriTokenQualityCanarySetService::class)->report();

        foreach ($payload['cases'] as $case) {
            $this->assertNotEmpty($case['must_keep_kinds']);
            $this->assertContains('must_keep_coverage', $case['required_gates']);
            $this->assertContains('evidence_coverage', $case['required_gates']);
            $this->assertContains('sufficiency', $case['required_gates']);
            $this->assertContains('rollback_ref', $case['required_gates']);
            $this->assertNotEmpty($case['token_quality_acceptance']);
        }
    }

    public function test_command_emits_json(): void
    {
        $exitCode = Artisan::call('atlas:aucri:token-quality-canaries', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasAucriTokenQualityCanarySetService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(5, $payload['summary']['total_cases']);
    }
}
