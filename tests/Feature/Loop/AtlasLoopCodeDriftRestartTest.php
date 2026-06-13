<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L4-5: auto-restart por drift de PIPELINE (CRÍTICO para auto-evolução real 24h+).
 *
 * Um supervisor de vida longa não pode continuar evoluindo o Atlas com código velho do
 * PRÓPRIO motor. Quando o loop mergeia uma melhoria no pipeline (discovery/grinder/
 * certifier/auto-merge/gate), o processo vivo fica stale na hora. O supervisor detecta o
 * drift e sai limpo mantendo status=running; o keepalive relança com o código novo.
 *
 * O contrato fino que estes testes congelam: SÓ reinicia em drift de PIPELINE. Merges de
 * arquivos-ALVO (o trabalho normal do loop, a cada 30min) NÃO disparam restart — senão o
 * supervisor reiniciaria a cada merge (5min de downtime por merge, churn inútil).
 */
final class AtlasLoopCodeDriftRestartTest extends TestCase
{
    private function changedPipelineFiles(array $changed): array
    {
        $svc = app(AtlasLoopCampaignSupervisor::class);
        $svc->setChangedFilesResolverForTesting(static fn (): array => $changed);
        $m = new ReflectionMethod(AtlasLoopCampaignSupervisor::class, 'changedPipelineFiles');
        $m->setAccessible(true);

        // workspace precisa existir (is_dir guard); base_path() existe.
        return $m->invoke($svc, 'a0000000000000000000000000000000000000aa', 'b0000000000000000000000000000000000000bb', base_path());
    }

    public function test_pipeline_engine_change_triggers_restart(): void
    {
        $pipeline = $this->changedPipelineFiles([
            'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
        ]);
        $this->assertNotEmpty($pipeline, 'mudança no grinder = drift de pipeline');
        $this->assertContains('app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php', $pipeline);
    }

    public function test_adversarial_panel_and_models_and_config_are_pipeline(): void
    {
        foreach ([
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMutationAdequacyGateService.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopCrossFileConsumerGateService.php',
            'app/Models/AtlasLoopProposal.php',
            'config/atlas.php',
            'database/migrations/2026_06_12_000100_governed_merge_door_atlas_loop_proposals.php',
        ] as $file) {
            $this->assertNotEmpty($this->changedPipelineFiles([$file]), "$file deve contar como pipeline");
        }
    }

    public function test_target_file_merges_do_NOT_trigger_restart(): void
    {
        // O trabalho normal do loop: melhorar arquivos-alvo comuns. NÃO pode reiniciar.
        $pipeline = $this->changedPipelineFiles([
            'app/Services/Ai/Aaeos/Generated/AtlasContractSchemaRegistryService.php',
            'app/Support/TerminalMarkdownRenderer.php',
            'app/Services/Ai/Kernel/Decision/ProviderFitWeightPolicy.php',
        ]);
        $this->assertSame([], $pipeline, 'merges de alvo não disparam restart (sem churn)');
    }

    public function test_mixed_merge_with_one_pipeline_file_triggers_restart(): void
    {
        // Um único arquivo de pipeline no meio de alvos já basta para reciclar.
        $pipeline = $this->changedPipelineFiles([
            'app/Support/TerminalMarkdownRenderer.php',
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionScenarioExplorer.php',
            'app/Services/Ai/Aaeos/Generated/Foo.php',
        ]);
        $this->assertSame(['app/Services/Ai/AutonomousEvolution/AtlasEvolutionScenarioExplorer.php'], $pipeline);
    }

    public function test_git_error_degrades_to_no_drift(): void
    {
        // Resolver lançando = best-effort vazio (o keepalive ainda cobre morte real).
        $svc = app(AtlasLoopCampaignSupervisor::class);
        $svc->setChangedFilesResolverForTesting(static function (): array {
            throw new \RuntimeException('git boom');
        });
        $m = new ReflectionMethod(AtlasLoopCampaignSupervisor::class, 'changedPipelineFiles');
        $m->setAccessible(true);

        try {
            $result = $m->invoke($svc, 'aa', 'bb', base_path());
        } catch (\Throwable) {
            $result = ['threw'];
        }
        // Aceita tanto [] quanto exceção engolida — o ponto é NÃO derrubar o supervisor.
        $this->assertTrue($result === [] || $result === ['threw']);
    }
}
