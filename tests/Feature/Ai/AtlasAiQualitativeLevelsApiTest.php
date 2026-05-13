<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiQualitativeLevelsApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('atlas_ledger_events');
    }

    public function test_qualitative_levels_api_returns_read_model(): void
    {
        $this->getJson('/ai/qualitative-levels?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('hours', 24)
            ->assertJsonPath('qualitative_levels.schema_version', 'atlas.qualitative_levels.v1')
            ->assertJsonPath('qualitative_levels.current_level', 'P1')
            ->assertJsonPath('qualitative_levels.next_level', 'P2')
            ->assertJsonPath('qualitative_levels.rules.read_model_only', true)
            ->assertJsonPath('qualitative_levels.rules.human_agency_required', true)
            ->assertJsonPath('qualitative_levels.advanced_readiness.p6_presence_eclipse.promotion_allowed', false)
            ->assertJsonPath('qualitative_levels.advanced_readiness.p7_longitudinal_memory.promotion_allowed', false)
            ->assertJsonPath('qualitative_levels.evidence.evidence_ledger.available', false);
    }

    public function test_qualitative_levels_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/qualitative-levels')
            ->assertUnauthorized();
    }
}
