<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGovernedFrontierFetcher;
use Tests\TestCase;

final class AtlasBrainGovernedFrontierFetcherTest extends TestCase
{
    public function test_live_fetch_is_gated_off_by_default_and_does_not_call_transport(): void
    {
        $transportCalls = 0;

        $report = (new AtlasBrainGovernedFrontierFetcher)->run(
            limit: 5,
            dryRun: false,
            enabled: false,
            transport: function () use (&$transportCalls): array {
                $transportCalls++;

                return ['items' => [['title' => 'should not happen']]];
            },
            capturedAt: '2026-07-12T00:00:00Z',
        );

        self::assertSame('gated_off', $report['status']);
        self::assertFalse($report['network_attempted']);
        self::assertSame(0, $transportCalls);
        self::assertSame([], $report['candidates']);
    }

    public function test_dry_run_builds_discover_read_ground_payloads_without_transport(): void
    {
        $report = (new AtlasBrainGovernedFrontierFetcher)->run(
            limit: 5,
            dryRun: true,
            enabled: true,
            transport: static function (): array {
                self::fail('dry-run must not invoke transport');
            },
            capturedAt: '2026-07-12T00:00:00Z',
        );

        self::assertSame('dry_run', $report['status']);
        self::assertFalse($report['network_attempted']);
        self::assertSame(['discover', 'read', 'ground'], array_column($report['outbound_payloads'], 'stage'));
        self::assertTrue($report['egress_safety']['provider_safe']);
        foreach ($report['outbound_payloads'] as $payload) {
            self::assertSame('static_public_allowlist', $payload['payload_origin']);
            self::assertFalse($payload['class_gate']['repo_derived_content_allowed']);
            self::assertSame(['sensitive', 'secret', 'cyber', 'workspace_local'], $payload['class_gate']['blocked_classes']);
        }
    }

    public function test_enabled_fetch_maps_only_grounded_use_signals_to_exploratory_leads(): void
    {
        $responses = [
            'discover' => [
                'items' => [[
                    'title' => 'Public trend lead',
                    'url' => 'https://trendshift.io/repositories/example-agent',
                    'summary' => 'Public trend signal only.',
                ]],
            ],
            'read' => [
                'items' => [
                    [
                        'full_name' => 'public/example-no-tests',
                        'html_url' => 'https://github.com/public/example-no-tests',
                        'description' => 'No test evidence.',
                        'has_tests' => false,
                    ],
                    [
                        'full_name' => 'public/example-with-tests',
                        'html_url' => 'https://github.com/public/example-with-tests',
                        'description' => 'Includes tests and examples.',
                        'usage_signals' => ['tests', 'examples'],
                    ],
                ],
            ],
            'ground' => [
                'items' => [[
                    'title' => 'Public paper lead',
                    'url' => 'https://arxiv.org/abs/2607.00001',
                    'summary' => 'Public abstract signal.',
                ]],
            ],
        ];

        $report = (new AtlasBrainGovernedFrontierFetcher)->run(
            limit: 10,
            dryRun: false,
            enabled: true,
            transport: static fn (array $payload): array => $responses[(string) $payload['stage']] ?? ['items' => []],
            capturedAt: '2026-07-12T00:00:00Z',
        );

        self::assertSame('fetched', $report['status']);
        self::assertTrue($report['network_attempted']);
        self::assertSame([
            'Public trend lead',
            'public/example-with-tests',
            'Public paper lead',
        ], array_column($report['candidates'], 'title'));

        foreach ($report['candidates'] as $candidate) {
            self::assertSame('exploratory', $candidate['trust_tier']);
            self::assertTrue($candidate['lead_only']);
            self::assertNotSame('', $candidate['anti_hype_note']);
        }
    }
}
