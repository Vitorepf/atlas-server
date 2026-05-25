<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use Tests\TestCase;

class AtlasFrontendCompetitiveRubricServiceTest extends TestCase
{
    public function test_rubric_defines_weighted_frontend_quality_dimensions(): void
    {
        $payload = app(AtlasFrontendCompetitiveRubricService::class)->rubric();

        $this->assertSame('atlas.frontend.competitive_rubric.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(100, $payload['score_max']);
        $this->assertCount(10, $payload['dimensions']);
        $this->assertContains('product_intent_fit', collect($payload['dimensions'])->pluck('id')->all());
        $this->assertContains('evidence_completeness', collect($payload['dimensions'])->pluck('id')->all());
        $this->assertTrue((bool) data_get($payload, 'comparison_policy.same_rubric_required_across_systems'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['rubric_hash']);
    }

    public function test_rubric_validates_score_breakdown(): void
    {
        $rubric = app(AtlasFrontendCompetitiveRubricService::class);
        $breakdown = collect($rubric->rubric()['dimensions'])
            ->mapWithKeys(fn (array $dimension): array => [(string) $dimension['id'] => (int) $dimension['weight']])
            ->all();

        $this->assertSame([], $rubric->validateBreakdown($breakdown, 100, 100));

        unset($breakdown['evidence_completeness']);

        $issues = $rubric->validateBreakdown($breakdown, 90, 100);

        $this->assertContains('missing_score_breakdown_evidence_completeness', $issues);
    }
}
