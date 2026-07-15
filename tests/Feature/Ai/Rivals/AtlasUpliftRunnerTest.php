<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\LocalFakeSuiteAdapter;
use App\Services\Ai\Rivals\Core\Adjudicator;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\AtlasUpliftRunner;
use App\Services\Ai\Rivals\Core\EvidencePackBuilder;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Tests\TestCase;

class AtlasUpliftRunnerTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_uplift_test_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }
        parent::tearDown();
    }

    private function runWithArms(array $armSpecs): string
    {
        $adapter = new LocalFakeSuiteAdapter;
        $registry = new ArmRegistry;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            array_column($adapter->listCases(), 'case_id'),
            array_map(fn ($s) => $registry->parse($s), $armSpecs),
            3,
            ['max_usd' => 0.0, 'max_minutes' => 5],
            42,
        );
        $runId = $plan->persist();
        $adapter->execute($plan);
        (new EvidencePackBuilder)->build($runId);
        (new Adjudicator)->adjudicate($runId);

        return $runId;
    }

    public function test_same_model_two_runtimes_yields_scoped_delta(): void
    {
        $runId = $this->runWithArms(['local_fake_model@bare', 'local_fake_model@atlas_dev']);

        $uplift = (new AtlasUpliftRunner)->compare($runId, 'local_fake_model');

        $this->assertTrue($uplift['uplift_supported']);
        $this->assertSame('harness_uplift', $uplift['uplift_kind']);
        // local_fake uplift proves harness mechanics only — never market claim
        $this->assertFalse($uplift['claim_allowed']);
        $this->assertContains('harness_uplift_not_claimable', $uplift['claim_blockers']);
        $patch = collect($uplift['deltas'])->firstWhere('task_type', 'coding_patch');
        // fake: flaky estabiliza no runtime atlas → bare 5/9, atlas 6/9
        $this->assertEqualsWithDelta(5 / 9, $patch['base']['success_rate'], 0.001);
        $this->assertEqualsWithDelta(6 / 9, $patch['atlas']['success_rate'], 0.001);
        $this->assertEqualsWithDelta(1 / 9, $patch['delta_success_rate'], 0.001);
        // delta é escopado — nunca um veredito global
        $this->assertArrayNotHasKey('winner', $uplift);
        $this->assertNotNull($uplift['claim_scope']);
    }

    public function test_missing_runtime_command_blocks_non_harness_uplift(): void
    {
        // Arms may be created while a command exists; compare must still fail-closed
        // when the runtime command is unset for a non-harness suite.
        config()->set('atlas_rivals.runtime_commands.atlas_dev', 'echo atlas-dev-harness');
        $runId = $this->runWithArms(['local_fake_model@bare', 'local_fake_model@atlas_dev']);
        config()->set('atlas_rivals.runtime_commands.atlas_dev', null);
        $planPath = RunPaths::planPath($runId);
        $plan = json_decode(file_get_contents($planPath), true);
        $plan['suite_id'] = 'tau2_bench';
        file_put_contents($planPath, json_encode($plan));

        $uplift = (new AtlasUpliftRunner)->compare($runId, 'local_fake_model');
        $this->assertFalse($uplift['uplift_supported']);
        $this->assertStringContainsString('runtime_command_not_configured:atlas_dev', $uplift['reason']);
    }

    public function test_atlas_arm_absent_blocks_honestly_never_simulates(): void
    {
        $runId = $this->runWithArms(['local_fake_model@bare']);

        $uplift = (new AtlasUpliftRunner)->compare($runId, 'local_fake_model');

        $this->assertFalse($uplift['uplift_supported']);
        $this->assertFalse($uplift['claim_allowed']);
        $this->assertStringContainsString('arm_did_not_run:local_fake_model@atlas_dev', $uplift['reason']);
        $this->assertSame([], $uplift['deltas']);
    }

    public function test_same_runtime_comparison_is_rejected(): void
    {
        $runId = $this->runWithArms(['local_fake_model@bare']);

        $this->expectExceptionMessageMatches('/rivals_uplift_invalid_runtimes/');
        (new AtlasUpliftRunner)->compare($runId, 'local_fake_model', 'bare', 'bare');
    }

    public function test_real_uplift_is_case_rep_paired_and_requires_runtime_proof(): void
    {
        $registry = new ArmRegistry;
        $plan = RunPlan::make(
            'atlas_bench',
            ['c1', 'c2'],
            [
                $registry->parse('claude_sonnet_5@bare'),
                $registry->parse('claude_sonnet_5@atlas_dev'),
            ],
            3,
            ['max_usd' => 10.0, 'max_minutes' => 30],
            1,
        );
        $runId = $plan->persist();
        foreach (['c1', 'c2'] as $caseIndex => $caseId) {
            for ($rep = 1; $rep <= 3; $rep++) {
                foreach (['bare', 'atlas_dev'] as $runtime) {
                    $atlasSuccess = $runtime === 'atlas_dev' && ! ($caseIndex === 1 && $rep === 3);
                    $bareSuccess = $runtime === 'bare' && $caseIndex === 0;
                    RunReceipt::fromArray([
                        'schema_version' => SchemaContract::RUN_RECEIPT,
                        'run_id' => $runId,
                        'case_id' => $caseId,
                        'task_type' => 'coding_patch',
                        'arm_id' => 'claude_sonnet_5@'.$runtime,
                        'repetition' => $rep,
                        'status' => ($runtime === 'bare' ? $bareSuccess : $atlasSuccess) ? 'success' : 'failure',
                        'failure_class' => ($runtime === 'bare' ? $bareSuccess : $atlasSuccess) ? null : 'model_failure',
                        'wall_ms' => $runtime === 'bare' ? 1000 : 1200,
                        'tokens_in' => 10,
                        'tokens_out' => 2,
                        'cost_usd' => $runtime === 'bare' ? 0.10 : 0.12,
                        'field_presence' => [
                            'wall_ms' => ['present' => true, 'reason' => null],
                            'tokens_in' => ['present' => true, 'reason' => null],
                            'tokens_out' => ['present' => true, 'reason' => null],
                            'cost_usd' => ['present' => true, 'reason' => null],
                        ],
                        'claim_tier' => 'production',
                        'harness_only' => false,
                        'artifacts' => [],
                        'started_at' => null,
                        'finished_at' => null,
                        'metadata' => $runtime === 'atlas_dev'
                            ? [
                                'runtime_bridge' => [
                                    // Braço Atlas de verdade: o Atlas rodou.
                                    'atlas_runtime' => true,
                                    'real_provider' => true,
                                    'model' => 'claude-sonnet-5',
                                    'fair_mode' => [
                                        'single_provider' => true,
                                        'decide_disabled' => true,
                                        'fallback_disabled' => true,
                                    ],
                                ],
                            ]
                            : [
                                'direct_provider' => [
                                    'real_provider' => true,
                                    'model' => 'claude-sonnet-5',
                                ],
                            ],
                    ])->append();
                }
            }
        }
        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'internal_claim_allowed' => true,
            'public_claim_allowed' => false,
            'internal_claim_blockers' => [],
            'claim_scope' => ['suite' => 'atlas_bench'],
        ]));

        $uplift = (new AtlasUpliftRunner)->compare($runId, 'claude_sonnet_5');

        $this->assertTrue($uplift['uplift_supported']);
        $this->assertSame('real_uplift', $uplift['uplift_kind']);
        $this->assertTrue($uplift['internal_claim_allowed']);
        $this->assertFalse($uplift['stop_the_line']);
        $this->assertSame(6, $uplift['deltas'][0]['pairs']);
        $this->assertCount(6, $uplift['deltas'][0]['pair_keys']);
        $this->assertArrayHasKey('delta_success_rate_ci_95', $uplift['deltas'][0]);
    }

    public function test_arm_that_never_ran_atlas_is_refused_however_it_labels_itself(): void
    {
        // ⚠️ O DEFEITO CENTRAL do relatório, medido 15/07: para o modelo primário
        // (provider=hermes) o braço "atlas_dev" executava `hermes -z` — Hermes CLI
        // puro, sem artisan, sem memória do Atlas, sem Decide. 107 recibos assim,
        // ZERO com atlas_cli_dev_efficient. O delta publicado como "com Atlas"
        // comparava harness de agente (hermes vs o harness nativo do benchmark),
        // não a contribuição do Atlas.
        //
        // O portão não pegou porque conferia campos que o próprio bridge escrevia
        // hardcoded `true` — a autodeclaração do medido sobre si. Este teste fixa
        // a regra: quem não rodou o Atlas é RECUSADO por mais que se rotule de
        // Atlas. Recusar = "não medido", que é a verdade; publicar delta seria
        // inventar uma resposta para a única pergunta que o relatório existe para
        // responder.
        $registry = new ArmRegistry;
        $plan = RunPlan::make(
            'atlas_bench',
            ['c1'],
            [$registry->parse('claude_sonnet_5@bare'), $registry->parse('claude_sonnet_5@atlas_dev')],
            1,
            ['max_usd' => 10.0, 'max_minutes' => 30],
            1,
        );
        $runId = $plan->persist();
        foreach (['bare', 'atlas_dev'] as $runtime) {
            RunReceipt::fromArray([
                'schema_version' => SchemaContract::RUN_RECEIPT,
                'run_id' => $runId,
                'case_id' => 'c1',
                'task_type' => 'coding_patch',
                'arm_id' => 'claude_sonnet_5@'.$runtime,
                'repetition' => 1,
                'status' => 'success',
                'failure_class' => null,
                'wall_ms' => 1000,
                'tokens_in' => 10,
                'tokens_out' => 2,
                'cost_usd' => 0.1,
                'field_presence' => [
                    'wall_ms' => ['present' => true, 'reason' => null],
                    'tokens_in' => ['present' => true, 'reason' => null],
                    'tokens_out' => ['present' => true, 'reason' => null],
                    'cost_usd' => ['present' => true, 'reason' => null],
                ],
                'claim_tier' => 'production',
                'harness_only' => false,
                'artifacts' => [],
                'started_at' => null,
                'finished_at' => null,
                'metadata' => $runtime === 'atlas_dev'
                    ? [
                        // A forma REAL do recibo do bypass: tudo verde, menos o
                        // fato de o Atlas ter rodado.
                        'runtime_bridge' => [
                            'atlas_runtime' => false,
                            'execution' => 'hermes_cli_oneshot',
                            'real_provider' => true,
                            'model' => 'claude-sonnet-5',
                            'fair_mode' => [
                                'single_provider' => true,
                                'decide_disabled' => true,
                                'fallback_disabled' => true,
                            ],
                        ],
                    ]
                    : ['direct_provider' => ['real_provider' => true, 'model' => 'claude-sonnet-5']],
            ])->append();
        }
        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'internal_claim_allowed' => true,
            'public_claim_allowed' => false,
            'internal_claim_blockers' => [],
            'claim_scope' => ['suite' => 'atlas_bench'],
        ]));

        $uplift = (new AtlasUpliftRunner)->compare($runId, 'claude_sonnet_5');

        $this->assertFalse($uplift['uplift_supported'], 'braço sem Atlas não sustenta uplift do Atlas');
        $this->assertSame([], $uplift['deltas'], 'nenhum delta pode ser publicado sobre um Atlas que não rodou');
        $this->assertFalse($uplift['claim_allowed']);
    }
}
