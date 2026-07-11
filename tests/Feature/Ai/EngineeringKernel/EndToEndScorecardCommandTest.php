<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class EndToEndScorecardCommandTest extends TestCase
{
    private string $tmpStorage = '';

    private AtlasDecideLiveOutcomeFeedbackService $liveOutcomes;

    private ProviderGovernanceCoverageLedger $coverage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpStorage = sys_get_temp_dir().'/atlas-eng-e2e-'.bin2hex(random_bytes(4));
        @mkdir($this->tmpStorage, 0o755, true);
        $this->app->useStoragePath($this->tmpStorage);

        $this->liveOutcomes = new AtlasDecideLiveOutcomeFeedbackService;
        $this->liveOutcomes->setLogPathForTesting($this->tmpStorage.'/atlas/atlas_decide/live_outcomes.jsonl');
        $this->app->instance(AtlasDecideLiveOutcomeFeedbackService::class, $this->liveOutcomes);

        $this->coverage = new ProviderGovernanceCoverageLedger;
        $this->coverage->setLogPathForTesting($this->tmpStorage.'/atlas/ai/governance/provider_coverage.jsonl');
        $this->app->instance(ProviderGovernanceCoverageLedger::class, $this->coverage);

        config()->set('atlas.ai.governance.enforce', true);
        config()->set('atlas.ai.cache.cost_guard.hard_units', 5.0);
        config()->set('atlas.engineering_kernel.forge_execution_gate_enforcing', true);
        config()->set('atlas.patamar4.adml_cost_outcome.enabled', true);
        config()->set('atlas.patamar4.adml_cost_outcome.min_evidence', 3);

        $this->seedPassingSources();
    }

    protected function tearDown(): void
    {
        if ($this->tmpStorage !== '') {
            $this->deleteDirectory($this->tmpStorage);
        }

        parent::tearDown();
    }

    public function test_scorecard_passes_only_when_all_eight_primary_source_checks_pass(): void
    {
        $data = $this->runScorecard();

        self::assertSame('atlas.engineering.end_to_end_scorecard.v1', $data['schema']);
        self::assertSame('PASS', $data['verdict']);
        self::assertSame(8, $data['check_count']);
        self::assertSame(8, $data['pass_count']);
        self::assertSame([
            'awis_mutative_coverage',
            'dev_gate_parity',
            'forge_sovereign_evidence_real',
            'landing_authority_single',
            'governance_soak',
            'forge_execution_gate_enforcing',
            'adml_cost_outcome',
            'live_outcomes_proven_real',
        ], array_keys($data['checks']));

        foreach ($data['checks'] as $check) {
            self::assertTrue($check['pass'], $check['name']);
            self::assertIsArray($check['evidence']);
        }
    }

    public function test_executor_claimed_forge_verdict_does_not_satisfy_harness_captured_check(): void
    {
        $this->rewriteForgeVerdicts(static function (array $rows): array {
            $rows[array_key_last($rows)]['evidence_provenance'] = 'executor_claimed';

            return $rows;
        });

        $check = $this->runScorecard()['checks']['forge_sovereign_evidence_real'];

        self::assertFalse($check['pass']);
        self::assertContains('forge_evidence_not_harness_captured', $check['blockers']);
    }

    public function test_governance_soak_fails_on_empty_volume_even_with_zero_bypass_rate(): void
    {
        AppendOnlyJsonlStore::rewrite($this->coverage->logPath(), []);

        $check = $this->runScorecard()['checks']['governance_soak'];

        self::assertFalse($check['pass']);
        self::assertContains('governance_soak_volume_below_floor', $check['blockers']);
    }

    public function test_live_outcomes_check_fails_when_proven_real_lines_are_removed(): void
    {
        $rows = $this->liveOutcomes->listOutcomes();
        foreach ($rows as &$row) {
            $row['proven_real'] = false;
        }
        unset($row);
        AppendOnlyJsonlStore::rewrite($this->liveOutcomes->logPath(), $rows);

        $check = $this->runScorecard()['checks']['live_outcomes_proven_real'];

        self::assertFalse($check['pass']);
        self::assertContains('live_outcomes_proven_real_share_below_floor', $check['blockers']);
    }

    public function test_adml_check_fails_when_flip_is_off_even_with_live_route_evidence(): void
    {
        config()->set('atlas.patamar4.adml_cost_outcome.enabled', false);

        $check = $this->runScorecard()['checks']['adml_cost_outcome'];

        self::assertFalse($check['pass']);
        self::assertContains('adml_cost_outcome_disabled', $check['blockers']);
    }

    /** @return array<string,mixed> */
    private function runScorecard(): array
    {
        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:engineering:end-to-end-scorecard', ['--json' => true], $out);
        $data = json_decode($out->fetch(), true);

        self::assertSame(0, $exit);
        self::assertIsArray($data);

        return $data;
    }

    private function seedPassingSources(): void
    {
        $now = now()->subHours(2)->toIso8601String();

        foreach (['dev', 'forge', 'autonomos'] as $executor) {
            AppendOnlyJsonlStore::append($this->coverage->logPath(), [
                'schema_version' => ProviderGovernanceCoverageLedger::SCHEMA,
                'path' => ProviderGovernanceCoverageLedger::PATH_CONSULTED,
                'covered' => false,
                'governed' => true,
                'provider' => $executor.'_provider',
                'surface' => $executor.'_surface',
                'context' => [
                    'executor' => $executor,
                    'recorded_at' => $now,
                    'would_have_blocked' => true,
                    'completion_outcome' => 'red',
                    'pre_cost_units' => 2.0,
                    'post_cost_units' => 3.0,
                ],
            ]);
        }

        for ($i = 0; $i < 20; $i++) {
            AppendOnlyJsonlStore::append($this->forgeVerdictPath(), [
                'recorded_at' => $now,
                'promoted' => true,
                'tests_run' => 3,
                'commands' => ['vendor/bin/phpunit tests/Feature/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleServiceTest.php'],
                'evidence_provenance' => 'harness_captured',
            ]);
        }

        $actors = [
            'dev' => 'engineering_outcome_spine:dev',
            'forge' => 'atlas_forge_work_packet_complete',
            'autonomos' => 'atlas_autonomos_landing',
        ];
        $routes = [
            ['task_category' => 'implementation', 'role' => 'dev'],
            ['task_category' => 'verification', 'role' => 'forge'],
            ['task_category' => 'landing', 'role' => 'autonomos'],
        ];
        foreach ($routes as $routeIndex => $route) {
            foreach (range(1, 3) as $n) {
                $executor = array_keys($actors)[$routeIndex];
                AppendOnlyJsonlStore::append($this->liveOutcomes->logPath(), [
                    'schema_version' => AtlasDecideLiveOutcomeFeedbackService::OUTCOME_SCHEMA,
                    'recorded_at' => $now,
                    'task_category' => $route['task_category'],
                    'role' => $route['role'],
                    'framework' => null,
                    'provider' => $executor.'_provider',
                    'model' => null,
                    'result' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                    'proven_real' => true,
                    'latency_ms' => 10,
                    'quality_score' => 1.0,
                    'cost_usd' => 0.0,
                    'tokens_used' => 10,
                    'actor' => $actors[$executor],
                    'entry_hash' => 'test-'.$routeIndex.'-'.$n,
                ]);
            }
        }
    }

    private function forgeVerdictPath(): string
    {
        return $this->tmpStorage.'/app/atlas/engineering-kernel/forge-sovereign-verdicts.jsonl';
    }

    /**
     * @param  callable(list<array<string,mixed>>):list<array<string,mixed>>  $mutate
     */
    private function rewriteForgeVerdicts(callable $mutate): void
    {
        AppendOnlyJsonlStore::rewrite($this->forgeVerdictPath(), $mutate(AppendOnlyJsonlStore::read($this->forgeVerdictPath())));
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
