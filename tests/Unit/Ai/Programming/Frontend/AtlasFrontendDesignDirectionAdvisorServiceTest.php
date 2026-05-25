<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDirectionAdvisorService;
use Tests\TestCase;

class AtlasFrontendDesignDirectionAdvisorServiceTest extends TestCase
{
    public function test_advisor_returns_three_governed_directions_for_ambiguous_frontend_brief(): void
    {
        $payload = app(AtlasFrontendDesignDirectionAdvisorService::class)->advise([
            'task' => 'Melhorar visual do SaaS dashboard para ficar moderno e premium',
            'company_profile_hash' => str_repeat('a', 64),
        ]);

        $this->assertSame(AtlasFrontendDesignDirectionAdvisorService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'signals.ambiguous_brief'));
        $this->assertCount(3, $payload['directions']);
        $this->assertSame(['operational_clarity', 'brand_product_depth', 'accessibility_first'], collect($payload['directions'])->pluck('id')->all());
        $this->assertContains('frontend_visual_quality_gate', data_get($payload, 'directions.0.required_gates'));
        $this->assertTrue((bool) data_get($payload, 'selection_contract.selected_direction_must_feed_visual_quality_gate'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['advisor_hash']);
    }

    public function test_advisor_blocks_missing_task(): void
    {
        $payload = app(AtlasFrontendDesignDirectionAdvisorService::class)->advise([]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('task_missing', $payload['blockers']);
        $this->assertSame([], $payload['directions']);
    }

    public function test_direction_ids_are_stable(): void
    {
        $ids = app(AtlasFrontendDesignDirectionAdvisorService::class)->directionIds();

        $this->assertSame(['operational_clarity', 'brand_product_depth', 'accessibility_first'], $ids);
    }
}
