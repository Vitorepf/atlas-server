<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship;

use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

final class AutonomosDigestSurfaceTest extends TestCase
{
    private const TOKEN = 'test-token-with-enough-length-123';

    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => self::TOKEN];

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', self::TOKEN);

        $this->tmp = sys_get_temp_dir().'/atlas_autonomos_digest_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $runner = $this->app->make(Reliable24hLoopRunnerService::class);
        $runner->setStorageRootForTesting($this->tmp);
        $this->app->instance(Reliable24hLoopRunnerService::class, $runner);

        $this->mock(AreaFocusLoopReadModelService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('project')->andReturnUsing(function (array $input): array {
                $area = (string) ($input['area_id'] ?? 'agentic_engineering_os');

                return [
                    'schema_version' => AreaFocusLoopReadModelService::REPORT_SCHEMA,
                    'status' => 'ready',
                    'area_id' => $area,
                    'finding_count' => $area === 'agentic_engineering_os' ? 2 : 0,
                    'findings' => $area === 'agentic_engineering_os' ? [
                        [
                            'finding_id' => 'nsf_digest_pending_decision',
                            'title' => 'Production deploy decision needs operator',
                            'severity' => 'high',
                            'source' => 'owner_docs',
                            'source_ref' => 'owner_doc:atlas-agentic-engineering-os',
                            'route' => 'inbox_only',
                            'route_reason' => 'high_risk_or_sensitive_requires_operator',
                            'operator_decision_required' => true,
                            'priority_score' => 41,
                            'evidence_refs' => ['docs/engineering-knowledge-base/atlas-agentic-engineering-os.md'],
                        ],
                        [
                            'finding_id' => 'nsf_digest_low_signal',
                            'title' => 'Low risk backlog signal',
                            'severity' => 'low',
                            'source' => 'self_directed_evolution',
                            'source_ref' => 'gap:low',
                            'route' => 'atlas_dev',
                            'operator_decision_required' => false,
                            'priority_score' => 10,
                            'evidence_refs' => [],
                        ],
                    ] : [],
                ];
            });
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    public function test_autonomos_digest_aggregates_real_loop_delivery_risks_and_pending_decisions(): void
    {
        $this->appendCycle('agentic_engineering_os', [
            'cycle_index' => 1,
            'cycle_id' => 'aesc_delivered_recent',
            'outcome' => 'merged',
            'cycle_final_status' => 'merged',
            'merge_performed' => true,
            'merge_hash' => 'abc1234',
            'recorded_at' => now('UTC')->subHours(2)->format(\DateTimeInterface::ATOM),
        ]);
        $this->appendCycle('agentic_engineering_os', [
            'cycle_index' => 2,
            'cycle_id' => 'aesc_blocked_recent',
            'outcome' => 'blocked',
            'cycle_final_status' => 'blocked',
            'blockers' => ['provider_quota_exhausted', 'operator_decision_required'],
            'recorded_at' => now('UTC')->subHour()->format(\DateTimeInterface::ATOM),
        ]);
        $this->appendCycle('agentic_engineering_os', [
            'cycle_index' => 3,
            'cycle_id' => 'aesc_delivered_old',
            'outcome' => 'merged',
            'cycle_final_status' => 'merged',
            'merge_performed' => true,
            'merge_hash' => 'def5678',
            'recorded_at' => now('UTC')->subDays(3)->format(\DateTimeInterface::ATOM),
        ]);

        $response = $this->getJson('/ai/software-company-stewardship/autonomos/digest?hours=24', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.autonomos.digest.v1')
            ->assertJsonPath('next_digest_at', null)
            ->assertJsonPath('last.window.hours', 24)
            ->assertJsonPath('last.delivered.0.area_id', 'agentic_engineering_os')
            ->assertJsonPath('last.delivered.0.cycle_id', 'aesc_delivered_recent')
            ->assertJsonPath('last.delivered.0.merge_hash', 'abc1234')
            ->assertJsonPath('last.risks.0.area_id', 'agentic_engineering_os')
            ->assertJsonPath('last.risks.0.source', 'cycle_ledger')
            ->assertJsonPath('last.pending_decisions.0.finding_id', 'nsf_digest_pending_decision');

        $response->assertJsonCount(1, 'last.delivered');
        $response->assertJsonCount(2, 'last.risks');
        $response->assertJsonCount(1, 'last.pending_decisions');
        $this->assertStringNotContainsString($this->tmp, (string) $response->getContent());
    }

    public function test_autonomos_digest_requires_atlas_token(): void
    {
        $this->getJson('/ai/software-company-stewardship/autonomos/digest')->assertStatus(401);
    }

    /**
     * Append one genuine AP-790 ledger row into the runner's isolated ledger path.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function appendCycle(string $area, array $overrides): void
    {
        $record = array_merge([
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'run_id' => 'ap790_digest_test',
            'cycle_index' => 1,
            'cycle_id' => 'aesc_digest_test',
            'finding_key' => 'finding-digest',
            'finding_keys' => ['finding-digest'],
            'outcome' => 'blocked',
            'work_class' => 'product',
            'session_status' => 'completed',
            'cycle_final_status' => 'blocked',
            'blockers' => [],
            'merge_performed' => false,
            'merge_hash' => '',
            'cumulative' => ['cycles_this_run' => 1, 'merges_total' => 0, 'blocked_in_row' => 0],
            'recorded_at' => now('UTC')->format(\DateTimeInterface::ATOM),
        ], $overrides);

        $runner = $this->app->make(Reliable24hLoopRunnerService::class);
        $path = $runner->ledgerPath($area, 'dev_forge');
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
