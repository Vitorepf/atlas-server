<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * L3 governança · rede anti-reabertura do bypass: todo spawn-site de provider CLI que
 * NÃO passa pelo AiProviderManager precisa consultar o seam governado
 * (ProviderGovernanceConsult::consultBeforeSpawn) antes do spawn. Se alguém remover a
 * consulta de um destes arquivos, este scan quebra — o bypass não reabre em silêncio.
 */
class ProviderSpawnGovernanceConsultTest extends TestCase
{
    /**
     * arquivo => lane (por que ele spawna provider fora do manager)
     *
     * @var array<string,string>
     */
    private const GOVERNED_SPAWN_SITES = [
        'app/Services/Ai/Programming/AtlasForgeBaseCliInvocationDriver.php' => 'Forge CLI drivers (claude/codex/gemini/cursor)',
        // Pin relocated under GOD-DEBULK D3 (2026-07-22): executeClaudeProvider moved verbatim
        // from PipelineRunExecutor.php into the ProviderExecutionSection family class; the
        // consult-before-spawn invariant is unchanged, only the file location moved.
        'app/Http/Controllers/AtlasDev/Support/PipelineRun/ProviderExecutionSection.php' => 'Dev claude lane (SymfonyClaudeCliGateway)',
        'app/Services/Engineering/EngineeringClaudeCodeBaselineRunnerService.php' => '3º pé do triplo claude (baseline runner)',
        'app/Services/Ai/Programming/ProviderRuntimeProcessFactory.php' => 'lane SDK (cursor/minimax/antigravity)',
    ];

    public function test_every_direct_provider_spawn_site_consults_the_governance_seam(): void
    {
        foreach (self::GOVERNED_SPAWN_SITES as $relPath => $lane) {
            $absolute = base_path($relPath);
            $this->assertFileExists($absolute, "spawn-site sumiu: {$relPath} ({$lane})");

            $source = (string) file_get_contents($absolute);
            $this->assertStringContainsString(
                'ProviderGovernanceConsult',
                $source,
                "BYPASS REABERTO: {$relPath} ({$lane}) spawna provider sem referenciar o seam governado.",
            );
            $this->assertStringContainsString(
                'consultBeforeSpawn',
                $source,
                "BYPASS REABERTO: {$relPath} ({$lane}) referencia o seam mas não chama consultBeforeSpawn.",
            );
        }
    }

    public function test_governance_consult_stays_failopen_at_the_new_spawn_sites(): void
    {
        // Os dois call-sites fechados em 09/07 são advisory: a consulta vive num try/catch
        // engolido (governança fora do ar NUNCA derruba spawn/captura). O scan garante que
        // o wrap não foi removido junto de refactors.
        foreach ([
            'app/Services/Engineering/EngineeringClaudeCodeBaselineRunnerService.php',
            'app/Services/Ai/Programming/ProviderRuntimeProcessFactory.php',
        ] as $relPath) {
            $source = (string) file_get_contents(base_path($relPath));
            $consultPos = strpos($source, 'consultBeforeSpawn');
            $this->assertNotFalse($consultPos, "consultBeforeSpawn ausente em {$relPath}");
            $before = substr($source, max(0, $consultPos - 600), 600);
            $this->assertStringContainsString('try {', $before, "consulta sem try fail-open em {$relPath}");
        }
    }
}
