<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasDocumentationEnforcementService;
use App\Services\Engineering\AtlasDocumentationProviderBootstrapProbe;
use Tests\TestCase;

final class AtlasDocumentationEnforcementServiceTest extends TestCase
{
    public function test_report_aggregates_documentation_gates_without_writes_or_provider_claims(): void
    {
        $this->bindProviderBootstrapProbe($this->providerBootstrapPayload('ready'));

        $payload = app(AtlasDocumentationEnforcementService::class)->report(
            task: 'elevar documentacao governanca enforcement para nota 10',
            feature: 'documentation enforcement runtime for AI implementation sessions',
            targets: ['docs/engineering-knowledge-base/atlas-documentation-enforcement-runtime.md'],
        );

        $this->assertSame(AtlasDocumentationEnforcementService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains($payload['status'], ['ready', 'review', 'blocked']);
        $this->assertGreaterThanOrEqual(8.0, $payload['score']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertFalse($payload['claim_policy']['providers_invoked']);
        $this->assertFalse($payload['claim_policy']['declares_documentation_perfect']);
        $this->assertTrue($payload['ai_execution_contract']['must_run_before_code']);
        $this->assertTrue($payload['ai_execution_contract']['blocked_means_no_code']);

        foreach ([
            'canonical_docs_health',
            'documentation_reality',
            'authority_control',
            'code_reality_alignment',
            'cartography_boundary',
            'provider_bootstrap_enforcement',
            'strict_gate_strength',
            'validation_completeness',
        ] as $scoreKey) {
            $this->assertArrayHasKey($scoreKey, $payload['subarea_scores']);
            $this->assertGreaterThanOrEqual(0, $payload['subarea_scores'][$scoreKey]);
            $this->assertLessThanOrEqual(10, $payload['subarea_scores'][$scoreKey]);
        }

        $this->assertStringStartsWith('php artisan atlas:documentation:enforce', $payload['command_matrix']['hard_gate']['command']);
        $this->assertSame('status != ready', $payload['command_matrix']['hard_gate']['blocks_code_when']);
        $this->assertContains(
            'php artisan atlas:code-reality reality-audit --json',
            $payload['required_before_code'],
        );
    }

    public function test_status_reflects_blockers_review_items_and_warnings_honestly(): void
    {
        $this->bindProviderBootstrapProbe($this->providerBootstrapPayload('ready'));

        $payload = app(AtlasDocumentationEnforcementService::class)->report();

        $blockers = (int) data_get($payload, 'summary.blockers_count', 0);
        $review = (int) data_get($payload, 'summary.review_count', 0);
        $warnings = (int) data_get($payload, 'summary.warnings_count', 0);

        if ($blockers > 0) {
            $this->assertSame('blocked', $payload['status']);

            return;
        }

        if ($review > 0 || $warnings > 0) {
            $this->assertSame('review', $payload['status']);

            return;
        }

        $this->assertSame('ready', $payload['status']);
    }

    public function test_certification_hash_is_deterministic_for_same_inputs(): void
    {
        $this->bindProviderBootstrapProbe($this->providerBootstrapPayload('ready'));

        $service = app(AtlasDocumentationEnforcementService::class);

        $first = $service->report(task: 'same task', feature: 'same feature', targets: ['same-target']);
        $second = $service->report(task: 'same task', feature: 'same feature', targets: ['same-target']);

        $this->assertSame($first['certification_hash'], $second['certification_hash']);
    }

    public function test_report_blocks_when_provider_bootstrap_probe_fails_closed(): void
    {
        $this->bindProviderBootstrapProbe($this->providerBootstrapPayload('blocked'));

        $payload = app(AtlasDocumentationEnforcementService::class)->report(
            task: 'bootstrap unavailable should block code',
            feature: 'documentation governance enforcement',
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', $payload['source_status']['provider_bootstrap']['status']);
        $this->assertSame(0.0, $payload['subarea_scores']['provider_bootstrap_enforcement']);
        $this->assertContains(
            'provider bootstrap gate is blocked: session-bootstrap did not execute successfully',
            $payload['blockers'],
        );
    }

    public function test_provider_bootstrap_probe_isolates_fatal_subcommand_output(): void
    {
        $probe = new class extends AtlasDocumentationProviderBootstrapProbe
        {
            public function __construct() {}

            /**
             * @param  array<int,string>  $argv
             * @return array{exit_code:int, stdout:string, stderr:string, timed_out:bool}
             */
            protected function runProbeProcess(array $argv): array
            {
                return [
                    'exit_code' => 255,
                    'stdout' => '',
                    'stderr' => 'PHP Fatal error: Allowed memory size of 134217728 bytes exhausted in /Users/example/project/app/HeavyGate.php on line 42',
                    'timed_out' => false,
                ];
            }
        };

        $payload = $probe->report('task', 'feature', 'atlas-server');

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', $payload['session_bootstrap']['status']);
        $this->assertTrue($payload['session_bootstrap']['process_isolated']);
        $this->assertSame('1024M', $payload['session_bootstrap']['memory_limit']);
        $this->assertSame('memory_limit_exhausted', $payload['session_bootstrap']['failure_reason']);
        $this->assertSame(255, $payload['session_bootstrap']['exit_code']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['session_bootstrap']['stderr_hash']);
        $this->assertStringNotContainsString('/Users/example', (string) $payload['session_bootstrap']['stderr_excerpt']);
        $this->assertStringContainsString('<local-path>', (string) $payload['session_bootstrap']['stderr_excerpt']);
    }

    public function test_provider_bootstrap_probe_reports_strict_gate_reason_from_valid_json(): void
    {
        $probe = new class extends AtlasDocumentationProviderBootstrapProbe
        {
            public function __construct() {}

            /**
             * @param  array<int,string>  $argv
             * @return array{exit_code:int, stdout:string, stderr:string, timed_out:bool}
             */
            protected function runProbeProcess(array $argv): array
            {
                return [
                    'exit_code' => 1,
                    'stdout' => json_encode([
                        'schema_version' => 'atlas.session_bootstrap.v1',
                        'status' => 'ok',
                        'gate_status' => 'blocked',
                        'session_gate' => [
                            'reason' => 'feature_placement_gate_blocked',
                            'blocked_when' => ['high_overlap_duplicate_candidate_requires_reuse_or_explicit_supersede_decision'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'stderr' => '',
                    'timed_out' => false,
                ];
            }
        };

        $payload = $probe->report('task', 'feature', 'atlas-server');

        $this->assertSame('blocked', $payload['session_bootstrap']['status']);
        $this->assertSame('ok', $payload['session_bootstrap']['payload_status']);
        $this->assertSame('blocked', $payload['session_bootstrap']['payload_gate_status']);
        $this->assertSame('feature_placement_gate_blocked', $payload['session_bootstrap']['blocked_reason']);
        $this->assertSame(
            ['high_overlap_duplicate_candidate_requires_reuse_or_explicit_supersede_decision'],
            $payload['session_bootstrap']['blocked_when'],
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function bindProviderBootstrapProbe(array $payload): void
    {
        $this->app->instance(
            AtlasDocumentationProviderBootstrapProbe::class,
            new class($payload) extends AtlasDocumentationProviderBootstrapProbe
            {
                /**
                 * @param  array<string,mixed>  $payload
                 */
                public function __construct(private readonly array $payload) {}

                /**
                 * @return array<string,mixed>
                 */
                public function report(string $task, string $feature, string $workspace): array
                {
                    return $this->payload;
                }
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function providerBootstrapPayload(string $status): array
    {
        $check = [
            'command' => 'php artisan atlas:ai:session-bootstrap --task="<task>" --strict --json',
            'status' => $status,
            'exit_code' => $status === 'ready' ? 0 : 1,
            'payload_status' => $status,
            'schema_version' => null,
        ];

        return [
            'status' => $status,
            'session_bootstrap' => $check,
            'feature_placement' => array_merge($check, [
                'command' => 'php artisan atlas:ai:place-feature "<feature>" --strict --json',
            ]),
            'fail_closed' => true,
        ];
    }
}
