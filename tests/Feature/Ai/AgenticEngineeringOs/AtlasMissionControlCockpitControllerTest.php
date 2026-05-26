<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AgenticEngineeringOs;

use Tests\TestCase;

final class AtlasMissionControlCockpitControllerTest extends TestCase
{
    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_baseline_snapshot_returns_canonical_schema_without_intent(): void
    {
        $response = $this->getJson('/atlas-code/aaeos/cockpit', $this->headers);

        $response->assertStatus(200)
            ->assertJsonPath('cockpit.schema', 'atlas.aaeos.mission_control_cockpit.v1')
            ->assertJsonPath('cockpit.phase_count', 17)
            ->assertJsonPath('baseline', true);
    }

    public function test_baseline_snapshot_uses_provided_intent_id(): void
    {
        $response = $this->getJson('/atlas-code/aaeos/cockpit?intent=my-intent-001', $this->headers);

        $response->assertStatus(200)
            ->assertJsonPath('cockpit.intent_id', 'my-intent-001');
    }

    public function test_requires_atlas_token(): void
    {
        $response = $this->getJson('/atlas-code/aaeos/cockpit');

        $response->assertStatus(401);
    }
}
