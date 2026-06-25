<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBacklogIntentSource;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * L3-12: meta-loop COM freio. O Loop pode afiar o próprio harness — mas o conjunto de
 * SEGURANÇA (frozen judge, gates, never-merge, este guard) é PÉTREO: jamais um alvo,
 * independentemente de flag, backlog ou score. É o invariante anti-runaway que impede o
 * loop de editar a própria fechadura. Arquivos de harness não-segurança só entram com a
 * flag meta ON.
 */
final class AtlasLoopHarnessGuardTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        @File::delete(storage_path('app/atlas/loop/backlog-intents.json'));
        parent::tearDown();
    }

    public function test_safety_files_are_forbidden_self_targets_always(): void
    {
        $guard = new AtlasLoopHarnessGuard();

        foreach (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS as $forbidden) {
            $this->assertTrue($guard->isForbiddenSelfTarget($forbidden), "$forbidden é pétreo-proibido");
            // Proibido em AMBOS os estados de flag — o loop nunca edita a própria fechadura.
            $this->assertSame('forbidden', $guard->admit($forbidden, false));
            $this->assertSame('forbidden', $guard->admit($forbidden, true));
        }
    }

    public function test_forbidden_set_covers_the_critical_safety_spine(): void
    {
        // Ratchet: o conjunto proibido NÃO pode encolher abaixo da espinha de segurança.
        $set = implode('|', AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);
        foreach ([
            'AtlasEvolutionFrozenJudge',
            'AtlasLoopProposalPromotionGate',
            'AtlasLoopAutoMergeService',
            'AtlasLoopNetDirectionGuard',
            'AtlasLoopHarnessGuard',
            'AtlasLoopUtilityGradeService',       // o termômetro honesto (pétreo desde 14/06)
            'AtlasLoopWiredCallerService',         // a fonte de verdade que a régua re-resolve
            'complete_atlas_loop_runtime_schema', // never-merge CHECK+trigger
            'governed_merge_door',                // a porta governada
            'AtlasLoopNextWorkDecider',            // o priorizador ungameable
            'AtlasLoopIdeaTreeAccessor',           // ARBOR-GRAFT: substrato de candidatos/árvore
            'AtlasLoopSelectAdjuster',             // ARBOR-GRAFT: termo de SELECT determinístico
            'AtlasLoopSystemAxisService',          // LOOP-OS EV-brain: vetor de eixo-sistema (gargalo)
            'AtlasLoopTouchesAxesProducer',        // LOOP-OS EV-brain: relief machine-computável
            'AtlasLoopExpectedValueDecider',       // LOOP-OS EV-brain: argmax do salto exponencial
            'AtlasLoopProjectionEngine',           // LOOP-OS Slice 9: contrato de obrigações (content-fixpoint)
            // LOOP-OS Fase 1 · Slice -1: a closure DELEGADA do juiz + moat + Constituição + back-doors
            'AtlasEngineeringHonestyGate',         // o gate determinístico que o Certifier DELEGA
            'AtlasLoopMutationAdequacyGateService',
            'AtlasLoopCrossFileConsumerGateService',
            'AtlasLoopJudgeConsensusGate',
            'AtlasLoopHeldOutDeltaCertifier',
            'AtlasLoopMutationOperators',          // vocabulário de mutação (moat de detecção)
            'AutonomousEvolution/Constitution/',   // a subárvore inteira da Constituição
            'config/atlas.php',                    // back-door: rebaixar os próprios safety-gates
            'bin/atlas-loop-watchdog.sh',          // gatilho externo de respawn
            'tests/Feature/Loop/Constitution/',    // a suíte-sentinela §3.6 (descarga build-time) é pétrea
            // FASE 0 · piso de autonomia responsável (24/06): a superfície de reward-hack é pétrea
            'AutonomousEvolution/SelfModel/',      // o músculo endógeno R7 (oráculo/promoção/corpus/eval/training)
            'AutonomousEvolution/Attribution/',    // o gradiente de atribuição causal R3
            'AutonomousEvolution/Defect/',         // o gate de falsificação R8.3 (bug = RED reproduzível)
            'AutonomousEvolution/V4/',             // meta-objetivo + auto-arquitetura
            'AtlasLoopComprehensionGroundingGate', // o veto de grounding (material só nasce ancorado)
            'AtlasLoopAmbitionDecider',            // o decisor de ambição (o réu não edita o que decide o salto)
            'app/Services/Ai/AgentGovernance/',    // reconciler/desired-state/fleet-driver: autoriza a própria execução
            'AutonomousEvolution/BehaviorDelta/',  // FASE 1: a cadeia de medição do Δ (termômetro do grade)
        ] as $critical) {
            $this->assertStringContainsString($critical, $set, "$critical deve estar no conjunto proibido");
        }
    }

    public function test_harness_target_gated_by_flag_non_safety_file(): void
    {
        $guard = new AtlasLoopHarnessGuard();
        $harnessNonSafety = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php';

        $this->assertFalse($guard->isForbiddenSelfTarget($harnessNonSafety), 'não é segurança');
        $this->assertTrue($guard->isHarnessTarget($harnessNonSafety), 'é harness');
        $this->assertSame('harness_gated', $guard->admit($harnessNonSafety, false), 'flag OFF rejeita');
        $this->assertSame('admissible', $guard->admit($harnessNonSafety, true), 'flag ON admite');
    }

    public function test_ordinary_app_file_is_always_admissible(): void
    {
        $guard = new AtlasLoopHarnessGuard();
        $ordinary = 'app/Support/TerminalMarkdownRenderer.php';

        $this->assertSame('admissible', $guard->admit($ordinary, false));
        $this->assertSame('admissible', $guard->admit($ordinary, true));
    }

    public function test_discovery_never_surfaces_a_forbidden_file_even_via_backlog(): void
    {
        // Mesmo se um arquivo de SEGURANÇA for injetado no backlog com prioridade máxima,
        // a discovery NUNCA o entrega como alvo (o guard é o chokepoint final).
        $repo = sys_get_temp_dir().'/atlas-hg-'.bin2hex(random_bytes(4));
        $this->dirs[] = $repo;
        $forbidden = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';
        File::ensureDirectoryExists(dirname($repo.'/'.$forbidden));
        File::put($repo.'/'.$forbidden, "<?php\nclass AtlasEvolutionFrozenJudge {}\n");

        File::ensureDirectoryExists(storage_path('app/atlas/loop'));
        File::put(storage_path('app/atlas/loop/backlog-intents.json'), json_encode(['items' => [
            ['path' => $forbidden, 'objective' => 'tentar enfraquecer o juiz', 'priority' => 1.0],
        ]]));
        config(['atlas.loop.discovery_backlog_intents' => true, 'atlas.loop.meta_harness_targets' => true]);

        $discovery = new AtlasLoopTargetDiscoveryService(
            app(AtlasLoopTargetRepository::class),
            null,
            new AtlasLoopBacklogIntentSource(),
            new AtlasLoopHarnessGuard(),
        );
        $result = $discovery->discover($repo, 'camp-hg', ['roots' => ['app/Services'], 'limit' => 10]);

        $paths = array_column($result['top'], 'path');
        $this->assertNotContains($forbidden, $paths, 'o frozen judge NUNCA é alvo, nem via backlog priorizado');
    }

    /**
     * LOOP-OS Fase 1 · Slice -1: a CLOSURE DELEGADA do juiz + a subárvore da Constituição +
     * os back-doors (config/atlas.php, vocabulário de mutação, gatilho de respawn) são pétreos
     * em AMBOS os estados de flag. Proteger só o Certifier NÃO protegia o juiz (achado R4):
     * o veredito é delegado a HonestyGate/MutationAdequacy/CrossFileConsumer/JudgeConsensus/etc.
     */
    public function test_phase1_freeze_locks_cert_chain_closure_constitution_and_backdoors(): void
    {
        $guard = new AtlasLoopHarnessGuard();

        $frozen = [
            // closure transitiva do cert-chain (delegados do veredito)
            'app/Services/Ai/AutonomousEvolution/Verify/AtlasEngineeringHonestyGate.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMutationAdequacyGateService.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopCrossFileConsumerGateService.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopJudgeConsensusGate.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopHeldOutDeltaCertifier.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopBehavioralEquivalenceGate.php',
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopNodeInterfaceExtractor.php',
            // moat + back-doors
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMutationOperators.php',
            'config/atlas.php',
            'bin/atlas-loop-watchdog.sh',
            // arquivo AINDA INEXISTENTE sob a subárvore da Constituição → pétreo por substring,
            // então a fundação nasce inviolável (o loop não pode tocá-la enquanto é escrita).
            'app/Services/Ai/AutonomousEvolution/Constitution/AtlasLoopMergeActuator.php',
            'app/Services/Ai/AutonomousEvolution/Constitution/battery/case-0001-bad.json',
        ];

        foreach ($frozen as $path) {
            $this->assertTrue($guard->isForbiddenSelfTarget($path), "$path deve ser pétreo");
            $this->assertSame('forbidden', $guard->admit($path, false), "$path forbidden com flag OFF");
            $this->assertSame('forbidden', $guard->admit($path, true), "$path forbidden com flag ON");
        }

        // O congelamento é CIRÚRGICO, não um freeze do tronco inteiro: um arquivo de harness
        // NÃO-juiz do loop continua apenas flag-gated (o loop ainda evolui o próprio harness).
        $nonJudge = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php';
        $this->assertFalse($guard->isForbiddenSelfTarget($nonJudge), 'harness não-juiz não é pétreo');
        $this->assertSame('admissible', $guard->admit($nonJudge, true), 'harness não-juiz admissível com flag ON');
    }

    /**
     * FASE 0 · PISO DE AUTONOMIA RESPONSÁVEL (2026-06-24): antes de o Loop rodar com autonomia plena
     * (meta_harness ON), a SUPERFÍCIE DE REWARD-HACK precisa ser pétrea. Sem isto, no exato regime que
     * a tese de autonomia total exige, o réu poderia editar o próprio oráculo de outcome, a própria
     * atribuição causal, o próprio gate de falsificação, o próprio meta-objetivo e o autorizador de
     * campanha — forjando o próprio sucesso (o que matou o Sakana DGM/CUDA Engineer). Pétreo em AMBOS
     * os estados de flag, pelo mesmo princípio do termômetro e do master switch.
     */
    public function test_phase0_responsible_autonomy_floor_freezes_rewardhack_surface(): void
    {
        $guard = new AtlasLoopHarnessGuard();

        $frozen = [
            // (i) o músculo endógeno R7 inteiro — réu não treina/promove o próprio músculo na própria métrica
            'app/Services/Ai/AutonomousEvolution/SelfModel/Oracle/AtlasLoopCrossDomainOracleRegistry.php',
            'app/Services/Ai/AutonomousEvolution/SelfModel/Promotion/AtlasLoopModelPromotionGate.php',
            'app/Services/Ai/AutonomousEvolution/SelfModel/Corpus/AtlasLoopOutcomeCorpusBuilder.php',
            'app/Services/Ai/AutonomousEvolution/SelfModel/Eval/AtlasLoopHeldOutEvalHarness.php',
            // (ii) a atribuição causal R3 — o gradiente que credita as próprias shapes
            'app/Services/Ai/AutonomousEvolution/Attribution/AtlasLoopCapabilityDeltaAttributionService.php',
            // (iii) o gate de falsificação R8.3 — bug só conta com RED reproduzível
            'app/Services/Ai/AutonomousEvolution/Defect/AtlasLoopDefectFalsificationGate.php',
            // (iv) meta-objetivo + auto-arquitetura V4
            'app/Services/Ai/AutonomousEvolution/V4/AtlasLoopV4MetaObjectiveOriginator.php',
            'app/Services/Ai/AutonomousEvolution/V4/AtlasLoopV4SelfArchitectureProposer.php',
            // (v) o veto de grounding — material só nasce ancorado
            'app/Services/Ai/AutonomousEvolution/Verify/AtlasLoopComprehensionGroundingGate.php',
            // (vi) o decisor de ambição — o réu não edita o que decide o próprio salto
            'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopAmbitionDecider.php',
            // (vii) a governança de agentes — autoriza a própria execução (fechadura externa, como o switch)
            'app/Services/Ai/AgentGovernance/AtlasAgentReconciler.php',
            // arquivo AINDA INEXISTENTE sob as árvores pétreas → pétreo por substring (nasce inviolável)
            'app/Services/Ai/AutonomousEvolution/SelfModel/Training/AtlasLoopFutureMuscleTrainer.php',
        ];

        foreach ($frozen as $path) {
            $this->assertTrue($guard->isForbiddenSelfTarget($path), "$path deve ser pétreo (piso de autonomia)");
            $this->assertSame('forbidden', $guard->admit($path, false), "$path forbidden com flag OFF");
            $this->assertSame('forbidden', $guard->admit($path, true), "$path forbidden com flag ON");
        }

        // CIRÚRGICO: um arquivo de harness NÃO-reward-hack continua flag-gated (o loop ainda evolui o
        // próprio harness não-juiz). O piso aperta só a superfície de auto-engano, não o tronco inteiro.
        $nonHack = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php';
        $this->assertFalse($guard->isForbiddenSelfTarget($nonHack), 'harness não-reward-hack não é pétreo');
        $this->assertSame('admissible', $guard->admit($nonHack, true), 'harness não-reward-hack admissível com flag ON');
    }
}
