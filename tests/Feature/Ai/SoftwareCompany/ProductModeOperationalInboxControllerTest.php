<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompany;

use Tests\TestCase;

/**
 * Feature tests for stewardship operational inbox HTTP surface.
 */
final class ProductModeOperationalInboxControllerTest extends TestCase
{
    private const PATH = '/ai/software-company-stewardship/operational-inbox/atlas_software_company';

    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_requires_atlas_token(): void
    {
        $this->getJson(self::PATH)->assertStatus(401);
    }

    public function test_returns_operational_inbox_smoke_json(): void
    {
        $started = microtime(true);

        $response = $this->getJson(self::PATH.'?area=agentic_engineering_os', $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('schema_version', 'atlas.software_company.product_mode_operational_inbox.v1')
            ->assertJsonPath('portfolio_id', 'atlas_software_company')
            ->assertJsonPath('read_only', true)
            ->assertJsonStructure([
                'status',
                'items',
                'item_count',
                'counters' => [
                    'approvals',
                    'recommendations',
                    'insights',
                    'alerts',
                    'blocked',
                    'executed',
                    'deferred',
                ],
                'sources',
                'latest_receipts',
                'blockers',
                'next_actions',
                'timing',
                'claim_policy',
                'projection_hash',
            ]);

        $elapsedMs = (microtime(true) - $started) * 1000;
        $this->assertLessThan(2000, $elapsedMs, 'Operational inbox endpoint must respond within 2s');

        $status = (string) $response->json('status');
        $this->assertContains($status, ['ready', 'empty', 'degraded']);

        if ($status === 'empty') {
            $response->assertJsonPath('empty_state.honest', true)
                ->assertJsonPath('empty_state.loading', false);
        }
    }

    public function test_etag_supports_conditional_get(): void
    {
        $response = $this->getJson(self::PATH, $this->headers)->assertStatus(200);
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->getJson(self::PATH, array_merge($this->headers, ['If-None-Match' => $etag]))
            ->assertStatus(304);
    }
}
