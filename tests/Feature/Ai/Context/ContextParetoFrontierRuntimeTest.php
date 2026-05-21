<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasContextParetoFrontierRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ContextParetoFrontierRuntimeTest extends TestCase
{
    public function test_report_is_shadow_only_and_selects_frontier_candidates(): void
    {
        $payload = app(AtlasContextParetoFrontierRuntimeService::class)->report();

        $this->assertSame(AtlasContextParetoFrontierRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(15, $payload['summary']['total_candidates']);
        $this->assertSame(5, $payload['summary']['selected_candidates']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['frontier_candidates']);
        $this->assertTrue($payload['claims']['shadow_only']);
        $this->assertFalse($payload['claims']['providers_invoked']);
        $this->assertFalse($payload['claims']['promotes_runtime_change']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['frontier_hash']);
    }

    public function test_candidates_with_must_keep_below_one_are_blocked(): void
    {
        $payload = app(AtlasContextParetoFrontierRuntimeService::class)->report();

        $blocked = array_values(array_filter(
            $payload['candidates'],
            static fn (array $candidate): bool => $candidate['promotion_status'] === 'blocked',
        ));

        $this->assertNotEmpty($blocked);

        foreach ($blocked as $candidate) {
            $this->assertLessThan(1.0, $candidate['must_keep_coverage']);
            $this->assertContains('must_keep_coverage_below_1_0', $candidate['blockers']);
        }
    }

    public function test_pareto_frontier_excludes_dominated_and_blocked_candidates(): void
    {
        $service = app(AtlasContextParetoFrontierRuntimeService::class);
        $payload = $service->report();

        foreach ($payload['frontier'] as $candidate) {
            $this->assertFalse($candidate['pareto_dominated']);
            $this->assertNotSame('blocked', $candidate['promotion_status']);
        }

        $custom = $service->paretoFrontier([
            [
                'candidate_id' => 'a',
                'case_id' => 'case',
                'quality_score' => 0.90,
                'input_tokens' => 8000,
                'cost_units' => 1.0,
                'latency_ms' => 900,
                'must_keep_coverage' => 1.0,
                'promotion_status' => 'shadow_candidate',
                'pareto_dominated' => false,
            ],
            [
                'candidate_id' => 'b',
                'case_id' => 'case',
                'quality_score' => 0.91,
                'input_tokens' => 7000,
                'cost_units' => 0.8,
                'latency_ms' => 800,
                'must_keep_coverage' => 1.0,
                'promotion_status' => 'shadow_candidate',
                'pareto_dominated' => false,
            ],
        ]);

        $this->assertCount(1, $custom);
        $this->assertSame('b', $custom[0]['candidate_id']);
    }

    public function test_command_emits_json(): void
    {
        $exitCode = Artisan::call('atlas:context:pareto-frontier', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasContextParetoFrontierRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(5, $payload['summary']['selected_candidates']);
    }
}
